<?php
// fetch_data.php

$stats = [];
$list_data = [];
$page = (int)($_GET['page'] ?? 1); 
$per_page = 28; 
$total_pages = 1; 
if ($page < 1) $page = 1;

if ($action === 'dashboard') {
    $stats['geotags'] = $pdo->query("SELECT COUNT(*) FROM geotags")->fetchColumn();
    $stats['projects'] = $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
    $stats['cond'] = $pdo->query("SELECT `condition`, COUNT(*) FROM geotags GROUP BY `condition`")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['daily'] = $pdo->query("SELECT DATE(timestamp), COUNT(*) FROM geotags WHERE timestamp >= DATE(NOW()) - INTERVAL 7 DAY GROUP BY DATE(timestamp)")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['loc_stats'] = $pdo->query("SELECT locationName, COUNT(*) FROM geotags GROUP BY locationName ORDER BY COUNT(*) DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['officer_stats'] = $pdo->query("SELECT projects.officers, COUNT(geotags.id) as total FROM geotags LEFT JOIN projects ON geotags.projectId = projects.projectId WHERE projects.officers IS NOT NULL AND projects.officers != '' GROUP BY projects.officers ORDER BY total DESC")->fetchAll();
}
elseif (in_array($action, ['list', 'gallery', 'map', 'users'])) {
    if ($action == 'users') { 
        $list_data = $pdo->query("SELECT * FROM admin_users ORDER BY id DESC")->fetchAll(); 
    } elseif ($action == 'map') {
        list($where, $p) = buildWhere('geotags', $pdo);
        $w_sql = $where ? "WHERE ".implode(' AND ', $where) : "";
        $sql = "SELECT geotags.id, geotags.latitude, geotags.longitude, geotags.itemType, geotags.condition, geotags.photoPath, geotags.locationName 
                FROM geotags LEFT JOIN projects ON geotags.projectId = projects.projectId 
                $w_sql ORDER BY geotags.id DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($p); $map_data = $stmt->fetchAll();
    } else {
        list($where, $p) = buildWhere($table, $pdo);
        $w_sql = $where ? "WHERE ".implode(' AND ', $where) : "";
        $offset = ($page - 1) * $per_page;
        
        if ($table == 'geotags') {
            $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM geotags LEFT JOIN projects ON geotags.projectId = projects.projectId $w_sql"); 
            $total_stmt->execute($p);
            $total_rows = $total_stmt->fetchColumn(); 
            
            $sql = "SELECT geotags.*, projects.officers FROM geotags LEFT JOIN projects ON geotags.projectId = projects.projectId 
                    $w_sql ORDER BY geotags.id DESC LIMIT $per_page OFFSET $offset";
            $stmt = $pdo->prepare($sql); $stmt->execute($p); $list_data = $stmt->fetchAll();
            $projects_list = $pdo->query("SELECT projectId, activityName, locationName FROM projects ORDER BY created_at DESC")->fetchAll(); 
        } else {
            $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` $w_sql"); $total_stmt->execute($p);
            $total_rows = $total_stmt->fetchColumn(); 
            $stmt = $pdo->prepare("SELECT * FROM `$table` $w_sql ORDER BY `$pk` DESC LIMIT $per_page OFFSET $offset");
            $stmt->execute($p); $list_data = $stmt->fetchAll();
        }
        $total_pages = ceil($total_rows / $per_page);
    }
}
?>