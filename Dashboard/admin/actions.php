<?php
// actions.php - FULL RECODE

require_once 'config.php';
require_once 'functions.php';

// --- HELPER: SMART REDIRECT ---
function get_redirect_smart($table) {
    $params = ['action' => 'list', 'table' => $table];
    $filters = ['search', 'page', 'projectId', 'condition', 'start_date', 'end_date', 'locationName', 'itemType', 'has_photo', 'is_duplicate'];
    
    foreach($filters as $key) {
        // Ambil dari POST ret_* (yang di-inject JS)
        if(isset($_POST['ret_'.$key]) && $_POST['ret_'.$key] !== '') {
            $params[$key] = $_POST['ret_'.$key];
        }
    }
    return '?' . http_build_query($params);
}

// --- 1. EXPORT HANDLER (GET/POST) ---
// [FIXED] Menangani request dari JS Fetch (POST)
// actions.php (Bagian Export Full)

if ($action === 'export_full' || (isset($_POST['action']) && $_POST['action'] === 'export_full')) {
    
    // Setting Server Biar Kuat
    set_time_limit(0); 
    ini_set('memory_limit', '1024M');
    ignore_user_abort(true);
    session_write_close(); 

    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
        http_response_code(401); echo json_encode(['status'=>'error']); exit;
    }

    $type = $_REQUEST['type'] ?? 'csv';
    list($where, $params) = buildWhere('geotags', $pdo);

    // --- CASE 1: CLIENT SIDE (Minta JSON Daftar Foto) ---
// --- CASE 1: CLIENT SIDE - GET JSON PHOTOS ---
    if ($type === 'get_json_photos') {
        header('Content-Type: application/json');
        
        // Cek apakah request dari checkbox (Selected IDs) atau Filter
        if (isset($_POST['selected_ids']) && !empty($_POST['selected_ids'])) {
             $ids = $_POST['selected_ids'];
             $ph = implode(',', array_fill(0, count($ids), '?'));
             
             // [UPDATE SQL] Tambahkan locationName dan timestamp
             $sql = "SELECT id, itemType, photoPath, locationName, timestamp FROM geotags WHERE id IN ($ph) AND photoPath IS NOT NULL AND photoPath != ''";
             $stmt = $pdo->prepare($sql);
             $stmt->execute($ids);
        } else {
             // [UPDATE SQL] Tambahkan locationName dan timestamp
             $sql = "SELECT geotags.id, geotags.itemType, geotags.photoPath, geotags.locationName, geotags.timestamp FROM geotags LEFT JOIN projects ON geotags.projectId = projects.projectId " . ($where ? "WHERE " . implode(' AND ', $where) : "") . " AND geotags.photoPath IS NOT NULL AND geotags.photoPath != ''";
             $stmt = $pdo->prepare($sql);
             $stmt->execute($params);
        }

        $photos = [];
        while($r = $stmt->fetch()) {
            // 1. Bersihkan Nama
            $cleanLoc  = preg_replace('/[^A-Za-z0-9]/', '_', $r['locationName']);
            $cleanType = preg_replace('/[^A-Za-z0-9]/', '_', $r['itemType']);
            
            // 2. Format Tanggal
            $date = date('Y-m-d', strtotime($r['timestamp']));
            
            // 3. Susun Nama
            $finalName = $cleanLoc . '_' . $cleanType . '_' . $date . '_' . $r['id'] . '.jpg';

            $photos[] = [
                'name' => $finalName,
                'url'  => get_photo_url($r['photoPath'], $photo_base_url)
            ];
        }
        echo json_encode(['status' => 'success', 'data' => $photos]);
        exit;
    }
// ... (kode 'get_json_photos' yang lama biarkan di atas sini) ...

    // --- TAMBAHAN BARU: API UNTUK CLIENT-SIDE CSV/KML (Data Lengkap) ---
    // --- CASE 2: CLIENT SIDE - GET JSON FULL (Untuk PDF/CSV/KML Client) ---
    if ($type === 'get_json_full') {
        // [FIX UTAMA] Bersihkan semua output sampah (spasi/error) sebelum kirim JSON
        while (ob_get_level()) ob_end_clean();
        
        header('Content-Type: application/json');

        try {
            // Logic Filter sama seperti sebelumnya
            list($where, $params) = buildWhere('geotags', $pdo);
            
            if (isset($_POST['selected_ids']) && !empty($_POST['selected_ids'])) {
                 $ids = $_POST['selected_ids'];
                 $ph = implode(',', array_fill(0, count($ids), '?'));
                 $sql = "SELECT geotags.*, projects.officers FROM geotags LEFT JOIN projects ON geotags.projectId = projects.projectId WHERE geotags.id IN ($ph) ORDER BY geotags.id DESC";
                 $stmt = $pdo->prepare($sql);
                 $stmt->execute($ids);
            } else {
                 $sql = "SELECT geotags.*, projects.officers FROM geotags LEFT JOIN projects ON geotags.projectId = projects.projectId " . ($where ? "WHERE " . implode(' AND ', $where) : "") . " ORDER BY geotags.id DESC";
                 $stmt = $pdo->prepare($sql);
                 $stmt->execute($params);
            }

            $data = [];
            while($r = $stmt->fetch()) {
                // Pastikan URL foto valid
                $r['full_photo_url'] = get_photo_url($r['photoPath'], $photo_base_url);
                $data[] = $r;
            }
            
            echo json_encode(['status' => 'success', 'data' => $data]);
            
        } catch (Exception $e) {
            // Tangkap Error SQL jika ada
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    // --- END TAMBAHAN BARU ---
    // --- CASE 2: SERVER SIDE (Langsung Download File) ---
    // Siapkan query custom untuk fungsi export_data
    $custom_query = [ 
        'custom_where' => $where ? "WHERE " . implode(' AND ', $where) : "", 
        'params' => $params 
    ];

    if (in_array($type, ['csv', 'download_zip', 'kml'])) {
        // Ambil ID jika request dari checkbox bulk action
        $ids = $_POST['selected_ids'] ?? [];
        
        // Panggil fungsi export server side
        // Jika $ids ada isinya, dia export selected. Jika kosong, dia export filtered ($custom_query)
        export_data($pdo, $ids, $type, $photo_base_url, $photo_dir, empty($ids) ? $custom_query : null);
        exit;
    }
}

// --- 2. LOGOUT ---
if ($action === 'logout') { 
    session_destroy(); 
    header('Location: ?action=login'); 
    exit; 
}

// --- 3. POST REQUESTS HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // A. LOGIN SYSTEM
    if (isset($_POST['login'])) {
        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?"); 
        $stmt->execute([$_POST['username']??'']); 
        $user = $stmt->fetch();
        
        if ($user && password_verify($_POST['password']??'', $user['password_hash'])) {
            $_SESSION['auth'] = true; 
            $_SESSION['auth_time'] = time();
            $_SESSION['admin_id'] = $user['id']; 
            $_SESSION['admin_username'] = $user['username']; 
            $_SESSION['admin_role'] = $user['role'];
            $_SESSION['swal_success'] = "Selamat Datang, " . $user['username']; 
            header('Location: index.php'); exit;
        } else { 
            $_SESSION['swal_error'] = 'Username atau Password salah'; 
        }
    }

    // B. CSRF SECURITY CHECK
    if (isset($_POST['csrf_token'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            die('CSRF Validation Failed. Silakan refresh halaman.');
        }
        require_auth();

        // C. KML LAYER UPLOAD
        if (isset($_POST['upload_kml']) || isset($_POST['delete_kml'])) {
            if (!is_admin()) { 
                $_SESSION['swal_error'] = "Akses Ditolak! Hanya Admin."; 
                header("Location: ?action=layers"); exit; 
            }
            
            if (isset($_POST['delete_kml'])) {
                if (file_exists($kml_file_path)) unlink($kml_file_path);
                $_SESSION['swal_success'] = "Layer berhasil dihapus.";
            } else {
                $file = $_FILES['kml_file']; 
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if ($file['error'] !== UPLOAD_ERR_OK) { 
                    $_SESSION['swal_error'] = "Upload Error Code: " . $file['error']; 
                } elseif ($ext === 'kml') { 
                    move_uploaded_file($file['tmp_name'], $kml_file_path); 
                    $_SESSION['swal_success'] = "File KML berhasil dipasang!"; 
                } elseif ($ext === 'kmz') {
                    $zip = new ZipArchive;
                    if ($zip->open($file['tmp_name']) === TRUE) {
                        $found = false;
                        for($i=0; $i<$zip->numFiles; $i++) {
                            if(strtolower(pathinfo($zip->getNameIndex($i), PATHINFO_EXTENSION))==='kml') { 
                                copy("zip://".$file['tmp_name']."#".$zip->getNameIndex($i), $kml_file_path); 
                                $_SESSION['swal_success'] = "File KMZ berhasil diekstrak!"; 
                                $found = true; break; 
                            }
                        } 
                        $zip->close();
                        if(!$found) $_SESSION['swal_error'] = "File KML tidak ditemukan dalam KMZ.";
                    } else { $_SESSION['swal_error'] = "Gagal membuka file KMZ."; }
                } else { $_SESSION['swal_error'] = "Format file harus .kml atau .kmz"; }
            }
            header("Location: ?action=layers"); exit;
        }

        // D. CREATE / UPDATE DATA
        if (isset($_POST['update']) || isset($_POST['create'])) {
            try {
                $id = $_POST[$pk] ?? null; 
                
                if ($table == 'projects') {
                    if (isset($_POST['create'])) {
                        $pdo->prepare("INSERT INTO projects (projectId, activityName, locationName, officers, status) VALUES (?,?,?,?,?)")
                            ->execute([$_POST['projectId'], $_POST['activityName'], $_POST['locationName'], $_POST['officers'], $_POST['status']]);
                    } else {
                        $pdo->prepare("UPDATE projects SET activityName=?, locationName=?, officers=?, status=? WHERE projectId=?")
                            ->execute([$_POST['activityName'], $_POST['locationName'], $_POST['officers'], $_POST['status'], $id]);
                    }
                } elseif ($table == 'admin_users') {
                    $username = trim($_POST['username']); 
                    $role = $_POST['role'];
                    if (isset($_POST['create'])) {
                        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        $pdo->prepare("INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, ?)")->execute([$username, $hash, $role]);
                    } else {
                        if (!empty($_POST['password'])) {
                            $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                            $pdo->prepare("UPDATE admin_users SET username=?, role=?, password_hash=? WHERE id=?")->execute([$username, $role, $hash, $id]);
                        } else {
                            $pdo->prepare("UPDATE admin_users SET username=?, role=? WHERE id=?")->execute([$username, $role, $id]);
                        }
                    }
                    $_SESSION['swal_success'] = "Data User Tersimpan"; 
                    header("Location: ?action=users"); exit; 
                } elseif ($table == 'geotags') {
                    $params = [$_POST['itemType'], $_POST['condition'], $_POST['details'], $_POST['locationName'], $_POST['latitude'], $_POST['longitude'], $_POST['isSynced'], $_POST['projectId'] ?? 0];
                    if (isset($_POST['create'])) {
                        $pdo->prepare("INSERT INTO geotags (itemType, `condition`, details, locationName, latitude, longitude, isSynced, projectId) VALUES (?,?,?,?,?,?,?,?)")->execute($params);
                    } else {
                        $params[] = $id; 
                        $pdo->prepare("UPDATE geotags SET itemType=?, `condition`=?, details=?, locationName=?, latitude=?, longitude=?, isSynced=?, projectId=? WHERE id=?")->execute($params);
                    }
                }
                
                $_SESSION['swal_success'] = "Data Berhasil Disimpan"; 
                if ($table == 'admin_users') header("Location: ?action=users");
                else header("Location: " . get_redirect_smart($table)); 
                exit;
            } catch(Exception $e) { $_SESSION['swal_error'] = "Database Error: " . $e->getMessage(); }
        }

        // E. DELETE SINGLE
        if (isset($_POST['delete'])) {
            try {
                $id = $_POST['delete_id'];
                if ($table == 'geotags') {
                    $r = $pdo->query("SELECT photoPath FROM geotags WHERE id=$id")->fetch();
                    if ($r && $r['photoPath']) {
                        $filePath = $photo_dir . basename($r['photoPath']);
                        if (file_exists($filePath)) @unlink($filePath);
                    }
                }
                $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = ?")->execute([$id]);
                $_SESSION['swal_success'] = "Data Berhasil Dihapus"; 
                if ($table == 'admin_users') header("Location: ?action=users"); 
                else header("Location: " . get_redirect_smart($table)); 
                exit;
            } catch(Exception $e) { $_SESSION['swal_error'] = $e->getMessage(); }
        }

        // F. BULK ACTIONS
        if (isset($_POST['bulk_action'])) {
            $ids = $_POST['selected_ids'] ?? []; 
            $type = $_POST['bulk_action_type'] ?? '';
            
            if (!empty($ids)) {
                // Aksi Download/Export (Selected Checkboxes)
                if (in_array($type, ['download_zip', 'export_csv', 'export_kml']) && $table == 'geotags') {
                    $exportType = ($type=='download_zip') ? 'download_zip' : (($type=='export_kml') ? 'kml' : 'csv');
                    // set limit untuk bulk action juga
                    set_time_limit(0); ini_set('memory_limit', '1024M');
                    export_data($pdo, $ids, $exportType, $photo_base_url, $photo_dir);
                    exit;
                }
                
                // Aksi Edit Massal
                if ($type == 'mass_edit_type' && $table == 'geotags') {
                    $newType = $_POST['new_tree_type'] ?? '';
                    if (!empty($newType)) {
                        $placeholders = implode(',', array_fill(0, count($ids), '?')); 
                        $params = array_merge([$newType], $ids);
                        $pdo->prepare("UPDATE geotags SET itemType = ? WHERE id IN ($placeholders)")->execute($params);
                        $_SESSION['swal_success'] = count($ids) . " data berhasil diubah.";
                    } else { $_SESSION['swal_error'] = "Pilih jenis pohon baru!"; }
                } 
                
                // Aksi Hapus Massal
                elseif ($type == 'delete_selected') {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    if ($table == 'geotags') {
                        $stmt = $pdo->prepare("SELECT photoPath FROM geotags WHERE id IN ($placeholders)");
                        $stmt->execute($ids);
                        while($r = $stmt->fetch()) {
                            if($r['photoPath']) {
                                $filePath = $photo_dir . basename($r['photoPath']);
                                if(file_exists($filePath)) @unlink($filePath);
                            }
                        }
                    }
                    $pdo->prepare("DELETE FROM `$table` WHERE `$pk` IN ($placeholders)")->execute($ids);
                    $_SESSION['swal_success'] = count($ids) . " data berhasil dihapus.";
                }
                header("Location: " . get_redirect_smart($table)); exit;
            }
        }
    }
}
if ($action !== 'login') require_auth();
?>