<?php
// admin.php - Ver. Final Comprehensive (FIXED: Unknown column 'table' & Filter)

session_start();

// --- 1. CONFIG & KONEKSI DATABASE ---
$db_host = 'localhost';
$db_name = 'seedlocm_apk';
$db_user = 'seedlocm_ali';
$db_pass = 'alialiali123!';
$dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";

// Hidden Password Hash (ali210103)
$PASSWORD_HASH = 'e0a4cb68ee74255ea69548de2d27e40aa5aaaed8b0b14bb0caab9f9124cc6b64';
$photo_base_url = 'https://seedloc.my.id/api/';
$upload_dir = '../api/uploads/'; // Path relatif ke direktori upload

// [DUAL-TABLE LOGIC]
$current_table_param = $_GET['table'] ?? 'geotags'; 
$allowed_tables = ['geotags', 'projects'];
$table = in_array($current_table_param, $allowed_tables) ? $current_table_param : 'geotags';
$pk = ($table === 'projects') ? 'projectId' : 'id'; // PK dinamis

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    // Tangani kegagalan koneksi DB di tampilan dashboard
    die("
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; background-color: #fcebeb; color: #5a1e1e; padding: 50px; }
        .error-box { max-width: 600px; margin: 0 auto; padding: 30px; background-color: #fff; border: 1px solid #e0b4b4; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.05); }
        h1 { color: #d32f2f; border-bottom: 2px solid #d32f2f; padding-bottom: 10px; }
    </style>
    <div class='error-box'>
        <h1>Admin Dashboard Error</h1>
        <p><strong>Koneksi database gagal.</strong></p>
        <p><strong>Detail Teknis:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
    </div>
    ");
}

// FUNGSI BARU: Ambil semua Project untuk filter
function fetch_all_projects($pdo) {
    $stmt = $pdo->query("SELECT projectId, activityName FROM projects ORDER BY projectId DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// Filter variables (Ditarik dari GET request)
$search_query = $_GET['search'] ?? '';
$condition_filter = $_GET['condition'] ?? 'all';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$project_id_filter = $_GET['projectId'] ?? 'all'; // NEW: Project ID filter
$conditions_list = ['Baik', 'Cukup', 'Buruk', 'Rusak']; 


// CSRF & Auth functions remain the same
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

function is_authenticated() {
    return isset($_SESSION['auth']) && $_SESSION['auth'] === true;
}

function require_auth() {
    if (!is_authenticated()) {
        header('Location: ?action=login');
        exit;
    }
}

// --- PENINGKATAN KEAMANAN: SESSION TIMEOUT (30 MENIT) ---
$session_timeout = 1800; // 30 menit
if (is_authenticated()) {
    if (isset($_SESSION['auth_time']) && (time() - $_SESSION['auth_time'] > $session_timeout)) {
        session_unset();
        session_destroy();
        header('Location: ?action=login&timeout=1');
        exit;
    }
    $_SESSION['auth_time'] = time(); // Update session time on activity
}
// --- AKHIR SESSION TIMEOUT ---


// ---------- LOGIN / AUTHENTICATION ----------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header('Location: ?action=login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $input = $_POST['password'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        die('CSRF token mismatch');
    }
    if ($input === '') {
        $login_error = 'Masukkan password.';
    } else {
        $input_hash = hash('sha256', $input);
        if (hash_equals($input_hash, $PASSWORD_HASH)) {
            $_SESSION['auth'] = true;
            $_SESSION['auth_time'] = time(); // Set initial session time
            header('Location: admin.php');
            exit;
        } else {
            $login_error = 'Password salah.';
        }
    }
}

$action = $_GET['action'] ?? 'list';

if ($action !== 'login') {
    require_auth();
}

// FUNGSI HELPER: Hapus File Foto Fisik
function delete_photo_file($pdo, $table, $pk, $id, $upload_dir) {
    if ($table !== 'geotags') return;

    $stmt = $pdo->prepare("SELECT photoPath FROM `$table` WHERE `$pk` = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    
    if ($row && !empty($row['photoPath'])) {
        // Hapus path API base url jika ada, ambil hanya nama file/path
        $filename_in_db = basename($row['photoPath']);
        $file_path = $upload_dir . $filename_in_db;
        
        // Pastikan bukan URL eksternal yang dimulai dengan http/https
        if (strpos($row['photoPath'], 'http') !== 0 && file_exists($file_path)) {
            @unlink($file_path);
        }
    }
}

// FUNGSI INLINE: Export Geotags yang dipilih ke CSV
function export_geotags_to_csv($pdo, $selected_ids, $photo_base_url) {
    if (empty($selected_ids)) return; 
    
    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
    
    $sql = "SELECT id, projectId, latitude, longitude, locationName, timestamp, itemType, `condition`, details, photoPath, isSynced, deviceId FROM geotags WHERE id IN ($placeholders) ORDER BY id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($selected_ids);
    $geotags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($geotags)) return; 

    $filename = "seedloc_geotags_selected_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'ID', 
        'Project ID', 
        'Latitude', 
        'Longitude', 
        'Location Name', 
        'Timestamp', 
        'Item Type', 
        'Condition', 
        'Details', 
        'Photo Path (Full URL)',
        'Is Synced',
        'Device ID'
    ]);

    foreach ($geotags as $row) {
        $photo_path = $row['photoPath'];
        
        if (!empty($photo_path) && strpos($photo_path, 'http') !== 0) {
            $row['photoPath'] = $photo_base_url . $photo_path; 
        }
        
        fputcsv($output, array_values($row));
    }

    fclose($output);
    exit; 
}


// -------------- CRUD HANDLERS (Create, Update, Delete, Bulk) ----------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF mismatch');
    $selected_ids = $_POST['selected_ids'] ?? [];
    $bulk_action_type = $_POST['bulk_action_type'] ?? '';
    $success_count = 0;

    if (empty($selected_ids)) {
        $error = "Tidak ada item yang dipilih untuk aksi massal.";
    } else {
        // --- LOGIKA EXPORT ---
        if ($bulk_action_type === 'export_selected' && $table === 'geotags') {
            export_geotags_to_csv($pdo, $selected_ids, $photo_base_url); 
        }
        // --- AKHIR LOGIKA EXPORT ---

        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        $success = true;

        try {
            $pdo->beginTransaction();
            if ($bulk_action_type === 'delete_selected') {
                if ($table === 'geotags') {
                    foreach ($selected_ids as $id) {
                        delete_photo_file($pdo, $table, $pk, $id, $upload_dir);
                    }
                }
                $sql = "DELETE FROM `$table` WHERE `$pk` IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($selected_ids);
                $success_count = $stmt->rowCount();
                $pdo->commit();
                $success = true;
                $message = "Berhasil menghapus $success_count record.";

            } elseif ($bulk_action_type === 'mark_synced' && $table === 'geotags') {
                $sql = "UPDATE `$table` SET isSynced = 1 WHERE `$pk` IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($selected_ids);
                $success_count = $stmt->rowCount();
                $pdo->commit();
                $success = true;
                $message = "Berhasil menandai $success_count geotag sebagai Tersinkron.";
            } else {
                $error = "Aksi massal tidak valid.";
                $success = false;
            }
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error = "Gagal melakukan aksi massal: " . htmlspecialchars($e->getMessage());
            $success = false;
        }

        if ($success) {
            header("Location: admin.php?table=$table&message=" . urlencode($message));
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF mismatch');
    
    if ($table !== 'projects') die('Aksi CREATE tidak diizinkan untuk tabel ini.');
    
    $fields = [];
    $placeholders = [];
    $values = [];
    
    foreach ($_POST as $k => $v) {
        if (in_array($k, ['create','csrf_token', $pk])) continue;
        if (!in_array($k, ['activityName', 'locationName', 'officers', 'status'])) continue;
        
        $fields[] = "`" . str_replace('`','', $k) . "`";
        $placeholders[] = ':' . $k;
        $values[':' . $k] = $v;
    }
    
    if (empty($_POST['projectId'])) {
         $error = 'Project ID harus diisi untuk Proyek baru.';
    } else {
         $fields[] = "`projectId`";
         $placeholders[] = ':projectId';
         $values[':projectId'] = $_POST['projectId'];
         
         if (!isset($values[':status'])) {
             $fields[] = '`status`';
             $placeholders[] = ':status';
             $values[':status'] = 'Active';
         }

         if (empty($fields)) {
             $error = 'Tidak ada data yang dikirim.';
         } else {
             try {
                $sql = "INSERT INTO `$table` (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($values);
                header('Location: admin.php?table=projects');
                exit;
             } catch(PDOException $e) {
                 $error = "Gagal membuat Proyek: " . htmlspecialchars($e->getMessage());
             }
         }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF mismatch');
    $id = $_POST[$pk] ?? '';
    if ($id === '') die('ID kosong');

    $sets = [];
    $values = [];
    
    // --- SERVER-SIDE VALIDATION DAN LOGIC ---
    $error = null; // Reset error for local context
    if ($table === 'geotags') {
        // Cek dan Validasi Lat/Lng
        if (isset($_POST['latitude']) && !is_numeric($_POST['latitude'])) { $error = 'Latitude harus berupa angka.'; }
        if (isset($_POST['longitude']) && !is_numeric($_POST['longitude'])) { $error = 'Longitude harus berupa angka.'; }
        
        // Logic Hapus Foto
        if (isset($_POST['delete_photo']) && $_POST['delete_photo'] === '1') {
             delete_photo_file($pdo, $table, $pk, $id, $upload_dir);
             $_POST['photoPath'] = ''; // Paksa photoPath menjadi kosong di DB
        }
    }
    if ($error !== null) { // Jika ada error validasi, hentikan proses update
        $action = 'edit'; // Kembali ke halaman edit
        goto render_page; // Lompat ke bagian rendering untuk menampilkan error
    }
    // --- AKHIR SERVER-SIDE VALIDATION DAN LOGIC ---


    $excluded_fields = ['update','csrf_token',$pk, 'table', 'delete_photo']; 
    
    foreach ($_POST as $k => $v) {
        if (in_array($k, $excluded_fields)) continue;
        
        if (in_array($k, ['created_at'])) continue;
        
        // Konversi isSynced kembali ke INTEGER sebelum disimpan (hanya untuk geotags)
        if ($table === 'geotags' && $k === 'isSynced') {
             $v = ($v === '1' || $v === 'true' || $v === 'Yes') ? 1 : 0;
        }

        $sets[] = "`" . str_replace('`','', $k) . "` = :" . $k;
        $values[':' . $k] = $v;
    }
    
    $values[':pkval'] = $id;
    if (empty($sets)) {
        $error = 'Tidak ada perubahan.';
    } else {
        try {
            $sql = "UPDATE `$table` SET " . implode(',', $sets) . " WHERE `$pk` = :pkval";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            header("Location: admin.php?table=$table");
            exit;
        } catch(PDOException $e) {
            $error = "Gagal Update: " . htmlspecialchars($e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF mismatch');
    $id = $_POST['id'] ?? '';
    if ($id === '') die('ID kosong');
    try {
        // Hapus file fisik terlebih dahulu jika geotags
        if ($table === 'geotags') {
             delete_photo_file($pdo, $table, $pk, $id, $upload_dir);
        }
        
        $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = :id");
        $stmt->execute([':id' => $id]);
        header("Location: admin.php?table=$table");
        exit;
    } catch(PDOException $e) {
        $error = "Gagal Hapus: " . htmlspecialchars($e->getMessage());
    }
}

// Ambil semua metadata kolom
$colsMeta = [];
if ($action !== 'login') {
    $colsStmt = $pdo->query("DESCRIBE `$table`");
    $colsMeta = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Ambil pesan dari redirect (Bulk Action Success)
$success_message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '';

// ----------------- RENDER HTML DENGAN STYLE INDEX.PHP -------------------
render_page:
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin CRUD - <?php echo htmlspecialchars($table); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        /* Menggunakan style dari index.php */
        :root {
            --primary-color: #2E7D32; 
            --secondary-color: #66BB6A;
            --background-light: #f4f6f8;
            --text-dark: #2c3e50;
            --muted: #7f8c8d;
            --shadow-subtle: 0 6px 20px rgba(0,0,0,0.06);
            --radius-default: 10px;
        }

        * { box-sizing: border-box; }
        body { 
            font-family: 'Poppins', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial; 
            margin: 0; 
            background-color: var(--background-light); 
            color: var(--text-dark); 
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .container { 
            max-width: 1220px; 
            margin: 28px auto; 
            padding: 26px; 
            background: rgba(255,255,255,0.98);
            box-shadow: var(--shadow-subtle);
            border-radius: 14px; 
            min-height: 60vh;
        }

        header.app-header {
            display: flex;
            align-items: center;
            gap: 16px;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .brand {
            display: flex;
            gap: 12px;
            align-items: center;
            text-decoration: none;
            color: inherit;
        }
        /* Style untuk Logo Image */
        .brand .logo {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(46,125,50,0.15);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            background: transparent;
        }
        .brand .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 12px;
        }

        .brand h1 { font-size: 20px; margin: 0; }
        .brand p { margin: 0; color: var(--muted); font-size: 13px; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid rgba(0,0,0,0.06);
            background: white;
            cursor: pointer;
            font-weight: 600;
            color: var(--text-dark);
            text-decoration: none;
            line-height: 1; 
        }
        .btn.primary {
            background: var(--primary-color);
            color: white;
            border: none;
            box-shadow: 0 6px 16px rgba(46,125,50,0.12);
        }
        
        h2.page-title {
            font-size: 24px;
            color: var(--primary-color);
            border-bottom: 2px solid #e6e9ec;
            padding-bottom: 10px;
            margin-bottom: 25px;
        }
        /* Penyesuaian Judul Halaman List/Tabel */
        .page-list-title {
            font-size: 24px;
            color: var(--primary-color);
            border-bottom: 2px solid #e6e9ec;
            padding-bottom: 10px;
            margin-bottom: 15px; /* Kurangi margin bawah */
        }

        /* Navigasi Tabel */
        .table-nav {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .table-nav .nav-item {
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            color: var(--text-dark);
            background: #eee;
            transition: background 0.2s;
            border: 1px solid #ddd;
        }
        .table-nav .nav-item:hover {
            background: #e0e0e0;
        }
        .table-nav .nav-item.active {
            background: var(--primary-color);
            color: white;
            border: 1px solid var(--primary-color);
        }
        
        /* Filter Area */
        .filter-area { 
            display: flex; 
            gap: 10px; 
            margin-bottom: 12px; 
            flex-wrap: wrap; 
            align-items: flex-end;
            padding: 15px;
            border-radius: 10px;
            background: #fff;
            border: 1px solid #e6e9ec;
            box-shadow: var(--shadow-subtle);
        }
        .filter-area .filter-group { flex: 1; min-width: 180px; }
        .filter-area select, .filter-area input[type="text"], .filter-area input[type="date"] { 
            padding: 8px 10px; 
            border: 1px solid #ccc; 
            border-radius: 6px; 
            font-size: 14px; 
            width: 100%; 
        }
        .filter-area label { font-size: 12px; color: var(--muted); font-weight: 600; margin-bottom: 4px; display: block; }
        .filter-area button.btn-filter { padding: 9px 12px; background-color: #3498db; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 700; transition: background-color 0.3s; }
        .filter-area button.btn-filter:hover { background-color: #2980b9; }

        /* Bulk Action Form (Diubah agar selalu di atas tabel) */
        .bulk-actions {
            margin-top: 10px; /* Tambahkan margin atas agar tidak terlalu dekat dengan judul */
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 10px 0;
            border-radius: 8px;
            justify-content: flex-start;
            flex-wrap: wrap;
        }
        .bulk-actions select { padding: 8px; border-radius: 6px; border: 1px solid #ccc; }
        .bulk-actions button {
            padding: 8px 12px;
            background: #e67e22; 
            color: white; 
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
        }
        .bulk-actions .info {
             font-size: 14px; 
             color: var(--muted);
             margin-left: 10px;
        }

        /* Tabel Data */
        .data-table-container { 
            overflow-x: auto; 
            border: 1px solid #e6e9ec;
            border-radius: 12px;
            box-shadow: var(--shadow-subtle);
            background: white;
            max-height: 600px;
        }
        .data-table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        .data-table th, .data-table td { 
            padding: 14px 16px; 
            text-align: left; 
            border-bottom: 1px solid #f1f4f6; 
            font-size: 14px; 
            vertical-align: middle;
            white-space: nowrap; 
        }
        .data-table th { 
            background-color: #fbfdfe; 
            color: var(--muted); 
            position: sticky; 
            top: 0; 
            z-index: 2;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.04em;
        }
        .data-table tr:hover { background: #fbfff9; }
        form.inline{display:inline}
        .photo-thumb { 
            width: 50px; 
            height: 50px; 
            object-fit: cover; 
            border-radius: 4px; 
            cursor: pointer; 
        }

        /* Form Edit/Create */
        .form-card {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e6e9ec;
            box-shadow: var(--shadow-subtle);
            max-width: 700px;
        }
        label { 
            display: block; 
            margin-bottom: 15px; 
            font-weight: 600; 
            color: var(--text-dark);
        }
        input[type="text"], input[type="number"], textarea, select { 
            width: 100%; 
            padding: 10px; 
            margin-top: 5px; 
            border: 1px solid #ccc; 
            border-radius: 6px;
            font-size: 15px;
            transition: border-color 0.2s;
        }
        input[type="text"]:focus, input[type="number"]:focus, textarea:focus, select:focus {
            border-color: var(--secondary-color);
            outline: none;
        }
        textarea { min-height: 100px; resize: vertical; }
        .warning { color: #d35400; font-weight: bold; font-size: 0.9em; margin-left: 10px; }
        .btn-form-action {
            padding: 10px 20px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            transition: background-color 0.3s;
        }
        .btn-form-action:hover {
            background-color: var(--secondary-color);
        }
        .success-message { background: #e8f8f3; color: #27ae60; padding: 10px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #27ae60; }
    </style>
</head>
<body>

<div class="container" role="main">

<?php if ($action === 'login'): ?>
    <div class="form-card" style="margin: 50px auto;">
        <h2><i class="fas fa-lock"></i> Admin Login</h2>
        <?php if (isset($_GET['timeout'])): ?>
            <div style="color:red; margin-bottom: 10px;">Sesi telah habis karena tidak ada aktivitas (30 menit).</div>
        <?php endif; ?>
        <p>Masukkan password untuk mengakses panel admin.</p>
        <?php if (!empty($login_error)): ?><div style="color:red; margin-bottom: 10px;"><?php echo htmlspecialchars($login_error); ?></div><?php endif; ?>
        <form method="post">
            <label>Password: 
                <input type="password" name="password" autocomplete="off" style="margin-top: 5px;">
            </label>
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <button type="submit" name="login" class="btn-form-action">Masuk</button>
        </form>
    </div>

<?php else: ?>
    <?php 
    // Ambil semua proyek hanya saat tampilan admin tidak login
    $all_projects = fetch_all_projects($pdo);
    ?>

    <header class="app-header" role="banner">
        <div class="brand">
            <div class="logo" aria-hidden="true">
                <img src="https://seedloc.my.id/logo.png" alt="SeedLoc Logo">
            </div>
            <div>
                <h1>Admin Panel</h1>
                <p>Kelola Data `<?php echo htmlspecialchars($table); ?>`</p>
            </div>
        </div>
        <div class="header-actions">
            <a class="btn" href="?action=logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>
    
    <div class="table-nav">
        <a href="?table=geotags&action=list" class="nav-item <?php echo $table === 'geotags' ? 'active' : ''; ?>">
            <i class="fas fa-map-marker-alt"></i> Kelola Geotag
        </a>
        <a href="?table=projects&action=list" class="nav-item <?php echo $table === 'projects' ? 'active' : ''; ?>">
            <i class="fas fa-folder-open"></i> Kelola Proyek
        </a>
        <?php if ($table === 'projects'): ?>
            <a href="?table=projects&action=create" class="btn primary">
                <i class="fas fa-plus"></i> Buat Proyek Baru
            </a>
        <?php endif; ?>
    </div>


    <?php if (!empty($error)): ?><div style="color:red; margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (!empty($success_message)): ?><div class="success-message"><i class="fas fa-check-circle"></i> <?php echo $success_message; ?></div><?php endif; ?>

    <?php if ($action === 'create' && $table === 'projects'): ?>
        <h2 class="page-title"><i class="fas fa-plus"></i> Buat Proyek Baru</h2>
        <div class="form-card">
            <form method="post">
                <input type="hidden" name="table" value="projects">
                <label>Project ID (Wajib, Angka): 
                    <input type="text" name="projectId" required>
                    <span class="warning">(Harus unik, Project ID tidak otomatis)</span>
                </label>
                <label>Activity Name: <input type="text" name="activityName" required></label>
                <label>Location Name: <input type="text" name="locationName" required></label>
                <label>Officers (Pisahkan dengan koma): <textarea name="officers"></textarea></label>
                <label>Status: 
                    <select name="status">
                        <option value="Active">Active</option>
                        <option value="Completed">Completed</option>
                    </select>
                </label>
                
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button name="create" class="btn-form-action"><i class="fas fa-save"></i> Simpan Proyek</button>
            </form>
        </div>
        <p style="margin-top: 20px;"><a href="admin.php?table=projects"><i class="fas fa-arrow-left"></i> Kembali ke daftar Proyek</a></p>

    <?php elseif ($action === 'edit'):
        $id = $_GET['id'] ?? $id; 
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$pk` = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        
        $page_title_name = $table === 'projects' ? "Proyek ID: " . htmlspecialchars($row[$pk]) : "Geotag ID: " . htmlspecialchars($row[$pk]);

        if (!$row) { echo '<div>Record tidak ditemukan.</div><p><a href="admin.php?table=' . htmlspecialchars($table) . '">Kembali</a></p>'; }
        else {
    ?>
        <h2 class="page-title"><i class="fas fa-edit"></i> Edit Data - <?php echo $page_title_name; ?></h2>
        <div class="form-card">
            <form method="post">
                <input type="hidden" name="table" value="<?php echo htmlspecialchars($table); ?>">
                <input type="hidden" name="<?php echo $pk; ?>" value="<?php echo htmlspecialchars($row[$pk]); ?>">
                
                <?php foreach ($colsMeta as $col): 
                    $colName = $col['Field'];
                    // Menggunakan nilai dari POST jika ada error validasi sebelumnya
                    $val = isset($_POST[$colName]) ? $_POST[$colName] : ($row[$colName] ?? '');
                    
                    $isPk = ($colName === $pk);
                    $isReadOnly = $isPk || in_array($colName, ['created_at']);
                    $isSelect = ($colName === 'isSynced' && $table === 'geotags') || ($colName === 'status' && $table === 'projects');
                    $isLocation = ($table === 'geotags' && in_array($colName, ['latitude', 'longitude']));


                    if ($isPk) {
                        echo "<label><strong>" . htmlspecialchars(ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $colName))) . ":</strong> <span class='warning'>" . htmlspecialchars($val) . "</span></label>";
                        continue;
                    }

                    if ($table === 'geotags' && $colName === 'photoPath'): 
                        $photo_full_url = (!empty($val) && strpos($val, 'http') === 0) ? $val : ($photo_base_url . $val);
                    ?>
                        <label>
                            Photo Path:
                            <input type="text" name="<?php echo htmlspecialchars($colName); ?>" value="<?php echo htmlspecialchars($val); ?>">
                            
                            <?php if (!empty($val)): ?>
                                <a href="<?php echo $photo_full_url; ?>" target="_blank" style="margin-top: 10px; display: inline-block;"><i class="fas fa-external-link-alt"></i> Lihat Foto</a>
                                <img src="<?php echo $photo_full_url; ?>" onerror="this.src='//via.placeholder.com/100?text=NO+IMG';" alt="Foto" class="photo-thumb" style="width: 100px; height: 100px; display: block; margin-top: 10px;">
                                <div style="margin-top: 10px;">
                                    <input type="checkbox" id="delete_photo" name="delete_photo" value="1" <?php echo isset($_POST['delete_photo']) ? 'checked' : ''; ?>>
                                    <label for="delete_photo" style="display: inline; font-weight: normal; color: #e74c3c;"><i class="fas fa-trash"></i> Hapus Foto (Juga hapus file fisik di server)</label>
                                </div>
                            <?php endif; ?>
                        </label>
                    <?php 
                        continue;
                    endif;

                    if ($isSelect):
                        $options = [];
                        if ($colName === 'isSynced') {
                            $options = [1 => 'Tersinkron (1)', 0 => 'Belum Tersinkron (0)'];
                        } elseif ($colName === 'status') {
                            $options = ['Active' => 'Active', 'Completed' => 'Completed'];
                        }
                    ?>
                        <label>
                            <?php echo htmlspecialchars(ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $colName))); ?>:
                            <select name="<?php echo htmlspecialchars($colName); ?>">
                                <?php foreach ($options as $opt_val => $opt_label): ?>
                                    <option value="<?php echo $opt_val; ?>" <?php echo (string)$opt_val === (string)$val ? 'selected' : ''; ?>>
                                        <?php echo $opt_label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php 
                        continue;
                    endif;

                ?>
                    <label>
                        <?php echo htmlspecialchars(ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $colName))); ?>:
                        <?php if ($isReadOnly): ?>
                             <input type="text" name="<?php echo htmlspecialchars($colName); ?>" value="<?php echo htmlspecialchars($val); ?>" readonly>
                             <span class="warning">(Otomatis/Tidak dapat diedit)</span>
                        <?php elseif ($isLocation): ?>
                             <input type="number" name="<?php echo htmlspecialchars($colName); ?>" value="<?php echo htmlspecialchars($val); ?>" step="any">
                             <span class="warning">(Harus Angka Desimal)</span>
                        <?php elseif (strpos($col['Type'], 'text') !== false || strpos($col['Type'], 'varchar') !== false && (strlen((string)$val) > 50 || strpos($colName, 'officers') !== false || strpos($colName, 'details') !== false)): ?>
                            <textarea name="<?php echo htmlspecialchars($colName); ?>"><?php echo htmlspecialchars($val); ?></textarea>
                        <?php else: ?>
                            <input type="text" name="<?php echo htmlspecialchars($colName); ?>" value="<?php echo htmlspecialchars($val); ?>">
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
                
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button name="update" class="btn-form-action"><i class="fas fa-save"></i> Update Data</button>
            </form>
        </div>
        <p style="margin-top: 20px;"><a href="admin.php?table=<?php echo htmlspecialchars($table); ?>"><i class="fas fa-arrow-left"></i> Kembali ke daftar</a></p>
    <?php } ?>

    <?php else: // list view ?>
        <h2 class="page-list-title"><i class="fas fa-table"></i> Daftar Data `<?php echo htmlspecialchars($table); ?>`</h2>
        
        <?php if ($table === 'geotags'): ?>
        <form class="filter-area" method="GET" action="admin.php" role="search">
            <input type="hidden" name="table" value="geotags">
            
            <div class="filter-group">
                <label for="projectIdFilter">Filter Project ID</label>
                <select name="projectId" id="projectIdFilter">
                    <option value="all">-- Semua Project --</option>
                    <?php foreach ($all_projects as $project): ?>
                        <option value="<?php echo htmlspecialchars($project['projectId']); ?>" <?php echo $project_id_filter == $project['projectId'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($project['projectId'] . ' - ' . $project['activityName']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="searchInput">Cari Nama/Lokasi</label>
                <input id="searchInput" type="text" name="search" placeholder="Cari Nama Pohon atau Lokasi..." value="<?php echo htmlspecialchars($search_query); ?>">
            </div>

            <div class="filter-group">
                <label for="conditionFilter">Filter Kondisi</label>
                <select name="condition" id="conditionFilter">
                    <option value="all">-- Semua Kondisi --</option>
                    <?php foreach ($conditions_list as $cond): ?>
                        <option value="<?php echo $cond; ?>" <?php echo $condition_filter === $cond ? 'selected' : ''; ?>>
                            <?php echo $cond; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="startDate">Mulai Tanggal</label>
                <input type="date" name="start_date" id="startDate" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            
            <div class="filter-group">
                <label for="endDate">Sampai Tanggal</label>
                <input type="date" name="end_date" id="endDate" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>

            <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($search_query || $condition_filter !== 'all' || $start_date || $end_date || $project_id_filter !== 'all'): ?>
                <a href="admin.php?table=geotags" class="btn" title="Reset Filter" style="background: #e74c3c; color: white; font-weight: 700;"><i class="fas fa-times-circle"></i> Reset</a>
            <?php endif; ?>
        </form>
        <?php endif; ?>

        
        <form id="bulkActionForm" method="post" class="bulk-actions" onsubmit="return handleBulkAction(this);">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <select name="bulk_action_type" id="bulkActionType" required>
                <option value="">-- Pilih Aksi Massal --</option>
                <option value="export_selected" <?php echo $table !== 'geotags' ? 'disabled' : ''; ?>>Export ke CSV (Excel)</option>
                <option value="delete_selected">Hapus yang Dipilih (<?php echo $table === 'geotags' ? 'Hapus Foto Fisik' : 'Hapus'; ?>)</option>
                <?php if ($table === 'geotags'): ?>
                    <option value="mark_synced">Tandai Tersinkron</option>
                <?php endif; ?>
            </select>
            
            <button type="submit" name="bulk_action" class="btn" style="background: #e67e22; color: white; border: none;"><i class="fas fa-tools"></i> Terapkan Aksi</button>

            <div class="info">Pilih item di bawah dengan checkbox</div>

        <div class="data-table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select_all"></th>
                        <?php
                        $cols = array_column($colsMeta, 'Field');
                        foreach ($cols as $c) echo '<th>' . htmlspecialchars(ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $c))) . '</th>';
                        ?>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // --- KONTEN DATA TABEL DENGAN FILTER ---
                $where_clauses = [];
                $query_params = [];
                $is_geotags = $table === 'geotags';

                // Filters applied ONLY if the table is geotags
                if ($is_geotags) {
                    // NEW: Project ID Filter
                    if ($project_id_filter && $project_id_filter !== 'all') {
                        $where_clauses[] = "projectId = ?";
                        $query_params[] = $project_id_filter;
                    }

                    if ($search_query) {
                        $where_clauses[] = "(itemType LIKE ? OR locationName LIKE ?)";
                        $query_params[] = "%$search_query%";
                        $query_params[] = "%$search_query%";
                    }
                    if ($condition_filter && $condition_filter !== 'all') {
                        $where_clauses[] = "`condition` = ?";
                        $query_params[] = $condition_filter;
                    }
                    if ($start_date) {
                        $where_clauses[] = "timestamp >= ?";
                        $query_params[] = $start_date . ' 00:00:00';
                    }
                    if ($end_date) {
                        $where_clauses[] = "timestamp <= ?";
                        $query_params[] = $end_date . ' 23:59:59';
                    }
                }
                
                $where_sql = !empty($where_clauses) ? " WHERE " . implode(' AND ', $where_clauses) : "";
                
                $sql = "SELECT * FROM `$table` $where_sql ORDER BY `$pk` DESC LIMIT 100";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($query_params);
                
                while ($r = $stmt->fetch()) {
                    echo '<tr>';
                    // Checkbox untuk Bulk Action
                    echo '<td><input type="checkbox" name="selected_ids[]" value="' . htmlspecialchars($r[$pk]) . '"></td>';
                    
                    foreach ($cols as $c) {
                        $val = isset($r[$c]) ? $r[$c] : '';
                        
                        if ($table === 'geotags' && $c === 'photoPath' && !empty($val)) {
                            $photo_full_url = (strpos($val, 'http') === 0) ? $val : $photo_base_url . $val;
                            echo '<td><a href="' . $photo_full_url . '" target="_blank"><img src="' . $photo_full_url . '" onerror="this.src=\'//via.placeholder.com/50?text=NO+IMG\';" alt="Foto" class="photo-thumb"></a></td>';
                            continue;
                        }

                        if ($table === 'geotags' && $c === 'isSynced') {
                            $is_synced = (int)$val === 1;
                            $style = $is_synced ? 'color: var(--primary-color);' : 'color: #d35400; font-weight: bold;';
                            $text = $is_synced ? '<i class="fas fa-check-circle"></i> Yes' : '<i class="fas fa-clock"></i> No';
                            echo '<td style="' . $style . '">' . $text . '</td>';
                            continue;
                        }

                        if ($table === 'projects' && $c === 'status') {
                            $is_active = $val === 'Active';
                            $style = $is_active ? 'color: var(--primary-color);' : 'color: #34495e; font-weight: bold;';
                            echo '<td style="' . $style . '">' . htmlspecialchars((string)$val) . '</td>';
                            continue;
                        }
                        
                        echo '<td>' . htmlspecialchars((string)$val) . '</td>';
                    }
                    echo '<td>';
                    echo '<a class="btn" href="?table=' . htmlspecialchars($table) . '&action=edit&id=' . urlencode($r[$pk]) . '"><i class="fas fa-edit"></i> Edit</a> ';
                    echo '<form class="inline" method="post" onsubmit="return confirm(\'Yakin ingin menghapus record ' . htmlspecialchars($table) . ' ID: ' . htmlspecialchars($r[$pk]) . '?\')">';
                    echo '<input type="hidden" name="id" value="' . htmlspecialchars($r[$pk]) . '">'; 
                    echo '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
                    echo '<button name="delete" class="btn" style="background: #e74c3c; color: white; border: none;"><i class="fas fa-trash-alt"></i> Hapus</button>';
                    echo '</form>';
                    echo '</td>';
                    echo '</tr>';
                }
                ?>
                </tbody>
            </table>
        </div>
        </form> <script>
            function handleBulkAction(form) {
                const actionType = document.getElementById('bulkActionType').value;
                const selectedCount = document.querySelectorAll('input[name="selected_ids[]"]:checked').length;
                
                if (selectedCount === 0) {
                    alert('Pilih setidaknya satu item untuk aksi ini.');
                    return false;
                }
                
                if (actionType === 'export_selected') {
                    // Jika aksi adalah EXPORT, ubah target form ke tab baru dan submit
                    form.target = '_blank'; 
                    return true; 
                } 
                
                // Untuk DELETE atau SYNC
                form.target = ''; // Kembali ke tab yang sama
                return confirm(`Yakin ingin ${actionType.replace('_', ' ')} ${selectedCount} item yang dipilih?`);
            }
            
            // Logika Select All untuk Bulk Actions
            document.getElementById('select_all').addEventListener('change', function(e) {
                const checkboxes = document.querySelectorAll('input[name="selected_ids[]"]');
                for (let i = 0; i < checkboxes.length; i++) {
                    checkboxes[i].checked = e.target.checked;
                }
            });

            // Logika disable/enable export untuk tabel non-geotags
            if ('<?php echo $table; ?>' !== 'geotags') {
                document.querySelector('option[value="export_selected"]').disabled = true;
                // document.querySelector('option[value="mark_synced"]').disabled = true; // Sudah di-handle di PHP
            }
        </script>
    <?php endif; ?>

<?php endif; ?>
</div>

</body>
</html>