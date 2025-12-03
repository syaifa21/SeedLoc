<?php
// admin.php - Dashboard Admin SeedLoc (Full Recode: Enhanced CRUD Project & UI + Admin CRUD)

session_start();

// --- 1. CONFIG & DATABASE CONNECTION ---
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
    die("<h3>Database Error:</h3> " . htmlspecialchars($e->getMessage()));
}

// --- 2. LOAD METADATA (Untuk Dropdown) ---
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
function get_photo_url($path, $base) {
    if (empty($path)) return '';
    return (strpos($path, 'http') === 0) ? $path : $base . $path;
}

// Fungsi Export Data (ZIP, CSV, KML)
function export_data($pdo, $ids, $type, $base_url, $upload_dir) {
    if(empty($ids)) return;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    
    // Hanya export dari Geotags
    $stmt = $pdo->prepare("SELECT * FROM geotags WHERE id IN ($ph) ORDER BY id DESC");
    $stmt->execute($ids);

    if ($type === 'download_zip') {
        if (!class_exists('ZipArchive')) { $_SESSION['swal_error'] = "ZipArchive extension missing."; return; }
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
        } else { $_SESSION['swal_warning'] = "Tidak ada foto yang valid untuk diunduh."; unlink($tempZip); return; }

    } elseif ($type === 'csv') {
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="export_geotags_'.date('YmdHis').'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'ProjectID', 'Lat', 'Lng', 'Location', 'Time', 'Type', 'Condition', 'Details', 'PhotoURL']);
        while($r = $stmt->fetch()) {
            $r['photoPath'] = get_photo_url($r['photoPath'], $base_url);
            fputcsv($out, $r);
        }
        fclose($out); exit;

    } elseif ($type === 'kml') {
        header('Content-Type: application/vnd.google-earth.kml+xml'); header('Content-Disposition: attachment; filename="export_'.date('YmdHis').'.kml"');
        echo '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>';
        while($r = $stmt->fetch()) {
            $img = get_photo_url($r['photoPath'], $base_url);
            $desc = "<b>Kondisi:</b> {$r['condition']}<br><b>Lokasi:</b> {$r['locationName']}<br><b>Waktu:</b> {$r['timestamp']}";
            if($img) $desc .= "<br><img src='$img' width='200'>";
            echo "<Placemark><name>".htmlspecialchars($r['itemType'])."</name><description><![CDATA[$desc]]></description><Point><coordinates>{$r['longitude']},{$r['latitude']}</coordinates></Point></Placemark>";
        }
        echo '</Document></kml>'; exit;
    }
}

// --- 4. AUTHENTICATION & SECURITY ---
if (isset($_SESSION['auth']) && $_SESSION['auth'] === true) {
    if (isset($_SESSION['auth_time']) && (time() - $_SESSION['auth_time'] > 3600)) {
        session_unset(); session_destroy(); header('Location: ?action=login&timeout=1'); exit;
    }
    $_SESSION['auth_time'] = time();
}
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function require_auth() { if (!isset($_SESSION['auth']) || !$_SESSION['auth']) { header('Location: ?action=login'); exit; } }

// --- 5. CONTROLLER (LOGIC) ---
$action = $_GET['action'] ?? 'dashboard';
$table = $_GET['table'] ?? 'geotags'; 
$pk = ($table === 'projects') ? 'projectId' : 'id'; 

// Login Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
    $stmt->execute([$_POST['username'] ?? '']);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        $_SESSION['auth'] = true; $_SESSION['auth_time'] = time();
        $_SESSION['admin_id'] = $user['id']; $_SESSION['admin_username'] = $user['username'];
        $_SESSION['swal_success'] = "Login Berhasil"; header('Location: admin.php'); exit;
    } else { $_SESSION['swal_error'] = 'Username atau Password salah'; }
}

if ($action === 'logout') { session_destroy(); header('Location: ?action=login'); exit; }
if ($action !== 'login') require_auth();

// POST Handler (CRUD & Bulk Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) die('CSRF Validation Failed');
    
    // --- CREATE & UPDATE ---
    if (isset($_POST['update']) || isset($_POST['create'])) {
        try {
            $id = $_POST[$pk] ?? null; 
            
            if ($table == 'projects') {
                // CRUD PROJECT
                if (isset($_POST['create'])) {
                    $check = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE projectId = ?");
                    $check->execute([$_POST['projectId']]);
                    if($check->fetchColumn() > 0) throw new Exception("Project ID {$_POST['projectId']} sudah ada!");

                    $sql = "INSERT INTO projects (projectId, activityName, locationName, officers, status) VALUES (?,?,?,?,?)";
                    $params = [$_POST['projectId'], $_POST['activityName'], $_POST['locationName'], $_POST['officers'], $_POST['status']];
                } else {
                    $sql = "UPDATE projects SET activityName=?, locationName=?, officers=?, status=? WHERE projectId=?";
                    $params = [$_POST['activityName'], $_POST['locationName'], $_POST['officers'], $_POST['status'], $id];
                }
                $pdo->prepare($sql)->execute($params);

            } elseif ($table == 'admin_users') {
                // --- CRUD ADMIN USERS (BARU) ---
                $username = trim($_POST['username']);
                $role = $_POST['role'];

                if (isset($_POST['create'])) {
                    // Create Admin Baru
                    if(empty($_POST['password'])) throw new Exception("Password wajib diisi untuk user baru!");
                    
                    // Cek username kembar
                    $check = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");
                    $check->execute([$username]);
                    if($check->fetchColumn() > 0) throw new Exception("Username '$username' sudah digunakan!");

                    $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, ?)");
                    $stmt->execute([$username, $hash, $role]);

                } else {
                    // Update Admin
                    if (!empty($_POST['password'])) {
                        // Jika password diisi, update password
                        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE admin_users SET username=?, role=?, password_hash=? WHERE id=?");
                        $stmt->execute([$username, $role, $hash, $id]);
                    } else {
                        // Jika kosong, hanya update data lain
                        $stmt = $pdo->prepare("UPDATE admin_users SET username=?, role=? WHERE id=?");
                        $stmt->execute([$username, $role, $id]);
                    }
                }
                
                $_SESSION['swal_success'] = "Data Admin berhasil disimpan";
                header("Location: ?action=users"); exit; 
                // Redirect khusus kembali ke halaman users

            } elseif ($table == 'geotags') {
                // CRUD GEOTAGS
                $sql = isset($_POST['create']) 
                    ? "INSERT INTO geotags (itemType, `condition`, details, locationName, latitude, longitude, isSynced, projectId) VALUES (?,?,?,?,?,?,?,?)"
                    : "UPDATE geotags SET itemType=?, `condition`=?, details=?, locationName=?, latitude=?, longitude=?, isSynced=? WHERE id=?";
                $params = [$_POST['itemType'], $_POST['condition'], $_POST['details'], $_POST['locationName'], $_POST['latitude'], $_POST['longitude'], $_POST['isSynced']];
                if(isset($_POST['create'])) $params[] = $_POST['projectId']??0; else $params[] = $id;
                $pdo->prepare($sql)->execute($params);
            }

            $_SESSION['swal_success'] = "Data berhasil disimpan"; 
            if($table != 'admin_users') { header("Location: ?action=list&table=$table"); exit; }

        } catch(Exception $e) { 
            $_SESSION['swal_error'] = $e->getMessage(); 
        }
    }

    // --- DELETE SINGLE ---
    if (isset($_POST['delete'])) {
        try {
            $id_to_delete = $_POST['delete_id'];

            // Cek jika menghapus admin
            if ($table == 'admin_users') {
                if ($id_to_delete == $_SESSION['admin_id']) {
                    throw new Exception("Anda tidak dapat menghapus akun Anda sendiri!");
                }
            }

            if($table=='geotags'){
                $r=$pdo->query("SELECT photoPath FROM geotags WHERE id=$id_to_delete")->fetch();
                if ($r) {
                    $p=$upload_dir.basename($r['photoPath']); 
                    if(file_exists($p)) @unlink($p);
                }
            }
            
            $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = ?")->execute([$id_to_delete]);
            $_SESSION['swal_success'] = "Data berhasil dihapus"; 
            
            if ($table == 'admin_users') { header("Location: ?action=users"); exit; }
            else { header("Location: ?action=list&table=$table"); exit; }

        } catch(Exception $e) {
            $_SESSION['swal_error'] = $e->getMessage();
        }
    }

    // --- BULK ACTIONS ---
    if (isset($_POST['bulk_action'])) {
        $ids = $_POST['selected_ids'] ?? [];
        $type = $_POST['bulk_action_type'] ?? '';
        if (!empty($ids)) {
            if ($type == 'download_zip' && $table == 'geotags') export_data($pdo, $ids, 'download_zip', $photo_base_url, $upload_dir);
            elseif ($type == 'export_csv' && $table == 'geotags') export_data($pdo, $ids, 'csv', $photo_base_url, $upload_dir);
            elseif ($type == 'export_kml' && $table == 'geotags') export_data($pdo, $ids, 'kml', $photo_base_url, $upload_dir);
            elseif ($type == 'delete_selected') {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                if($table=='geotags'){
                    $f=$pdo->prepare("SELECT photoPath FROM geotags WHERE id IN ($ph)"); $f->execute($ids);
                    while($r=$f->fetch()){ $p=$upload_dir.basename($r['photoPath']); if(file_exists($p)) @unlink($p); }
                }
                $pdo->prepare("DELETE FROM `$table` WHERE `$pk` IN ($ph)")->execute($ids);
                $_SESSION['swal_success'] = count($ids) . " data dihapus"; header("Location: ?action=list&table=$table"); exit;
            } elseif ($type == 'mark_synced' && $table == 'geotags') {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("UPDATE geotags SET isSynced = 1 WHERE id IN ($ph)")->execute($ids);
                $_SESSION['swal_success'] = "Sync status diperbarui"; header("Location: ?action=list&table=$table"); exit;
            }
        } else {
            $_SESSION['swal_warning'] = "Tidak ada item yang dipilih";
        }
    }
}

// --- 6. DATA PREPARATION FOR VIEW ---
function buildWhere($table, $pdo) {
    $where = []; $p = [];
    if (!empty($_GET['search'])) { 
        if ($table == 'geotags') { 
            $where[] = "(itemType LIKE ? OR locationName LIKE ?)"; $p[] = "%{$_GET['search']}%"; $p[] = "%{$_GET['search']}%"; 
        } elseif ($table == 'projects') {
            $where[] = "(activityName LIKE ? OR locationName LIKE ? OR officers LIKE ?)"; 
            $p[] = "%{$_GET['search']}%"; $p[] = "%{$_GET['search']}%"; $p[] = "%{$_GET['search']}%";
        }
    }
    // Filter Geotag Spesifik
    if ($table == 'geotags') {
        if (!empty($_GET['condition']) && $_GET['condition'] != 'all') { $where[] = "`condition`=?"; $p[] = $_GET['condition']; }
        if (!empty($_GET['projectId']) && $_GET['projectId'] != 'all') { $where[] = "projectId=?"; $p[] = $_GET['projectId']; }
        if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) { $where[] = "DATE(timestamp) BETWEEN ? AND ?"; $p[] = $_GET['start_date']; $p[] = $_GET['end_date']; }
    }
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

// Fetch List Data
if (in_array($action, ['list', 'gallery', 'map', 'users'])) {
    if ($action == 'users') { 
        $list_data = $pdo->query("SELECT * FROM admin_users ORDER BY id DESC")->fetchAll(); 
    } elseif ($action == 'map') {
        list($where, $p) = buildWhere('geotags', $pdo);
        $sql = "SELECT id, latitude, longitude, itemType, `condition`, photoPath, locationName FROM geotags " . ($where ? "WHERE ".implode(' AND ', $where) : "");
        $stmt = $pdo->prepare($sql); $stmt->execute($p); $map_data = $stmt->fetchAll();
    } else {
        // List View (Projects / Geotags)
        list($where, $p) = buildWhere($table, $pdo);
        $w_sql = $where ? "WHERE ".implode(' AND ', $where) : "";
        
        $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` $w_sql"); $total_stmt->execute($p);
        $total_rows = $total_stmt->fetchColumn(); $total_pages = ceil($total_rows / $per_page);
        $offset = ($page - 1) * $per_page;
        
        $stmt = $pdo->prepare("SELECT * FROM `$table` $w_sql ORDER BY `$pk` DESC LIMIT $per_page OFFSET $offset");
        $stmt->execute($p); $list_data = $stmt->fetchAll();
        
        if ($table == 'geotags') $projects_list = $pdo->query("SELECT projectId, activityName FROM projects")->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SeedLoc Admin Panel</title>
    <link rel="icon" href="https://seedloc.my.id/logo.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body{font-family:'Segoe UI', sans-serif;background:#f4f6f8;margin:0;display:flex;height:100vh;overflow:hidden;color:#333}
        .sidebar{width:250px;background:#fff;border-right:1px solid #e0e0e0;display:flex;flex-direction:column;flex-shrink:0}
        .brand{padding:20px;border-bottom:1px solid #f0f0f0;font-weight:700;color:#2E7D32;display:flex;align-items:center;gap:10px;font-size:18px}
        .nav{list-style:none;padding:10px 0;margin:0;flex:1;overflow-y:auto}
        .nav a{display:flex;align-items:center;gap:10px;padding:12px 20px;color:#555;text-decoration:none;border-left:4px solid transparent;transition:all 0.2s}
        .nav a:hover,.nav a.active{background:#e8f5e9;color:#2E7D32;border-left-color:#2E7D32}
        .main{flex:1;padding:25px;overflow-y:auto;display:flex;flex-direction:column}
        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:25px}
        .card{background:#fff;padding:20px;border-radius:10px;box-shadow:0 2px 5px rgba(0,0,0,0.05);margin-bottom:20px;border:1px solid #f0f0f0}
        .btn{padding:8px 14px;border:none;border-radius:6px;color:#fff;cursor:pointer;text-decoration:none;font-size:14px;display:inline-flex;align-items:center;gap:5px;font-weight:600;transition:opacity 0.2s}
        .btn:hover{opacity:0.9}
        .btn-p{background:#2E7D32} .btn-d{background:#d32f2f} .btn-w{background:#f39c12} .btn-b{background:#2196f3}
        table{width:100%;border-collapse:collapse;font-size:14px} 
        th{background:#f8f9fa;font-weight:600;color:#666;text-transform:uppercase;font-size:12px;letter-spacing:0.5px}
        th,td{padding:12px 15px;border-bottom:1px solid #eee;text-align:left;vertical-align:middle}
        tr:hover{background-color:#fafafa}
        .filter-bar{display:flex;gap:10px;flex-wrap:wrap;background:#fff;padding:15px;border-radius:10px;border:1px solid #e0e0e0;margin-bottom:20px;align-items:center}
        input,select{padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;outline:none}
        input:focus,select:focus{border-color:#2E7D32}
        .modal{display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.9);justify-content:center;align-items:center;flex-direction:column}
        .modal-content{max-width:90%;max-height:85%;border-radius:5px;box-shadow:0 0 20px rgba(0,0,0,0.5)}
        .status-badge{padding:4px 8px;border-radius:4px;font-size:11px;font-weight:bold;text-transform:uppercase}
        .status-Active{background:#e8f5e9;color:#2E7D32} .status-Completed{background:#e3f2fd;color:#1976d2}
        @media(max-width:768px){.sidebar{width:60px}.brand span,.nav span{display:none}.brand{justify-content:center;padding:15px}.nav a{justify-content:center;padding:15px}}
    </style>
</head>
<body>

<?php 
// SweetAlert Notifikasi
if(isset($_SESSION['swal_success'])){ echo "<script>Swal.fire({icon:'success',title:'Berhasil',text:'{$_SESSION['swal_success']}',timer:1500,showConfirmButton:false});</script>"; unset($_SESSION['swal_success']); }
if(isset($_SESSION['swal_error'])){ echo "<script>Swal.fire({icon:'error',title:'Gagal',text:'{$_SESSION['swal_error']}'});</script>"; unset($_SESSION['swal_error']); }
if(isset($_SESSION['swal_warning'])){ echo "<script>Swal.fire({icon:'warning',title:'Perhatian',text:'{$_SESSION['swal_warning']}'});</script>"; unset($_SESSION['swal_warning']); }
?>

<?php if($action === 'login'): ?>
    <div style="width:100%;display:flex;justify-content:center;align-items:center;background:#eef2f5;">
        <div class="card" style="width:320px;text-align:center;padding:40px 30px;">
            <img src="https://seedloc.my.id/logo.png" width="80" style="margin-bottom:20px;border-radius:15px;box-shadow:0 4px 10px rgba(0,0,0,0.1)">
            <h2 style="color:#2E7D32;margin-bottom:5px;">Admin Login</h2>
            <p style="color:#888;margin-bottom:25px;font-size:14px;">Masuk untuk mengelola data</p>
            <form method="post">
                <div style="margin-bottom:15px;text-align:left;">
                    <input type="text" name="username" placeholder="Username" style="width:100%;box-sizing:border-box;" required>
                </div>
                <div style="margin-bottom:20px;text-align:left;">
                    <input type="password" name="password" placeholder="Password" style="width:100%;box-sizing:border-box;" required>
                </div>
                <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                <button name="login" class="btn btn-p" style="width:100%;justify-content:center;padding:12px;">LOGIN</button>
            </form>
        </div>
    </div>
<?php else: ?>

<nav class="sidebar">
    <div class="brand"><img src="https://seedloc.my.id/logo.png" width="32"> <span>SeedLoc</span></div>
    <ul class="nav">
        <li><a href="?action=dashboard" class="<?=$action=='dashboard'?'active':''?>"><i class="fas fa-chart-pie"></i> <span>Dashboard</span></a></li>
        <li><a href="?action=map" class="<?=$action=='map'?'active':''?>"><i class="fas fa-map-marked-alt"></i> <span>Peta Sebaran</span></a></li>
        <li><a href="?action=list&table=projects" class="<?=($action=='list'&&$table=='projects')?'active':''?>"><i class="fas fa-folder-open"></i> <span>Data Projects</span></a></li>
        <li><a href="?action=list&table=geotags" class="<?=($action=='list'&&$table=='geotags')?'active':''?>"><i class="fas fa-leaf"></i> <span>Data Geotags</span></a></li>
        <li><a href="?action=gallery" class="<?=$action=='gallery'?'active':''?>"><i class="fas fa-images"></i> <span>Galeri Foto</span></a></li>
        <li style="border-top:1px solid #f0f0f0;margin:10px 0;"></li>
        <li><a href="?action=users" class="<?=$action=='users'?'active':''?>"><i class="fas fa-user-shield"></i> <span>Admin Users</span></a></li>
        <li><a href="?action=logout" style="color:#d32f2f;"><i class="fas fa-sign-out-alt"></i> <span>Keluar</span></a></li>
    </ul>
</nav>

<main class="main">
    
    <?php if($action === 'dashboard'): ?>
        <div class="header"><h2>Overview Dashboard</h2></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-bottom:25px;">
            <div class="card" style="border-left:5px solid #2E7D32;display:flex;align-items:center;gap:15px;">
                <div style="background:#e8f5e9;padding:15px;border-radius:50%;color:#2E7D32;"><i class="fas fa-leaf fa-2x"></i></div>
                <div><h2 style="margin:0;font-size:28px;"><?=$stats['geotags']?></h2><small style="color:#888;">Total Geotags</small></div>
            </div>
            <div class="card" style="border-left:5px solid #1976d2;display:flex;align-items:center;gap:15px;">
                <div style="background:#e3f2fd;padding:15px;border-radius:50%;color:#1976d2;"><i class="fas fa-folder fa-2x"></i></div>
                <div><h2 style="margin:0;font-size:28px;"><?=$stats['projects']?></h2><small style="color:#888;">Active Projects</small></div>
            </div>
        </div>
        <div style="display:flex;gap:20px;flex-wrap:wrap;">
            <div class="card" style="flex:1;min-width:300px;"><h4>Kondisi Tanaman</h4><canvas id="c1"></canvas></div>
            <div class="card" style="flex:2;min-width:400px;"><h4>Aktivitas Harian</h4><canvas id="c2"></canvas></div>
        </div>
        <script>
            new Chart(document.getElementById('c1'),{type:'doughnut',data:{labels:<?=json_encode(array_keys($stats['cond']))?>,datasets:[{data:<?=json_encode(array_values($stats['cond']))?>,backgroundColor:['#4caf50','#ffeb3b','#f44336','#ff9800']}]}});
            new Chart(document.getElementById('c2'),{type:'line',data:{labels:<?=json_encode(array_keys($stats['daily']))?>,datasets:[{label:'Geotag Masuk',data:<?=json_encode(array_values($stats['daily']))?>,borderColor:'#2E7D32',tension:0.3,fill:true,backgroundColor:'rgba(46,125,50,0.1)'}]}});
        </script>

    <?php elseif($action === 'map'): ?>
        <div class="header"><h2>Peta Sebaran Real-time</h2></div>
        <form class="filter-bar">
            <input type="hidden" name="action" value="map">
            <select name="condition"><option value="all">Semua Kondisi</option><option value="Baik">Baik</option><option value="Rusak">Rusak</option><option value="Mati">Mati</option></select>
            <div style="display:flex;align-items:center;gap:5px;"><input type="date" name="start_date"> <span>s/d</span> <input type="date" name="end_date"></div>
            <button class="btn btn-p">Filter</button> <a href="?action=map" class="btn btn-d">Reset</a>
        </form>
        <div class="card" style="padding:0;overflow:hidden;">
            <div id="map" style="height:650px;"></div>
        </div>
        <script>
            var m = L.map('map').setView([-6.2, 106.8], 5);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(m);
            var pts = <?=json_encode($map_data)?>; var b=[];
            pts.forEach(p=>{
                var lat=parseFloat(p.latitude),lng=parseFloat(p.longitude);
                if(!isNaN(lat)){
                    var c='#f39c12'; 
                    if(p.condition=='Baik'||p.condition=='Hidup')c='#2E7D32'; 
                    if(p.condition=='Mati'||p.condition=='Rusak'||p.condition=='Buruk')c='#d32f2f';
                    var img=p.photoPath?(p.photoPath.startsWith('http')?p.photoPath:'<?=$photo_base_url?>'+p.photoPath):'';
                    L.circleMarker([lat,lng],{radius:7,fillColor:c,color:"#fff",weight:2,opacity:1,fillOpacity:0.8}).addTo(m)
                     .bindPopup(`<b>${p.itemType}</b><br><span style='color:${c}'>${p.condition}</span><br>${p.locationName}${img?'<br><img src="'+img+'" width="100%" style="margin-top:5px;border-radius:4px;">':''}`);
                    b.push([lat,lng]);
                }
            });
            if(b.length) m.fitBounds(b);
        </script>

    <?php elseif(in_array($action, ['list', 'users'])): ?>
        <div class="header">
            <h2 style="margin:0;">Data <?=ucfirst($table)?></h2>
            
            <a href="?action=create&table=<?=$action=='users'?'admin_users':$table?>" class="btn btn-p">
                <i class="fas fa-plus-circle"></i> Tambah Data
            </a>
        </div>

        <?php if($action=='list'): ?>
        <form class="filter-bar">
            <input type="hidden" name="action" value="list"><input type="hidden" name="table" value="<?=$table?>">
            <input type="text" name="search" placeholder="Cari data..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="flex:1;">
            
            <?php if($table=='geotags'): ?>
                <select name="projectId"><option value="all">Semua Project</option><?php foreach($projects_list as $p) echo "<option value='{$p['projectId']}'>{$p['activityName']}</option>"; ?></select>
                <div style="display:flex;align-items:center;gap:5px;"><input type="date" name="start_date"> - <input type="date" name="end_date"></div>
            <?php endif; ?>
            
            <button class="btn btn-b"><i class="fas fa-search"></i> Cari</button> <a href="?action=list&table=<?=$table?>" class="btn btn-d"><i class="fas fa-sync"></i></a>
        </form>

        <form method="post" id="bulkForm">
            <?php if($table == 'geotags'): ?>
            <div style="background:#e8f5e9;padding:12px;border-radius:8px;margin-bottom:15px;display:flex;gap:10px;align-items:center;border:1px solid #c8e6c9;">
                <i class="fas fa-check-square" style="color:#2E7D32;"></i> <b>Aksi Terpilih:</b> 
                <select name="bulk_action_type" required style="border-color:#2E7D32;">
                    <option value="">-- Pilih Aksi --</option>
                    <option value="download_zip">Download Foto (ZIP)</option>
                    <option value="export_csv">Export Data (Excel/CSV)</option>
                    <option value="export_kml">Export Peta (KML)</option>
                    <option value="delete_selected">Hapus Data</option>
                </select>
                <button type="button" onclick="confirmBulk()" class="btn btn-w">Proses</button> 
                <button name="bulk_action" id="realBulkBtn" style="display:none;"></button>
                <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
            </div>
            <?php endif; ?>

            <div class="card" style="padding:0;overflow:hidden;">
                <div style="overflow-x:auto;">
                    <table>
                    <thead>
                        <tr>
                            <?php if($table=='geotags'): ?><th width="30"><input type="checkbox" onclick="toggle(this)"></th><?php endif; ?>
                            
                            <?php if($table=='geotags'): ?>
                                <th>Foto</th><th>ID</th><th>Jenis</th><th>Lokasi</th><th>Tanggal</th><th>Kondisi</th>
                            <?php else: // PROJECTS ?>
                                <th>ID</th><th>Nama Kegiatan</th><th>Lokasi Project</th><th>Petugas</th><th>Status</th>
                            <?php endif; ?>
                            
                            <th style="text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($list_data)): ?>
                            <tr><td colspan="10" align="center" style="padding:30px;color:#999;">Tidak ada data ditemukan.</td></tr>
                        <?php else: foreach($list_data as $r): ?>
                        <tr>
                            <?php if($table=='geotags'): ?>
                                <td><input type="checkbox" name="selected_ids[]" value="<?=$r[$pk]?>"></td>
                                <?php $i=get_photo_url($r['photoPath'], $photo_base_url); ?>
                                <td><?php if($i):?><img src="<?=$i?>" width="45" height="45" style="object-fit:cover;border-radius:4px;cursor:pointer;border:1px solid #ddd;" onclick="showModal('<?=$i?>','<?=$r['itemType']?>')"><?php else: ?>-<?php endif; ?></td>
                                <td>#<?=$r['id']?></td>
                                <td><b><?=$r['itemType']?></b></td>
                                <td><?=$r['locationName']?></td>
                                <td><?=substr($r['timestamp'],0,10)?></td>
                                <td><span class="status-badge" style="<?=$r['condition']=='Baik'?'color:#2E7D32;background:#e8f5e9;':'color:#c62828;background:#ffebee;'?>"><?=$r['condition']?></span></td>
                            <?php else: // PROJECTS ?>
                                <td><b>#<?=$r['projectId']?></b></td>
                                <td><?=$r['activityName']?></td>
                                <td><i class="fas fa-map-marker-alt" style="color:#d32f2f;margin-right:5px;"></i> <?=$r['locationName']?></td>
                                <td><small style="color:#666;"><?=$r['officers']?></small></td>
                                <td><span class="status-badge status-<?=$r['status']?>"><?=$r['status']?></span></td>
                            <?php endif; ?>
                            
                            <td style="text-align:right;">
                                <a href="?action=edit&table=<?=$table?>&id=<?=$r[$pk]?>" class="btn btn-b" title="Edit"><i class="fas fa-edit"></i></a>
                                <button type="button" onclick="confirmDel('<?=$r[$pk]?>')" class="btn btn-d" title="Hapus"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    </table>
                </div>
            </div>
        </form>
        
        <form method="post" id="delForm">
            <input type="hidden" name="delete" value="1">
            <input type="hidden" name="delete_id" id="delId">
            <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
        </form>

        <?php elseif($action=='users'): $table='admin_users'; ?>
            <div class="card" style="padding:0;overflow:hidden;">
                <table>
                    <thead>
                        <tr><th>ID</th><th>Username</th><th>Role</th><th style="text-align:right;">Aksi</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach($list_data as $u): ?>
                    <tr>
                        <td>#<?=$u['id']?></td>
                        <td><b><?=$u['username']?></b></td>
                        <td><span class="status-badge" style="background:#eee;color:#333;"><?=$u['role']?></span></td>
                        <td style="text-align:right;">
                            <a href="?action=edit&table=admin_users&id=<?=$u['id']?>" class="btn btn-b"><i class="fas fa-edit"></i></a>
                            <?php if($u['id'] != $_SESSION['admin_id']): ?>
                                <button onclick="confirmDel('<?=$u['id']?>')" class="btn btn-d"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <form method="post" id="delForm">
                <input type="hidden" name="delete" value="1">
                <input type="hidden" name="delete_id" id="delId">
                <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
            </form>
        <?php endif; ?>
        
        <div style="display:flex;justify-content:center;gap:8px;margin-top:20px;">
            <?php 
            $q = $_GET; 
            if($page > 1) { $q['page'] = $page - 1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p" style="background:#fff;color:#333;border:1px solid #ddd;">&laquo; Prev</a>'; }
            if($page < $total_pages) { $q['page'] = $page + 1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p" style="background:#fff;color:#333;border:1px solid #ddd;">Next &raquo;</a>'; } 
            ?>
        </div>

    <?php elseif($action === 'gallery'): ?>
        <div class="header"><h2>Galeri Lapangan</h2></div>
        <form class="filter-bar"><input type="hidden" name="action" value="gallery"><input type="hidden" name="table" value="geotags"><input type="text" name="search" placeholder="Cari foto..." style="flex:1;"><button class="btn btn-p">Cari</button></form>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:15px;">
            <?php if(!empty($list_data)): foreach($list_data as $r): $i=get_photo_url($r['photoPath']??'', $photo_base_url); if(!$i) continue; ?>
            <div class="card" style="padding:0;overflow:hidden;cursor:pointer;transition:transform 0.2s;" onclick="showModal('<?=$i?>','<?=$r['itemType']?>')" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                <img src="<?=$i?>" style="width:100%;height:140px;object-fit:cover;">
                <div style="padding:10px;">
                    <b style="font-size:13px;display:block;margin-bottom:3px;"><?=$r['itemType']?></b>
                    <small style="color:#888;"><i class="fas fa-map-marker-alt"></i> <?=$r['locationName']?></small>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <div style="display:flex;justify-content:center;gap:5px;margin-top:20px;"><?php $q=$_GET; if($page>1){$q['page']=$page-1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p">Prev</a>';} if($page<$total_pages){$q['page']=$page+1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p">Next</a>';} ?></div>

    <?php elseif($action === 'edit' || $action === 'create'): 
        $is_edit = ($action=='edit');
        $d = $is_edit ? $pdo->query("SELECT * FROM `$table` WHERE `$pk`='{$_GET['id']}'")->fetch() : []; 
    ?>
        <div class="header"><h2><?=$is_edit ? 'Edit Data' : 'Tambah Data Baru'?> (<?=ucfirst(str_replace('_',' ',$table))?>)</h2></div>
        <div class="card" style="max-width:700px;margin:0 auto;">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                
                <?php if($table=='geotags'): ?>
                    <input type="hidden" name="id" value="<?=$d['id']?>">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
                        <div><label>Project ID</label><input type="number" name="projectId" value="<?=$d['projectId']??''?>" style="width:100%;"></div>
                        <div><label>Tipe Item</label><select name="itemType" style="width:100%;"><?php foreach($tree_types as $t) echo "<option value='$t' ".($d['itemType']==$t?'selected':'').">$t</option>"; ?></select></div>
                    </div>
                    <div style="margin-bottom:15px;"><label>Lokasi</label><select name="locationName" style="width:100%;"><?php foreach($locations_list as $l) echo "<option value='$l' ".($d['locationName']==$l?'selected':'').">$l</option>"; ?></select></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
                        <div><label>Lat</label><input type="text" name="latitude" value="<?=$d['latitude']??''?>" style="width:100%;"></div>
                        <div><label>Lng</label><input type="text" name="longitude" value="<?=$d['longitude']??''?>" style="width:100%;"></div>
                    </div>
                    <div style="margin-bottom:15px;"><label>Kondisi</label><select name="condition" style="width:100%;"><option value="Baik" <?=($d['condition']=='Baik'?'selected':'')?>>Baik</option><option value="Rusak" <?=($d['condition']=='Rusak'?'selected':'')?>>Rusak</option></select></div>
                    <div style="margin-bottom:15px;"><label>Detail</label><input type="text" name="details" value="<?=$d['details']??''?>" style="width:100%;"></div>
                    <div style="margin-bottom:15px;"><label>Sync Status</label><select name="isSynced" style="width:100%;"><option value="1">Sudah</option><option value="0">Belum</option></select></div>

                <?php elseif($table == 'admin_users'): ?>
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;display:block;margin-bottom:5px;">Username</label>
                        <input type="text" name="username" value="<?=$d['username']??''?>" style="width:100%;" required>
                    </div>
                    
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;display:block;margin-bottom:5px;">Role / Jabatan</label>
                        <select name="role" style="width:100%;">
                            <option value="Admin" <?=($d['role']??'')=='Admin'?'selected':''?>>Admin (Full Access)</option>
                            <option value="Viewer" <?=($d['role']??'')=='Viewer'?'selected':''?>>Viewer (Read Only)</option>
                        </select>
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;display:block;margin-bottom:5px;">Password</label>
                        <input type="password" name="password" style="width:100%;" placeholder="<?=$is_edit ? 'Kosongkan jika tidak ingin mengubah password' : 'Masukkan password baru'?>" <?=$is_edit ? '' : 'required'?>>
                        <?php if($is_edit): ?><small style="color:#888;">Biarkan kosong jika tetap menggunakan password lama.</small><?php endif; ?>
                    </div>

                <?php else: ?>
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;display:block;margin-bottom:5px;">Project ID (Angka Unik)</label>
                        <input type="number" name="projectId" value="<?=$d['projectId']??''?>" style="width:100%;background:<?=$is_edit?'#eee':'#fff'?>" <?=$is_edit?'readonly':''?> required placeholder="Contoh: 101">
                        <?php if($is_edit): ?><small style="color:#888;">ID tidak dapat diubah setelah dibuat.</small><?php endif; ?>
                    </div>
                    
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;display:block;margin-bottom:5px;">Nama Kegiatan</label>
                        <input type="text" name="activityName" value="<?=$d['activityName']??''?>" style="width:100%;" required placeholder="Contoh: Patroli Hutan Lindung">
                    </div>
                    
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;display:block;margin-bottom:5px;">Lokasi Kegiatan</label>
                        <input type="text" name="locationName" value="<?=$d['locationName']??''?>" style="width:100%;" required placeholder="Contoh: Blok A">
                    </div>
                    
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;display:block;margin-bottom:5px;">Petugas (Pisahkan dengan koma)</label>
                        <input type="text" name="officers" value="<?=$d['officers']??''?>" style="width:100%;" required placeholder="Contoh: Budi, Santoso">
                    </div>
                    
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;display:block;margin-bottom:5px;">Status</label>
                        <select name="status" style="width:100%;">
                            <option value="Active" <?=($d['status']??'')=='Active'?'selected':''?>>Active (Berjalan)</option>
                            <option value="Completed" <?=($d['status']??'')=='Completed'?'selected':''?>>Completed (Selesai)</option>
                        </select>
                    </div>
                <?php endif; ?>

                <div style="margin-top:25px;display:flex;justify-content:flex-end;gap:10px;">
                    <a href="?action=list&table=<?=$table?>" class="btn btn-d" style="background:#888;">Batal</a>
                    <button name="<?=$is_edit?'update':'create'?>" class="btn btn-p"><i class="fas fa-save"></i> Simpan Data</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</main>

<div id="imgModal" class="modal" onclick="this.style.display='none'"><img class="modal-content" id="modalImg"><div id="modalCaption" style="color:#fff;margin-top:15px;font-size:16px;background:rgba(0,0,0,0.5);padding:5px 15px;border-radius:20px;"></div></div>

<script>
    function toggle(s){var c=document.querySelectorAll('input[name="selected_ids[]"]');for(var i=0;i<c.length;i++)c[i].checked=s.checked;}
    function showModal(s,c){document.getElementById('imgModal').style.display="flex";document.getElementById('modalImg').src=s;document.getElementById('modalCaption').innerHTML=c;}
    
    function confirmBulk(){
        var s=document.querySelector('select[name="bulk_action_type"]');
        if(s.value==''){ Swal.fire('Pilih Aksi','Silakan pilih aksi massal terlebih dahulu.','info'); return; } 
        Swal.fire({title:'Konfirmasi Massal',text:'Yakin ingin memproses data terpilih?',icon:'warning',showCancelButton:true,confirmButtonText:'Ya, Proses!',cancelButtonText:'Batal'}).then((r)=>{
            if(r.isConfirmed) document.getElementById('realBulkBtn').click();
        });
    }

    function confirmDel(id){
        Swal.fire({title:'Hapus Data?',text:'Data yang dihapus tidak dapat dikembalikan!',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',confirmButtonText:'Ya, Hapus!'}).then((r)=>{
            if(r.isConfirmed){
                document.getElementById('delId').value = id;
                document.getElementById('delForm').submit();
            }
        });
    }
</script>

<?php endif; ?>
</body>
</html>