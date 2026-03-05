<?php
function get_photo_url($path, $base) {
    $path = trim($path ?? '');
    if (empty($path)) return '';
    // Jika path sudah berupa URL lengkap (http/https), pakai itu
    if (strpos($path, 'http') === 0) return $path;
    // Jika path relatif, gabungkan dengan base URL API
    return $base . $path;
}
function build_url($params = []) {
    $current = $_GET;
 
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
    $where = []; 
    $p = [];

    $input = $_REQUEST; 

    if (!empty($input['search'])) { 
        $raw = trim($input['search']);
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
        if (!empty($input['condition']) && $input['condition'] != 'all') { 
            $where[] = "geotags.condition=?"; $p[] = $input['condition']; 
        }
        
        // Filter Project ID
        if (!empty($input['projectId']) && $input['projectId'] != 'all') { 
            $where[] = "geotags.projectId=?"; $p[] = $input['projectId']; 
        }
        
        // Filter Lokasi
        if (!empty($input['locationName']) && $input['locationName'] != 'all') { 
            $where[] = "geotags.locationName=?"; $p[] = $input['locationName']; 
        }
        
        // Filter Jenis Pohon
        if (!empty($input['itemType']) && $input['itemType'] != 'all') { 
            $where[] = "geotags.itemType=?"; $p[] = $input['itemType']; 
        }
        
        // Filter Status Foto
        if (!empty($input['has_photo']) && $input['has_photo'] != 'all') {
            if ($input['has_photo'] == 'yes') { 
                $where[] = "(geotags.photoPath IS NOT NULL AND geotags.photoPath != '')"; 
            } else { 
                $where[] = "(geotags.photoPath IS NULL OR geotags.photoPath = '')"; 
            }
        }

        // Filter Tanggal
        if (!empty($input['start_date']) && !empty($input['end_date'])) { 
            $where[] = "DATE(geotags.timestamp) BETWEEN ? AND ?"; 
            $p[] = $input['start_date']; 
            $p[] = $input['end_date']; 
        }

        // --- 3. FILTER DUPLIKAT ---
        if (!empty($input['is_duplicate']) && $input['is_duplicate'] != 'all') {
            if ($input['is_duplicate'] == 'exact') {
                $where[] = "EXISTS (SELECT 1 FROM geotags g2 WHERE g2.latitude = geotags.latitude AND g2.longitude = geotags.longitude AND g2.id != geotags.id)";
            } elseif ($input['is_duplicate'] == 'near') {
                $where[] = "EXISTS (SELECT 1 FROM geotags g2 WHERE g2.latitude BETWEEN geotags.latitude - 0.00002 AND geotags.latitude + 0.00002 AND g2.longitude BETWEEN geotags.longitude - 0.00002 AND geotags.longitude + 0.00002 AND g2.id != geotags.id)";
            } elseif ($input['is_duplicate'] == 'photo') {
                $where[] = "geotags.photoPath != '' AND geotags.photoPath IS NOT NULL AND EXISTS (SELECT 1 FROM geotags g2 WHERE g2.photoPath = geotags.photoPath AND g2.photoPath != '' AND g2.id != geotags.id)";
            }
        }
    }
    return [$where, $p];
}

function export_data($pdo, $ids, $type, $base_url, $photo_dir, $full_query = null) {
    // 1. Matikan buffering & set unlimited time
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', 1);
    @ini_set('zlib.output_compression', 0);
    @ini_set('implicit_flush', 1);
    while (ob_get_level()) ob_end_clean();
    set_time_limit(0); 
    ini_set('memory_limit', '1024M');

    // 2. Siapkan Query
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

    try {
        // [KUNCI ANTI-TIMEOUT] Gunakan Unbuffered Query
        // Ini memaksa PHP mengambil data satu per satu dari MySQL, bukan sekaligus load ke RAM
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // --- A. EXPORT CSV (STREAMING) ---
        if ($type === 'csv') {
            header('Content-Type: text/csv; charset=utf-8'); 
            header('Content-Disposition: attachment; filename="'.$prefix.'Data_100k_'.date('YmdHis').'.csv"');
            
            $out = fopen('php://output', 'w'); 
            fputs($out, "\xEF\xBB\xBF"); // BOM UTF-8
            
            // Header
            fputcsv($out, ['ID', 'ProjectID', 'Officer', 'Lat', 'Lng', 'Loc', 'Time', 'Type', 'Cond', 'Detail', 'Photo URL']);
            
            // Loop One-by-One (Hemat RAM)
            $count = 0;
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, [
                    $r['id'], $r['projectId'], $r['officers'] ?? '-', $r['latitude'], $r['longitude'],
                    $r['locationName'], $r['timestamp'], $r['itemType'], $r['condition'], $r['details'],
                    get_photo_url($r['photoPath'], $base_url)
                ]);

                // Flush setiap 1000 baris agar browser tetap loading (tidak diam)
                $count++;
                if($count % 1000 == 0) {
                    fflush($out);
                    flush();
                }
            }
            fclose($out);
            
            // Kembalikan setting MySQL (Penting!)
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            exit;
        }
        
        // --- B. EXPORT KML (STREAMING) ---
        elseif ($type === 'kml') {
            header('Content-Type: application/vnd.google-earth.kml+xml'); 
            header('Content-Disposition: attachment; filename="'.$prefix.'Map_100k_'.date('YmdHis').'.kml"');
            
            // Stream XML header langsung
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>Export SeedLoc</name>';
            flush();

            $count = 0;
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $desc = "Tipe: {$r['itemType']}\nKondisi: {$r['condition']}\nPetugas: ".($r['officers']??'-')."\nWaktu: {$r['timestamp']}";
                // Escape XML Special Char
                $safeName = htmlspecialchars($r['itemType']);
                $safeDesc = htmlspecialchars($desc);
                
                echo "<Placemark><name>#{$r['id']} - {$safeName}</name><description>{$safeDesc}</description><Point><coordinates>{$r['longitude']},{$r['latitude']}</coordinates></Point></Placemark>";
                
                $count++;
                if($count % 500 == 0) flush(); // Dorong ke browser
            } 
            echo '</Document></kml>';
            
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            exit;
        }

        // --- C. EXPORT ZIP (TIDAK BISA STREAMING PENUH) ---
        // ZIP butuh file temporary karena header ZIP ditulis di akhir file.
        // Untuk 100k foto, ini SANGAT BERAT. Disarankan pakai Client-Side.
        elseif ($type === 'download_zip') {
            // Kita matikan unbuffered khusus ZIP karena logic ZIP butuh temporary file
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            
            if (!class_exists('ZipArchive')) throw new Exception("Extension ZipArchive mati.");
            
            $zip = new ZipArchive(); 
            $zipName = $prefix.'Photos_'.date('YmdHis').'.zip'; 
            
            // Pastikan folder temp server cukup besar
            $temp = tempnam(sys_get_temp_dir(), 'zip');
            if ($zip->open($temp, ZipArchive::CREATE)!==TRUE) throw new Exception("Gagal buat temp ZIP.");
            
            $count = 0;
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $path = $r['photoPath'];
                if(empty($path)) continue; 
                
                // Limitasi server side: Hanya proses 2000 foto pertama agar server tidak hang
                // Jika butuh full 100k, WAJIB pakai Client Side Export
                if($count >= 2000) break; 
                        $path = $r['photoPath'];
                        if(empty($path)) continue; 

                        // 1. Bersihkan Nama Lokasi & Jenis (Ganti spasi/simbol jadi underscore)
                        $cleanLoc  = preg_replace('/[^A-Za-z0-9]/', '_', $r['locationName']);
                        $cleanType = preg_replace('/[^A-Za-z0-9]/', '_', $r['itemType']);

                        // 2. Format Tanggal (Y-m-d)
                        $date = date('Y-m-d', strtotime($r['timestamp']));

                        // 3. Ambil Ekstensi File Asli (jpg/png)
                        $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg';

                        // 4. SUSUN NAMA FINAL: Lokasi_Jenis_Tanggal_ID.ext
                        $nameInZip = $cleanLoc . '_' . $cleanType . '_' . $date . '_' . $r['id'] . '.' . $ext;

                if(strpos($path, 'http') === 0) { 
                    $content = @file_get_contents($path); 
                    if($content) $zip->addFromString($nameInZip, $content); 
                } else { 
                    $localFile = $photo_dir . basename($path); 
                    $cleanRelPath = ltrim(str_replace('uploads/', '', $path), '/'); 
                    $localFile2 = $photo_dir . $cleanRelPath;

                    if(file_exists($localFile)) $zip->addFile($localFile, $nameInZip);
                    elseif(file_exists($localFile2)) $zip->addFile($localFile2, $nameInZip);
                }
                $count++;
            }
            $zip->close();
            
            if ($count == 0 && $stmt->rowCount() > 0) throw new Exception("Tidak ada foto valid (atau limit tercapai).");

            header('Content-Type: application/zip'); 
            header('Content-Disposition: attachment; filename="'.$zipName.'"'); 
            header('Content-Length: ' . filesize($temp));
            readfile($temp); 
            unlink($temp); 
            exit;
        } 

    } catch (Exception $e) {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); // Reset
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

?>