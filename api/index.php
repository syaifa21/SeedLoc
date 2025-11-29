<?php
// api/index.php - Tahap 6: Centralized Metadata

require_once 'config.php';
require_once 'db.php';

// --- LOAD METADATA DARI FILE TERPISAH ---
$metadata = require 'metadata.php'; // Load array dari file

// --- 1. CONFIG SECURITY ---
$API_KEY_SECRET = 'SeedLoc_Secret_Key_2025_Secure';

function isAuthorized() {
    global $API_KEY_SECRET;
    $headers = getallheaders();
    $clientKey = $headers['X-API-KEY'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
    return $clientKey === $API_KEY_SECRET;
}

// --- 2. IMAGE COMPRESSION ---
function compressAndResizeImage($source, $destination, $quality, $maxWidth) {
    $info = getimagesize($source);
    if(!$info) return false;
    $mime = $info['mime'];

    if ($mime == 'image/jpeg') $image = imagecreatefromjpeg($source);
    elseif ($mime == 'image/png') $image = imagecreatefrompng($source);
    else return false;

    $width = imagesx($image);
    $height = imagesy($image);
    
    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = floor($height * ($maxWidth / $width));
        $image_p = imagecreatetruecolor($newWidth, $newHeight);
        if ($mime == 'image/png') {
            imagealphablending($image_p, false);
            imagesavealpha($image_p, true);
        }
        imagecopyresampled($image_p, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        $image = $image_p;
    }

    if ($mime == 'image/png') imagepng($image, $destination, 8); 
    else imagejpeg($image, $destination, $quality);
    
    imagedestroy($image);
    return true;
}

// --- 3. INIT DB ---
try {
    $db = new Database();
    $conn = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500); echo json_encode(['success' => false, 'message' => 'DB Error']); exit();
}

// --- 4. ROUTING ---
$method = $_SERVER['REQUEST_METHOD'];
$path = isset($_GET['path']) ? trim($_GET['path'], '/') : '';

if ($path !== '' && $path !== 'status' && !isAuthorized()) {
    http_response_code(401); die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

switch($path) {
    case '':
    case 'status':
        echo json_encode(['status' => 'online', 'version' => '1.1','time' => date('Y-m-d H:i:s'),'Sinkronisasi Berhasil']);
        break;

    case 'meta':
        // CUKUP RETURN VARIABLE $metadata YANG SUDAH DI-INCLUDE DI ATAS
        if ($method === 'GET') {
            echo json_encode(['success' => true, 'data' => $metadata]);
        }
        break;
        
    case 'projects':
        if ($method === 'GET') {
            $stmt = $conn->query("SELECT * FROM projects ORDER BY projectId DESC");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } elseif ($method === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            $sql = "INSERT INTO projects (projectId, activityName, locationName, officers, status) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE activityName=VALUES(activityName), locationName=VALUES(locationName), officers=VALUES(officers), status=VALUES(status)";
            $conn->prepare($sql)->execute([$data['projectId'], $data['activityName'], $data['locationName'], $data['officers'], $data['status']??'Active']);
            echo json_encode(['success' => true, 'message' => 'Project synced']);
        }
        break;
        
    case 'geotags':
        if ($method === 'GET') {
            $sql = isset($_GET['projectId']) ? "SELECT * FROM geotags WHERE projectId = ? ORDER BY id DESC" : "SELECT * FROM geotags ORDER BY id DESC";
            $stmt = $conn->prepare($sql);
            isset($_GET['projectId']) ? $stmt->execute([$_GET['projectId']]) : $stmt->execute();
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } elseif ($method === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            $sql = "INSERT INTO geotags (projectId, latitude, longitude, locationName, timestamp, itemType, `condition`, details, photoPath, isSynced, deviceId) VALUES (?,?,?,?,?,?,?,?,?,1,?)";
            $stmt = $conn->prepare($sql);
            
            if (isset($data['geotags'])) {
                $conn->beginTransaction();
                try {
                    foreach($data['geotags'] as $g) $stmt->execute([$g['projectId'], $g['latitude'], $g['longitude'], $g['locationName']??'', $g['timestamp'], $g['itemType'], $g['condition'], $g['details']??'', $g['photoPath']??'', $g['deviceId']]);
                    $conn->commit();
                    echo json_encode(['success' => true, 'message' => 'Bulk synced']);
                } catch(Exception $e) { $conn->rollBack(); http_response_code(500); echo json_encode(['success'=>false]); }
            } else {
                $stmt->execute([$data['projectId'], $data['latitude'], $data['longitude'], $data['locationName']??'', $data['timestamp'], $data['itemType'], $data['condition'], $data['details']??'', $data['photoPath']??'', $data['deviceId']]);
                echo json_encode(['success' => true, 'id' => $conn->lastInsertId()]);
            }
        }
        break;
        
    case 'upload':
        if ($method === 'POST' && isset($_FILES['photo'])) {
            $uploadDir = 'uploads/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
            $file = $_FILES['photo'];
            $targetPath = $uploadDir . $file['name'];
            if(getimagesize($file['tmp_name'])) {
                if (compressAndResizeImage($file['tmp_name'], $targetPath, 75, 1000)) {
                    echo json_encode(['success' => true, 'path' => $targetPath, 'url' => 'https://seedloc.my.id/api/' . $targetPath]);
                } else {
                    if(move_uploaded_file($file['tmp_name'], $targetPath)) echo json_encode(['success' => true, 'path' => $targetPath]);
                    else { http_response_code(500); echo json_encode(['success' => false]); }
                }
            } else { http_response_code(400); echo json_encode(['success' => false]); }
        }
        break;
        
    case 'stats':
        $stats = [
            'totalProjects' => $conn->query("SELECT COUNT(*) FROM projects")->fetchColumn(),
            'totalGeotags' => $conn->query("SELECT COUNT(*) FROM geotags")->fetchColumn(),
            'syncedGeotags' => $conn->query("SELECT COUNT(*) FROM geotags WHERE isSynced=1")->fetchColumn()
        ];
        echo json_encode(['success' => true, 'data' => $stats]);
        break;
        
    default:
        http_response_code(404); echo json_encode(['error' => 'Not Found']); break;
}
?>