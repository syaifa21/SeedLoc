<?php
// Dashboard/export_geotags.php - Script untuk mengkonversi data geotag terpilih ke format CSV (Excel)

// Gunakan konfigurasi dan koneksi database yang sama
require_once 'api/config.php';
require_once 'api/db.php';

// Atur sesi (diperlukan untuk keamanan dan konteks admin)
session_start();

// Cek autentikasi sederhana
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(403);
    die("Akses ditolak. Anda harus login sebagai Admin.");
}

// --- 1. KONEKSI KE DATABASE ---
try {
    $db = new Database();
    $conn = $db->getConnection();
} catch (Exception $e) {
    die("Database connection failed for export: " . $e->getMessage());
}

// --- 2. LOGIKA EXPORT ---
$selected_ids = $_POST['selected_ids'] ?? [];

if (empty($selected_ids)) {
    die("Tidak ada Geotag yang dipilih untuk diekspor.");
}

$placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

// Ambil data dari database berdasarkan ID yang dipilih
$sql = "SELECT id, projectId, latitude, longitude, locationName, timestamp, itemType, `condition`, details, photoPath, isSynced, deviceId FROM geotags WHERE id IN ($placeholders) ORDER BY id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($selected_ids);
$geotags = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($geotags)) {
    die("Data Geotag tidak ditemukan untuk ID yang dipilih.");
}

// Nama file output (menggunakan tanggal)
$filename = "seedloc_geotags_selected_" . date('Ymd_His') . ".csv";

// Set header untuk file CSV (memaksa download dan format Excel)
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// URL dasar untuk foto
$photo_base_url = 'https://seedloc.my.id/api/';

// Menulis header kolom CSV
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
    'Photo Path (Full URL)', // Diubah agar URL lengkap
    'Is Synced',
    'Device ID'
]);

// Menulis baris data
foreach ($geotags as $row) {
    $photo_path = $row['photoPath'];
    
    // Pastikan path foto adalah URL lengkap
    if (!empty($photo_path) && strpos($photo_path, 'http') !== 0) {
        $row['photoPath'] = $photo_base_url . $photo_path; 
    }
    
    // Konversi array asosiatif menjadi indexed array untuk fputcsv
    fputcsv($output, array_values($row));
}

fclose($output);
exit;
?>