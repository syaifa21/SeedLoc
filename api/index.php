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
                // Single sync
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
        // Handle photo upload
        if ($method === 'POST' && isset($_FILES['photo'])) {
            $uploadDir = 'uploads/';
            // Ensure permissions are set for the directory
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true); // Changed 0777 to 0755 for better security
            }
            
            // Validate file type (basic check)
            $allowedTypes = ['image/jpeg', 'image/png'];
            if (!in_array($_FILES['photo']['type'], $allowedTypes)) {
                 http_response_code(415); // Unsupported Media Type
                 echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPEG and PNG allowed.']);
                 break;
            }
            
            $fileExtension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $fileName = time() . '_' . uniqid() . '.' . $fileExtension; // Added uniqid for better uniqueness
            $targetPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Photo uploaded',
                    'path' => $targetPath,
                    'url' => 'https://seedloc.my.id/api/' . $targetPath
                ]);
            } else {
                // Check if file size exceeds limit or other server issues
                $error = $_FILES['photo']['error'];
                $message = 'Upload failed (Code: ' . $error . ')';
                if ($error == UPLOAD_ERR_INI_SIZE) {
                    $message = 'Upload failed: File size too large for PHP limit.';
                }
                echo json_encode(['success' => false, 'message' => $message]);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No photo provided or invalid request method']);
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