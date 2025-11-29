<?php
// admin.php - Fixed Pagination & Favicon

session_start();

// --- 1. CONFIG & DATABASE ---
$db_host = 'localhost';
$db_name = 'seedlocm_apk';
$db_user = 'seedlocm_ali';
$db_pass = 'alialiali123!';
$dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
$photo_base_url = 'https://seedloc.my.id/api/';
$upload_dir = __DIR__ . '/../api/uploads/'; 

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

// --- 2. LOAD METADATA ---
$metadata_path = __DIR__ . '/../api/metadata.php';
if (file_exists($metadata_path)) {
    $metadata = require $metadata_path;
    $tree_types = $metadata['treeTypes'];
    $locations_list = $metadata['locations'];
} else {
    $tree_types = ['Lainnya'];
    $locations_list = ['Lainnya'];
}

// --- 3. HELPER FUNCTIONS ---
function log_activity($pdo, $action, $table, $details) {
    if (!isset($_SESSION['admin_username'])) return;
    $stmt = $pdo->prepare("INSERT INTO activity_logs (admin_username, action, target_table, details, ip_address) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_SESSION['admin_username'], $action, $table, $details, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
}

function get_photo_url($path, $base) {
    if (empty($path)) return '';
    return (strpos($path, 'http') === 0) ? $path : $base . $path;
}

// Export Function
function export_data($pdo, $ids, $type, $base_url, $upload_dir) {
    if(empty($ids)) return;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM geotags WHERE id IN ($ph) ORDER BY id DESC");
    $stmt->execute($ids);

    if ($type === 'download_zip') {
        if (!class_exists('ZipArchive')) { $_SESSION['swal_error'] = "ZipArchive tidak aktif."; return; }
        $zip = new ZipArchive();
        $zipName = 'photos_' . date('Ymd_His') . '.zip';
        $tempZip = tempnam(sys_get_temp_dir(), 'zip');
        if ($zip->open($tempZip, ZipArchive::CREATE) !== TRUE) return;

        $count = 0;
        while($r = $stmt->fetch()) {
            $p = $r['photoPath'];
            if (empty($p)) continue;
            $cleanType = preg_replace('/[^A-Za-z0-9]/', '_', $r['itemType']);
            $zipInternalName = $r['id'] . '_' . $cleanType . '.jpg';

            if (strpos($p, 'http') === 0) {
                $content = @file_get_contents($p);
                if ($content) { $zip->addFromString($zipInternalName, $content); $count++; }
            } else {
                $filePath = $upload_dir . basename($p);
                if (file_exists($filePath)) { $zip->addFile($filePath, $zipInternalName); $count++; }
            }
        }
        $zip->close();
        if ($count > 0) {
            header('Content-Type: application/zip'); header('Content-disposition: attachment; filename='.$zipName);
            header('Content-Length: ' . filesize($tempZip)); readfile($tempZip); unlink($tempZip); exit;
        } else { $_SESSION['swal_warning'] = "Foto tidak ditemukan."; unlink($tempZip); return; }
    } elseif ($type === 'csv') {
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="export.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Project', 'Lat', 'Lng', 'Location', 'Time', 'Type', 'Condition', 'Details', 'Photo']);
        while($r = $stmt->fetch()) {
            $r['photoPath'] = get_photo_url($r['photoPath'], $base_url);
            fputcsv($out, $r);
        }
        fclose($out); exit;
    }
}

// --- 4. AUTHENTICATION ---
if (isset($_SESSION['auth']) && $_SESSION['auth'] === true) {
    if (isset($_SESSION['auth_time']) && (time() - $_SESSION['auth_time'] > 3600)) {
        session_unset(); session_destroy(); header('Location: ?action=login&timeout=1'); exit;
    }
    $_SESSION['auth_time'] = time();
}
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
function require_auth() { if (!isset($_SESSION['auth']) || !$_SESSION['auth']) { header('Location: ?action=login'); exit; } }

// --- 5. CONTROLLER ---
$action = $_GET['action'] ?? 'dashboard';
$table = $_GET['table'] ?? 'geotags';
$pk = ($table === 'projects') ? 'projectId' : 'id';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
    $stmt->execute([$_POST['username'] ?? '']);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        $_SESSION['auth'] = true; $_SESSION['auth_time'] = time();
        $_SESSION['admin_id'] = $user['id']; $_SESSION['admin_username'] = $user['username']; $_SESSION['admin_role'] = $user['role'];
        $_SESSION['swal_success'] = "Login Berhasil"; header('Location: admin.php'); exit;
    } else { $_SESSION['swal_error'] = 'Login Gagal'; }
}
if ($action === 'logout') { session_destroy(); header('Location: ?action=login'); exit; }
if ($action !== 'login') require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) die('CSRF Fail');
    
    // Bulk Actions
    if (isset($_POST['bulk_action'])) {
        $ids = $_POST['selected_ids'] ?? [];
        $type = $_POST['bulk_action_type'] ?? '';
        if (!empty($ids)) {
            if ($type == 'download_zip') export_data($pdo, $ids, 'download_zip', $photo_base_url, $upload_dir);
            elseif ($type == 'export_csv') export_data($pdo, $ids, 'csv', $photo_base_url, $upload_dir);
            elseif ($type == 'delete_selected') {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                if($table=='geotags'){
                    $f=$pdo->prepare("SELECT photoPath FROM geotags WHERE id IN ($ph)"); $f->execute($ids);
                    while($r=$f->fetch()){ $p=$upload_dir.basename($r['photoPath']); if(file_exists($p)) @unlink($p); }
                }
                $pdo->prepare("DELETE FROM `$table` WHERE `$pk` IN ($ph)")->execute($ids);
                $_SESSION['swal_success'] = "Data dihapus"; header("Location: ?action=list&table=$table"); exit;
            } elseif ($type == 'mark_synced' && $table == 'geotags') {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("UPDATE geotags SET isSynced = 1 WHERE id IN ($ph)")->execute($ids);
                $_SESSION['swal_success'] = "Data di-sync"; header("Location: ?action=list&table=$table"); exit;
            }
        }
    }

    // CRUD
    if (isset($_POST['update']) || isset($_POST['create'])) {
        try {
            $id = $_POST[$pk] ?? null;
            if ($table == 'geotags') {
                $sql = isset($_POST['create']) 
                    ? "INSERT INTO geotags (itemType, `condition`, details, locationName, latitude, longitude, isSynced, projectId) VALUES (?,?,?,?,?,?,?,?)"
                    : "UPDATE geotags SET itemType=?, `condition`=?, details=?, locationName=?, latitude=?, longitude=?, isSynced=? WHERE id=?";
                $params = [$_POST['itemType'], $_POST['condition'], $_POST['details'], $_POST['locationName'], $_POST['latitude'], $_POST['longitude'], $_POST['isSynced']];
                if(isset($_POST['create'])) $params[] = $_POST['projectId']??0; else $params[] = $id;
                $pdo->prepare($sql)->execute($params);
            } else {
                $sql = isset($_POST['create']) 
                    ? "INSERT INTO projects (projectId, activityName, locationName, officers, status) VALUES (?,?,?,?,?)" 
                    : "UPDATE projects SET activityName=?, locationName=?, officers=?, status=? WHERE projectId=?";
                $params = [$_POST['activityName'], $_POST['locationName'], $_POST['officers'], $_POST['status']];
                if(isset($_POST['create'])) array_unshift($params, $_POST['projectId']); else $params[] = $id;
                $pdo->prepare($sql)->execute($params);
            }
            $_SESSION['swal_success'] = "Data disimpan"; header("Location: ?action=list&table=$table"); exit;
        } catch(Exception $e) { $_SESSION['swal_error'] = $e->getMessage(); }
    }
    if (isset($_POST['delete'])) {
        $id = $_POST['id'];
        if($table=='geotags'){
            $r=$pdo->query("SELECT photoPath FROM geotags WHERE id=$id")->fetch();
            $p=$upload_dir.basename($r['photoPath']); if(file_exists($p)) @unlink($p);
        }
        $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = ?")->execute([$id]);
        $_SESSION['swal_success'] = "Data dihapus"; header("Location: ?action=list&table=$table"); exit;
    }
}

// --- 6. DATA FETCHING & QUERY BUILDER ---
function buildWhere($table) {
    $where = []; $p = [];
    if (!empty($_GET['search'])) { if ($table == 'geotags') { $where[] = "(itemType LIKE ? OR locationName LIKE ?)"; $p[] = "%{$_GET['search']}%"; $p[] = "%{$_GET['search']}%"; } }
    if (!empty($_GET['condition']) && $_GET['condition'] != 'all') { $where[] = "`condition`=?"; $p[] = $_GET['condition']; }
    if (!empty($_GET['projectId']) && $_GET['projectId'] != 'all') { $where[] = "projectId=?"; $p[] = $_GET['projectId']; }
    if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) { $where[] = "DATE(timestamp) BETWEEN ? AND ?"; $p[] = $_GET['start_date']; $p[] = $_GET['end_date']; }
    return [$where, $p];
}

$stats = [];
if ($action === 'dashboard') {
    $stats['geotags'] = $pdo->query("SELECT COUNT(*) FROM geotags")->fetchColumn();
    $stats['projects'] = $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
    $stats['cond'] = $pdo->query("SELECT `condition`, COUNT(*) FROM geotags GROUP BY `condition`")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['daily'] = $pdo->query("SELECT DATE(timestamp), COUNT(*) FROM geotags WHERE timestamp >= DATE(NOW()) - INTERVAL 7 DAY GROUP BY DATE(timestamp)")->fetchAll(PDO::FETCH_KEY_PAIR);
}

$list_data = []; $page = (int)($_GET['page'] ?? 1); $per_page = 20; $total_pages = 1;
if ($page < 1) $page = 1;

if (in_array($action, ['list', 'gallery', 'map', 'users'])) {
    if ($action == 'users') { 
        $list_data = $pdo->query("SELECT * FROM admin_users ORDER BY id DESC")->fetchAll(); 
    } elseif ($action == 'map') {
        list($where, $p) = buildWhere('geotags');
        $sql = "SELECT id, latitude, longitude, itemType, `condition`, photoPath, locationName FROM geotags " . ($where ? "WHERE ".implode(' AND ', $where) : "");
        $stmt = $pdo->prepare($sql); $stmt->execute($p); $map_data = $stmt->fetchAll();
    } else {
        list($where, $p) = buildWhere($table);
        $w_sql = $where ? "WHERE ".implode(' AND ', $where) : "";
        
        // Count Total
        $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` $w_sql");
        $total_stmt->execute($p);
        $total_rows = $total_stmt->fetchColumn();
        $total_pages = ceil($total_rows / $per_page);
        
        // Offset Logic
        $offset = ($page - 1) * $per_page;
        
        // Fetch Data
        $stmt = $pdo->prepare("SELECT * FROM `$table` $w_sql ORDER BY `$pk` DESC LIMIT $per_page OFFSET $offset");
        $stmt->execute($p);
        $list_data = $stmt->fetchAll();
        
        if ($table == 'geotags') $projects_list = $pdo->query("SELECT projectId, activityName FROM projects")->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SeedLoc Admin</title>
    <link rel="icon" href="https://seedloc.my.id/Logo.png" type="image/png">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body{font-family:sans-serif;background:#f4f6f8;margin:0;display:flex;height:100vh;overflow:hidden}
        .sidebar{width:240px;background:#fff;border-right:1px solid #ddd;display:flex;flex-direction:column}
        .brand{padding:20px;border-bottom:1px solid #eee;font-weight:bold;color:#2E7D32;display:flex;align-items:center;gap:10px}
        .nav{list-style:none;padding:0;margin:0;flex:1;overflow-y:auto}
        .nav a{display:block;padding:12px 20px;color:#555;text-decoration:none;border-left:4px solid transparent}
        .nav a:hover,.nav a.active{background:#e8f5e9;color:#2E7D32;border-left-color:#2E7D32}
        .main{flex:1;padding:20px;overflow-y:auto}
        .card{background:#fff;padding:20px;border-radius:8px;border:1px solid #ddd;margin-bottom:20px}
        .btn{padding:8px 12px;border:none;border-radius:4px;color:#fff;cursor:pointer;text-decoration:none;font-size:13px}
        .btn-p{background:#2E7D32} .btn-d{background:#d32f2f} .btn-w{background:#f39c12}
        table{width:100%;border-collapse:collapse;font-size:14px} th,td{padding:10px;border-bottom:1px solid #eee;text-align:left} th{background:#f9f9f9}
        .filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:15px;background:#fff;padding:15px;border-radius:8px;border:1px solid #ddd;align-items:center}
        input,select{padding:8px;border:1px solid #ccc;border-radius:4px}
        .modal{display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.9);justify-content:center;align-items:center;flex-direction:column}
        .modal-content{max-width:90%;max-height:85%;border-radius:5px}
        @media(max-width:768px){.sidebar{width:50px}.brand span,.nav span{display:none}.brand{justify-content:center;padding:15px 0}}
    </style>
</head>
<body>

<?php 
if(isset($_SESSION['swal_success'])){ echo "<script>Swal.fire({icon:'success',title:'Berhasil',text:'{$_SESSION['swal_success']}',timer:1500,showConfirmButton:false});</script>"; unset($_SESSION['swal_success']); }
if(isset($_SESSION['swal_error'])){ echo "<script>Swal.fire({icon:'error',title:'Error',text:'{$_SESSION['swal_error']}'});</script>"; unset($_SESSION['swal_error']); }
?>

<?php if($action === 'login'): ?>
    <div style="width:100%;display:flex;justify-content:center;align-items:center;">
        <div class="card" style="width:300px;text-align:center;padding-top:40px;">
            <img src="https://seedloc.my.id/Logo.png" width="80" style="margin-bottom:15px;border-radius:10px;">
            <h3>Admin Login</h3>
            <form method="post">
                <input type="text" name="username" placeholder="Username" style="width:90%;margin-bottom:15px;" required>
                <input type="password" name="password" placeholder="Password" style="width:90%;margin-bottom:15px;" required>
                <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                <button name="login" class="btn btn-p" style="width:100%;">MASUK</button>
            </form>
        </div>
    </div>
<?php else: ?>

<nav class="sidebar">
    <div class="brand"><img src="https://seedloc.my.id/logo.png" width="30"> <span>SeedLoc</span></div>
    <ul class="nav">
        <li><a href="?action=dashboard" class="<?=$action=='dashboard'?'active':''?>"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
        <li><a href="?action=map" class="<?=$action=='map'?'active':''?>"><i class="fas fa-map"></i> <span>Peta</span></a></li>
        <li><a href="?action=list&table=geotags" class="<?=($action=='list'&&$table=='geotags')?'active':''?>"><i class="fas fa-leaf"></i> <span>Geotag</span></a></li>
        <li><a href="?action=gallery" class="<?=$action=='gallery'?'active':''?>"><i class="fas fa-images"></i> <span>Galeri</span></a></li>
        <li><a href="?action=list&table=projects" class="<?=($action=='list'&&$table=='projects')?'active':''?>"><i class="fas fa-folder"></i> <span>Proyek</span></a></li>
        <li><a href="?action=users" class="<?=$action=='users'?'active':''?>"><i class="fas fa-users-cog"></i> <span>Admins</span></a></li>
        <li><a href="?action=logout" style="color:#d32f2f;"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
    </ul>
</nav>

<main class="main">
    <div class="header">
        <h2 style="margin:0;"><?=ucfirst($action=='list'?"Data $table":$action)?></h2>
    </div>

    <?php if($action === 'dashboard'): ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:20px;">
            <div class="card" style="border-left:4px solid #2E7D32;"><h3><?=$stats['geotags']?></h3><small>Geotags</small></div>
            <div class="card" style="border-left:4px solid #1976d2;"><h3><?=$stats['projects']?></h3><small>Projects</small></div>
        </div>
        <div style="display:flex;gap:20px;flex-wrap:wrap;">
            <div class="card" style="flex:1;"><h4>Kondisi</h4><canvas id="c1"></canvas></div>
            <div class="card" style="flex:2;"><h4>Harian</h4><canvas id="c2"></canvas></div>
        </div>
        <script>
            new Chart(document.getElementById('c1'),{type:'doughnut',data:{labels:<?=json_encode(array_keys($stats['cond']))?>,datasets:[{data:<?=json_encode(array_values($stats['cond']))?>,backgroundColor:['#4caf50','#ffeb3b','#f44336']}]}});
            new Chart(document.getElementById('c2'),{type:'bar',data:{labels:<?=json_encode(array_keys($stats['daily']))?>,datasets:[{label:'Jml',data:<?=json_encode(array_values($stats['daily']))?>,backgroundColor:'#2196f3'}]}});
        </script>

    <?php elseif($action === 'map'): ?>
        <form class="filter-bar">
            <input type="hidden" name="action" value="map">
            <select name="condition"><option value="all">Semua Kondisi</option><option value="Baik">Baik</option><option value="Rusak">Rusak</option><option value="Mati">Mati</option></select>
            <input type="date" name="start_date"> - <input type="date" name="end_date">
            <button class="btn btn-p">Filter</button> <a href="?action=map" class="btn btn-d">Reset</a>
        </form>
        <div id="map" style="height:600px;border-radius:8px;"></div>
        <script>
            var m = L.map('map').setView([-6.2, 106.8], 5);
            L.control.layers({"Jalan":L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'),"Satelit":L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}')}).addTo(m);
            var pts = <?=json_encode($map_data)?>; var b=[];
            pts.forEach(p=>{
                var lat=parseFloat(p.latitude),lng=parseFloat(p.longitude);
                if(!isNaN(lat)){
                    var c='#f39c12'; if(p.condition=='Baik'||p.condition=='Hidup')c='#2E7D32'; if(p.condition=='Mati')c='#d32f2f';
                    var img=p.photoPath?(p.photoPath.startsWith('http')?p.photoPath:'<?=$photo_base_url?>'+p.photoPath):'';
                    L.circleMarker([lat,lng],{radius:8,fillColor:c,color:"#fff",weight:1,opacity:1,fillOpacity:0.8}).addTo(m).bindPopup(`<b>${p.itemType}</b><br>${p.condition}<br>${p.locationName}${img?'<br><img src="'+img+'" width="150">':''}`);
                    b.push([lat,lng]);
                }
            });
            if(b.length) m.fitBounds(b);
        </script>

    <?php elseif(in_array($action, ['list', 'users'])): ?>
        <?php if($action=='list' && $table=='geotags'): ?>
        <form class="filter-bar">
            <input type="hidden" name="action" value="list"><input type="hidden" name="table" value="geotags">
            <input type="text" name="search" placeholder="Cari..." value="<?=htmlspecialchars($_GET['search']??'')?>">
            <select name="projectId"><option value="all">Project</option><?php foreach($projects_list as $p) echo "<option value='{$p['projectId']}'>{$p['activityName']}</option>"; ?></select>
            <input type="date" name="start_date"> - <input type="date" name="end_date">
            <button class="btn btn-p">Cari</button> <a href="?action=list&table=geotags" class="btn btn-d">Reset</a>
        </form>
        <?php endif; ?>

        <?php if($action=='list'): ?>
        <form method="post" id="bulkForm">
            <?php if($table == 'geotags'): ?>
            <div style="background:#e8f5e9;padding:10px;border-radius:8px;margin-bottom:10px;display:flex;gap:10px;">
                <b>Aksi Massal:</b> <select name="bulk_action_type"><option value="">Pilih...</option><option value="download_zip">Download ZIP</option><option value="delete_selected">Hapus</option></select>
                <button type="button" onclick="confirmBulk()" class="btn btn-w">Proses</button> <button name="bulk_action" id="realBulkBtn" style="display:none;"></button>
                <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
            </div>
            <?php endif; ?>
            <div style="overflow-x:auto;">
                <table>
                <thead><tr><th width="30"><input type="checkbox" onclick="toggle(this)"></th><?php if($table=='geotags'): ?><th>Foto</th><th>Tipe</th><th>Lokasi</th><th>Tanggal</th><th>Kondisi</th><?php else: ?><th>ID</th><th>Nama</th><th>Status</th><?php endif; ?><th>Aksi</th></tr></thead>
                <tbody>
                    <?php foreach($list_data as $r): ?>
                    <tr>
                        <td><input type="checkbox" name="selected_ids[]" value="<?=$r[$pk]?>"></td>
                        <?php if($table=='geotags'): $i=get_photo_url($r['photoPath'], $photo_base_url); ?>
                        <td><?php if($i):?><img src="<?=$i?>" width="40" height="40" style="object-fit:cover;cursor:pointer;" onclick="showModal('<?=$i?>','<?=$r['itemType']?>')"><?php endif; ?></td>
                        <td><?=$r['itemType']?></td><td><?=$r['locationName']?></td><td><?=substr($r['timestamp'],0,10)?></td><td><?=$r['condition']?></td>
                        <?php else: ?><td><?=$r['projectId']?></td><td><?=$r['activityName']?></td><td><?=$r['status']?></td><?php endif; ?>
                        <td><a href="?action=edit&table=<?=$table?>&id=<?=$r[$pk]?>" class="btn btn-p">Edit</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                </table>
            </div>
        </form>
        <?php elseif($action=='users'): ?>
            <div class="card"><table><tr><th>User</th><th>Role</th></tr><?php foreach($list_data as $u): ?><tr><td><?=$u['username']?></td><td><?=$u['role']?></td></tr><?php endforeach; ?></table></div>
        <?php endif; ?>
        
        <div style="display:flex;justify-content:center;gap:5px;margin-top:20px;">
            <?php 
            // Pastikan parameter URL tetap terbawa
            $q = $_GET; 
            $q['action'] = $action; // Force action
            $q['table'] = $table;   // Force table
            
            if($page > 1) { 
                $q['page'] = $page - 1; 
                echo '<a href="?'.http_build_query($q).'" class="btn btn-p">Prev</a>'; 
            }
            if($page < $total_pages) { 
                $q['page'] = $page + 1; 
                echo '<a href="?'.http_build_query($q).'" class="btn btn-p">Next</a>'; 
            } 
            ?>
        </div>

    <?php elseif($action === 'gallery'): ?>
        <form class="filter-bar"><input type="hidden" name="action" value="gallery"><input type="hidden" name="table" value="geotags"><input type="text" name="search" placeholder="Cari..."><button class="btn btn-p">Cari</button></form>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;">
            <?php if(!empty($list_data)): foreach($list_data as $r): $i=get_photo_url($r['photoPath']??'', $photo_base_url); if(!$i) continue; ?>
            <div class="card" style="padding:0;overflow:hidden;"><img src="<?=$i?>" style="width:100%;height:120px;object-fit:cover;cursor:pointer;" onclick="showModal('<?=$i?>','')"><div style="padding:5px;font-size:12px;"><b><?=$r['itemType']?></b><br><?=$r['locationName']?></div></div>
            <?php endforeach; endif; ?>
        </div>
        <div style="display:flex;justify-content:center;gap:5px;margin-top:20px;"><?php $q=$_GET; if($page>1){$q['page']=$page-1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p">Prev</a>';} if($page<$total_pages){$q['page']=$page+1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p">Next</a>';} ?></div>

    <?php elseif($action === 'edit' || $action === 'create'): $d=$action=='edit'?$pdo->query("SELECT * FROM `$table` WHERE `$pk`='{$_GET['id']}'")->fetch():[]; ?>
        <div class="card" style="max-width:600px;margin:auto;">
            <h3>Input Data</h3>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                <?php if($table=='geotags'): ?>
                    <input type="hidden" name="id" value="<?=$d['id']?>">
                    <label>Tipe</label><select name="itemType" style="width:100%;margin-bottom:10px;"><?php foreach($tree_types as $t) echo "<option value='$t' ".($d['itemType']==$t?'selected':'').">$t</option>"; ?></select>
                    <label>Lokasi</label><select name="locationName" style="width:100%;margin-bottom:10px;"><?php foreach($locations_list as $l) echo "<option value='$l' ".($d['locationName']==$l?'selected':'').">$l</option>"; ?></select>
                    <label>Kondisi</label><select name="condition" style="width:100%;margin-bottom:10px;"><option value="Baik" <?=($d['condition']=='Baik'?'selected':'')?>>Baik</option><option value="Rusak" <?=($d['condition']=='Rusak'?'selected':'')?>>Rusak</option></select>
                    <label>Detail</label><input type="text" name="details" value="<?=$d['details']?>" style="width:100%;margin-bottom:10px;">
                    <div style="display:flex;gap:10px;"><input type="text" name="latitude" value="<?=$d['latitude']?>" placeholder="Lat"><input type="text" name="longitude" value="<?=$d['longitude']?>" placeholder="Lng"></div>
                    <label>Sync</label><select name="isSynced" style="width:100%;margin-bottom:10px;"><option value="1">Ya</option><option value="0">Tidak</option></select>
                <?php else: ?>
                    <input type="text" name="projectId" value="<?=$d['projectId']??''?>" placeholder="ID"><input type="text" name="activityName" value="<?=$d['activityName']??''?>" placeholder="Nama"><select name="status"><option value="Active">Active</option></select>
                <?php endif; ?>
                <div style="margin-top:20px;display:flex;justify-content:space-between;"><a href="?action=list&table=<?=$table?>" class="btn btn-w">Batal</a><button name="<?=$action=='edit'?'update':'create'?>" class="btn btn-p">Simpan</button></div>
            </form>
        </div>
    <?php endif; ?>
</main>

<div id="imgModal" class="modal" onclick="this.style.display='none'"><img class="modal-content" id="modalImg"><div id="modalCaption" style="color:#fff;margin-top:10px;"></div></div>
<script>
    function toggle(s){var c=document.querySelectorAll('input[name="selected_ids[]"]');for(var i=0;i<c.length;i++)c[i].checked=s.checked;}
    function showModal(s,c){document.getElementById('imgModal').style.display="flex";document.getElementById('modalImg').src=s;document.getElementById('modalCaption').innerHTML=c;}
    function confirmBulk(){var s=document.querySelector('select[name="bulk_action_type"]');if(s.value==''){Swal.fire('Pilih Aksi','','info');return;} Swal.fire({title:'Yakin?',icon:'warning',showCancelButton:true}).then((r)=>{if(r.isConfirmed)document.getElementById('realBulkBtn').click();});}
</script>

<?php endif; ?>
</body>
</html>