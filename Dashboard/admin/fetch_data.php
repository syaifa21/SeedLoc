<?php
$stats = [];
$list_data = [];
$monitoring_tables = [];
$loc_keys = [];

$page = (int)($_GET['page'] ?? 1); 
$per_page = 200; 
if ($page < 1) $page = 1;

if ($action === 'dashboard') {

    $stats['geotags'] = $pdo->query("SELECT COUNT(id) FROM geotags")->fetchColumn();
    $stats['projects'] = $pdo->query("SELECT COUNT(projectId) FROM projects")->fetchColumn();
    $stats['cond'] = $pdo->query("SELECT `condition`, COUNT(id) FROM geotags GROUP BY `condition`")->fetchAll(PDO::FETCH_KEY_PAIR);

    $stats['daily'] = $pdo->query("SELECT DATE(timestamp), COUNT(id) FROM geotags WHERE timestamp >= DATE(NOW()) - INTERVAL 7 DAY GROUP BY DATE(timestamp)")->fetchAll(PDO::FETCH_KEY_PAIR);

    $stats['loc_stats'] = $pdo->query("SELECT locationName, COUNT(id) FROM geotags GROUP BY locationName ORDER BY COUNT(id) DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
    

    $sql_officers = "SELECT p.officers, COUNT(g.id) as total 
                     FROM projects p 
                     LEFT JOIN geotags g ON p.projectId = g.projectId 
                     WHERE p.officers IS NOT NULL AND p.officers != '' 
                     GROUP BY p.officers 
                     ORDER BY total DESC"; 
                     
    $stats['officer_stats'] = $pdo->query($sql_officers)->fetchAll();
}


elseif ($action === 'monitor') {
    $target_config = []; 
    $json_file = __DIR__ . '/targets.json';
    if (file_exists($json_file)) {
        $json = json_decode(file_get_contents($json_file), true);
        if (json_last_error() === JSON_ERROR_NONE) $target_config = $json;
    }

    $raw_monitor = $pdo->query("SELECT locationName, itemType, COUNT(id) as total FROM geotags GROUP BY locationName, itemType")->fetchAll();
    $real_data = [];

    foreach ($raw_monitor as $row) {
        $loc = trim($row['locationName']);
        $item = trim($row['itemType']);
        $qty = $row['total'];

        $matched = false;
        if (isset($target_config[$loc])) {
            foreach ($target_config[$loc] as $t_name => $t_val) {
                if (stripos($item, $t_name) !== false) {
                    $real_data[$loc][$t_name] = ($real_data[$loc][$t_name] ?? 0) + $qty;
                    $matched = true; break;
                }
            }
        }
        if (!$matched) $real_data[$loc][$item] = ($real_data[$loc][$item] ?? 0) + $qty;
    }

    $idx = 0;
    foreach ($target_config as $loc => $targets) {
        $key = 'loc_'.$idx++; $loc_keys[$key] = $loc;
        $rows = [];
        foreach ($targets as $jenis => $tgt) {
            $real = $real_data[$loc][$jenis] ?? 0;
            $rows[] = ['jenis'=>$jenis, 'target'=>$tgt, 'real'=>$real, 'percent'=>($tgt>0?round(($real/$tgt)*100):0)];
        }
        if(isset($real_data[$loc])) {
            foreach($real_data[$loc] as $jenis => $val) {
                if(!isset($targets[$jenis])) $rows[] = ['jenis'=>$jenis.' <small style="color:red">(Lainnya)</small>', 'target'=>0, 'real'=>$val, 'percent'=>100];
            }
        }
        $monitoring_tables[$key] = $rows;
    }
}

// --- 3. LIST DATA & MAP ---
elseif (in_array($action, ['list', 'gallery', 'map', 'users'])) {
    if ($action == 'users') {
        $list_data = $pdo->query("SELECT * FROM admin_users ORDER BY id DESC")->fetchAll();
    } 
    elseif ($action == 'map') {
        list($where, $p) = buildWhere('geotags', $pdo);
        $w_sql = $where ? "WHERE ".implode(' AND ', $where) : "";
        
        $sql = "SELECT geotags.id, geotags.latitude, geotags.longitude, geotags.itemType, 
                       geotags.condition, geotags.photoPath, geotags.locationName,
                       geotags.details, geotags.timestamp, geotags.projectId, 
                       projects.officers 
                FROM geotags 
                LEFT JOIN projects ON geotags.projectId = projects.projectId 
                $w_sql ORDER BY geotags.id DESC LIMIT 100000";
        $stmt = $pdo->prepare($sql); $stmt->execute($p); $map_data = $stmt->fetchAll();
    } 
    else {
        list($where, $p) = buildWhere($table, $pdo);
        $w_sql = $where ? "WHERE ".implode(' AND ', $where) : "";
        $offset = ($page - 1) * $per_page;

        if ($table == 'geotags') {
            if (empty($where)) {
                $total_rows = $pdo->query("SELECT COUNT(id) FROM geotags")->fetchColumn();
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(geotags.id) FROM geotags LEFT JOIN projects ON geotags.projectId = projects.projectId $w_sql");
                $stmt->execute($p); $total_rows = $stmt->fetchColumn();
            }

            $sql = "SELECT geotags.id, geotags.projectId, geotags.itemType, geotags.locationName, geotags.latitude, geotags.longitude, 
                           geotags.timestamp, geotags.condition, geotags.photoPath, geotags.details, 
                           projects.officers 
                    FROM geotags 
                    LEFT JOIN projects ON geotags.projectId = projects.projectId 
                    $w_sql 
                    ORDER BY geotags.id DESC 
                    LIMIT $per_page OFFSET $offset";
            
            $stmt = $pdo->prepare($sql); $stmt->execute($p); $list_data = $stmt->fetchAll();
            
            if ($action === 'list') $projects_list = $pdo->query("SELECT projectId, locationName FROM projects ORDER BY projectId DESC LIMIT 100")->fetchAll();

        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` $w_sql"); $stmt->execute($p); $total_rows = $stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT * FROM `$table` $w_sql ORDER BY `$pk` DESC LIMIT $per_page OFFSET $offset");
            $stmt->execute($p); $list_data = $stmt->fetchAll();
        }
        $total_pages = ceil($total_rows / $per_page);
    }
}
?>