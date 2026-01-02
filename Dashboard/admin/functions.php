<?php
// functions.php

function get_photo_url($path, $base) {
    if (empty($path)) return '';
    return (strpos($path, 'http') === 0) ? $path : $base . $path;
}

function build_url($params = []) {
    $current = $_GET;
    if((isset($params['search']) || isset($params['condition']) || isset($params['projectId'])) && !isset($params['page'])) {
        unset($current['page']);
    }
    $query = array_merge($current, $params);
    return '?' . http_build_query($query);
}

function buildWhere($table, $pdo) {
    $where = []; $p = [];
    if (!empty($_GET['search'])) { 
        $s = "%{$_GET['search']}%";
        if ($table == 'geotags') { 
            $where[] = "(geotags.id LIKE ? OR geotags.itemType LIKE ? OR geotags.locationName LIKE ? OR geotags.details LIKE ? OR geotags.condition LIKE ? OR geotags.projectId LIKE ? OR projects.officers LIKE ?)"; 
            $p = array_fill(0, 7, $s);
        } 
        elseif ($table == 'projects') { 
            $where[] = "(activityName LIKE ? OR locationName LIKE ? OR officers LIKE ? OR projectId LIKE ?)"; 
            $p = array_fill(0, 4, $s);
        }
    }
    if ($table == 'geotags') {
        if (!empty($_GET['condition']) && $_GET['condition'] != 'all') { $where[] = "geotags.condition=?"; $p[] = $_GET['condition']; }
        if (!empty($_GET['projectId']) && $_GET['projectId'] != 'all') { $where[] = "geotags.projectId=?"; $p[] = $_GET['projectId']; }
        if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) { $where[] = "DATE(geotags.timestamp) BETWEEN ? AND ?"; $p[] = $_GET['start_date']; $p[] = $_GET['end_date']; }
    }
    return [$where, $p];
}

// Updated to receive photo_dir specifically
function export_data($pdo, $ids, $type, $base_url, $photo_dir, $full_project_id = null) {
    if ($full_project_id) {
        $sql = ($full_project_id === 'all') ? "SELECT * FROM geotags ORDER BY id DESC" : "SELECT * FROM geotags WHERE projectId = ? ORDER BY id DESC";
        $params = ($full_project_id === 'all') ? [] : [$full_project_id];
        $filename_prefix = ($full_project_id === 'all') ? "All_Data_" : "Project_{$full_project_id}_";
    } else {
        if(empty($ids)) return;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM geotags WHERE id IN ($ph) ORDER BY id DESC";
        $params = $ids;
        $filename_prefix = "Selected_";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($type === 'download_zip') {
        if (!class_exists('ZipArchive')) { $_SESSION['swal_error'] = "ZipArchive extension missing."; return; }
        $zip = new ZipArchive();
        $zipName = $filename_prefix . 'Photos_' . date('Ymd_His') . '.zip';
        $tempZip = tempnam(sys_get_temp_dir(), 'zip');
        if ($zip->open($tempZip, ZipArchive::CREATE) !== TRUE) return;

        $count = 0;
        while($r = $stmt->fetch()) {
            $p = $r['photoPath']; if (empty($p)) continue;
            $cleanType = preg_replace('/[^A-Za-z0-9]/', '_', $r['itemType']);
            $cleanLoc  = preg_replace('/[^A-Za-z0-9]/', '_', $r['locationName']);
            $zipInternalName = $r['id'] .'_'. $cleanLoc. '_' . $cleanType . '.jpg';
            if (strpos($p, 'http') === 0) {
                $content = @file_get_contents($p); if ($content) { $zip->addFromString($zipInternalName, $content); $count++; }
            } else {
                // Read from PHOTO DIR
                $filePath = $photo_dir . basename($p); 
                if (file_exists($filePath)) { $zip->addFile($filePath, $zipInternalName); $count++; }
            }
        }
        $zip->close();
        if ($count > 0) {
            header('Content-Type: application/zip'); header('Content-disposition: attachment; filename='.$zipName);
            header('Content-Length: ' . filesize($tempZip)); readfile($tempZip); unlink($tempZip); exit;
        } else { $_SESSION['swal_warning'] = "Tidak ada foto valid."; unlink($tempZip); if(!$full_project_id) header("Location: ?action=list&table=geotags"); return; }

    } elseif ($type === 'csv') {
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="'.$filename_prefix.'Data_'.date('YmdHis').'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'ProjectID', 'Lat', 'Lng', 'Location', 'Time', 'Type', 'Condition', 'Details', 'PhotoURL']);
        while($r = $stmt->fetch()) {
            fputcsv($out, [$r['id'], $r['projectId'], $r['latitude'], $r['longitude'], $r['locationName'], $r['timestamp'], $r['itemType'], $r['condition'], $r['details'], get_photo_url($r['photoPath'], $base_url)]);
        }
        fclose($out); exit;

    } elseif ($type === 'kml') {
        header('Content-Type: application/vnd.google-earth.kml+xml'); header('Content-Disposition: attachment; filename="'.$filename_prefix.'Map_'.date('YmdHis').'.kml"');
        echo '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>';
        while($r = $stmt->fetch()) {
            $img = get_photo_url($r['photoPath'], $base_url);
            $desc = "<b>Kondisi:</b> {$r['condition']}<br><b>Lokasi:</b> {$r['locationName']}<br><b>Waktu:</b> {$r['timestamp']}";
            if($img) $desc .= "<br><img src='$img' width='200'>";
            echo "<Placemark><name>".htmlspecialchars($r['itemType'])."</name><description><![CDATA[$desc]]></description><Point><coordinates>{$r['longitude']},{$r['latitude']}</coordinates></Point></Placemark>";
        }
        echo '</Document></kml>'; exit;
    }
}
?> 