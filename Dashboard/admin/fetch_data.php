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

// --- 1. DASHBOARD (Dioptimalkan & Multi-Tenant Grup) ---
if ($action === 'dashboard') {
    // Cek apakah tabel project_groups sudah ada (cegah error sebelum migrasi)
    $has_groups = false;
    try {
        $pdo->query("SELECT 1 FROM project_groups LIMIT 1");
        $has_groups = true;
    } catch (Exception $e) {}

    if ($has_groups) {
        // Ambil daftar grup untuk menu dropdown
        $project_groups = $pdo->query("SELECT * FROM project_groups ORDER BY id DESC")->fetchAll();
    } else {
        $project_groups = [];
    }
    
    $selected_group = $_GET['groupId'] ?? '';
    
    // Hanya jalankan query berat jika grup sudah dipilih (biar enteng)
    if ($selected_group !== '' && $has_groups) {
        $gid = (int)$selected_group;
        $g_projects = $pdo->query("SELECT projectId FROM projects WHERE groupId = $gid")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($g_projects)) {
            $in_clause = implode(',', $g_projects);
            $w = "WHERE projectId IN ($in_clause)";
            $w_and = "AND projectId IN ($in_clause)";

            $stats['geotags'] = $pdo->query("SELECT COUNT(id) FROM geotags $w")->fetchColumn();
            $stats['projects'] = count($g_projects);
            
            $stats['cond'] = $pdo->query("SELECT `condition`, COUNT(id) FROM geotags $w GROUP BY `condition`")->fetchAll(PDO::FETCH_KEY_PAIR);
            $stats['daily'] = $pdo->query("SELECT DATE(timestamp), COUNT(id) FROM geotags WHERE timestamp >= DATE(NOW()) - INTERVAL 7 DAY $w_and GROUP BY DATE(timestamp)")->fetchAll(PDO::FETCH_KEY_PAIR);
            $stats['loc_stats'] = $pdo->query("SELECT locationName, COUNT(id) FROM geotags $w GROUP BY locationName ORDER BY COUNT(id) DESC ")->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $sql_officers = "SELECT p.officers, COUNT(g.id) as total 
                             FROM projects p 
                             JOIN geotags g ON p.projectId = g.projectId 
                             WHERE p.officers IS NOT NULL AND p.officers != '' AND g.projectId IN ($in_clause)
                             GROUP BY p.officers 
                             ORDER BY total DESC LIMIT 100"; 
            $stats['officer_stats'] = $pdo->query($sql_officers)->fetchAll();
        } else {
            // Grup ada tapi belum punya project
            $stats['geotags'] = 0; $stats['projects'] = 0;
            $stats['cond'] = []; $stats['daily'] = []; $stats['loc_stats'] = []; $stats['officer_stats'] = [];
        }
    }
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
        $has_groups = false;
        try {
            $pdo->query("SELECT 1 FROM project_groups LIMIT 1");
            $has_groups = true;
        } catch (Exception $e) {}

        if ($has_groups) {
            $project_groups = $pdo->query("SELECT * FROM project_groups ORDER BY id DESC")->fetchAll();
        } else {
            $project_groups = [];
        }
        
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

        } 
        // --- DAFTAR LIST (Data Projects) ---
        elseif ($action == 'list' && $table == 'projects') {
            $has_groups = false;
            try {
                $pdo->query("SELECT 1 FROM project_groups LIMIT 1");
                $has_groups = true;
            } catch (Exception $e) {}

            if ($has_groups) {
                $sql = "SELECT projects.*, project_groups.name as groupName FROM projects LEFT JOIN project_groups ON projects.groupId = project_groups.id ORDER BY projectId DESC";
            } else {
                $sql = "SELECT * FROM projects ORDER BY projectId DESC";
            }
            $list_data = $pdo->query($sql)->fetchAll();
        }
        // --- MANAJEMEN GRUP ---
        elseif ($action == 'groups') {
            $project_groups = [];
            $unassigned_projects = [];
            try {
                $project_groups = $pdo->query("SELECT * FROM project_groups ORDER BY id DESC")->fetchAll();
                $unassigned_projects = $pdo->query("SELECT projectId, activityName FROM projects WHERE groupId IS NULL ORDER BY projectId DESC")->fetchAll();
                
                foreach ($project_groups as &$g) {
                    $g['projects'] = $pdo->query("SELECT projectId, activityName FROM projects WHERE groupId = " . (int)$g['id'])->fetchAll();
                }
            } catch (Exception $e) {
                // Tabel belum dibuat
            }
        }
        else {
            // Logic umum untuk tabel lain (misal: projects)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` $w_sql"); $stmt->execute($p); $total_rows = $stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT * FROM `$table` $w_sql ORDER BY `$pk` DESC LIMIT $per_page OFFSET $offset");
            $stmt->execute($p); $list_data = $stmt->fetchAll();
        }
        $total_pages = ceil($total_rows / $per_page);
    }
}
?>