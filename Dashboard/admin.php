<?php
// admin.php - Dashboard Admin SeedLoc
// Fix: Delete Admin Logic & Update Search/CRUD

session_start();

// --- 1. CONFIG & DATABASE CONNECTION ---
$db_file = __DIR__ . '/../api/db.php';
$conn = null;

if (file_exists($db_file)) {
    require_once $db_file;
    try {
        $database = new Database();
        $pdo = $database->getConnection();
    } catch (Exception $e) { $manual_connect = true; }
} else { $manual_connect = true; }

if (isset($manual_connect)) {
    $db_host = 'localhost';
    $db_name = 'seedlocm_apk'; 
    $db_user = 'seedlocm_ali'; 
    $db_pass = 'alialiali123!'; 
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) { die("<h3>Database Error:</h3> " . htmlspecialchars($e->getMessage())); }
}

$photo_base_url = 'https://seedloc.my.id/api/'; 
$upload_dir = __DIR__ . '/../api/uploads/'; 

// --- 2. LOAD METADATA ---
$metadata_path = realpath(__DIR__ . '/api/metadata.php');
if ($metadata_path && file_exists($metadata_path)) {
    $metadata = require $metadata_path;
    $tree_types = $metadata['treeTypes'] ?? [];
    $locations_list = $metadata['locations'] ?? [];
} else {
    $tree_types = ['Metadata File Not Found'];
    $locations_list = ['Metadata File Not Found'];
}

// --- 3. HELPER FUNCTIONS ---
function get_photo_url($path, $base) {
    if (empty($path)) return '';
    return (strpos($path, 'http') === 0) ? $path : $base . $path;
}

function build_url($params = []) {
    $current = $_GET;
    if(isset($params['action']) && $params['action'] !== ($current['action'] ?? '')) {
        unset($current['page']);
    }
    $query = array_merge($current, $params);
    return '?' . http_build_query($query);
}

function export_data($pdo, $ids, $type, $base_url, $upload_dir, $full_project_id = null) {
    if ($full_project_id) {
        $sql = ($full_project_id === 'all') ? "SELECT * FROM geotags ORDER BY id DESC" : "SELECT * FROM geotags WHERE projectId = ? ORDER BY id DESC";
        $params = ($full_project_id === 'all') ? [] : [$full_project_id];
        $filename_prefix = ($full_project_id === 'all') ? "All_Data_" : "Project_{$full_project_id}_";
    } else {
        if(empty($ids)) return;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM geotags WHERE id IN ($ph) ORDER BY id DESC";
        $params = $ids;
        $filename_prefix = "Selected_";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($type === 'download_zip') {
        if (!class_exists('ZipArchive')) { $_SESSION['swal_error'] = "ZipArchive extension missing."; return; }
        $zip = new ZipArchive();
        $zipName = $filename_prefix . 'Photos_' . date('Ymd_His') . '.zip';
        $tempZip = tempnam(sys_get_temp_dir(), 'zip');
        if ($zip->open($tempZip, ZipArchive::CREATE) !== TRUE) return;

        $count = 0;
        while($r = $stmt->fetch()) {
            $p = $r['photoPath']; if (empty($p)) continue;
            $cleanType = preg_replace('/[^A-Za-z0-9]/', '_', $r['itemType']);
            $zipInternalName = $r['id'] . '_' . $cleanType . '.jpg';
            if (strpos($p, 'http') === 0) {
                $content = @file_get_contents($p); if ($content) { $zip->addFromString($zipInternalName, $content); $count++; }
            } else {
                $filePath = $upload_dir . basename($p); if (file_exists($filePath)) { $zip->addFile($filePath, $zipInternalName); $count++; }
            }
        }
        $zip->close();
        if ($count > 0) {
            header('Content-Type: application/zip'); header('Content-disposition: attachment; filename='.$zipName);
            header('Content-Length: ' . filesize($tempZip)); readfile($tempZip); unlink($tempZip); exit;
        } else { $_SESSION['swal_warning'] = "Tidak ada foto valid."; unlink($tempZip); if(!$full_project_id) header("Location: ?action=list&table=geotags"); return; }

    } elseif ($type === 'csv') {
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="'.$filename_prefix.'Data_'.date('YmdHis').'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'ProjectID', 'Lat', 'Lng', 'Location', 'Time', 'Type', 'Condition', 'Details', 'PhotoURL']);
        while($r = $stmt->fetch()) {
            fputcsv($out, [$r['id'], $r['projectId'], $r['latitude'], $r['longitude'], $r['locationName'], $r['timestamp'], $r['itemType'], $r['condition'], $r['details'], get_photo_url($r['photoPath'], $base_url)]);
        }
        fclose($out); exit;

    } elseif ($type === 'kml') {
        header('Content-Type: application/vnd.google-earth.kml+xml'); header('Content-Disposition: attachment; filename="'.$filename_prefix.'Map_'.date('YmdHis').'.kml"');
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

// --- 4. AUTHENTICATION ---
if (isset($_SESSION['auth']) && $_SESSION['auth'] === true) {
    if (isset($_SESSION['auth_time']) && (time() - $_SESSION['auth_time'] > 7200)) { session_unset(); session_destroy(); header('Location: ?action=login&timeout=1'); exit; }
    $_SESSION['auth_time'] = time();
}
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function require_auth() { if (!isset($_SESSION['auth']) || !$_SESSION['auth']) { header('Location: ?action=login'); exit; } }

// --- 5. CONTROLLER ---
$action = $_GET['action'] ?? 'dashboard';

// FIX: Pastikan $table benar saat action = 'users'
if ($action === 'users') {
    $table = 'admin_users';
} else {
    $table = $_GET['table'] ?? 'geotags'; 
}

$pk = ($table === 'projects') ? 'projectId' : 'id'; 

if ($action === 'export_full') {
    require_auth();
    $pid = $_GET['projectId'] ?? 'all'; $type = $_GET['type'] ?? 'csv';
    if (in_array($type, ['csv', 'download_zip', 'kml'])) export_data($pdo, [], $type, $photo_base_url, $upload_dir, $pid);
    else { $_SESSION['swal_error'] = "Parameter salah."; header("Location: ?action=list&table=geotags"); exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?"); $stmt->execute([$_POST['username']??'']); $user = $stmt->fetch();
    if ($user && password_verify($_POST['password']??'', $user['password_hash'])) {
        $_SESSION['auth'] = true; $_SESSION['auth_time'] = time();
        $_SESSION['admin_id'] = $user['id']; $_SESSION['admin_username'] = $user['username'];
        $_SESSION['swal_success'] = "Login Berhasil"; header('Location: admin.php'); exit;
    } else { $_SESSION['swal_error'] = 'Username atau Password salah'; }
}

if ($action === 'logout') { session_destroy(); header('Location: ?action=login'); exit; }
if ($action !== 'login') require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) die('CSRF Validation Failed');
    
    if (isset($_POST['update']) || isset($_POST['create'])) {
        try {
            $id = $_POST[$pk] ?? null; 
            if ($table == 'projects') {
                if (isset($_POST['create'])) {
                    $check = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE projectId = ?"); $check->execute([$_POST['projectId']]);
                    if($check->fetchColumn() > 0) throw new Exception("Project ID sudah ada!");
                    $sql = "INSERT INTO projects (projectId, activityName, locationName, officers, status) VALUES (?,?,?,?,?)";
                    $params = [$_POST['projectId'], $_POST['activityName'], $_POST['locationName'], $_POST['officers'], $_POST['status']];
                } else {
                    $sql = "UPDATE projects SET activityName=?, locationName=?, officers=?, status=? WHERE projectId=?";
                    $params = [$_POST['activityName'], $_POST['locationName'], $_POST['officers'], $_POST['status'], $id];
                }
                $pdo->prepare($sql)->execute($params);

            } elseif ($table == 'admin_users') {
                $username = trim($_POST['username']); $role = $_POST['role'];
                if (isset($_POST['create'])) {
                    if(empty($_POST['password'])) throw new Exception("Password wajib diisi!");
                    $check = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?"); $check->execute([$username]);
                    if($check->fetchColumn() > 0) throw new Exception("Username sudah digunakan!");
                    $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, ?)")->execute([$username, $hash, $role]);
                } else {
                    if (!empty($_POST['password'])) {
                        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE admin_users SET username=?, role=?, password_hash=? WHERE id=?")->execute([$username, $role, $hash, $id]);
                    } else {
                        $pdo->prepare("UPDATE admin_users SET username=?, role=? WHERE id=?")->execute([$username, $role, $id]);
                    }
                }
                $_SESSION['swal_success'] = "Data Admin berhasil disimpan"; header("Location: ?action=users"); exit; 

            } elseif ($table == 'geotags') {
                $common_params = [
                    $_POST['itemType'], 
                    $_POST['condition'], 
                    $_POST['details'], 
                    $_POST['locationName'], 
                    $_POST['latitude'], 
                    $_POST['longitude'], 
                    $_POST['isSynced'], 
                    $_POST['projectId'] ?? 0
                ];
                
                if (isset($_POST['create'])) {
                    $sql = "INSERT INTO geotags (itemType, `condition`, details, locationName, latitude, longitude, isSynced, projectId) VALUES (?,?,?,?,?,?,?,?)";
                    $params = $common_params;
                } else {
                    $sql = "UPDATE geotags SET itemType=?, `condition`=?, details=?, locationName=?, latitude=?, longitude=?, isSynced=?, projectId=? WHERE id=?";
                    $params = $common_params;
                    $params[] = $id; 
                }
                $pdo->prepare($sql)->execute($params);
            }
            $_SESSION['swal_success'] = "Data berhasil disimpan"; 
            if($table != 'admin_users') { header("Location: ?action=list&table=$table"); exit; }
        } catch(Exception $e) { $_SESSION['swal_error'] = $e->getMessage(); }
    }

    if (isset($_POST['delete'])) {
        try {
            $id_to_delete = $_POST['delete_id'];
            if ($table == 'admin_users' && $id_to_delete == $_SESSION['admin_id']) throw new Exception("Tidak bisa menghapus diri sendiri!");
            
            // Delete logic sesuai table
            if($table=='geotags'){ $r=$pdo->query("SELECT photoPath FROM geotags WHERE id=$id_to_delete")->fetch(); if($r['photoPath']) @unlink($upload_dir.basename($r['photoPath'])); }
            if($table=='projects'){ $ps=$pdo->prepare("SELECT photoPath FROM geotags WHERE projectId=?"); $ps->execute([$id_to_delete]); while($ph=$ps->fetch()) @unlink($upload_dir.basename($ph['photoPath'])); $pdo->prepare("DELETE FROM geotags WHERE projectId = ?")->execute([$id_to_delete]); }
            
            // Execute delete
            $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = ?")->execute([$id_to_delete]);
            
            $_SESSION['swal_success'] = "Data berhasil dihapus"; 
            
            // Redirect sesuai halaman asal
            if ($table == 'admin_users') header("Location: ?action=users");
            else header("Location: ?action=list&table=$table");
            exit;
        } catch(Exception $e) { $_SESSION['swal_error'] = $e->getMessage(); }
    }

    if (isset($_POST['bulk_action'])) {
        $ids = $_POST['selected_ids'] ?? []; $type = $_POST['bulk_action_type'] ?? '';
        if (!empty($ids)) {
            if ($type == 'download_zip' && $table == 'geotags') export_data($pdo, $ids, 'download_zip', $photo_base_url, $upload_dir);
            elseif ($type == 'export_csv' && $table == 'geotags') export_data($pdo, $ids, 'csv', $photo_base_url, $upload_dir);
            elseif ($type == 'export_kml' && $table == 'geotags') export_data($pdo, $ids, 'kml', $photo_base_url, $upload_dir);
            elseif ($type == 'delete_selected') {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                if($table=='geotags'){ $f=$pdo->prepare("SELECT photoPath FROM geotags WHERE id IN ($ph)"); $f->execute($ids); while($r=$f->fetch()) if($r['photoPath']) @unlink($upload_dir.basename($r['photoPath'])); }
                $pdo->prepare("DELETE FROM `$table` WHERE `$pk` IN ($ph)")->execute($ids);
                $_SESSION['swal_success'] = count($ids) . " data dihapus"; header("Location: ?action=list&table=$table"); exit;
            } elseif ($type == 'mark_synced' && $table == 'geotags') {
                $ph = implode(',', array_fill(0, count($ids), '?')); $pdo->prepare("UPDATE geotags SET isSynced = 1 WHERE id IN ($ph)")->execute($ids);
                $_SESSION['swal_success'] = "Sync status diperbarui"; header("Location: ?action=list&table=$table"); exit;
            }
        }
    }
}

// --- 6. VIEW HELPERS & SEARCH LOGIC ---
function buildWhere($table, $pdo) {
    $where = []; $p = [];
    if (!empty($_GET['search'])) { 
        $s = "%{$_GET['search']}%";
        if ($table == 'geotags') { 
            // FIX: Global search includes Officer Name from projects table
            $where[] = "(geotags.id LIKE ? OR geotags.itemType LIKE ? OR geotags.locationName LIKE ? OR geotags.details LIKE ? OR geotags.condition LIKE ? OR geotags.projectId LIKE ? OR projects.officers LIKE ?)"; 
            $p = array_fill(0, 7, $s);
        } 
        elseif ($table == 'projects') { 
            $where[] = "(activityName LIKE ? OR locationName LIKE ? OR officers LIKE ? OR projectId LIKE ?)"; 
            $p = array_fill(0, 4, $s);
        }
    }
    if ($table == 'geotags') {
        if (!empty($_GET['condition']) && $_GET['condition'] != 'all') { $where[] = "geotags.condition=?"; $p[] = $_GET['condition']; }
        if (!empty($_GET['projectId']) && $_GET['projectId'] != 'all') { $where[] = "geotags.projectId=?"; $p[] = $_GET['projectId']; }
        if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) { $where[] = "DATE(geotags.timestamp) BETWEEN ? AND ?"; $p[] = $_GET['start_date']; $p[] = $_GET['end_date']; }
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

// --- LOGIKA PAGINATION & QUERY ---
$list_data = []; 
$page = (int)($_GET['page'] ?? 1); 

// Matikan Pagination khusus Geotags
if ($table === 'geotags' && $action === 'list') {
    $per_page = 9999999; 
} else {
    $per_page = 20; 
}

$total_pages = 1; 
if ($page < 1) $page = 1;

if (in_array($action, ['list', 'gallery', 'map', 'users'])) {
    if ($action == 'users') { 
        $list_data = $pdo->query("SELECT * FROM admin_users ORDER BY id DESC")->fetchAll(); 
    } elseif ($action == 'map') {
        list($where, $p) = buildWhere('geotags', $pdo);
        $w_sql = $where ? "WHERE ".implode(' AND ', $where) : "";
        
        $sql = "SELECT geotags.id, geotags.latitude, geotags.longitude, geotags.itemType, geotags.condition, geotags.photoPath, geotags.locationName 
                FROM geotags 
                LEFT JOIN projects ON geotags.projectId = projects.projectId 
                $w_sql ORDER BY geotags.id DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($p); $map_data = $stmt->fetchAll();
    } else {
        // MAIN LIST LOGIC
        list($where, $p) = buildWhere($table, $pdo);
        $w_sql = $where ? "WHERE ".implode(' AND ', $where) : "";
        
        $offset = ($page - 1) * $per_page;
        
        if ($table == 'geotags') {
            $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM geotags LEFT JOIN projects ON geotags.projectId = projects.projectId $w_sql"); 
            $total_stmt->execute($p);
            $total_rows = $total_stmt->fetchColumn(); 
            
            $sql = "SELECT geotags.*, projects.officers 
                    FROM geotags 
                    LEFT JOIN projects ON geotags.projectId = projects.projectId 
                    $w_sql 
                    ORDER BY geotags.id DESC LIMIT $per_page OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($p); 
            $list_data = $stmt->fetchAll();
            $projects_list = $pdo->query("SELECT projectId, activityName, locationName FROM projects ORDER BY created_at DESC")->fetchAll(); 

        } else {
            $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` $w_sql"); $total_stmt->execute($p);
            $total_rows = $total_stmt->fetchColumn(); 
            $stmt = $pdo->prepare("SELECT * FROM `$table` $w_sql ORDER BY `$pk` DESC LIMIT $per_page OFFSET $offset");
            $stmt->execute($p); $list_data = $stmt->fetchAll();
        }
        $total_pages = ceil($total_rows / $per_page);
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
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .btn-p{background:#2E7D32} .btn-d{background:#d32f2f} .btn-w{background:#f39c12} .btn-b{background:#2196f3} .btn-i{background:#1565c0}
        table{width:100%;border-collapse:collapse;font-size:14px} 
        th{background:#f8f9fa;font-weight:600;color:#666;text-transform:uppercase;font-size:12px;letter-spacing:0.5px}
        th,td{padding:12px 15px;border-bottom:1px solid #eee;text-align:left;vertical-align:middle}
        tr:hover{background-color:#fafafa}
        .filter-bar{display:flex;gap:10px;flex-wrap:wrap;background:#fff;padding:15px;border-radius:10px;border:1px solid #e0e0e0;margin-bottom:20px;align-items:center}
        input,select{padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;outline:none}
        input:focus,select:focus{border-color:#2E7D32}
        .modal{display:none;position:fixed;z-index:3000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.85);justify-content:center;align-items:center;flex-direction:column}
        .modal-content{max-width:90%;max-height:85%;border-radius:5px;box-shadow:0 0 20px rgba(0,0,0,0.5)}
        .status-badge{padding:4px 8px;border-radius:4px;font-size:11px;font-weight:bold;text-transform:uppercase}
        .status-Active{background:#e8f5e9;color:#2E7D32} .status-Completed{background:#e3f2fd;color:#1976d2}
        .custom-div-icon div { width:100%; height:100%; border-radius:50%; border:2px solid white; box-shadow:0 0 3px black; }
        @media(max-width:768px){.sidebar{width:60px}.brand span,.nav span{display:none}.brand{justify-content:center;padding:15px}.nav a{justify-content:center;padding:15px}}
    </style>
</head>
<body>

<?php 
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
        <li><a href="<?=build_url(['action'=>'dashboard'])?>" class="<?=$action=='dashboard'?'active':''?>"><i class="fas fa-chart-pie"></i> <span>Dashboard</span></a></li>
        <li><a href="<?=build_url(['action'=>'map'])?>" class="<?=$action=='map'?'active':''?>"><i class="fas fa-map-marked-alt"></i> <span>Peta Sebaran</span></a></li>
        <li><a href="<?=build_url(['action'=>'list', 'table'=>'projects'])?>" class="<?=($action=='list'&&$table=='projects')?'active':''?>"><i class="fas fa-folder-open"></i> <span>Data Projects</span></a></li>
        <li><a href="<?=build_url(['action'=>'list', 'table'=>'geotags'])?>" class="<?=($action=='list'&&$table=='geotags')?'active':''?>"><i class="fas fa-leaf"></i> <span>Data Geotags</span></a></li>
        <li><a href="<?=build_url(['action'=>'gallery'])?>" class="<?=$action=='gallery'?'active':''?>"><i class="fas fa-images"></i> <span>Galeri Foto</span></a></li>
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
        <div class="header"><h2>Peta Sebaran Real-time (Full Data)</h2></div>
        <form class="filter-bar">
            <input type="hidden" name="action" value="map">
            <input type="text" name="search" placeholder="Cari ID, Petugas, Lokasi, Detail..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="max-width:200px;">
            <select name="condition">
                <option value="all" <?=($_GET['condition']??'')=='all'?'selected':''?>>Semua Kondisi</option>
                <option value="Hidup" <?=($_GET['condition']??'')=='Hidup'?'selected':''?>>Hidup</option>
                <option value="Merana" <?=($_GET['condition']??'')=='Merana'?'selected':''?>>Merana</option>
                <option value="Mati" <?=($_GET['condition']??'')=='Mati'?'selected':''?>>Mati</option>
            </select>
            <div style="display:flex;align-items:center;gap:5px;">
                <input type="date" name="start_date" value="<?=htmlspecialchars($_GET['start_date']??'')?>"> 
                <span>s/d</span> 
                <input type="date" name="end_date" value="<?=htmlspecialchars($_GET['end_date']??'')?>">
            </div>
            <button class="btn btn-p">Filter</button> <a href="?action=map" class="btn btn-d">Reset</a>
        </form>
        <div class="card" style="padding:0;overflow:hidden;">
            <div id="map" style="height:650px;"></div>
        </div>
        <script>
            var m = L.map('map').setView([-6.2, 106.8], 5);
            var streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' });
            var satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: '&copy; Esri' });
            
            var savedLayer = localStorage.getItem('SelectedLayer');
            if (savedLayer === 'Satelit') { m.addLayer(satellite); } else { m.addLayer(streets); }

            var baseMaps = { "Peta Jalan": streets, "Satelit": satellite };
            L.control.layers(baseMaps).addTo(m);

            m.on('baselayerchange', function(e) { localStorage.setItem('SelectedLayer', e.name); });

            var markers = L.markerClusterGroup();
            var pts = <?=json_encode($map_data)?>; 
            var bounds = [];

            pts.forEach(p=>{
                var lat=parseFloat(p.latitude),lng=parseFloat(p.longitude);
                if(!isNaN(lat)){
                    var color = 'blue';
                    if (p.condition == 'Hidup' || p.condition == 'Baik') color = 'green';
                    else if (p.condition == 'Merana' || p.condition == 'Rusak') color = 'orange';
                    else if (p.condition == 'Mati') color = 'red';
                    
                    var icon = L.divIcon({
                        className: 'custom-div-icon',
                        html: `<div style="background-color:${color}; width:12px; height:12px; border-radius:50%; border:2px solid white; box-shadow:0 0 3px black;"></div>`,
                        iconSize: [12, 12], iconAnchor: [6, 6]
                    });

                    var img=p.photoPath?(p.photoPath.startsWith('http')?p.photoPath:'<?=$photo_base_url?>'+p.photoPath):'';
                    var mkr = L.marker([lat,lng],{icon:icon});
                    mkr.bindPopup(`<b>${p.itemType}</b><br><span style='color:${color}'>${p.condition}</span><br>${p.locationName}${img?'<br><img src="'+img+'" width="100%" style="margin-top:5px;border-radius:4px;">':''}`);
                    markers.addLayer(mkr);
                    bounds.push([lat,lng]);
                }
            });
            m.addLayer(markers);
            if(bounds.length) m.fitBounds(bounds, {padding:[50,50]});
        </script>

    <?php elseif(in_array($action, ['list', 'users'])): ?>
        <div class="header">
            <h2 style="margin:0;">Data <?=ucfirst($table)?></h2>
            <a href="?action=create&table=<?=$action=='users'?'admin_users':$table?>" class="btn btn-p"><i class="fas fa-plus-circle"></i> Tambah Data</a>
        </div>

        <?php if($action=='list'): ?>
        <form class="filter-bar">
            <input type="hidden" name="action" value="list"><input type="hidden" name="table" value="<?=$table?>">
            
            <?php if($table=='geotags'): ?>
                <input type="text" name="search" placeholder="Cari ID, Jenis, Lokasi, Petugas, Detail..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="flex:1;">
                
                <select name="projectId">
                    <option value="all">Semua Lokasi Project</option>
                    <?php foreach($projects_list as $p) echo "<option value='{$p['projectId']}' ". (($_GET['projectId']??'') == $p['projectId'] ? 'selected' : '') .">{$p['locationName']}</option>"; ?>
                </select>
                <div style="display:flex;align-items:center;gap:5px;">
                    <input type="date" name="start_date" value="<?=htmlspecialchars($_GET['start_date']??'')?>"> 
                    - 
                    <input type="date" name="end_date" value="<?=htmlspecialchars($_GET['end_date']??'')?>">
                </div>
            <?php else: ?>
                <input type="text" name="search" placeholder="Cari Project, Lokasi, Petugas..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="flex:1;">
            <?php endif; ?>
            
            <button class="btn btn-b"><i class="fas fa-search"></i> Cari</button> <a href="?action=list&table=<?=$table?>" class="btn btn-d"><i class="fas fa-sync"></i></a>

            <?php if($table=='geotags'): ?>
                <?php 
                    $currProj = $_GET['projectId'] ?? 'all';
                    $labelExport = ($currProj == 'all' || empty($currProj)) ? "SEMUA DATA" : "PROJECT #$currProj";
                ?>
                <div style="display:flex; gap:5px; align-items:center; background:#e8f5e9; padding:5px 10px; border-radius:6px; margin-left:auto;">
                    <span style="font-size:11px; font-weight:bold; color:#2E7D32; text-transform:uppercase;">Export (<?=$labelExport?>):</span>
                    <a href="?action=export_full&projectId=<?=$currProj?>&type=csv" target="_blank" class="btn btn-i" style="padding:4px 8px; font-size:11px;" title="CSV"><i class="fas fa-file-csv"></i> CSV</a>
                    <a href="?action=export_full&projectId=<?=$currProj?>&type=download_zip" target="_blank" class="btn btn-w" style="padding:4px 8px; font-size:11px;" title="Foto ZIP"><i class="fas fa-file-archive"></i> ZIP</a>
                    <a href="?action=export_full&projectId=<?=$currProj?>&type=kml" target="_blank" class="btn btn-b" style="padding:4px 8px; font-size:11px;" title="KML"><i class="fas fa-map"></i> KML</a>
                </div>
            <?php endif; ?>
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
                                <th>Foto</th><th>ID</th><th>Jenis</th><th>Petugas</th><th>Lokasi</th><th>Tanggal</th><th>Kondisi</th>
                            <?php else: ?>
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
                                <td><?php if($i):?><img src="<?=$i?>" width="45" height="45" style="object-fit:cover;border-radius:4px;cursor:pointer;border:1px solid #ddd;" onclick="viewDetail(<?=htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8')?>)"><?php else: ?>-<?php endif; ?></td>
                                <td>#<?=$r['id']?></td>
                                <td><b><?=$r['itemType']?></b></td>
                                <td><small style="color:#1565c0;font-weight:600;"><i class="fas fa-user-tag"></i> <?=$r['officers'] ?? '-'?></small></td>
                                <td><?=$r['locationName']?></td>
                                <td><?=substr($r['timestamp'],0,10)?></td>
                                <td>
                                    <span class="status-badge" style="<?= 
                                        ($r['condition']=='Hidup' || $r['condition']=='Baik') ? 'color:#2E7D32;background:#e8f5e9;' : 
                                        (($r['condition']=='Merana') ? 'color:#856404;background:#fff3cd;' : 
                                        'color:#c62828;background:#ffebee;') 
                                    ?>">
                                        <?=$r['condition']?>
                                    </span>
                                </td>
                            <?php else: ?>
                                <td><b>#<?=$r['projectId']?></b></td>
                                <td><?=$r['activityName']?></td>
                                <td><i class="fas fa-map-marker-alt" style="color:#d32f2f;margin-right:5px;"></i> <?=$r['locationName']?></td>
                                <td><small style="color:#666;"><?=$r['officers']?></small></td>
                                <td><span class="status-badge status-<?=$r['status']?>"><?=$r['status']?></span></td>
                            <?php endif; ?>
                            
                            <td style="text-align:right;">
                                <?php if($table=='geotags'): 
                                    $r_json = htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <button type="button" onclick="viewDetail(<?=$r_json?>)" class="btn btn-i" title="Detail" style="background:#00bcd4;"><i class="fas fa-eye"></i></button>
                                <?php endif; ?>
                                
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
        
        <?php if($table !== 'geotags' || $action !== 'list'): ?>
        <div style="display:flex;justify-content:center;gap:8px;margin-top:20px;">
            <?php 
            $q = $_GET; 
            if($page > 1) { $q['page'] = $page - 1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p" style="background:#fff;color:#333;border:1px solid #ddd;">&laquo; Prev</a>'; }
            if($page < $total_pages) { $q['page'] = $page + 1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p" style="background:#fff;color:#333;border:1px solid #ddd;">Next &raquo;</a>'; } 
            ?>
        </div>
        <?php endif; ?>

    <?php elseif($action === 'gallery'): ?>
        <div class="header"><h2>Galeri Lapangan</h2></div>
        <form class="filter-bar"><input type="hidden" name="action" value="gallery"><input type="hidden" name="table" value="geotags"><input type="text" name="search" placeholder="Cari foto..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="flex:1;"><button class="btn btn-p">Cari</button></form>
        
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:15px;">
            <?php if(!empty($list_data)): foreach($list_data as $r): $i=get_photo_url($r['photoPath']??'', $photo_base_url); if(!$i) continue; ?>
            <div class="card" style="padding:0;overflow:hidden;cursor:pointer;transition:transform 0.2s;position:relative;" onclick="viewDetail(<?=htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8')?>)" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                
                <div style="position:absolute;top:10px;right:10px;padding:3px 8px;border-radius:4px;font-size:10px;font-weight:bold;box-shadow:0 2px 4px rgba(0,0,0,0.3);<?= 
                    ($r['condition']=='Hidup' || $r['condition']=='Baik') ? 'background:#e8f5e9;color:#2E7D32;' : 
                    (($r['condition']=='Merana') ? 'background:#fff3cd;color:#856404;' : 
                    'background:#ffebee;color:#c62828;') 
                ?>">
                    <?=$r['condition']?>
                </div>

                <img src="<?=$i?>" style="width:100%;height:150px;object-fit:cover;">
                
                <div style="padding:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                        <span style="font-size:11px;color:#999;">#<?=$r['id']?></span>
                        <span style="font-size:11px;color:#666;"><?=substr($r['timestamp'],0,10)?></span>
                    </div>
                    <b style="font-size:14px;display:block;margin-bottom:3px;color:#333;"><?=$r['itemType']?></b>
                    <small style="color:#666;display:block;margin-bottom:5px;"><i class="fas fa-map-marker-alt"></i> <?=$r['locationName']?></small>
                    <?php if(!empty($r['details'])): ?>
                        <small style="color:#888;font-style:italic;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">"<?=$r['details']?>"</small>
                    <?php endif; ?>
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
                        <div>
                            <label>Tipe Item</label>
                            <select name="itemType" style="width:100%;">
                                <?php 
                                $opts = $tree_types;
                                if($is_edit && !in_array($d['itemType'], $opts)) array_unshift($opts, $d['itemType']);
                                foreach($opts as $t) {
                                    $sel = ($d['itemType'] == $t) ? 'selected' : '';
                                    echo "<option value='".htmlspecialchars($t)."' $sel>".htmlspecialchars($t)."</option>"; 
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div style="margin-bottom:15px;">
                        <label>Lokasi</label>
                        <select name="locationName" style="width:100%;">
                            <?php 
                            $locs = $locations_list;
                            if($is_edit && !in_array($d['locationName'], $locs)) array_unshift($locs, $d['locationName']);
                            foreach($locs as $l) {
                                $sel = ($d['locationName'] == $l) ? 'selected' : '';
                                echo "<option value='".htmlspecialchars($l)."' $sel>".htmlspecialchars($l)."</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
                        <div><label>Lat</label><input type="text" name="latitude" value="<?=$d['latitude']??''?>" style="width:100%;"></div>
                        <div><label>Lng</label><input type="text" name="longitude" value="<?=$d['longitude']??''?>" style="width:100%;"></div>
                    </div>
                    <div style="margin-bottom:15px;">
                     <label>Kondisi</label>
                      <select name="condition" style="width:100%;">
                      <option value="Hidup" <?=($d['condition']=='Hidup'?'selected':'')?>>Hidup</option>
                         <option value="Merana" <?=($d['condition']=='Merana'?'selected':'')?>>Merana</option>
                        <option value="Mati" <?=($d['condition']=='Mati'?'selected':'')?>>Mati</option>
                        </select>
                    </div>
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

<div id="detailModal" class="modal" style="display:none;">
    <div class="modal-content" style="width: 500px; padding: 0; background: #fff; border-radius: 8px; position: relative; overflow: hidden;">
        <div style="background:#2E7D32; padding:15px 20px; color:#fff; font-weight:bold; font-size:16px;">
            Detail Data
            <span onclick="document.getElementById('detailModal').style.display='none'" style="float:right; cursor:pointer; font-size:20px;">&times;</span>
        </div>
        <div id="detailContent" style="padding:20px; max-height: 80vh; overflow-y:auto;"></div>
    </div>
</div>

<div id="imgModal" class="modal" onclick="this.style.display='none'"><img class="modal-content" id="modalImg"><div id="modalCaption" style="color:#fff;margin-top:15px;font-size:16px;background:rgba(0,0,0,0.5);padding:5px 15px;border-radius:20px;"></div></div>

<script>
    function toggle(s){var c=document.querySelectorAll('input[name="selected_ids[]"]');for(var i=0;i<c.length;i++)c[i].checked=s.checked;}
    
    // Original Show Modal for Images (from Gallery)
    function showModal(s,c){
        document.getElementById('imgModal').style.display="flex";
        document.getElementById('modalImg').src=s;
        document.getElementById('modalCaption').innerHTML=c;
    }

    // FIX: New View Detail Function (Added Officer to Detail View as well)
    function viewDetail(data) {
        var photoUrl = data.photoPath ? (data.photoPath.startsWith('http') ? data.photoPath : '<?=$photo_base_url?>' + data.photoPath) : '';
        var color = data.condition == 'Hidup' || data.condition == 'Baik' ? '#2E7D32' : (data.condition == 'Mati' ? '#c62828' : '#f39c12');
        
        var html = `
            <div style="text-align:center; margin-bottom:15px;">
                ${photoUrl ? `<img src="${photoUrl}" style="max-width:100%; max-height:250px; border-radius:8px; border:1px solid #eee; cursor:pointer;" onclick="showModal('${photoUrl}', '${data.itemType}')">` : '<div style="padding:40px; background:#f5f5f5; color:#999; border-radius:8px;">Tidak Ada Foto</div>'}
            </div>
            <table style="width:100%; border-collapse:collapse;">
                <tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold; width:100px;">JENIS POHON</td><td style="font-weight:bold; font-size:15px;">${data.itemType}</td></tr>
                <tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold;">PETUGAS</td><td><i class="fas fa-user"></i> ${data.officers || '-'}</td></tr>
                <tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold;">LOKASI</td><td><i class="fas fa-map-marker-alt" style="color:#d32f2f"></i> ${data.locationName}</td></tr>
                <tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold;">KONDISI</td><td><span class="status-badge" style="background:${color}; color:#fff;">${data.condition}</span></td></tr>
                <tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold;">KOORDINAT</td><td style="font-family:monospace;">${parseFloat(data.latitude).toFixed(6)}, ${parseFloat(data.longitude).toFixed(6)}</td></tr>
                <tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold;">WAKTU</td><td>${data.timestamp}</td></tr>
                <tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold; vertical-align:top;">CATATAN</td><td style="background:#f9f9f9; padding:8px; border-radius:4px; font-size:13px; line-height:1.4;">${data.details || '-'}</td></tr>
                <tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold;">PROJECT ID</td><td>#${data.projectId}</td></tr>
            </table>
        `;
        document.getElementById('detailContent').innerHTML = html;
        document.getElementById('detailModal').style.display = 'flex';
    }

    function confirmBulk(){
        var s=document.querySelector('select[name="bulk_action_type"]');
        if(s.value==''){ Swal.fire('Pilih Aksi','Silakan pilih aksi massal terlebih dahulu.','info'); return; } 
        Swal.fire({title:'Konfirmasi Massal',text:'Yakin ingin memproses data terpilih?',icon:'warning',showCancelButton:true,confirmButtonText:'Ya, Proses!',cancelButtonText:'Batal'}).then((r)=>{
            if(r.isConfirmed) document.getElementById('realBulkBtn').click();
        });
    }
    function confirmDel(id){
        Swal.fire({title:'Hapus Data?',text:'Data yang dihapus (beserta Fotonya) tidak dapat dikembalikan!',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',confirmButtonText:'Ya, Hapus!'}).then((r)=>{
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