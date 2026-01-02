<?php
// actions.php

// --- EXPORT HANDLER ---
if ($action === 'export_full') {
    require_auth();
    $pid = $_GET['projectId'] ?? 'all'; $type = $_GET['type'] ?? 'csv';
    if (in_array($type, ['csv', 'download_zip', 'kml'])) export_data($pdo, [], $type, $photo_base_url, $photo_dir, $pid);
    else { $_SESSION['swal_error'] = "Parameter salah."; header("Location: ?action=list&table=geotags"); exit; }
}

// --- LOGOUT ---
if ($action === 'logout') { session_destroy(); header('Location: ?action=login'); exit; }

// --- POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. LOGIN
    if (isset($_POST['login'])) {
        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?"); $stmt->execute([$_POST['username']??'']); $user = $stmt->fetch();
        if ($user && password_verify($_POST['password']??'', $user['password_hash'])) {
            $_SESSION['auth'] = true; $_SESSION['auth_time'] = time();
            $_SESSION['admin_id'] = $user['id']; 
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_role'] = $user['role'];
            $_SESSION['swal_success'] = "Login Berhasil"; header('Location: index.php'); exit;
        } else { $_SESSION['swal_error'] = 'Username atau Password salah'; }
    }

    // CSRF CHECK FOR OTHER POSTS
    if (isset($_POST['csrf_token'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) die('CSRF Validation Failed');
        
        require_auth(); 

        // 2. KML UPLOAD (FIXED: Upload ke folder admin)
        if (isset($_POST['upload_kml'])) {
            if (!is_admin()) { $_SESSION['swal_error'] = "Akses Ditolak!"; header("Location: ?action=layers"); exit; }
            $file = $_FILES['kml_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($file['error'] !== UPLOAD_ERR_OK) { $_SESSION['swal_error'] = "Upload Error Code: " . $file['error']; } 
            elseif (!is_writable($layer_dir)) { $_SESSION['swal_error'] = "Permission denied pada folder admin ($layer_dir). Coba chmod 755."; }
            else {
                if ($ext === 'kml') {
                    if(move_uploaded_file($file['tmp_name'], $kml_file_path)) $_SESSION['swal_success'] = "Layer KML berhasil diupload!";
                    else $_SESSION['swal_error'] = "Gagal memindahkan file KML.";
                } elseif ($ext === 'kmz') {
                    if (!class_exists('ZipArchive')) { $_SESSION['swal_error'] = "Butuh ekstensi ZipArchive."; } 
                    else {
                        $zip = new ZipArchive;
                        if ($zip->open($file['tmp_name']) === TRUE) {
                            $foundKml = false;
                            for($i = 0; $i < $zip->numFiles; $i++) {
                                $entryName = $zip->getNameIndex($i);
                                if (strtolower(pathinfo($entryName, PATHINFO_EXTENSION)) === 'kml') {
                                    copy("zip://".$file['tmp_name']."#".$entryName, $kml_file_path); $foundKml = true; break;
                                }
                            }
                            $zip->close();
                            if($foundKml) $_SESSION['swal_success'] = "Layer KMZ berhasil diekstrak!";
                            else $_SESSION['swal_error'] = "Tidak ada file .kml dalam KMZ.";
                        } else { $_SESSION['swal_error'] = "File KMZ Corrupt."; }
                    }
                } else { $_SESSION['swal_error'] = "Format harus .kml atau .kmz"; }
            }
            header("Location: ?action=layers"); exit;
        }

        // 3. KML DELETE
        if (isset($_POST['delete_kml'])) {
            if (!is_admin()) { $_SESSION['swal_error'] = "Akses Ditolak!"; header("Location: ?action=layers"); exit; }
            if (file_exists($kml_file_path)) unlink($kml_file_path);
            $_SESSION['swal_success'] = "Layer dihapus."; header("Location: ?action=layers"); exit;
        }

        // 4. CREATE / UPDATE
        if (isset($_POST['update']) || isset($_POST['create'])) {
            try {
                $id = $_POST[$pk] ?? null; 
                if ($table == 'projects') {
                    if (isset($_POST['create'])) {
                        $check = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE projectId = ?"); $check->execute([$_POST['projectId']]);
                        if($check->fetchColumn() > 0) throw new Exception("Project ID sudah ada!");
                        $sql = "INSERT INTO projects (projectId, activityName, locationName, officers, status) VALUES (?,?,?,?,?)";
                        $params = [$_POST['projectId'], $_POST['activityName'], $_POST['locationName'], $_POST['officers'], $_POST['status']];
                    } else {
                        $sql = "UPDATE projects SET activityName=?, locationName=?, officers=?, status=? WHERE projectId=?";
                        $params = [$_POST['activityName'], $_POST['locationName'], $_POST['officers'], $_POST['status'], $id];
                    }
                    $pdo->prepare($sql)->execute($params);

                } elseif ($table == 'admin_users') {
                    $username = trim($_POST['username']); $role = $_POST['role'];
                    if (isset($_POST['create'])) {
                        if(empty($_POST['password'])) throw new Exception("Password wajib diisi!");
                        $check = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?"); $check->execute([$username]);
                        if($check->fetchColumn() > 0) throw new Exception("Username sudah digunakan!");
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
                    $_SESSION['swal_success'] = "Data Admin berhasil disimpan"; header("Location: ?action=users"); exit; 

                } elseif ($table == 'geotags') {
                    $common_params = [$_POST['itemType'], $_POST['condition'], $_POST['details'], $_POST['locationName'], $_POST['latitude'], $_POST['longitude'], $_POST['isSynced'], $_POST['projectId'] ?? 0];
                    if (isset($_POST['create'])) {
                        $sql = "INSERT INTO geotags (itemType, `condition`, details, locationName, latitude, longitude, isSynced, projectId) VALUES (?,?,?,?,?,?,?,?)";
                        $params = $common_params;
                    } else {
                        $sql = "UPDATE geotags SET itemType=?, `condition`=?, details=?, locationName=?, latitude=?, longitude=?, isSynced=?, projectId=? WHERE id=?";
                        $params = $common_params; $params[] = $id; 
                    }
                    $pdo->prepare($sql)->execute($params);
                }
                $_SESSION['swal_success'] = "Data berhasil disimpan"; 
                
                if($table != 'admin_users') {
                    $redirect_query = ['action'=>'list', 'table'=>$table];
                    foreach(['search','page','condition','projectId','start_date','end_date'] as $k) {
                        if(!empty($_POST['ret_'.$k])) $redirect_query[$k] = $_POST['ret_'.$k];
                    }
                    header("Location: ?" . http_build_query($redirect_query)); exit; 
                }

            } catch(Exception $e) { $_SESSION['swal_error'] = $e->getMessage(); }
        }

        // 5. DELETE (FIXED: Pakai $photo_dir untuk hapus foto)
        if (isset($_POST['delete'])) {
            try {
                $id_to_delete = $_POST['delete_id'];
                if ($table == 'admin_users' && $id_to_delete == $_SESSION['admin_id']) throw new Exception("Tidak bisa menghapus diri sendiri!");
                
                if($table=='geotags'){ 
                    $r=$pdo->query("SELECT photoPath FROM geotags WHERE id=$id_to_delete")->fetch(); 
                    if($r['photoPath']) @unlink($photo_dir.basename($r['photoPath'])); 
                }
                if($table=='projects'){ 
                    $ps=$pdo->prepare("SELECT photoPath FROM geotags WHERE projectId=?"); $ps->execute([$id_to_delete]); 
                    while($ph=$ps->fetch()) @unlink($photo_dir.basename($ph['photoPath'])); 
                    $pdo->prepare("DELETE FROM geotags WHERE projectId = ?")->execute([$id_to_delete]); 
                }
                
                $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = ?")->execute([$id_to_delete]);
                $_SESSION['swal_success'] = "Data berhasil dihapus"; 
                if ($table == 'admin_users') header("Location: ?action=users"); else header("Location: ?action=list&table=$table"); exit;
            } catch(Exception $e) { $_SESSION['swal_error'] = $e->getMessage(); }
        }

        // 6. BULK ACTIONS (FIXED: Pakai $photo_dir untuk export/hapus)
        if (isset($_POST['bulk_action'])) {
            $ids = $_POST['selected_ids'] ?? []; $type = $_POST['bulk_action_type'] ?? '';
            if (!empty($ids)) {
                if ($type == 'download_zip' && $table == 'geotags') export_data($pdo, $ids, 'download_zip', $photo_base_url, $photo_dir);
                elseif ($type == 'export_csv' && $table == 'geotags') export_data($pdo, $ids, 'csv', $photo_base_url, $photo_dir);
                elseif ($type == 'export_kml' && $table == 'geotags') export_data($pdo, $ids, 'kml', $photo_base_url, $photo_dir);
                elseif ($type == 'delete_selected') {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    if($table=='geotags'){ 
                        $f=$pdo->prepare("SELECT photoPath FROM geotags WHERE id IN ($ph)"); $f->execute($ids); 
                        while($r=$f->fetch()) if($r['photoPath']) @unlink($photo_dir.basename($r['photoPath'])); 
                    }
                    $pdo->prepare("DELETE FROM `$table` WHERE `$pk` IN ($ph)")->execute($ids);
                    $_SESSION['swal_success'] = count($ids) . " data dihapus"; header("Location: ?action=list&table=$table"); exit;
                } elseif ($type == 'mark_synced' && $table == 'geotags') {
                    $ph = implode(',', array_fill(0, count($ids), '?')); $pdo->prepare("UPDATE geotags SET isSynced = 1 WHERE id IN ($ph)")->execute($ids);
                    $_SESSION['swal_success'] = "Sync status diperbarui"; header("Location: ?action=list&table=$table"); exit;
                }
            }
        }
    }
}
if ($action !== 'login') require_auth();
?>