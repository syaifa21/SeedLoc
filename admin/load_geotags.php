<?php
// load_geotags.php (FULL CODE)

// 1. Naikkan Limit Waktu & Memori (Khusus untuk query berat seperti Duplicate Check)
set_time_limit(300); // 5 Menit max execution time
ini_set('memory_limit', '512M');

// 2. Load Config & Functions
require_once __DIR__ . '/config.php';   
require_once __DIR__ . '/functions.php';

// Atur Header agar browser tahu ini JSON (mencegah parsing error)
header('Content-Type: application/json');

// 3. Auth Check
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access. Silakan login ulang.']);
    exit;
}

// 4. Setup Pagination
$page = (int)($_GET['page'] ?? 1);
$per_page = 100; 
if ($page < 1) $page = 1;
$offset = ($page - 1) * $per_page;

try {
    // Ambil Filter dari Functions.php
    list($where, $params) = buildWhere('geotags', $pdo);
    $w_sql = $where ? "WHERE " . implode(' AND ', $where) : "";

    // Query Hitung Total (Optimized Count)
    $count_sql = "SELECT COUNT(geotags.id) FROM geotags LEFT JOIN projects ON geotags.projectId = projects.projectId $w_sql";
    $stmt = $pdo->prepare($count_sql);
    if (!empty($where)) $stmt->execute($params); else $stmt->execute();
    $total_rows = $stmt->fetchColumn();
    $total_pages = ceil($total_rows / $per_page);

    // Query Data Utama
    $sql = "SELECT geotags.id, geotags.projectId, geotags.itemType, geotags.locationName, geotags.latitude, geotags.longitude, 
                   geotags.timestamp, geotags.condition, geotags.photoPath, geotags.details, 
                   projects.officers 
            FROM geotags 
            LEFT JOIN projects ON geotags.projectId = projects.projectId 
            $w_sql 
            ORDER BY geotags.id DESC 
            LIMIT $per_page OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    $formatted_data = [];
    $no_urut = $offset + 1;

    foreach($data as $row) {
        // --- FIX 401 UNAUTHORIZED IMAGE ---
        // Cek ekstensi file. Jika bukan gambar, kosongkan URL agar tidak error.
        $rawPath = trim($row['photoPath'] ?? '');
        $photoUrl = '';
        $ext = strtolower(pathinfo($rawPath, PATHINFO_EXTENSION));
        
        if (!empty($rawPath) && in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            $photoUrl = get_photo_url($rawPath, $photo_base_url);
        }

        // --- BADGE STYLE ---
        $badgeStyle = '';
        if($row['condition'] == 'Hidup' || $row['condition'] == 'Baik') { $badgeStyle = 'color:#2E7D32;background:#e8f5e9;'; } 
        elseif($row['condition'] == 'Merana') { $badgeStyle = 'color:#856404;background:#fff3cd;'; } 
        else { $badgeStyle = 'color:#c62828;background:#ffebee;'; }

        $formatted_data[] = [
            'no' => $no_urut++,
            'id' => $row['id'],
            'photoUrl' => $photoUrl,
            'itemType' => htmlspecialchars($row['itemType']),
            'officers' => htmlspecialchars($row['officers'] ?? '-'),
            'locationName' => htmlspecialchars($row['locationName']),
            'lat' => substr($row['latitude'], 0, 8),
            'lng' => substr($row['longitude'], 0, 9),
            'date' => substr($row['timestamp'], 0, 10),
            'condition' => $row['condition'],
            'badgeStyle' => $badgeStyle,
            'raw' => $row // Untuk Modal Detail
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data' => $formatted_data,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_rows' => $total_rows
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server Error: ' . $e->getMessage()]);
}
?>