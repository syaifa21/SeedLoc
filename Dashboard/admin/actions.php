<?php
// actions.php - FULL RECODE

// --- EXPORT HANDLER (GET REQUEST) ---
if ($action === 'export_full') {
    require_auth();
    $type = $_GET['type'] ?? 'csv';
    list($where, $params) = buildWhere('geotags', $pdo);
    $custom_query = [ 'custom_where' => $where ? "WHERE " . implode(' AND ', $where) : "", 'params' => $params ];

    if (in_array($type, ['csv', 'download_zip', 'kml'])) {
        export_data($pdo, [], $type, $photo_base_url, $photo_dir, $custom_query);
    } else { 
        $_SESSION['swal_error'] = "Parameter salah."; 
        header("Location: ?action=list&table=geotags"); exit; 
    }
}

// --- LOGOUT ---
if ($action === 'logout') { session_destroy(); header('Location: ?action=login'); exit; }

// --- POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. LOGIN
    if (isset($_POST['login'])) {
        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?"); 
        $stmt->execute([$_POST['username']??'']); $user = $stmt->fetch();
        if ($user && password_verify($_POST['password']??'', $user['password_hash'])) {
            $_SESSION['auth'] = true; $_SESSION['auth_time'] = time();
            $_SESSION['admin_id'] = $user['id']; $_SESSION['admin_username'] = $user['username']; $_SESSION['admin_role'] = $user['role'];
            $_SESSION['swal_success'] = "Login Berhasil"; header('Location: index.php'); exit;
        } else { $_SESSION['swal_error'] = 'Username atau Password salah'; }
    }

    // CSRF CHECK
    if (isset($_POST['csrf_token'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) die('CSRF Validation Failed');
        require_auth(); 

        // HELPER: REDIRECT WITH FILTERS
        function get_redirect_url($table) {
            $redirect_query = ['action'=>'list', 'table'=>$table];
            $filters = ['search', 'page', 'projectId', 'condition', 'start_date', 'end_date', 'locationName', 'itemType', 'has_photo', 'is_duplicate'];
            foreach($filters as $k) { if(!empty($_POST['ret_'.$k])) $redirect_query[$k] = $_POST['ret_'.$k]; }
            return '?' . http_build_query($redirect_query);
        }

        // 2. KML ACTIONS
        if (isset($_POST['upload_kml']) || isset($_POST['delete_kml'])) {
            if (!is_admin()) { $_SESSION['swal_error'] = "Akses Ditolak!"; header("Location: ?action=layers"); exit; }
            if (isset($_POST['delete_kml'])) {
                if (file_exists($kml_file_path)) unlink($kml_file_path);
                $_SESSION['swal_success'] = "Layer dihapus.";
            } else {
                $file = $_FILES['kml_file']; $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($file['error'] !== UPLOAD_ERR_OK) { $_SESSION['swal_error'] = "Upload Error: " . $file['error']; }
                elseif ($ext === 'kml') { move_uploaded_file($file['tmp_name'], $kml_file_path); $_SESSION['swal_success'] = "KML Uploaded!"; }
                elseif ($ext === 'kmz') {
                    $zip = new ZipArchive;
                    if ($zip->open($file['tmp_name']) === TRUE) {
                        for($i=0; $i<$zip->numFiles; $i++) {
                            if(strtolower(pathinfo($zip->getNameIndex($i), PATHINFO_EXTENSION))==='kml') { 
                                copy("zip://".$file['tmp_name']."#".$zip->getNameIndex($i), $kml_file_path); 
                                $_SESSION['swal_success'] = "KMZ Extracted!"; break; 
                            }
                        } $zip->close();
                    }
                }
            }
            header("Location: ?action=layers"); exit;
        }

        // 3. CREATE / UPDATE
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
                    // (Logic Admin User sama seperti sebelumnya)
                    $username = trim($_POST['username']); $role = $_POST['role'];
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
                    $_SESSION['swal_success'] = "Data User Tersimpan"; header("Location: ?action=users"); exit; 
                } elseif ($table == 'geotags') {
                    $p = [$_POST['itemType'], $_POST['condition'], $_POST['details'], $_POST['locationName'], $_POST['latitude'], $_POST['longitude'], $_POST['isSynced'], $_POST['projectId'] ?? 0];
                    if (isset($_POST['create'])) {
                        $pdo->prepare("INSERT INTO geotags (itemType, `condition`, details, locationName, latitude, longitude, isSynced, projectId) VALUES (?,?,?,?,?,?,?,?)")->execute($p);
                    } else {
                        $p[] = $id;
                        $pdo->prepare("UPDATE geotags SET itemType=?, `condition`=?, details=?, locationName=?, latitude=?, longitude=?, isSynced=?, projectId=? WHERE id=?")->execute($p);
                    }
                }
                $_SESSION['swal_success'] = "Data Berhasil Disimpan"; header("Location: " . get_redirect_url($table)); exit;
            } catch(Exception $e) { $_SESSION['swal_error'] = $e->getMessage(); }
        }

        // 4. DELETE SINGLE
        if (isset($_POST['delete'])) {
            try {
                $id = $_POST['delete_id'];
                if ($table == 'geotags') {
                    $r=$pdo->query("SELECT photoPath FROM geotags WHERE id=$id")->fetch();
                    if($r['photoPath']) @unlink($photo_dir.basename($r['photoPath']));
                }
                $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = ?")->execute([$id]);
                $_SESSION['swal_success'] = "Data Dihapus"; 
                if ($table == 'admin_users') header("Location: ?action=users"); else header("Location: " . get_redirect_url($table)); exit;
            } catch(Exception $e) { $_SESSION['swal_error'] = $e->getMessage(); }
        }

        // 5. BULK ACTIONS
        if (isset($_POST['bulk_action'])) {
            $ids = $_POST['selected_ids'] ?? []; 
            $type = $_POST['bulk_action_type'] ?? '';
            
            if (!empty($ids)) {
                // A. Direct Download (No Redirect)
                if (in_array($type, ['download_zip', 'export_csv', 'export_kml']) && $table == 'geotags') {
                    export_data($pdo, $ids, ($type=='download_zip'?'download_zip':($type=='export_kml'?'kml':'csv')), $photo_base_url, $photo_dir);
                    exit;
                }
                
                // B. Action with Redirect
                if ($type == 'mass_edit_type' && $table == 'geotags') {
                    $newType = $_POST['new_tree_type'] ?? '';
                    if (!empty($newType)) {
                        $ph = implode(',', array_fill(0, count($ids), '?')); $params = array_merge([$newType], $ids);
                        $pdo->prepare("UPDATE geotags SET itemType = ? WHERE id IN ($ph)")->execute($params);
                        $_SESSION['swal_success'] = count($ids) . " data diubah ke: $newType";
                    } else { $_SESSION['swal_error'] = "Pilih jenis pohon baru!"; }
                } 
                elseif ($type == 'delete_selected') {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    if ($table == 'geotags') {
                        $f=$pdo->prepare("SELECT photoPath FROM geotags WHERE id IN ($ph)"); $f->execute($ids);
                        while($r=$f->fetch()) if($r['photoPath']) @unlink($photo_dir.basename($r['photoPath']));
                    }
                    $pdo->prepare("DELETE FROM `$table` WHERE `$pk` IN ($ph)")->execute($ids);
                    $_SESSION['swal_success'] = count($ids) . " data dihapus";
                }
                header("Location: " . get_redirect_url($table)); exit;
            }
        }
    }
}
if ($action !== 'login') require_auth();
?>