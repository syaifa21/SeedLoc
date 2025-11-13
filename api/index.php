<?php
require_once 'config.php';
require_once 'db.php';

$db = new Database();
$conn = $db->getConnection();

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$path = isset($_GET['path']) ? $_GET['path'] : '';

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
            // Create new project
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $conn->prepare("INSERT INTO projects (projectId, activityName, locationName, officers, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['projectId'],
                $data['activityName'],
                $data['locationName'],
                $data['officers'],
                $data['status'] ?? 'Active'
            ]);
            echo json_encode(['success' => true, 'message' => 'Project created']);
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
                foreach ($data['geotags'] as $geotag) {
                    $stmt = $conn->prepare("INSERT INTO geotags (projectId, latitude, longitude, locationName, timestamp, itemType, `condition`, details, photoPath, isSynced, deviceId) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
                    $stmt->execute([
                        $geotag['projectId'],
                        $geotag['latitude'],
                        $geotag['longitude'],
                        $geotag['locationName'],
                        $geotag['timestamp'],
                        $geotag['itemType'],
                        $geotag['condition'],
                        $geotag['details'],
                        $geotag['photoPath'] ?? '',
                        $geotag['deviceId']
                    ]);
                    $synced++;
                }
                echo json_encode(['success' => true, 'message' => "$synced geotags synced"]);
            } else {
                // Single sync
                $stmt = $conn->prepare("INSERT INTO geotags (projectId, latitude, longitude, locationName, timestamp, itemType, `condition`, details, photoPath, isSynced, deviceId) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
                $stmt->execute([
                    $data['projectId'],
                    $data['latitude'],
                    $data['longitude'],
                    $data['locationName'],
                    $data['timestamp'],
                    $data['itemType'],
                    $data['condition'],
                    $data['details'],
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
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileName = time() . '_' . basename($_FILES['photo']['name']);
            $targetPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Photo uploaded',
                    'path' => $targetPath,
                    'url' => 'https://seedloc.my.id/api/' . $targetPath
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Upload failed']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'No photo provided']);
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
