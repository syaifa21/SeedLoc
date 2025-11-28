<?php
// admin.php - Updated with Dropdowns for ItemType & Location

session_start();

// --- 1. CONFIG & DATABASE ---
$db_host = 'localhost';
$db_name = 'seedlocm_apk';
$db_user = 'seedlocm_ali';
$db_pass = 'alialiali123!';
$dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
$photo_base_url = 'https://seedloc.my.id/api/';
$upload_dir = '../api/uploads/';

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

// --- 2. DATA LISTS (Sinkron dengan Flutter) ---
$tree_types = [
    'Saninten (Castanopsis argentea)', 'Kuray (Trema orientalis)', 'Kondang (Ficus variegata)', 
    'Nangsi (Oreocnide rubescens)', 'Pingku (Dysoxylum ramiflorum)', 'Manglid (Magnolia blumei)', 
    'Puspa (Schima wallichii)', 'Pasang (Lithocarpus sp)', 'Darangdan (Ficus melinocarpa)', 
    'Simpur (Dillenia obovata)', 'Kemiri (Aleurites sp)', 'Picung/Kluwek (Pangium edule)', 
    'Huru-huruan (Litsea sp)', 'Beunying (Ficus fistulosa)', 'Lame/Pulai (Alstonia scholaris)', 
    'Mara (Macaranga tanarius)', 'Hamberang (Ficus fulva)', 'Kiteja (Cinnamomum iners)', 
    'Walen (Ficus ribes)', 'Peutag (Acemena acuminatissima)', 'Hantap (Sterculia oblongata)', 
    'Benda (Arthocarpus elasticus)', 'Kedoya (Dysoxylum gaudichaudianum)', 'Kileho (Saurauia pendula)', 
    'Kopo (Syzygium pycnanthum)', 'Kimuncang (Croton argyratus)', 'Cangcaratan (Neonauclea sp)', 
    'Salam (Syzygium polyanthum)', 'Ki Lampet (Wenlandia junghuhniana)', 'Kawoyang (Prunus grisea)', 
    'Kareumbi (Homalanthus populnes)', 'Kosambi (Schleichera oleosa)', 'Masawa (Anisoptera costata)', 
    'Huru Dapung (Actinodaphne glomerata)', 'Petai (Parkia speciosa)', 'Jengkol (Archidendron pauciflorum)', 
    'Bingbin (Pinanga coronata)', 'Aren (Arenga pinnata)', 'Jamuju (Podocarpus imbricatus)', 
    'Ganitri (Eleaocarpus ganitrus)', 'Ki Beusi (Dodonaea viscosa)', 'Huru dadap (Erythrina fusca)', 
    'Hanjawar (Caryota mitis)', 'Lainnya'
];

$locations_list = ['Sayangkaak', 'Simpangangin', 'K2', 'K5', 'Batu Ngadapang', 'Lainnya'];

// --- 3. FUNGSI BANTUAN ---
function log_activity($pdo, $action, $table, $details) {
    if (!isset($_SESSION['admin_username'])) return;
    $stmt = $pdo->prepare("INSERT INTO activity_logs (admin_username, action, target_table, details, ip_address) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_SESSION['admin_username'], $action, $table, $details, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
}

function get_photo_url($path, $base) {
    if (empty($path)) return '';
    return (strpos($path, 'http') === 0) ? $path : $base . $path;
}

// Export Functions
function export_data($pdo, $ids, $type, $base_url) {
    if(empty($ids)) return;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    
    if ($type === 'csv') {
        $sql = "SELECT * FROM geotags WHERE id IN ($ph) ORDER BY id DESC";
        $rows = $pdo->prepare($sql); $rows->execute($ids);
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="export_'.date('YmdHis').'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'ProjectID', 'Lat', 'Lng', 'Location', 'Time', 'Type', 'Condition', 'Details', 'PhotoURL', 'Synced']);
        while($r = $rows->fetch()) {
            $r['photoPath'] = get_photo_url($r['photoPath'], $base_url);
            fputcsv($out, $r);
        }
        fclose($out);
    } elseif ($type === 'kml') {
        $rows = $pdo->prepare("SELECT * FROM geotags WHERE id IN ($ph) ORDER BY id DESC"); $rows->execute($ids);
        header('Content-Type: application/vnd.google-earth.kml+xml'); header('Content-Disposition: attachment; filename="export_'.date('YmdHis').'.kml"');
        echo '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>';
        while($r = $rows->fetch()) {
            $img = get_photo_url($r['photoPath'], $base_url);
            $desc = "<b>Kondisi:</b> {$r['condition']}<br><b>Lokasi:</b> {$r['locationName']}<br><b>Waktu:</b> {$r['timestamp']}";
            if($img) $desc .= "<br><img src='$img' width='200'>";
            echo "<Placemark><name>".htmlspecialchars($r['itemType'])."</name><description><![CDATA[$desc]]></description><Point><coordinates>{$r['longitude']},{$r['latitude']}</coordinates></Point></Placemark>";
        }
        echo '</Document></kml>';
    }
    log_activity($pdo, 'EXPORT_'.strtoupper($type), 'geotags', 'Exported '.count($ids).' items');
    exit;
}

// --- 4. AUTHENTICATION ---
$session_timeout = 3600; 
if (isset($_SESSION['auth']) && $_SESSION['auth'] === true) {
    if (isset($_SESSION['auth_time']) && (time() - $_SESSION['auth_time'] > $session_timeout)) {
        session_unset(); session_destroy(); header('Location: ?action=login&timeout=1'); exit;
    }
    $_SESSION['auth_time'] = time();
}
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
function require_auth() { if (!isset($_SESSION['auth']) || !$_SESSION['auth']) { header('Location: ?action=login'); exit; } }

// --- 5. CONTROLLER LOGIC ---
$action = $_GET['action'] ?? 'dashboard';
$table = $_GET['table'] ?? 'geotags';
$pk = ($table === 'projects') ? 'projectId' : 'id';

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
    $stmt->execute([$_POST['username'] ?? '']);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        $_SESSION['auth'] = true; $_SESSION['auth_time'] = time();
        $_SESSION['admin_id'] = $user['id']; $_SESSION['admin_username'] = $user['username']; $_SESSION['admin_role'] = $user['role'];
        log_activity($pdo, 'LOGIN', 'auth', 'Success');
        header('Location: admin.php'); exit;
    } else { $login_error = 'Username/Password salah.'; }
}
if ($action === 'logout') { log_activity($pdo, 'LOGOUT', 'auth', 'Bye'); session_destroy(); header('Location: ?action=login'); exit; }

if ($action !== 'login') require_auth();

// POST Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) die('CSRF Fail');
    
    // Bulk Actions
    if (isset($_POST['bulk_action'])) {
        $ids = $_POST['selected_ids'] ?? [];
        $type = $_POST['bulk_action_type'] ?? '';
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if ($type == 'export_csv') export_data($pdo, $ids, 'csv', $photo_base_url);
            elseif ($type == 'export_kml') export_data($pdo, $ids, 'kml', $photo_base_url);
            elseif ($type == 'delete_selected') {
                if($table == 'geotags') {
                    $files = $pdo->prepare("SELECT photoPath FROM geotags WHERE id IN ($ph)"); $files->execute($ids);
                    while($f = $files->fetch()) if($f['photoPath'] && !strpos($f['photoPath'], 'http')) @unlink($upload_dir . basename($f['photoPath']));
                }
                $pdo->prepare("DELETE FROM `$table` WHERE `$pk` IN ($ph)")->execute($ids);
                log_activity($pdo, 'BULK_DELETE', $table, count($ids).' items');
                header("Location: ?action=list&table=$table&msg=deleted"); exit;
            } elseif ($type == 'mark_synced' && $table == 'geotags') {
                $pdo->prepare("UPDATE geotags SET isSynced = 1 WHERE id IN ($ph)")->execute($ids);
                log_activity($pdo, 'BULK_SYNC', 'geotags', count($ids).' items');
                header("Location: ?action=list&table=$table&msg=synced"); exit;
            }
        }
    }

    // Create/Update/Delete
    if (isset($_POST['create_admin']) && $_SESSION['admin_role'] == 'superadmin') {
        try {
            $pdo->prepare("INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, ?)")
                ->execute([$_POST['username'], password_hash($_POST['password'], PASSWORD_DEFAULT), $_POST['role']]);
            header("Location: ?action=users"); exit;
        } catch(Exception $e) {}
    }
    if (isset($_POST['delete_admin']) && $_SESSION['admin_role'] == 'superadmin') {
        if($_POST['id'] != $_SESSION['admin_id']) $pdo->prepare("DELETE FROM admin_users WHERE id=?")->execute([$_POST['id']]);
        header("Location: ?action=users"); exit;
    }
    if (isset($_POST['create']) && $table == 'projects') {
        $pdo->prepare("INSERT INTO projects (projectId, activityName, locationName, officers, status) VALUES (?,?,?,?,?)")
            ->execute([$_POST['projectId'], $_POST['activityName'], $_POST['locationName'], $_POST['officers'], $_POST['status']]);
        log_activity($pdo, 'CREATE', 'projects', $_POST['projectId']);
        header("Location: ?action=list&table=projects"); exit;
    }
    if (isset($_POST['update'])) {
        $id = $_POST[$pk];
        if ($table == 'geotags') {
            if(isset($_POST['delete_photo']) && $_POST['delete_photo']==1) {
                $f = $pdo->query("SELECT photoPath FROM geotags WHERE id=$id")->fetch();
                if($f['photoPath'] && !strpos($f['photoPath'], 'http')) @unlink($upload_dir . basename($f['photoPath']));
                $pdo->query("UPDATE geotags SET photoPath='' WHERE id=$id");
            }
            $pdo->prepare("UPDATE geotags SET itemType=?, `condition`=?, details=?, locationName=?, latitude=?, longitude=?, isSynced=? WHERE id=?")
                ->execute([$_POST['itemType'], $_POST['condition'], $_POST['details'], $_POST['locationName'], $_POST['latitude'], $_POST['longitude'], $_POST['isSynced'], $id]);
        } else {
            $pdo->prepare("UPDATE projects SET activityName=?, locationName=?, officers=?, status=? WHERE projectId=?")
                ->execute([$_POST['activityName'], $_POST['locationName'], $_POST['officers'], $_POST['status'], $id]);
        }
        log_activity($pdo, 'UPDATE', $table, $id);
        header("Location: ?action=list&table=$table"); exit;
    }
    if (isset($_POST['delete'])) {
        $id = $_POST['id'];
        if($table == 'geotags') {
            $f = $pdo->query("SELECT photoPath FROM geotags WHERE id=$id")->fetch();
            if($f && $f['photoPath'] && !strpos($f['photoPath'], 'http')) @unlink($upload_dir . basename($f['photoPath']));
        }
        $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = ?")->execute([$id]);
        log_activity($pdo, 'DELETE', $table, $id);
        header("Location: ?action=list&table=$table"); exit;
    }
}

// --- 6. DATA FETCHING ---
$stats = [];
if ($action === 'dashboard') {
    $stats['geotags'] = $pdo->query("SELECT COUNT(*) FROM geotags")->fetchColumn();
    $stats['projects'] = $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
    $stats['cond'] = $pdo->query("SELECT `condition`, COUNT(*) FROM geotags GROUP BY `condition`")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['daily'] = $pdo->query("SELECT DATE(timestamp), COUNT(*) FROM geotags WHERE timestamp >= DATE(NOW()) - INTERVAL 7 DAY GROUP BY DATE(timestamp)")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['logs'] = $pdo->query("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 5")->fetchAll();
}

$list_data = []; $page = $_GET['page'] ?? 1; $per_page = 20; $total_pages = 1;
if (in_array($action, ['list', 'gallery', 'map', 'logs', 'users'])) {
    if ($action == 'users') {
        $list_data = $pdo->query("SELECT * FROM admin_users ORDER BY id DESC")->fetchAll();
    } elseif ($action == 'logs') {
        $total = $pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
        $total_pages = ceil($total/50); $offset = ($page-1)*50;
        $list_data = $pdo->query("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 50 OFFSET $offset")->fetchAll();
    } elseif ($action == 'map') {
        $where=[]; $p=[];
        if(!empty($_GET['search'])) { $where[]="(itemType LIKE ? OR locationName LIKE ?)"; $p[]="%{$_GET['search']}%"; $p[]="%{$_GET['search']}%"; }
        if(!empty($_GET['condition']) && $_GET['condition']!='all') { $where[]="`condition`=?"; $p[]=$_GET['condition']; }
        $sql = "SELECT id, latitude, longitude, itemType, `condition`, photoPath, locationName FROM geotags " . ($where?"WHERE ".implode(' AND ',$where):"");
        $stmt=$pdo->prepare($sql); $stmt->execute($p); $map_data=$stmt->fetchAll();
    } else {
        // List/Gallery Geotag & Project
        $where=[]; $p=[];
        if($table == 'geotags') {
            if(!empty($_GET['search'])) { $where[]="(itemType LIKE ? OR locationName LIKE ?)"; $p[]="%{$_GET['search']}%"; $p[]="%{$_GET['search']}%"; }
            if(!empty($_GET['condition']) && $_GET['condition']!='all') { $where[]="`condition`=?"; $p[]=$_GET['condition']; }
            if(!empty($_GET['projectId']) && $_GET['projectId']!='all') { $where[]="projectId=?"; $p[]=$_GET['projectId']; }
        }
        $w_sql = $where ? "WHERE ".implode(' AND ', $where) : "";
        $total = $pdo->prepare("SELECT COUNT(*) FROM `$table` $w_sql"); $total->execute($p);
        $total_pages = ceil($total->fetchColumn()/$per_page); $offset = ($page-1)*$per_page;
        
        $stmt = $pdo->prepare("SELECT * FROM `$table` $w_sql ORDER BY `$pk` DESC LIMIT $per_page OFFSET $offset");
        $stmt->execute($p); $list_data = $stmt->fetchAll();
        if($table=='geotags') $projects_list = $pdo->query("SELECT projectId, activityName FROM projects")->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SeedLoc Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        :root { --p: #2E7D32; --bg: #f4f6f8; --w: #fff; }
        body { font-family: sans-serif; background: var(--bg); margin: 0; display: flex; height: 100vh; overflow: hidden; }
        .sidebar { width: 240px; background: var(--w); border-right: 1px solid #ddd; display: flex; flex-direction: column; flex-shrink: 0; }
        .brand { padding: 20px; border-bottom: 1px solid #eee; font-weight: bold; color: var(--p); display: flex; align-items: center; gap: 10px; }
        .brand img { width: 30px; }
        .nav { list-style: none; padding: 0; margin: 0; flex: 1; overflow-y: auto; }
        .nav a { display: block; padding: 12px 20px; color: #555; text-decoration: none; border-left: 4px solid transparent; }
        .nav a:hover, .nav a.active { background: #e8f5e9; color: var(--p); border-left-color: var(--p); }
        .nav i { width: 25px; text-align: center; }
        .main { flex: 1; padding: 20px; overflow-y: auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card { background: var(--w); padding: 20px; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 20px; }
        .btn { padding: 8px 12px; border: none; border-radius: 4px; color: #fff; cursor: pointer; text-decoration: none; font-size: 13px; display: inline-block; }
        .btn-p { background: var(--p); } .btn-d { background: #d32f2f; } .btn-w { background: #f39c12; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; } th { background: #f9f9f9; }
        .login-box { width: 320px; margin: auto; text-align: center; padding-top: 50px; }
        .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px; background: #fff; padding: 15px; border-radius: 8px; border: 1px solid #ddd; }
        input, select { padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        
        /* Pop-up Modal Styles */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.9); align-items: center; justify-content: center; flex-direction: column; }
        .modal-content { max-width: 90%; max-height: 85%; border-radius: 5px; box-shadow: 0 0 20px rgba(0,0,0,0.5); }
        .modal-close { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; transition: 0.3s; }
        .modal-close:hover { color: #bbb; }
        .modal-caption { color: #ccc; text-align: center; padding: 10px; font-size: 16px; margin-top: 10px; background: rgba(0,0,0,0.5); border-radius: 5px; }

        @media(max-width:768px){ .sidebar{width:50px;} .brand span, .nav span{display:none;} .brand{justify-content:center; padding:15px 0;} }
    </style>
</head>
<body>

<?php if($action === 'login'): ?>
    <div style="width:100%; display:flex; justify-content:center; align-items:center;">
        <div class="card login-box">
            <img src="https://seedloc.my.id/logo.png" width="60" style="margin-bottom:15px; border-radius:8px;">
            <h3 style="margin:0 0 20px; color:var(--p);">Admin Login</h3>
            <?php if(isset($login_error)) echo "<p style='color:red; font-size:13px;'>$login_error</p>"; ?>
            <form method="post">
                <input type="text" name="username" placeholder="Username" style="width:90%; margin-bottom:10px;" required>
                <input type="password" name="password" placeholder="Password" style="width:90%; margin-bottom:15px;" required>
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button name="login" class="btn btn-p" style="width:100%;">Masuk</button>
            </form>
        </div>
    </div>
<?php else: ?>

<nav class="sidebar">
    <div class="brand"><img src="https://seedloc.my.id/logo.png"> <span>SeedLoc</span></div>
    <ul class="nav">
        <li><a href="?action=dashboard" class="<?= $action=='dashboard'?'active':'' ?>"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
        <li><a href="?action=map" class="<?= $action=='map'?'active':'' ?>"><i class="fas fa-map"></i> <span>Peta</span></a></li>
        <li><a href="?action=list&table=geotags" class="<?= ($action=='list' && $table=='geotags')?'active':'' ?>"><i class="fas fa-leaf"></i> <span>Geotag</span></a></li>
        <li><a href="?action=gallery" class="<?= $action=='gallery'?'active':'' ?>"><i class="fas fa-images"></i> <span>Galeri</span></a></li>
        <li><a href="?action=list&table=projects" class="<?= ($action=='list' && $table=='projects')?'active':'' ?>"><i class="fas fa-folder"></i> <span>Proyek</span></a></li>
        <li style="border-top:1px solid #eee; margin-top:10px;"></li>
        <li><a href="?action=users" class="<?= $action=='users'?'active':'' ?>"><i class="fas fa-users-cog"></i> <span>Admins</span></a></li>
        <li><a href="?action=logs" class="<?= $action=='logs'?'active':'' ?>"><i class="fas fa-history"></i> <span>Logs</span></a></li>
        <li><a href="?action=logout" style="color:#d32f2f;"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
    </ul>
</nav>

<main class="main">
    <div class="header">
        <h2 style="margin:0;"><?= ucfirst($action == 'list' ? "Data $table" : $action) ?></h2>
        
        <?php if($action == 'list' && $table == 'projects'): ?>
            <a href="?action=create&table=projects" class="btn btn-p"><i class="fas fa-plus"></i> Tambah Proyek</a>
        <?php else: ?>
            <div style="font-size:13px; color:#666;"><i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['admin_username']) ?></div>
        <?php endif; ?>
    </div>

    <?php if($action === 'dashboard'): ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:20px;">
            <div class="card" style="border-left:4px solid var(--p);"><h3><?= $stats['geotags'] ?></h3><small>Total Geotags</small></div>
            <div class="card" style="border-left:4px solid #1976d2;"><h3><?= $stats['projects'] ?></h3><small>Total Proyek</small></div>
        </div>
        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px;">
            <div class="card" style="flex:1;"><h4>Kondisi</h4><canvas id="c1"></canvas></div>
            <div class="card" style="flex:2;"><h4>Harian</h4><canvas id="c2"></canvas></div>
        </div>
        <script>
            new Chart(document.getElementById('c1'), {type:'doughnut', data:{labels:<?=json_encode(array_keys($stats['cond']))?>, datasets:[{data:<?=json_encode(array_values($stats['cond']))?>, backgroundColor:['#4caf50','#ffeb3b','#ff9800','#f44336']}]}});
            new Chart(document.getElementById('c2'), {type:'bar', data:{labels:<?=json_encode(array_keys($stats['daily']))?>, datasets:[{label:'Jml', data:<?=json_encode(array_values($stats['daily']))?>, backgroundColor:'#2196f3'}]}});
        </script>

    <?php elseif($action === 'map'): ?>
        <form class="filter-bar">
            <input type="hidden" name="action" value="map">
            <input type="text" name="search" placeholder="Cari..." value="<?=htmlspecialchars($_GET['search']??'')?>">
            <button class="btn btn-p">Cari</button>
        </form>
        <div id="map" style="height:550px; border-radius:8px; border:1px solid #ccc;"></div>
        <script>
            var m=L.map('map').setView([-6.2, 106.8], 5); L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(m);
            var pts=<?=json_encode($map_data)?>; var b=[];
            pts.forEach(p=>{
                var lat=parseFloat(p.latitude), lng=parseFloat(p.longitude);
                if(!isNaN(lat)){
                    var img=getPhoto(p.photoPath);
                    L.marker([lat,lng]).addTo(m).bindPopup(`<b>${p.itemType}</b><br>${p.condition}<br>${p.locationName}${img?'<br><img src="'+img+'" width="150">':''}`);
                    b.push([lat,lng]);
                }
            }); 
            if(b.length) m.fitBounds(b);
            function getPhoto(p){ return p ? (p.startsWith('http')?p:'<?=$photo_base_url?>'+p) : ''; }
        </script>

    <?php elseif(in_array($action, ['list', 'logs', 'users'])): ?>
        <?php if($action=='list' && $table=='geotags'): ?>
        <form class="filter-bar">
            <input type="hidden" name="action" value="list"><input type="hidden" name="table" value="geotags">
            <input type="text" name="search" placeholder="Cari Nama/Lokasi..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="min-width: 200px;">
            <select name="projectId"><option value="all">Project</option><?php foreach($projects_list as $p) echo "<option value='{$p['projectId']}'>{$p['activityName']}</option>"; ?></select>
            <select name="condition"><option value="all">Kondisi</option><option value="Baik">Baik</option><option value="Rusak">Rusak</option></select>
            <button class="btn btn-p"><i class="fas fa-search"></i> Cari</button>
            <?php if(!empty($_GET['search']) || !empty($_GET['projectId']) || !empty($_GET['condition'])): ?>
                <a href="?action=list&table=geotags" class="btn btn-d">Reset</a>
            <?php endif; ?>
        </form>
        <?php endif; ?>

        <?php if($action=='list'): ?>
        <form method="post" style="margin-bottom:15px; background:#e8f5e9; padding:10px; border-radius:4px;">
            <b>Massal:</b> 
            <select name="bulk_action_type" required>
                <option value="">Pilih...</option><option value="export_csv">Export CSV</option><option value="export_kml">Export KML</option><option value="mark_synced">Sync</option><option value="delete_selected">Hapus</option>
            </select>
            <button name="bulk_action" class="btn btn-w">Proses</button>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        
            <div style="overflow-x:auto; margin-top:10px;">
                <table>
                <thead>
                    <tr>
                        <th width="30"><input type="checkbox" onclick="toggle(this)"></th>
                        
                        <?php if($table=='geotags'): ?>
                            <th>Foto</th><th>ID</th><th>Tipe</th><th>Lokasi</th><th>Kondisi</th>
                        <?php else: ?>
                            <th>ID</th><th>Nama</th><th>Status</th>
                        <?php endif; ?>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($list_data as $r): ?>
                    <tr>
                        <td><input type="checkbox" name="selected_ids[]" value="<?=$r[$pk]?>"></td>
                        
                        <?php if($table=='geotags'): $i=get_photo_url($r['photoPath'], $photo_base_url); ?>
                        <td><?php if($i):?><img src="<?=$i?>" width="40" height="40" style="object-fit:cover;"><?php endif; ?></td>
                        <td><?=$r['id']?></td><td><?=$r['itemType']?></td><td><?=$r['locationName']?></td><td><?=$r['condition']?></td>
                        <?php else: ?><td><?=$r['projectId']?></td><td><?=$r['activityName']?></td><td><?=$r['status']?></td><?php endif; ?>
                        <td><a href="?action=edit&table=<?=$table?>&id=<?=$r[$pk]?>" class="btn btn-p" style="padding:4px 8px;">Edit</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                </table>
            </div>
        </form>
        <?php elseif($action=='users'): ?>
            <div class="card">
                <h3>Admin List</h3>
                <table><tr><th>User</th><th>Role</th><th>Action</th></tr>
                <?php foreach($list_data as $u): ?>
                <tr>
                    <td><?=$u['username']?></td><td><?=$u['role']?></td>
                    <td><?php if($_SESSION['admin_role']=='superadmin' && $u['id']!=$_SESSION['admin_id']): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Hapus?');"><input type="hidden" name="id" value="<?=$u['id']?>"><input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>"><button name="delete_admin" class="btn btn-d" style="padding:2px 6px;">Hapus</button></form>
                    <?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </table>
                <?php if($_SESSION['admin_role']=='superadmin'): ?>
                <form method="post" style="margin-top:20px; border-top:1px solid #eee; padding-top:10px;">
                    <b>Tambah Admin:</b> <input type="text" name="username" placeholder="User" required> <input type="password" name="password" placeholder="Pass" required> 
                    <select name="role"><option value="admin">Admin</option><option value="superadmin">Super</option></select>
                    <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                    <button name="create_admin" class="btn btn-p">Buat</button>
                </form>
                <?php endif; ?>
            </div>
        <?php elseif($action=='logs'): ?>
            <div class="card"><table><tr><th>Waktu</th><th>User</th><th>Aksi</th><th>Detail</th></tr><?php foreach($list_data as $l): ?><tr><td><?=$l['created_at']?></td><td><?=$l['admin_username']?></td><td><?=$l['action']?></td><td><?=$l['details']?></td></tr><?php endforeach; ?></table></div>
        <?php endif; ?>
        
        <div style="display:flex; justify-content:center; gap:5px; margin-top:15px;">
            <?php $q=$_GET; if($page>1){$q['page']=$page-1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p">Prev</a>';} if($page<$total_pages){$q['page']=$page+1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p">Next</a>';} ?>
        </div>
        
    <?php elseif($action === 'gallery'): ?>
        <form class="filter-bar">
            <input type="hidden" name="action" value="gallery"><input type="hidden" name="table" value="geotags">
            <input type="text" name="search" placeholder="Cari..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="min-width: 200px;">
            <select name="condition"><option value="all">Kondisi</option><option value="Baik">Baik</option><option value="Rusak">Rusak</option></select>
            <button class="btn btn-p"><i class="fas fa-search"></i> Cari</button>
            <?php if(!empty($_GET['search']) || !empty($_GET['condition'])): ?><a href="?action=gallery" class="btn btn-d">Reset</a><?php endif; ?>
        </form>

        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:10px;">
            <?php if(!empty($list_data)): foreach($list_data as $r): 
                $i = get_photo_url($r['photoPath'] ?? '', $photo_base_url); 
                if(!$i) continue; 
                $caption = "<b>" . htmlspecialchars($r['itemType']) . "</b><br>Kondisi: " . htmlspecialchars($r['condition']) . "<br>Lokasi: " . htmlspecialchars($r['locationName']) . "<br>Waktu: " . $r['timestamp'];
            ?>
            <div class="card" style="padding:5px; margin:0;">
                <img src="<?=$i?>" style="width:100%; height:120px; object-fit:cover; cursor:pointer;" onclick="showModal('<?=$i?>', `<?=$caption?>`)">
                <div style="font-size:11px; padding:5px;"><b><?=$r['itemType']?></b><br><?=$r['condition']?></div>
            </div>
            <?php endforeach; else: echo "<p>Tidak ada foto ditemukan.</p>"; endif; ?>
        </div>
        <div style="display:flex; justify-content:center; gap:5px; margin-top:15px;"><?php $q=$_GET; if($page>1){$q['page']=$page-1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p">Prev</a>';} if($page<$total_pages){$q['page']=$page+1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p">Next</a>';} ?></div>

    <?php elseif($action === 'edit' || $action === 'create'): $d=$action=='edit'?$pdo->query("SELECT * FROM `$table` WHERE `$pk`='{$_GET['id']}'")->fetch():[]; ?>
        <div class="card" style="max-width:600px;">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                <?php if($table=='geotags'): ?>
                    <input type="hidden" name="id" value="<?=$d['id']?>">
                    
                    <label>Jenis Pohon</label>
                    <select name="itemType" style="width:100%; margin-bottom:10px; padding:8px; border:1px solid #ccc; border-radius:4px;">
                        <?php 
                        foreach($tree_types as $type) {
                            $selected = ($d['itemType'] == $type) ? 'selected' : '';
                            echo "<option value='$type' $selected>$type</option>";
                        }
                        ?>
                    </select>

                    <label>Lokasi</label>
                    <select name="locationName" style="width:100%; margin-bottom:10px; padding:8px; border:1px solid #ccc; border-radius:4px;">
                        <?php 
                        foreach($locations_list as $loc) {
                            $selected = ($d['locationName'] == $loc) ? 'selected' : '';
                            echo "<option value='$loc' $selected>$loc</option>";
                        }
                        ?>
                    </select>

                    <label>Kondisi</label>
                    <select name="condition" style="width:100%; margin-bottom:10px; padding:8px; border:1px solid #ccc; border-radius:4px;">
                        <?php foreach(['Hidup','Merana','Mati'] as $c) echo "<option value='$c' ".($d['condition']==$c?'selected':'').">$c</option>"; ?>
                    </select>

                    <label>Detail</label>
                    <input type="text" name="details" value="<?=$d['details']?>" style="width:100%; margin-bottom:10px;">
                    
                    <div style="display:flex; gap:10px;">
                        <input type="text" name="latitude" value="<?=$d['latitude']?>" placeholder="Lat" style="flex:1;">
                        <input type="text" name="longitude" value="<?=$d['longitude']?>" placeholder="Lng" style="flex:1;">
                    </div>
                    
                    <br><label>Sync?</label>
                    <select name="isSynced" style="width:100%; margin-bottom:10px; padding:8px;">
                        <option value="1" <?=$d['isSynced']?'selected':''?>>Ya</option>
                        <option value="0" <?=!$d['isSynced']?'selected':''?>>Tidak</option>
                    </select>
                <?php else: ?>
                    <input type="text" name="projectId" value="<?=$d['projectId']??''?>" placeholder="ID" style="width:100%; margin-bottom:10px;">
                    <input type="text" name="activityName" value="<?=$d['activityName']??''?>" placeholder="Nama" style="width:100%; margin-bottom:10px;">
                    <input type="text" name="locationName" value="<?=$d['locationName']??''?>" placeholder="Lokasi" style="width:100%; margin-bottom:10px;">
                    <input type="text" name="officers" value="<?=$d['officers']??''?>" placeholder="Petugas" style="width:100%; margin-bottom:10px;">
                    <select name="status" style="width:100%;"><option value="Active">Active</option><option value="Completed">Completed</option></select>
                <?php endif; ?>
                <div style="margin-top:20px;">
                    <button name="<?=$action=='edit'?'update':'create'?>" class="btn btn-p">Simpan</button>
                    <?php if($action=='edit'): ?><button name="delete" class="btn btn-d" onclick="return confirm('Hapus?')">Hapus</button><?php endif; ?>
                </div>
            </form>
        </div>
    <?php endif; ?>
</main>

<div id="imgModal" class="modal" onclick="this.style.display='none'">
    <span class="modal-close">&times;</span>
    <img class="modal-content" id="modalImg">
    <div id="modalCaption" class="modal-caption"></div>
</div>

<script>
    function toggle(source) {
        var checkboxes = document.querySelectorAll('input[name="selected_ids[]"]');
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = source.checked;
        }
    }

    function showModal(src, caption) {
        const m = document.getElementById('imgModal');
        const i = document.getElementById('modalImg');
        const c = document.getElementById('modalCaption');
        m.style.display = "flex";
        i.src = src;
        c.innerHTML = caption;
    }
</script>

<?php endif; ?>
</body>
</html>