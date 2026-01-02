<?php
// config.php
session_start();

// --- DATABASE CONNECTION ---
$db_file = __DIR__ . '/api/db.php';
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

// --- CONFIG PATHS ---
$photo_base_url = 'https://seedloc.my.id/api/'; 
$upload_dir = realpath(__DIR__ . '/api/uploads') . DIRECTORY_SEPARATOR;
if (!$upload_dir || !file_exists($upload_dir)) { $upload_dir = __DIR__ . '/api/uploads/'; }

$kml_file_path = $upload_dir . 'admin_layer.kml'; 
$kml_url_path = 'api/uploads/admin_layer.kml'; 

// --- METADATA ---
$metadata_path = realpath(__DIR__ . '/api/metadata.php');
if ($metadata_path && file_exists($metadata_path)) {
    $metadata = require $metadata_path;
    $tree_types = $metadata['treeTypes'] ?? [];
    $locations_list = $metadata['locations'] ?? [];
} else {
    $tree_types = ['Metadata File Not Found'];
    $locations_list = ['Metadata File Not Found'];
}

// --- CONTROLLER VARS ---
$action = $_GET['action'] ?? 'dashboard';
$table = $_GET['table'] ?? 'geotags';
if ($action === 'users') { $table = 'admin_users'; }
$pk = ($table === 'projects') ? 'projectId' : 'id'; 

// --- AUTH HELPERS ---
if (isset($_SESSION['auth']) && $_SESSION['auth'] === true) {
    if (isset($_SESSION['auth_time']) && (time() - $_SESSION['auth_time'] > 7200)) { session_unset(); session_destroy(); header('Location: ?action=login&timeout=1'); exit; }
    $_SESSION['auth_time'] = time();
}
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function require_auth() { if (!isset($_SESSION['auth']) || !$_SESSION['auth']) { header('Location: ?action=login'); exit; } }
function is_admin() { return (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'Admin'); }
?>