<?php
// admin.php
// Admin sederhana untuk CRUD penuh terhadap tabel `projects` (sesuaikan nama tabel/kolom jika perlu)
// Ketentuan awal (diubah): saat membuka halaman pertama kali, user harus memasukkan password.
// Password yang valid adalah "ali210103" — **tetapi password tidak disimpan dalam bentuk teks** di file ini.
// Sebagai gantinya kita menyimpan hash SHA-256 dari password, sehingga nilai aslinya tidak tampak di kode.

session_start();

// ------------------ CONFIG ------------------
// Ganti sesuai konfigurasi database Anda
$db_host = 'localhost';
$db_name = 'seedlocm_apk';
$db_user = 'seedlocm_ali';
$db_pass = 'alialiali123!';
$dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";

// Nama tabel yang akan di-CRUD (contoh: projects)
$table = 'projects';
// Kolom primary key pada tabel
$pk = 'id';

// ------------------ HIDDEN PASSWORD ------------------
// Saya menyimpan SHA-256 dari password "ali210103" di bawah ini.
// Nilai plaintext tidak tertulis di file ini.
$PASSWORD_HASH = 'e0a4cb68ee74255ea69548de2d27e40aa5aaaed8b0b14bb0caab9f9124cc6b64';
// Untuk mengganti password, Anda dapat membuat hash baru (mis. dengan `hash('sha256', 'kata_baru')`) lalu simpan nilai hexnya di variabel di atas.
// Catatan: SHA-256 digunakan demi kesederhanaan; untuk keamanan lebih baik gunakan mekanisme password hashing yang lebih kuat seperti password_hash()/password_verify() dan simpan di file konfigurasi yang aman (di luar webroot) atau di environment variable.
// --------------------------------------------

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Koneksi DB gagal: " . htmlspecialchars($e->getMessage()));
}

// CSRF sederhana
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
        // bandingkan hash (tidak menyimpan password plaintext di file ini)
        $input_hash = hash('sha256', $input);
        if (hash_equals($input_hash, $PASSWORD_HASH)) {
            // login berhasil
            $_SESSION['auth'] = true;
            // optional: simpan waktu login
            $_SESSION['auth_time'] = time();
            header('Location: admin.php');
            exit;
        } else {
            $login_error = 'Password salah.';
        }
    }
}

// Jika bukan login, semua route berikut memerlukan autentikasi
$action = $_GET['action'] ?? 'list';

if ($action !== 'login') {
    require_auth();
}

// -------------- CRUD HANDLERS ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF mismatch');
    $fields = [];
    $placeholders = [];
    $values = [];

    foreach ($_POST as $k => $v) {
        if (in_array($k, ['create','csrf_token'])) continue;
        $fields[] = "`" . str_replace('`','', $k) . "`";
        $placeholders[] = ':' . $k;
        $values[':' . $k] = $v;
    }

    if (empty($fields)) {
        $error = 'Tidak ada data yang dikirim.';
    } else {
        $sql = "INSERT INTO `$table` (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        header('Location: admin.php');
        exit;
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
        $sets[] = "`" . str_replace('`','',$k) . "` = :" . $k;
        $values[':' . $k] = $v;
    }
    $values[':pkval'] = $id;
    if (empty($sets)) {
        $error = 'Tidak ada perubahan.';
    } else {
        $sql = "UPDATE `$table` SET " . implode(',', $sets) . " WHERE `$pk` = :pkval";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        header('Location: admin.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF mismatch');
    $id = $_POST['id'] ?? '';
    if ($id === '') die('ID kosong');
    $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = :id");
    $stmt->execute([':id' => $id]);
    header('Location: admin.php');
    exit;
}

// ----------------- RENDER -------------------
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin CRUD - <?php echo htmlspecialchars($table); ?></title>
    <style>
        body{font-family: Arial, Helvetica, sans-serif; margin:20px}
        table{border-collapse:collapse;width:100%}
        th,td{border:1px solid #ddd;padding:8px}
        th{background:#f4f4f4}
        form.inline{display:inline}
        .topbar{display:flex;justify-content:space-between;align-items:center}
        .btn{padding:6px 10px;border-radius:4px;border:1px solid #888;background:#eee;text-decoration:none}
    </style>
</head>
<body>
<?php if ($action === 'login'): ?>
    <h2>Login Admin</h2>
    <p>Masukkan password untuk mengakses panel admin.</p>
    <?php if (!empty($login_error)): ?><div style="color:red"><?php echo htmlspecialchars($login_error); ?></div><?php endif; ?>
    <form method="post">
        <label>Password: <input type="password" name="password" autocomplete="off"></label>
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <button type="submit" name="login">Masuk</button>
    </form>

<?php else: ?>
    <div class="topbar">
        <h2>Admin: Tabel <?php echo htmlspecialchars($table); ?></h2>
        <div>
            <a class="btn" href="?action=logout">Logout</a>
        </div>
    </div>

    <?php if (!empty($error)): ?><div style="color:red"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php if ($action === 'create'): ?>
        <h3>Buat Record Baru</h3>
        <form method="post">
            <label>id (jika PK auto_increment kosongkan): <input name="id"></label><br>
            <label>name: <input name="name"></label><br>
            <label>description: <textarea name="description"></textarea></label><br>
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <button name="create">Simpan</button>
        </form>
        <p><a href="admin.php">Kembali ke daftar</a></p>

    <?php elseif ($action === 'edit'):
        $id = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$pk` = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) { echo '<div>Record tidak ditemukan.</div><p><a href="admin.php">Kembali</a></p>'; }
        else {
    ?>
        <h3>Edit Record <?php echo htmlspecialchars($id); ?></h3>
        <form method="post">
            <input type="hidden" name="<?php echo $pk; ?>" value="<?php echo htmlspecialchars($row[$pk]); ?>">
            <label>name: <input name="name" value="<?php echo htmlspecialchars($row['name'] ?? ''); ?>"></label><br>
            <label>description: <textarea name="description"><?php echo htmlspecialchars($row['description'] ?? ''); ?></textarea></label><br>
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <button name="update">Update</button>
        </form>
        <p><a href="admin.php">Kembali ke daftar</a></p>
    <?php } ?>

    <?php else: // list view ?>
        <p><a class="btn" href="?action=create">Tambah Record</a></p>
        <table>
            <thead>
                <tr>
                    <?php
                    // ambil kolom tabel
                    $colsStmt = $pdo->query("DESCRIBE `$table`");
                    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($cols as $c) echo '<th>' . htmlspecialchars($c) . '</th>';
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
                    echo '<td>' . htmlspecialchars((string)$val) . '</td>';
                }
                echo '<td>';
                echo '<a href="?action=edit&id=' . urlencode($r[$pk]) . '">Edit</a> | ';
                echo '<form class="inline" method="post" onsubmit="return confirm(\'Yakin mau dihapus?\')">';
                echo '<input type="hidden" name="id" value="' . htmlspecialchars($r[$pk]) . '">';
                echo '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
                echo '<button name="delete">Hapus</button>';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php endif; ?>
</body>
</html>
