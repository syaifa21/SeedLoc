<?php
// admin.php
// Admin sederhana untuk CRUD Dual-Tabel: geotags & projects

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
            $_SESSION['auth_time'] = time();
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

// -------------- CRUD HANDLERS (Create, Update, Delete) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF mismatch');
    
    // Pastikan hanya bisa CREATE jika tabelnya adalah 'projects'
    if ($table !== 'projects') die('Aksi CREATE tidak diizinkan untuk tabel ini.');
    
    $fields = [];
    $placeholders = [];
    $values = [];
    
    // Filter kolom yang dikirim dari form
    foreach ($_POST as $k => $v) {
        if (in_array($k, ['create','csrf_token', $pk])) continue;
        if (!in_array($k, ['activityName', 'locationName', 'officers', 'status'])) continue;
        
        $fields[] = "`" . str_replace('`','', $k) . "`";
        $placeholders[] = ':' . $k;
        $values[':' . $k] = $v;
    }
    
    // Validasi dan masukkan projectId dari form
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
    foreach ($_POST as $k => $v) {
        if (in_array($k, ['update','csrf_token',$pk])) continue;
        
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


// ----------------- RENDER HTML DENGAN STYLE INDEX.PHP -------------------
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
        .brand .logo {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: #fff;
            box-shadow: 0 6px 18px rgba(46,125,50,0.15);
            font-size: 22px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
        input[type="text"], textarea, select { 
            width: 100%; 
            padding: 10px; 
            margin-top: 5px; 
            border: 1px solid #ccc; 
            border-radius: 6px;
            font-size: 15px;
            transition: border-color 0.2s;
        }
        input[type="text"]:focus, textarea:focus, select:focus {
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
    </style>
</head>
<body>

<div class="container" role="main">

<?php if ($action === 'login'): ?>
    <div class="form-card" style="margin: 50px auto;">
        <h2><i class="fas fa-lock"></i> Admin Login</h2>
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
    <header class="app-header" role="banner">
        <div class="brand">
            <div class="logo"><i class="fas fa-user-shield"></i></div>
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
        $id = $_GET['id'] ?? '';
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
                    $val = $row[$colName] ?? '';
                    $isPk = ($colName === $pk);
                    // Kolom yang bersifat read-only / otomatis
                    $isReadOnly = $isPk || in_array($colName, ['created_at']);
                    // Tentukan apakah ini kolom isSynced atau status (untuk projects)
                    $isSelect = ($colName === 'isSynced' && $table === 'geotags') || ($colName === 'status' && $table === 'projects');

                    // Lewati primary key, sudah ditangani sebagai hidden input di atas, hanya tampilkan teks
                    if ($isPk) {
                        echo "<label><strong>" . htmlspecialchars(ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $colName))) . ":</strong> <span class='warning'>" . htmlspecialchars($val) . "</span></label>";
                        continue;
                    }

                    // Tampilan Photo Path (hanya untuk geotags)
                    if ($table === 'geotags' && $colName === 'photoPath' && !empty($val)): 
                        $photo_full_url = (strpos($val, 'http') === 0) ? $val : $photo_base_url . $val;
                    ?>
                        <label>
                            Photo Path:
                            <input type="text" name="<?php echo htmlspecialchars($colName); ?>" value="<?php echo htmlspecialchars($val); ?>">
                            <a href="<?php echo $photo_full_url; ?>" target="_blank" style="margin-top: 10px; display: inline-block;"><i class="fas fa-external-link-alt"></i> Lihat Foto</a>
                            <img src="<?php echo $photo_full_url; ?>" alt="Foto" class="photo-thumb" style="width: 100px; height: 100px; display: block; margin-top: 10px;">
                        </label>
                    <?php 
                        continue;
                    endif;

                    // Tampilan Select (isSynced atau status)
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

                    // Tampilan Default
                ?>
                    <label>
                        <?php echo htmlspecialchars(ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $colName))); ?>:
                        <?php if ($isReadOnly): ?>
                             <input type="text" name="<?php echo htmlspecialchars($colName); ?>" value="<?php echo htmlspecialchars($val); ?>" readonly>
                             <span class="warning">(Otomatis/Tidak dapat diedit)</span>
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
        <h2 class="page-title"><i class="fas fa-table"></i> Daftar Data `<?php echo htmlspecialchars($table); ?>`</h2>
        <div class="data-table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <?php
                        $cols = array_column($colsMeta, 'Field');
                        foreach ($cols as $c) echo '<th>' . htmlspecialchars(ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $c))) . '</th>';
                        ?>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $stmt = $pdo->query("SELECT * FROM `$table` ORDER BY `$pk` DESC LIMIT 100");
                while ($r = $stmt->fetch()) {
                    echo '<tr>';
                    foreach ($cols as $c) {
                        $val = isset($r[$c]) ? $r[$c] : '';
                        
                        // Display for photoPath (only for geotags)
                        if ($table === 'geotags' && $c === 'photoPath' && !empty($val)) {
                            $photo_full_url = (strpos($val, 'http') === 0) ? $val : $photo_base_url . $val;
                            echo '<td><a href="' . $photo_full_url . '" target="_blank"><img src="' . $photo_full_url . '" onerror="this.src=\'//via.placeholder.com/50?text=NO+IMG\';" alt="Foto" class="photo-thumb"></a></td>';
                            continue;
                        }

                        // Display for isSynced (only for geotags)
                        if ($table === 'geotags' && $c === 'isSynced') {
                            $is_synced = (int)$val === 1;
                            $style = $is_synced ? 'color: var(--primary-color);' : 'color: #d35400; font-weight: bold;';
                            $text = $is_synced ? '<i class="fas fa-check-circle"></i> Yes' : '<i class="fas fa-clock"></i> No';
                            echo '<td style="' . $style . '">' . $text . '</td>';
                            continue;
                        }

                        // Display for status (only for projects)
                        if ($table === 'projects' && $c === 'status') {
                            $is_active = $val === 'Active';
                            $style = $is_active ? 'color: var(--primary-color);' : 'color: #34495e; font-weight: bold;';
                            echo '<td style="' . $style . '">' . htmlspecialchars((string)$val) . '</td>';
                            continue;
                        }
                        
                        // Default text display
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
    <?php endif; ?>

<?php endif; ?>
</div>

</body>
</html>