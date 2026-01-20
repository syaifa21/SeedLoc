<?php
// functions.php - VERSI OPTIMASI & STABIL

function get_photo_url($path, $base) {
    if (empty($path)) return '';
    return (strpos($path, 'http') === 0) ? $path : $base . $path;
}

function build_url($params = []) {
    $current = $_GET;
    // Reset halaman ke 1 jika filter utama berubah
    $reset_triggers = ['search', 'condition', 'projectId', 'locationName', 'itemType', 'has_photo', 'is_duplicate'];
    foreach($reset_triggers as $trigger) {
        if(isset($params[$trigger]) && !isset($params['page'])) {
            unset($current['page']);
        }
    }
    $query = array_merge($current, $params);
    return '?' . http_build_query($query);
}

function buildWhere($table, $pdo) {
    $where = []; $p = [];
    
    // 1. SEARCHING (Dioptimalkan)
    if (!empty($_GET['search'])) { 
        $raw = trim($_GET['search']);
        
        // A. Jika Angka -> Cari ID atau ProjectID (Cepat, pakai Index)
        if (is_numeric($raw)) {
            if ($table == 'geotags') {
                $where[] = "(geotags.id = ? OR geotags.projectId = ?)";
                $p[] = $raw; $p[] = $raw;
            } elseif ($table == 'projects') {
                $where[] = "(projectId = ?)"; $p[] = $raw;
            }
        } 
        // B. Jika Teks -> Cari Nama/Lokasi (Pakai LIKE)
        else {
            $s = "%{$raw}%";
            if ($table == 'geotags') { 
                // Cek 4 kolom: Jenis, Lokasi, Detail, Petugas(di tabel projects)
                $where[] = "(geotags.itemType LIKE ? OR geotags.locationName LIKE ? OR geotags.details LIKE ? OR projects.officers LIKE ?)"; 
                $p = array_merge($p, [$s, $s, $s, $s]);
            } 
            elseif ($table == 'projects') { 
                $where[] = "(activityName LIKE ? OR locationName LIKE ? OR officers LIKE ?)"; 
                $p = array_merge($p, [$s, $s, $s]);
            }
        }
    }

    // 2. FILTERING
    if ($table == 'geotags') {
        if (!empty($_GET['condition']) && $_GET['condition'] != 'all') { $where[] = "geotags.condition=?"; $p[] = $_GET['condition']; }
        if (!empty($_GET['projectId']) && $_GET['projectId'] != 'all') { $where[] = "geotags.projectId=?"; $p[] = $_GET['projectId']; }
        if (!empty($_GET['locationName']) && $_GET['locationName'] != 'all') { $where[] = "geotags.locationName=?"; $p[] = $_GET['locationName']; }
        if (!empty($_GET['itemType']) && $_GET['itemType'] != 'all') { $where[] = "geotags.itemType=?"; $p[] = $_GET['itemType']; }
        
        if (!empty($_GET['has_photo']) && $_GET['has_photo'] != 'all') {
            if ($_GET['has_photo'] == 'yes') { $where[] = "(geotags.photoPath IS NOT NULL AND geotags.photoPath != '')"; } 
            else { $where[] = "(geotags.photoPath IS NULL OR geotags.photoPath = '')"; }
        }

        // 3. DUPLICATE CHECK (Dioptimalkan menggunakan Range, bukan ROUND)
        if (!empty($_GET['is_duplicate']) && $_GET['is_duplicate'] != 'all') {
            if ($_GET['is_duplicate'] == 'exact') {
                // Koordinat sama persis
                $where[] = "EXISTS (SELECT 1 FROM geotags g2 WHERE g2.latitude = geotags.latitude AND g2.longitude = geotags.longitude AND g2.id != geotags.id)";
            } 
            elseif ($_GET['is_duplicate'] == 'near') {
                // Koordinat beda tipis (range +/- 0.00002 derajat / ~2 meter)
                // Ini JAUH lebih cepat daripada ROUND() karena memanfaatkan Index latitude/longitude
                $where[] = "EXISTS (SELECT 1 FROM geotags g2 WHERE g2.latitude BETWEEN geotags.latitude - 0.00002 AND geotags.latitude + 0.00002 AND g2.longitude BETWEEN geotags.longitude - 0.00002 AND geotags.longitude + 0.00002 AND g2.id != geotags.id)";
            }
            elseif ($_GET['is_duplicate'] == 'photo') {
                $where[] = "EXISTS (SELECT 1 FROM geotags g2 WHERE g2.photoPath = geotags.photoPath AND g2.photoPath != '' AND g2.id != geotags.id)";
            }
        }

        if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) { 
            $where[] = "DATE(geotags.timestamp) BETWEEN ? AND ?"; 
            $p[] = $_GET['start_date']; 
            $p[] = $_GET['end_date']; 
        }
    }
    return [$where, $p];
}

// FUNGSI EXPORT (Wajib ada)
function export_data($pdo, $ids, $type, $base_url, $photo_dir, $full_query = null) {
    // Logic Export tetap sama, hanya memastikan query SQL valid
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

    if ($type === 'download_zip') {
        if (!class_exists('ZipArchive')) { $_SESSION['swal_error'] = "ZipArchive extension missing."; return; }
        $zip = new ZipArchive(); 
        $zipName = $prefix.'Photos_'.date('YmdHis').'.zip'; 
        $temp = tempnam(sys_get_temp_dir(), 'zip');
        if ($zip->open($temp, ZipArchive::CREATE)!==TRUE) return;
        
        while($r = $stmt->fetch()) {
            if(empty($r['photoPath'])) continue;
            $name = $r['id'].'_'.preg_replace('/[^A-Za-z0-9]/','_',$r['itemType']).'.jpg';
            if(strpos($r['photoPath'],'http')===0) { 
                $c=@file_get_contents($r['photoPath']); if($c)$zip->addFromString($name,$c); 
            } else { 
                $f=$photo_dir.basename($r['photoPath']); if(file_exists($f))$zip->addFile($f,$name); 
            }
        }
        $zip->close();
        header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename='.$zipName); readfile($temp); unlink($temp); exit;
    } 
    elseif ($type === 'csv') {
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="'.$prefix.'Data.csv"');
        $out = fopen('php://output', 'w'); fputcsv($out, ['ID','ProjectID','Officer','Lat','Lng','Loc','Time','Type','Cond','Detail','Photo']);
        while($r = $stmt->fetch()) {
            fputcsv($out, [$r['id'],$r['projectId'],$r['officers']??'',$r['latitude'],$r['longitude'],$r['locationName'],$r['timestamp'],$r['itemType'],$r['condition'],$r['details'],get_photo_url($r['photoPath'],$base_url)]);
        }
        fclose($out); exit;
    }
    elseif ($type === 'kml') {
        header('Content-Type: application/vnd.google-earth.kml+xml'); header('Content-Disposition: attachment; filename="'.$prefix.'Map.kml"');
        echo '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>';
        while($r = $stmt->fetch()) {
            $desc = "Tipe: {$r['itemType']}\nKondisi: {$r['condition']}\nPetugas: ".($r['officers']??'-');
            echo "<Placemark><name>{$r['id']}</name><description>$desc</description><Point><coordinates>{$r['longitude']},{$r['latitude']}</coordinates></Point></Placemark>";
        } echo '</Document></kml>'; exit;
    }
}
?>