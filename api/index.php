<?php
// Recode of syaifa21/seedloc/SeedLoc-8b0dc11b592d9ddd5102231ff18005b178492a54/api/index.php

require_once 'config.php';
require_once 'db.php';

// Initialize DB connection and gracefully handle connection error by returning JSON.
try {
    $db = new Database();
    $conn = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API Configuration Error: Database connection failed.',
        'details' => $e->getMessage()
    ]);
    exit();
}

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];

// TRIM trailing/leading slash for robust routing: /geotags/ -> geotags
$path = isset($_GET['path']) ? trim($_GET['path'], '/') : '';

// Route requests
switch($path) {
    case '':
    case 'status':
        // API Status
        echo json_encode([
            'status' => 'online',
            'message' => 'SeedLoc API is running',
            'version' => '1.0',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        break;
        
    case 'projects':
        if ($method === 'GET') {
            // Get all projects
            $stmt = $conn->query("SELECT * FROM projects ORDER BY projectId DESC");
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $projects]);
        } elseif ($method === 'POST') {
            // --- MODIFIKASI: MENGGUNAKAN UPSERT (INSERT OR UPDATE) ---
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Basic validation check
            if (!isset($data['projectId'], $data['activityName'], $data['locationName'], $data['officers'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid project data provided']);
                break;
            }
            
            // Menggunakan INSERT ... ON DUPLICATE KEY UPDATE (UPSERT)
            // Ini akan membuat project jika baru, atau mengupdate jika sudah ada.
            $sql = "INSERT INTO projects (projectId, activityName, locationName, officers, status) 
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        activityName = VALUES(activityName),
                        locationName = VALUES(locationName),
                        officers = VALUES(officers),
                        status = VALUES(status)";
                        
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $data['projectId'],
                $data['activityName'],
                $data['locationName'],
                $data['officers'],
                $data['status'] ?? 'Active'
            ]);
            
            // Pesan disesuaikan karena bisa berupa insert atau update/sync
            echo json_encode(['success' => true, 'message' => 'Project synced']);
        }
        break;
        
    case 'geotags':
        if ($method === 'GET') {
            // Get geotags (optionally by projectId)
            $projectId = isset($_GET['projectId']) ? $_GET['projectId'] : null;
            if ($projectId) {
                $stmt = $conn->prepare("SELECT * FROM geotags WHERE projectId = ? ORDER BY id DESC");
                $stmt->execute([$projectId]);
            } else {
                $stmt = $conn->query("SELECT * FROM geotags ORDER BY id DESC");
            }
            $geotags = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $geotags]);
        } elseif ($method === 'POST') {
            // Sync geotag
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Check if it's bulk sync
            if (isset($data['geotags']) && is_array($data['geotags'])) {
                // Bulk sync
                $synced = 0;
                // Start transaction for bulk performance
                $conn->beginTransaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO geotags (projectId, latitude, longitude, locationName, timestamp, itemType, `condition`, details, photoPath, isSynced, deviceId) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
                    foreach ($data['geotags'] as $geotag) {
                        // Minimal data validation for required fields
                        if (!isset($geotag['projectId'], $geotag['latitude'], $geotag['longitude'], $geotag['timestamp'], $geotag['itemType'], $geotag['condition'], $geotag['deviceId'])) {
                            // Skip invalid data in bulk
                            continue;
                        }
                        
                        $stmt->execute([
                            $geotag['projectId'],
                            $geotag['latitude'],
                            $geotag['longitude'],
                            $geotag['locationName'] ?? '',
                            $geotag['timestamp'],
                            $geotag['itemType'],
                            $geotag['condition'],
                            $geotag['details'] ?? '',
                            $geotag['photoPath'] ?? '',
                            $geotag['deviceId']
                        ]);
                        $synced++;
                    }
                    $conn->commit();
                    echo json_encode(['success' => true, 'message' => "$synced geotags synced"]);
                } catch (Exception $e) {
                    $conn->rollBack();
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Bulk sync failed due to database error: ' . $e->getMessage()]);
                }
            } else {
                // Single sync (Dipakai saat upload foto sukses)
                // Basic validation check
                if (!isset($data['projectId'], $data['latitude'], $data['longitude'], $data['timestamp'], $data['itemType'], $data['condition'], $data['deviceId'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid geotag data provided']);
                    break;
                }
                
                $stmt = $conn->prepare("INSERT INTO geotags (projectId, latitude, longitude, locationName, timestamp, itemType, `condition`, details, photoPath, isSynced, deviceId) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
                $stmt->execute([
                    $data['projectId'],
                    $data['latitude'],
                    $data['longitude'],
                    $data['locationName'] ?? '',
                    $data['timestamp'],
                    $data['itemType'],
                    $data['condition'],
                    $data['details'] ?? '',
                    $data['photoPath'] ?? '',
                    $data['deviceId']
                ]);
                echo json_encode(['success' => true, 'message' => 'Geotag synced', 'id' => $conn->lastInsertId()]);
            }
        }
        break;
        
    case 'upload':
        // Handle photo upload (TIPE FILE DIHAPUS, MENGGUNAKAN NAMA DARI KLIEN)
        if ($method === 'POST' && isset($_FILES['photo'])) {
            $uploadDir = 'uploads/';
            
            // 1. PENANGANAN DIREKTORI & IZIN
            if (!file_exists($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) { // Mencoba membuat direktori
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Upload failed: Could not create upload directory. Check server permissions.']);
                    break;
                }
            } else if (!is_writable($uploadDir)) {
                // Memeriksa apakah direktori bisa ditulisi (writable)
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Upload failed: Upload directory is not writable. Ensure folder permissions are 755 or 775.']);
                break;
            }
            
            $file = $_FILES['photo'];
            
            // 2. PENANGANAN UPLOAD ERROR BAWAAN PHP
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errorMessage = 'Upload failed.';
                switch ($file['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $errorMessage = 'Upload failed: File size exceeds the maximum allowed size (check php.ini).';
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $errorMessage = 'Upload failed: File was only partially uploaded.';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $errorMessage = 'Upload failed: No file was sent.';
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $errorMessage = 'Upload failed: Missing a temporary folder on the server.';
                        break;
                    default:
                        $errorMessage = 'Unknown upload error.';
                }
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $errorMessage . ' (Code: ' . $file['error'] . ')']);
                break;
            }
            
            // 3. MENGGUNAKAN NAMA FILE DARI KLIEN UNTUK EXTENSION
            // Perhatian: Flutter mengirim nama file dengan format "customFileName.jpg"
            $fileName = $file['name']; 
            $targetPath = $uploadDir . $fileName;
            
            // 4. MEMINDAHKAN FILE UPLOAD
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Photo uploaded successfully.',
                    'path' => $targetPath,
                    // URL disesuaikan dengan domain Anda
                    'url' => 'https://seedloc.my.id/api/' . $targetPath
                ]);
            } else {
                // Penanganan jika move_uploaded_file gagal karena alasan lain
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Upload failed: Server could not finalize file move operation.']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No photo provided or invalid request method.']);
        }
        break;
        
    case 'stats':
        // Get statistics
        $stats = [];
        
        // Total projects
        $stmt = $conn->query("SELECT COUNT(*) as total FROM projects");
        $stats['totalProjects'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Total geotags
        $stmt = $conn->query("SELECT COUNT(*) as total FROM geotags");
        $stats['totalGeotags'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Synced geotags
        $stmt = $conn->query("SELECT COUNT(*) as total FROM geotags WHERE isSynced = 1");
        $stats['syncedGeotags'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        echo json_encode(['success' => true, 'data' => $stats]);
        break;
        
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
        break;
}
?>