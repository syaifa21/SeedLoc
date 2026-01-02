<?php
// fetch_data.php

$stats = [];
$list_data = [];
$monitoring_tables = [];
$loc_keys = [];

$page = (int)($_GET['page'] ?? 1); 
$per_page = 28; 
if ($page < 1) $page = 1;

// --- 1. DASHBOARD ---
if ($action === 'dashboard') {
    $stats['geotags'] = $pdo->query("SELECT COUNT(*) FROM geotags")->fetchColumn();
    $stats['projects'] = $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
    $stats['cond'] = $pdo->query("SELECT `condition`, COUNT(*) FROM geotags GROUP BY `condition`")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['daily'] = $pdo->query("SELECT DATE(timestamp), COUNT(*) FROM geotags WHERE timestamp >= DATE(NOW()) - INTERVAL 7 DAY GROUP BY DATE(timestamp)")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['loc_stats'] = $pdo->query("SELECT locationName, COUNT(*) FROM geotags GROUP BY locationName ORDER BY COUNT(*) DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['officer_stats'] = $pdo->query("SELECT projects.officers, COUNT(geotags.id) as total FROM geotags LEFT JOIN projects ON geotags.projectId = projects.projectId WHERE projects.officers IS NOT NULL AND projects.officers != '' GROUP BY projects.officers ORDER BY total DESC")->fetchAll();
}

// --- 2. MONITORING TARGET (SMART MATCHING) ---
elseif ($action === 'monitor') {
    // A. BACA TARGET (JSON)
    $json_file = __DIR__ . '/targets.json';
    $target_config = []; // Format: ['Lokasi' => ['Bibit' => Target]]
    
    if (file_exists($json_file)) {
        $json_content = file_get_contents($json_file);
        $decoded = json_decode($json_content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $target_config = $decoded;
        }
    }

    // B. AMBIL DATA REAL DARI DATABASE
    $sql_monitor = "SELECT locationName, itemType, COUNT(*) as total FROM geotags GROUP BY locationName, itemType";
    $raw_monitor = $pdo->query($sql_monitor)->fetchAll();

    // C. PROSES PENYATUAN NAMA (SMART GROUPING)
    $real_data = []; // Format: ['Lokasi' => ['NamaBibitTarget' => Total]]

    foreach ($raw_monitor as $row) {
        $db_loc = trim($row['locationName']);
        $db_item = trim($row['itemType']); // Contoh: "Durian Montong"
        $qty = $row['total'];

        // Cek apakah lokasi ini ada di target config?
        if (isset($target_config[$db_loc])) {
            $is_matched = false;

            // Loop setiap bibit target untuk dicocokkan
            foreach ($target_config[$db_loc] as $target_name => $target_val) {
                // LOGIKA MATCHING:
                // 1. Ubah ke huruf kecil semua biar tidak case-sensitive
                // 2. Cek apakah nama di DB "MENGANDUNG" kata target
                
                $clean_db = strtolower($db_item);       // db: "durian montong"
                $clean_target = strtolower($target_name); // target: "durian"

                // Jika db mengandung kata target (misal "durian montong" mengandung "durian")
                if (strpos($clean_db, $clean_target) !== false) {
                    // Masukkan ke kantong Target tersebut
                    if (!isset($real_data[$db_loc][$target_name])) {
                        $real_data[$db_loc][$target_name] = 0;
                    }
                    $real_data[$db_loc][$target_name] += $qty;
                    $is_matched = true;
                    break; // Sudah ketemu, stop cari bibit lain
                }
            }

            // Jika sampai sini tidak ada yang cocok sama sekali
            if (!$is_matched) {
                // Simpan sebagai "Unplanned" (pakai nama asli DB)
                $real_data[$db_loc][$db_item] = ($real_data[$db_loc][$db_item] ?? 0) + $qty;
            }

        } else {
            // Jika lokasinya saja tidak ada di target, simpan mentah-mentah
            $real_data[$db_loc][$db_item] = ($real_data[$db_loc][$db_item] ?? 0) + $qty;
        }
    }

    // D. SIAPKAN TAMPILAN
    $idx = 0;
    foreach ($target_config as $loc => $bibits_target) {
        $safe_id = 'loc_' . $idx++;
        $loc_keys[$safe_id] = $loc;
        $row_data = [];

        // 1. Tampilkan List Sesuai Rencana (Target)
        foreach ($bibits_target as $jenis => $target) {
            // Ambil data yang sudah disatukan tadi
            $real = $real_data[$loc][$jenis] ?? 0;

            $status = ($real > $target) ? 'OVER' : 'OK';
            $percent = ($target > 0) ? round(($real / $target) * 100) : 0;

            $row_data[$jenis] = [
                'jenis' => $jenis,
                'target' => $target,
                'real' => $real,
                'percent' => $percent,
                'is_planned' => true,
                'status' => $status
            ];
        }

        // 2. Tampilkan Bibit "Nyasar" (Yang tidak terdeteksi kemiripannya)
        if (isset($real_data[$loc])) {
            foreach ($real_data[$loc] as $jenis_real => $qty_real) {
                // Jika bibit ini belum dimasukkan ke list di atas
                if (!isset($row_data[$jenis_real])) {
                    $row_data[$jenis_real] = [
                        'jenis' => $jenis_real . ' <small style="color:red">(Di luar Rencana)</small>',
                        'target' => 0,
                        'real' => $qty_real,
                        'percent' => 100,
                        'is_planned' => false,
                        'status' => 'UNPLANNED'
                    ];
                }
            }
        }

        $monitoring_tables[$safe_id] = array_values($row_data);
    }
}

// --- 3. LIST DATA LAINNYA ---
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