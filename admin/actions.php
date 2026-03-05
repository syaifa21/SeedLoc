<?php
// actions.php - FULL RECODE (Fix Edit Map & Persistent Filter)

require_once 'config.php';
require_once 'functions.php';

// --- FUNGSI HELPER: SMART REDIRECT ---
// Fungsi ini mengembalikan user ke halaman list dengan filter yang sama persis
// Mengambil data dari input hidden "ret_*" yang dikirim dari form index.php
function get_redirect_smart($table) {
    // Default redirect
    $params = ['action' => 'list', 'table' => $table];
    
    // Daftar parameter filter yang perlu dijaga
    $filters = [
        'search', 'page', 'projectId', 'condition', 
        'start_date', 'end_date', 'locationName', 
        'itemType', 'has_photo', 'is_duplicate'
    ];
    
    foreach($filters as $key) {
        // Cek jika ada input hidden "ret_search", "ret_page", dll di $_POST
        if(isset($_POST['ret_'.$key]) && $_POST['ret_'.$key] !== '') {
            $params[$key] = $_POST['ret_'.$key];
        }
    }
    
    return '?' . http_build_query($params);
}

// --- 1. EXPORT HANDLER (GET REQUEST) ---
if ($action === 'export_full') {
    require_auth();
    $type = $_GET['type'] ?? 'csv';
    
    // Gunakan fungsi buildWhere yang sudah ada di functions.php untuk filter
    list($where, $params) = buildWhere('geotags', $pdo);
    $custom_query = [ 
        'custom_where' => $where ? "WHERE " . implode(' AND ', $where) : "", 
        'params' => $params 
    ];

    if (in_array($type, ['csv', 'download_zip', 'kml'])) {
        // Panggil fungsi export di functions.php
        export_data($pdo, [], $type, $photo_base_url, $photo_dir, $custom_query);
        exit;
    } else { 
        $_SESSION['swal_error'] = "Tipe export tidak valid."; 
        header("Location: ?action=list&table=geotags"); exit; 
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

    // B. CSRF SECURITY CHECK (Wajib untuk semua aksi POST selain login)
    if (isset($_POST['csrf_token'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            die('CSRF Validation Failed. Silakan refresh halaman.');
        }
        require_auth(); // Pastikan user login

        // C. KML LAYER UPLOAD (Map Overlay)
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
                }
                elseif ($ext === 'kml') { 
                    move_uploaded_file($file['tmp_name'], $kml_file_path); 
                    $_SESSION['swal_success'] = "File KML berhasil dipasang!"; 
                }
                elseif ($ext === 'kmz') {
                    // Extract KMZ (Zip)
                    $zip = new ZipArchive;
                    if ($zip->open($file['tmp_name']) === TRUE) {
                        $found = false;
                        for($i=0; $i<$zip->numFiles; $i++) {
                            if(strtolower(pathinfo($zip->getNameIndex($i), PATHINFO_EXTENSION))==='kml') { 
                                copy("zip://".$file['tmp_name']."#".$zip->getNameIndex($i), $kml_file_path); 
                                $_SESSION['swal_success'] = "File KMZ berhasil diekstrak & dipasang!"; 
                                $found = true; 
                                break; 
                            }
                        } 
                        $zip->close();
                        if(!$found) $_SESSION['swal_error'] = "File KML tidak ditemukan dalam KMZ.";
                    } else {
                        $_SESSION['swal_error'] = "Gagal membuka file KMZ.";
                    }
                } else {
                    $_SESSION['swal_error'] = "Format file harus .kml atau .kmz";
                }
            }
            header("Location: ?action=layers"); exit;
        }

        // D. CREATE / UPDATE DATA (Simpan Data Baru atau Edit)
        if (isset($_POST['update']) || isset($_POST['create'])) {
            try {
                $id = $_POST[$pk] ?? null; 
                
                // --- TABLE: PROJECTS ---
                if ($table == 'projects') {
                    if (isset($_POST['create'])) {
                        $pdo->prepare("INSERT INTO projects (projectId, activityName, locationName, officers, status) VALUES (?,?,?,?,?)")
                            ->execute([$_POST['projectId'], $_POST['activityName'], $_POST['locationName'], $_POST['officers'], $_POST['status']]);
                    } else {
                        $pdo->prepare("UPDATE projects SET activityName=?, locationName=?, officers=?, status=? WHERE projectId=?")
                            ->execute([$_POST['activityName'], $_POST['locationName'], $_POST['officers'], $_POST['status'], $id]);
                    }
                } 
                
                // --- TABLE: ADMIN_USERS ---
                elseif ($table == 'admin_users') {
                    $username = trim($_POST['username']); 
                    $role = $_POST['role'];
                    
                    if (isset($_POST['create'])) {
                        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        $pdo->prepare("INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, ?)")
                            ->execute([$username, $hash, $role]);
                    } else {
                        if (!empty($_POST['password'])) {
                            $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                            $pdo->prepare("UPDATE admin_users SET username=?, role=?, password_hash=? WHERE id=?")
                                ->execute([$username, $role, $hash, $id]);
                        } else {
                            $pdo->prepare("UPDATE admin_users SET username=?, role=? WHERE id=?")
                                ->execute([$username, $role, $id]);
                        }
                    }
                    $_SESSION['swal_success'] = "Data User Tersimpan"; 
                    header("Location: ?action=users"); exit; 
                } 
                
                // --- TABLE: GEOTAGS (Fix Edit Map Location) ---
                elseif ($table == 'geotags') {
                    // Pastikan urutan parameter sesuai dengan Query SQL
                    $params = [
                        $_POST['itemType'], 
                        $_POST['condition'], 
                        $_POST['details'], 
                        $_POST['locationName'], 
                        $_POST['latitude'],   // Ambil dari input form (terhubung JS Map)
                        $_POST['longitude'],  // Ambil dari input form (terhubung JS Map)
                        $_POST['isSynced'], 
                        $_POST['projectId'] ?? 0
                    ];

                    if (isset($_POST['create'])) {
                        $sql = "INSERT INTO geotags (itemType, `condition`, details, locationName, latitude, longitude, isSynced, projectId) VALUES (?,?,?,?,?,?,?,?)";
                        $pdo->prepare($sql)->execute($params);
                    } else {
                        // Tambahkan ID di akhir array untuk parameter WHERE id=?
                        $params[] = $id; 
                        $sql = "UPDATE geotags SET itemType=?, `condition`=?, details=?, locationName=?, latitude=?, longitude=?, isSynced=?, projectId=? WHERE id=?";
                        $pdo->prepare($sql)->execute($params);
                    }
                }
                
                $_SESSION['swal_success'] = "Data Berhasil Disimpan"; 
                // Gunakan Smart Redirect agar filter tidak hilang
                if ($table == 'admin_users') header("Location: ?action=users");
                else header("Location: " . get_redirect_smart($table)); 
                exit;

            } catch(Exception $e) { 
                $_SESSION['swal_error'] = "Database Error: " . $e->getMessage(); 
            }
        }

        // E. DELETE SINGLE (Hapus Satu Data)
        if (isset($_POST['delete'])) {
            try {
                $id = $_POST['delete_id'];
                
                // Hapus Foto dulu jika ada (Khusus Geotags)
                if ($table == 'geotags') {
                    $r = $pdo->query("SELECT photoPath FROM geotags WHERE id=$id")->fetch();
                    if ($r && $r['photoPath']) {
                        // Pastikan path aman dan ada di folder uploads
                        $filePath = $photo_dir . basename($r['photoPath']);
                        if (file_exists($filePath)) @unlink($filePath);
                    }
                }
                
                // Hapus Data Database
                $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = ?")->execute([$id]);
                $_SESSION['swal_success'] = "Data Berhasil Dihapus"; 
                
                // Smart Redirect
                if ($table == 'admin_users') header("Location: ?action=users"); 
                else header("Location: " . get_redirect_smart($table)); 
                exit;
                
            } catch(Exception $e) { $_SESSION['swal_error'] = $e->getMessage(); }
        }

        // F. BULK ACTIONS (Aksi Massal)
        if (isset($_POST['bulk_action'])) {
            $ids = $_POST['selected_ids'] ?? []; 
            $type = $_POST['bulk_action_type'] ?? '';
            
            if (!empty($ids)) {
                // Aksi Download/Export (Langsung Exit)
                if (in_array($type, ['download_zip', 'export_csv', 'export_kml']) && $table == 'geotags') {
                    $exportType = ($type=='download_zip') ? 'download_zip' : (($type=='export_kml') ? 'kml' : 'csv');
                    export_data($pdo, $ids, $exportType, $photo_base_url, $photo_dir);
                    exit;
                }
                
                // Aksi Edit Massal (Jenis Pohon)
                if ($type == 'mass_edit_type' && $table == 'geotags') {
                    $newType = $_POST['new_tree_type'] ?? '';
                    if (!empty($newType)) {
                        $placeholders = implode(',', array_fill(0, count($ids), '?')); 
                        $params = array_merge([$newType], $ids);
                        
                        $pdo->prepare("UPDATE geotags SET itemType = ? WHERE id IN ($placeholders)")->execute($params);
                        $_SESSION['swal_success'] = count($ids) . " data berhasil diubah ke: $newType";
                    } else { 
                        $_SESSION['swal_error'] = "Silakan pilih jenis pohon baru!"; 
                    }
                } 
                
                // Aksi Hapus Massal
                elseif ($type == 'delete_selected') {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    
                    // Hapus Foto Fisik
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
                    
                    // Hapus Data Database
                    $pdo->prepare("DELETE FROM `$table` WHERE `$pk` IN ($placeholders)")->execute($ids);
                    $_SESSION['swal_success'] = count($ids) . " data berhasil dihapus permanen.";
                }
                
                // Redirect Balik (Smart)
                header("Location: " . get_redirect_smart($table)); exit;
            }
        }
    }
}

// Redirect jika akses langsung tanpa login (tapi biasanya sudah dicek di atas)
if ($action !== 'login') require_auth();
?>