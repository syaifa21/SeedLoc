<?php
require_once 'config.php';
require_once 'db.php';

echo "<h1>SeedLoc API Test</h1>";

// Test 1: Database Connection
echo "<h2>1. Database Connection Test</h2>";
try {
    $db = new Database();
    $conn = $db->getConnection();
    echo "<p style='color:green'>✓ Database connection successful!</p>";
    
    // Show database info
    echo "<p>Database: " . DB_NAME . "</p>";
    echo "<p>Host: " . DB_HOST . ":" . DB_PORT . "</p>";
    echo "<p>User: " . DB_USER . "</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Database connection failed: " . $e->getMessage() . "</p>";
    exit;
}

// Test 2: Check Tables
echo "<h2>2. Database Tables Test</h2>";
try {
    $stmt = $conn->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($tables) > 0) {
        echo "<p style='color:green'>✓ Tables found: " . implode(", ", $tables) . "</p>";
        
        // Check if required tables exist
        $requiredTables = ['projects', 'geotags'];
        $missingTables = array_diff($requiredTables, $tables);
        
        if (empty($missingTables)) {
            echo "<p style='color:green'>✓ All required tables exist</p>";
        } else {
            echo "<p style='color:orange'>⚠ Missing tables: " . implode(", ", $missingTables) . "</p>";
            echo "<p>Please import database.sql</p>";
        }
    } else {
        echo "<p style='color:orange'>⚠ No tables found. Please import database.sql</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Error checking tables: " . $e->getMessage() . "</p>";
}

// Test 3: Count Records
echo "<h2>3. Data Count Test</h2>";
try {
    // Count projects
    $stmt = $conn->query("SELECT COUNT(*) as total FROM projects");
    $projectCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "<p>Projects: $projectCount</p>";
    
    // Count geotags
    $stmt = $conn->query("SELECT COUNT(*) as total FROM geotags");
    $geotagCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "<p>Geotags: $geotagCount</p>";
    
    echo "<p style='color:green'>✓ Data count successful</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Error counting data: " . $e->getMessage() . "</p>";
}

// Test 4: API Endpoints
echo "<h2>4. API Endpoints Test</h2>";
$baseUrl = 'https://seedloc.my.id/api';
echo "<p>Base URL: $baseUrl</p>";
echo "<ul>";
echo "<li><a href='$baseUrl/' target='_blank'>Status</a> - GET $baseUrl/</li>";
echo "<li><a href='$baseUrl/projects' target='_blank'>Projects</a> - GET $baseUrl/projects</li>";
echo "<li><a href='$baseUrl/geotags' target='_blank'>Geotags</a> - GET $baseUrl/geotags</li>";
echo "<li><a href='$baseUrl/stats' target='_blank'>Statistics</a> - GET $baseUrl/stats</li>";
echo "</ul>";

// Test 5: Uploads Folder
echo "<h2>5. Uploads Folder Test</h2>";
$uploadDir = 'uploads/';
if (file_exists($uploadDir)) {
    if (is_writable($uploadDir)) {
        echo "<p style='color:green'>✓ Uploads folder exists and is writable</p>";
    } else {
        echo "<p style='color:orange'>⚠ Uploads folder exists but not writable. Run: chmod 755 uploads/</p>";
    }
} else {
    echo "<p style='color:orange'>⚠ Uploads folder not found. Creating...</p>";
    if (mkdir($uploadDir, 0755, true)) {
        echo "<p style='color:green'>✓ Uploads folder created</p>";
    } else {
        echo "<p style='color:red'>✗ Failed to create uploads folder</p>";
    }
}

// Test 6: .htaccess
echo "<h2>6. .htaccess Test</h2>";
if (file_exists('.htaccess')) {
    echo "<p style='color:green'>✓ .htaccess file exists</p>";
} else {
    echo "<p style='color:red'>✗ .htaccess file not found</p>";
}

// Summary
echo "<h2>Summary</h2>";
echo "<p>If all tests passed, your API is ready to use!</p>";
echo "<p>Test from Flutter app by opening Settings and clicking 'Sinkronkan Data'</p>";

// cURL Examples
echo "<h2>cURL Test Examples</h2>";
echo "<pre>";
echo "# Test API Status\n";
echo "curl $baseUrl/\n\n";

echo "# Create Project\n";
echo "curl -X POST $baseUrl/projects \\\n";
echo "  -H 'Content-Type: application/json' \\\n";
echo "  -d '{\"projectId\":1,\"activityName\":\"Test\",\"locationName\":\"Jakarta\",\"officers\":\"Ali\",\"status\":\"Active\"}'\n\n";

echo "# Sync Geotag\n";
echo "curl -X POST $baseUrl/geotags \\\n";
echo "  -H 'Content-Type: application/json' \\\n";
echo "  -d '{\"projectId\":1,\"latitude\":-6.2,\"longitude\":106.8,\"locationName\":\"Jakarta\",\"timestamp\":\"2024-01-01T12:00:00\",\"itemType\":\"Pohon\",\"condition\":\"Baik\",\"details\":\"Test\",\"photoPath\":\"\",\"deviceId\":\"test123\"}'\n";
echo "</pre>";
?>
