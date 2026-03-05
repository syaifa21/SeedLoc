<?php
// fetch_data.php - VERSI SHOW ALL (OPTIMIZED & FULL FEATURED)

$stats = [];
$list_data = [];
$monitoring_tables = [];
$loc_keys = [];

// Pagination Dasar (Hanya untuk Tampilan List Tabel & Galeri)
$page = (int)($_GET['page'] ?? 1); 
$per_page = 100; 
if ($page < 1) $page = 1;

// --- 1. DASHBOARD (Dioptimalkan) ---
if ($action === 'dashboard') {
    // Gunakan COUNT(id) yang jauh lebih ringan daripada SELECT *
    $stats['geotags'] = $pdo->query("SELECT COUNT(id) FROM geotags")->fetchColumn();
    $stats['projects'] = $pdo->query("SELECT COUNT(projectId) FROM projects")->fetchColumn();
    
    // Statistik Kondisi (Grouping)
    $stats['cond'] = $pdo->query("SELECT `condition`, COUNT(id) FROM geotags GROUP BY `condition`")->fetchAll(PDO::FETCH_KEY_PAIR);

    // Statistik Harian (7 Hari Terakhir)
    $stats['daily'] = $pdo->query("SELECT DATE(timestamp), COUNT(id) FROM geotags WHERE timestamp >= DATE(NOW()) - INTERVAL 7 DAY GROUP BY DATE(timestamp)")->fetchAll(PDO::FETCH_KEY_PAIR);

    // Statistik Lokasi (Limit 15 teratas agar grafik rapi)
    $stats['loc_stats'] = $pdo->query("SELECT locationName, COUNT(id) FROM geotags GROUP BY locationName ORDER BY COUNT(id) DESC ")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Statistik Petugas (Top 10)
    $sql_officers = "SELECT p.officers, COUNT(g.id) as total 
                     FROM projects p 
                     JOIN geotags g ON p.projectId = g.projectId 
                     WHERE p.officers IS NOT NULL AND p.officers != '' 
                     GROUP BY p.officers 
                     ORDER BY total DESC LIMIT 100"; 
    $stats['officer_stats'] = $pdo->query($sql_officers)->fetchAll();
}

// --- 2. MONITORING TARGET ---
elseif ($action === 'monitor') {
    $target_config = []; 
    $json_file = __DIR__ . '/targets.json';
    if (file_exists($json_file)) {
        $json = json_decode(file_get_contents($json_file), true);
        if (json_last_error() === JSON_ERROR_NONE) $target_config = $json;
    }

    // Ambil data realisasi dari database (Grouping)
    $raw_monitor = $pdo->query("SELECT locationName, itemType, COUNT(id) as total FROM geotags GROUP BY locationName, itemType")->fetchAll();
    $real_data = [];

    // Proses pencocokan data real dengan target
    foreach ($raw_monitor as $row) {
        $loc = trim($row['locationName']);
        $item = trim($row['itemType']);
        $qty = $row['total'];

        $matched = false;
        if (isset($target_config[$loc])) {
            foreach ($target_config[$loc] as $t_name => $t_val) {
                // Pencocokan string itemType (case-insensitive)
                if (stripos($item, $t_name) !== false) {
                    $real_data[$loc][$t_name] = ($real_data[$loc][$t_name] ?? 0) + $qty;
                    $matched = true; break;
                }
            }
        }
        // Jika tidak ada di target, masukkan ke kategori lain-lain
        if (!$matched) $real_data[$loc][$item] = ($real_data[$loc][$item] ?? 0) + $qty;
    }

    // Susun tabel monitoring
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

// --- 3. LIST DATA, USERS & MAP ---
elseif (in_array($action, ['list', 'gallery', 'map', 'users'])) {
    
    if ($action == 'users') {
        $list_data = $pdo->query("SELECT * FROM admin_users ORDER BY id DESC")->fetchAll();
    } 
    
    // --- QUERY KHUSUS PETA (SHOW ALL) ---
    elseif ($action == 'map') {
        list($where, $p) = buildWhere('geotags', $pdo);
        $w_sql = $where ? "WHERE ".implode(' AND ', $where) : "";
        
        // LIMIT BESAR (500.000) agar semua data muncul sesuai permintaan
        // Kolom dipilih spesifik untuk menghemat memori server
        $sql = "SELECT geotags.id, geotags.latitude, geotags.longitude, geotags.itemType, 
                       geotags.condition, geotags.photoPath, geotags.locationName,
                       geotags.details, geotags.timestamp, geotags.projectId, 
                       projects.officers 
                FROM geotags 
                LEFT JOIN projects ON geotags.projectId = projects.projectId 
                $w_sql 
                ORDER BY geotags.id DESC 
                LIMIT 500000"; 
        
        // Trik optimasi PHP: Unbuffered Query untuk dataset besar
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $stmt = $pdo->prepare($sql); 
        $stmt->execute($p); 
        $map_data = $stmt->fetchAll();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); // Kembalikan setting default
    } 
    
    // --- QUERY LIST TABEL & GALERI (PAGINATION) ---
    else {
        list($where, $p) = buildWhere($table, $pdo);
        $w_sql = $where ? "WHERE ".implode(' AND ', $where) : "";
        $offset = ($page - 1) * $per_page;

        if ($table == 'geotags') {
            // Hitung total baris untuk pagination
            $count_sql = empty($where) ? "SELECT COUNT(id) FROM geotags" : "SELECT COUNT(geotags.id) FROM geotags LEFT JOIN projects ON geotags.projectId = projects.projectId $w_sql";
            $stmt = $pdo->prepare($count_sql);
            if(!empty($where)) $stmt->execute($p); else $stmt->execute();
            $total_rows = $stmt->fetchColumn();

            // Ambil data halaman aktif
            $sql = "SELECT geotags.id, geotags.projectId, geotags.itemType, geotags.locationName, geotags.latitude, geotags.longitude, 
                           geotags.timestamp, geotags.condition, geotags.photoPath, geotags.details, 
                           projects.officers 
                    FROM geotags 
                    LEFT JOIN projects ON geotags.projectId = projects.projectId 
                    $w_sql 
                    ORDER BY geotags.id DESC 
                    LIMIT $per_page OFFSET $offset";
            
            $stmt = $pdo->prepare($sql); $stmt->execute($p); $list_data = $stmt->fetchAll();
            
            // Dropdown filter project
            if ($action === 'list') $projects_list = $pdo->query("SELECT projectId, locationName FROM projects ORDER BY projectId DESC LIMIT 100")->fetchAll();

        } else {
            // Logic umum untuk tabel lain (misal: projects)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` $w_sql"); $stmt->execute($p); $total_rows = $stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT * FROM `$table` $w_sql ORDER BY `$pk` DESC LIMIT $per_page OFFSET $offset");
            $stmt->execute($p); $list_data = $stmt->fetchAll();
        }
        $total_pages = ceil($total_rows / $per_page);
    }
}
?>