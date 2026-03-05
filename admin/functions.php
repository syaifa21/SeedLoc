<?php
// functions.php - FULL OPTIMIZED VERSION (Anti-Stuck & Secure)

/**
 * Helper: Membuat URL Foto Valid
 * Mengubah path relatif database menjadi URL absolut
 */
function get_photo_url($path, $base) {
    $path = trim($path ?? '');
    if (empty($path)) return '';
    
    // Jika path sudah berupa URL lengkap (http/https), pakai itu
    if (strpos($path, 'http') === 0) return $path;
    
    // Jika path relatif, gabungkan dengan base URL API
    return $base . $path;
}

/**
 * Helper: Membuat URL Navigasi (Pagination/Filter)
 * Memastikan parameter filter tetap ada saat pindah halaman
 */
function build_url($params = []) {
    $current = $_GET;
    
    // Reset ke halaman 1 jika filter utama berubah
    $reset_triggers = ['search', 'condition', 'projectId', 'locationName', 'itemType', 'has_photo', 'is_duplicate'];
    foreach($reset_triggers as $trigger) {
        if(isset($params[$trigger]) && !isset($params['page'])) {
            unset($current['page']);
        }
    }
    
    $query = array_merge($current, $params);
    return '?' . http_build_query($query);
}

/**
 * Core Logic: Membuat Filter Query (WHERE Clause)
 * Digunakan oleh index.php (List) dan load_geotags.php (AJAX)
 */
function buildWhere($table, $pdo) {
    $where = []; 
    $p = [];
    
    // --- 1. SEARCHING (Cari Cepat) ---
    if (!empty($_GET['search'])) { 
        $raw = trim($_GET['search']);
        
        // Optimasi: Jika input angka, cari ID/ProjectID (Pakai Index DB)
        if (is_numeric($raw)) {
            if ($table == 'geotags') {
                $where[] = "(geotags.id = ? OR geotags.projectId = ?)";
                $p[] = $raw; $p[] = $raw;
            } elseif ($table == 'projects') {
                $where[] = "(projectId = ?)"; 
                $p[] = $raw;
            }
        } 
        // Jika input teks, cari menggunakan LIKE
        else {
            $s = "%{$raw}%";
            if ($table == 'geotags') { 
                $where[] = "(geotags.itemType LIKE ? OR geotags.locationName LIKE ? OR geotags.details LIKE ? OR projects.officers LIKE ?)"; 
                $p = array_merge($p, [$s, $s, $s, $s]);
            } 
            elseif ($table == 'projects') { 
                $where[] = "(activityName LIKE ? OR locationName LIKE ? OR officers LIKE ?)"; 
                $p = array_merge($p, [$s, $s, $s]);
            }
        }
    }

    // --- 2. FILTERING STANDARD (Geotags) ---
    if ($table == 'geotags') {
        // Filter Kondisi
        if (!empty($_GET['condition']) && $_GET['condition'] != 'all') { 
            $where[] = "geotags.condition=?"; $p[] = $_GET['condition']; 
        }
        
        // Filter Project ID
        if (!empty($_GET['projectId']) && $_GET['projectId'] != 'all') { 
            $where[] = "geotags.projectId=?"; $p[] = $_GET['projectId']; 
        }
        
        // Filter Lokasi
        if (!empty($_GET['locationName']) && $_GET['locationName'] != 'all') { 
            $where[] = "geotags.locationName=?"; $p[] = $_GET['locationName']; 
        }
        
        // Filter Jenis Pohon
        if (!empty($_GET['itemType']) && $_GET['itemType'] != 'all') { 
            $where[] = "geotags.itemType=?"; $p[] = $_GET['itemType']; 
        }
        
        // Filter Status Foto
        if (!empty($_GET['has_photo']) && $_GET['has_photo'] != 'all') {
            if ($_GET['has_photo'] == 'yes') { 
                $where[] = "(geotags.photoPath IS NOT NULL AND geotags.photoPath != '')"; 
            } else { 
                $where[] = "(geotags.photoPath IS NULL OR geotags.photoPath = '')"; 
            }
        }

        // Filter Tanggal
        if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) { 
            $where[] = "DATE(geotags.timestamp) BETWEEN ? AND ?"; 
            $p[] = $_GET['start_date']; 
            $p[] = $_GET['end_date']; 
        }

        // --- 3. FILTER DUPLIKAT (OPTIMIZED) ---
        // Logika ini diperbaiki agar query tidak berat (mengatasi stuck 95%)
        if (!empty($_GET['is_duplicate']) && $_GET['is_duplicate'] != 'all') {
            
            // A. Koordinat Persis Sama
            if ($_GET['is_duplicate'] == 'exact') {
                $where[] = "EXISTS (SELECT 1 FROM geotags g2 WHERE g2.latitude = geotags.latitude AND g2.longitude = geotags.longitude AND g2.id != geotags.id)";
            } 
            
            // B. Koordinat Berdekatan (Range ~2 meter)
            // Menggunakan BETWEEN jauh lebih cepat daripada fungsi ROUND()
            elseif ($_GET['is_duplicate'] == 'near') {
                $where[] = "EXISTS (SELECT 1 FROM geotags g2 WHERE g2.latitude BETWEEN geotags.latitude - 0.00002 AND geotags.latitude + 0.00002 AND g2.longitude BETWEEN geotags.longitude - 0.00002 AND geotags.longitude + 0.00002 AND g2.id != geotags.id)";
            }
            
            // C. File Foto Sama (File Path Duplikat)
            // Hanya cek jika photoPath tidak kosong, agar tidak scan jutaan data kosong
            elseif ($_GET['is_duplicate'] == 'photo') {
                $where[] = "geotags.photoPath != '' AND geotags.photoPath IS NOT NULL AND EXISTS (SELECT 1 FROM geotags g2 WHERE g2.photoPath = geotags.photoPath AND g2.photoPath != '' AND g2.id != geotags.id)";
            }
        }
    }
    return [$where, $p];
}

/**
 * Core Function: Export Data (ZIP, CSV, KML)
 * Menangani download file dalam jumlah banyak
 */
function export_data($pdo, $ids, $type, $base_url, $photo_dir, $full_query = null) {
    // Tentukan Query: Apakah berdasarkan Checkbox ID atau Filter Full?
    if (is_array($full_query) && isset($full_query['custom_where'])) {
        $sql = "SELECT geotags.*, projects.officers FROM geotags LEFT JOIN projects ON geotags.projectId = projects.projectId " . $full_query['custom_where'] . " ORDER BY geotags.id DESC";
        $params = $full_query['params'];
        $prefix = "Filtered_";
    } else {
        if(empty($ids)) return;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM geotags WHERE id IN ($ph) ORDER BY id DESC";
        $params = $ids;
        $prefix = "Selected_";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // --- 1. EXPORT ZIP (FOTO) ---
    if ($type === 'download_zip') {
        if (!class_exists('ZipArchive')) { 
            $_SESSION['swal_error'] = "ZipArchive extension missing on server."; 
            return; 
        }
        
        $zip = new ZipArchive(); 
        $zipName = $prefix.'Photos_'.date('YmdHis').'.zip'; 
        $temp = tempnam(sys_get_temp_dir(), 'zip');
        
        if ($zip->open($temp, ZipArchive::CREATE)!==TRUE) {
            $_SESSION['swal_error'] = "Gagal membuat file ZIP sementara.";
            return;
        }
        
        $count = 0;
        while($r = $stmt->fetch()) {
            $path = $r['photoPath'];
            if(empty($path)) continue; // Skip jika tidak ada foto
            
            // Bersihkan nama file untuk ZIP
            $cleanType = preg_replace('/[^A-Za-z0-9]/', '_', $r['itemType']);
            $nameInZip = $r['id'] . '_' . $cleanType . '.jpg';

            // Cek apakah URL (Remote) atau File Lokal
            if(strpos($path, 'http') === 0) { 
                // Download dari URL (Suppress error dengan @ agar tidak crash jika 401)
                $content = @file_get_contents($path); 
                if($content) {
                    $zip->addFromString($nameInZip, $content); 
                    $count++;
                }
            } else { 
                // Ambil dari Folder Lokal
                $localFile = $photo_dir . basename($path); 
                if(file_exists($localFile)) {
                    $zip->addFile($localFile, $nameInZip);
                    $count++;
                }
            }
        }
        $zip->close();
        
        if ($count == 0) {
            unlink($temp);
            $_SESSION['swal_warning'] = "Tidak ada foto valid yang dapat diunduh dari data terpilih.";
            header("Location: ?action=list&table=geotags");
            exit;
        }

        // Force Download
        header('Content-Type: application/zip'); 
        header('Content-Disposition: attachment; filename='.$zipName); 
        header('Content-Length: ' . filesize($temp));
        readfile($temp); 
        unlink($temp); 
        exit;
    } 
    
    // --- 2. EXPORT CSV (EXCEL) ---
    elseif ($type === 'csv') {
        header('Content-Type: text/csv'); 
        header('Content-Disposition: attachment; filename="'.$prefix.'Data_'.date('Ymd').'.csv"');
        
        $out = fopen('php://output', 'w'); 
        // Header CSV
        fputcsv($out, ['ID', 'ProjectID', 'Officer', 'Lat', 'Lng', 'Loc', 'Time', 'Type', 'Cond', 'Detail', 'Photo URL']);
        
        while($r = $stmt->fetch()) {
            fputcsv($out, [
                $r['id'],
                $r['projectId'],
                $r['officers'] ?? '',
                $r['latitude'],
                $r['longitude'],
                $r['locationName'],
                $r['timestamp'],
                $r['itemType'],
                $r['condition'],
                $r['details'],
                get_photo_url($r['photoPath'], $base_url)
            ]);
        }
        fclose($out); 
        exit;
    }
    
    // --- 3. EXPORT KML (MAPS) ---
    elseif ($type === 'kml') {
        header('Content-Type: application/vnd.google-earth.kml+xml'); 
        header('Content-Disposition: attachment; filename="'.$prefix.'Map_'.date('Ymd').'.kml"');
        
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<kml xmlns="http://www.opengis.net/kml/2.2"><Document>';
        echo '<name>Export Data SeedLoc</name>';
        
        while($r = $stmt->fetch()) {
            $desc = "Tipe: {$r['itemType']}\nKondisi: {$r['condition']}\nPetugas: ".($r['officers']??'-')."\nWaktu: {$r['timestamp']}";
            
            echo "<Placemark>";
            echo "<name>#{$r['id']} - {$r['itemType']}</name>";
            echo "<description><![CDATA[" . nl2br($desc) . "]]></description>";
            
            // Style icon berdasarkan kondisi (Simple logic)
            $color = ($r['condition'] == 'Hidup' || $r['condition'] == 'Baik') ? 'green' : 'red';
            
            echo "<Point><coordinates>{$r['longitude']},{$r['latitude']}</coordinates></Point>";
            echo "</Placemark>";
        } 
        echo '</Document></kml>'; 
        exit;
    }
}
?>