<?php
// index.php - Public Dashboard (Admin-Style Sidebar Layout)

require_once 'api/config.php';
require_once 'api/db.php';

// Hapus header JSON agar browser me-render HTML
header('Content-Type: text/html; charset=utf-8');

// --- 1. KONEKSI DATABASE ---
try {
    $db = new Database();
    $conn = $db->getConnection();
} catch (Exception $e) {
    die("Error Database: " . htmlspecialchars($e->getMessage()));
}

// --- 2. LOGIC PHP (Fetch Data) ---

// Ambil Project Terakhir (Fitur Context)
function fetch_latest_project($conn) {
    $stmt = $conn->query("SELECT * FROM projects ORDER BY created_at DESC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Ambil Data Geotags dengan Filter
function fetch_geotags($conn, $search, $condition, $start, $end, $limit, $offset, &$total_records) {
    $sql = "SELECT * FROM geotags";
    $where = [];
    $params = [];

    if ($search) {
        $where[] = "(itemType LIKE ? OR locationName LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%";
    }
    if ($condition && $condition !== 'all') {
        $where[] = "`condition` = ?";
        $params[] = $condition;
    }
    if ($start) {
        $where[] = "timestamp >= ?";
        $params[] = $start . ' 00:00:00';
    }
    if ($end) {
        $where[] = "timestamp <= ?";
        $params[] = $end . ' 23:59:59';
    }

    if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);

    // Hitung Total (untuk Pagination)
    $countSql = str_replace("SELECT *", "SELECT COUNT(*)", $sql);
    $stmtC = $conn->prepare($countSql);
    $stmtC->execute($params);
    $total_records = $stmtC->fetchColumn();

    // Fetch Data Limit
    $sql .= " ORDER BY id DESC LIMIT $limit OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- 3. PROSES REQUEST ---
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Ambil Parameter Filter
$search = $_GET['search'] ?? '';
$cond = $_GET['condition'] ?? 'all';
$start = $_GET['start_date'] ?? '';
$end = $_GET['end_date'] ?? '';
$view = $_GET['view'] ?? 'map'; // Default view: map

// Fetch Data
$total_records = 0;
$geotags = fetch_geotags($conn, $search, $cond, $start, $end, $limit, $offset, $total_records);
$total_pages = ceil($total_records / $limit);
$latest_project = fetch_latest_project($conn);
$geotags_json = json_encode($geotags);

// Base URL Foto
$photo_base_url = 'https://seedloc.my.id/api/';

// Helper Link untuk mempertahankan filter saat pindah halaman/view
function build_url($params = []) {
    $current = $_GET;
    return '?' . http_build_query(array_merge($current, $params));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SeedLoc Public Dashboard</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        /* --- STYLE MIRIP ADMIN DASHBOARD --- */
        :root { --primary: #2E7D32; --light: #f4f6f8; --white: #fff; --text: #2c3e50; --border: #eee; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--light); margin: 0; color: var(--text); display: flex; height: 100vh; overflow: hidden; }

        /* Sidebar Styling */
        .sidebar { width: 260px; background: var(--white); border-right: 1px solid var(--border); display: flex; flex-direction: column; flex-shrink: 0; z-index: 1000; height: 100%; }
        .brand { padding: 25px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--border); }
        .brand img { width: 45px; border-radius: 8px; }
        .brand div h2 { font-size: 18px; margin: 0; color: var(--primary); font-weight: 700; }
        .brand div p { margin: 0; font-size: 12px; color: #888; }
        
        .nav-links { list-style: none; padding: 20px 0; margin: 0; flex: 1; }
        .nav-links li a { display: flex; align-items: center; gap: 12px; padding: 12px 25px; color: #555; text-decoration: none; font-weight: 500; transition: 0.2s; border-left: 4px solid transparent; }
        .nav-links li a:hover, .nav-links li a.active { background: #e8f5e9; color: var(--primary); border-left-color: var(--primary); }
        .nav-links li.divider { height: 1px; background: var(--border); margin: 10px 25px; }

        /* Main Content */
        .main-content { flex: 1; overflow-y: auto; padding: 25px; display: flex; flex-direction: column; }
        
        /* Components */
        .card { background: var(--white); padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 20px; border: 1px solid var(--border); }
        .info-box { background: #e3f2fd; border-left: 4px solid #2196f3; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .info-box h4 { margin: 0 0 5px; color: #1565c0; font-size: 15px; }
        .info-box p { margin: 0; font-size: 13px; color: #333; }

        /* Filter Bar */
        .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; background: var(--white); padding: 15px; border-radius: 10px; border: 1px solid var(--border); margin-bottom: 20px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { font-size: 12px; font-weight: 600; color: #777; margin-bottom: 4px; display: block; }
        .filter-input { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
        .btn { padding: 9px 15px; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; font-size: 13px; text-decoration: none; display: inline-block; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-reset { background: #e74c3c; color: white; }

        /* Map & Table Views */
        .view-section { display: none; flex: 1; }
        .view-section.active { display: block; }
        #map { width: 100%; height: 600px; border-radius: 12px; border: 1px solid var(--border); z-index: 1; }
        
        /* Table */
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; white-space: nowrap; }
        th { background: #f8f9fa; font-weight: 600; color: #555; }
        tr:hover { background: #f1f8e9; cursor: pointer; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge.Baik { background: #e8f5e9; color: #2e7d32; }
        .badge.Rusak { background: #ffebee; color: #c62828; }
        .badge.Cukup { background: #fff3cd; color: #f39c12; }
        
        /* Thumbnails */
        .thumb { width: 50px; height: 50px; object-fit: cover; border-radius: 6px; border: 1px solid #ddd; transition: transform 0.2s; }
        .thumb:hover { transform: scale(1.1); }

        /* Pagination */
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px; }
        .pagination .current { background: var(--primary); color: white; border-color: var(--primary); }

        /* Modal */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(3px); }
        .modal.open { display: flex; }
        .modal img { max-width: 90%; max-height: 90vh; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .modal-close { position: absolute; top: 20px; right: 30px; color: white; font-size: 30px; cursor: pointer; border: none; background: none; }
        .modal-caption { position: absolute; bottom: 20px; background: white; padding: 10px 20px; border-radius: 20px; font-weight: 600; color: #333; }

        @media (max-width: 768px) {
            .sidebar { width: 70px; }
            .brand div, .nav-links span { display: none; }
            .brand { padding: 15px; justify-content: center; }
            .nav-links li a { justify-content: center; padding: 15px; }
            .info-box, .filter-bar { flex-direction: column; }
            .filter-group { width: 100%; }
        }
    </style>
    <link rel="icon" href="https://seedloc.my.id/logo.png" type="image/png">
</head>
<body>

    <nav class="sidebar">
        <div class="brand">
            <img src="https://seedloc.my.id/logo.png" alt="Logo">
            <div>
                <h2>SeedLoc</h2>
                <p>Public Dashboard</p>
            </div>
        </div>
        <ul class="nav-links">
            <li>
                <a href="<?php echo build_url(['view'=>'map']); ?>" class="<?php echo $view==='map'?'active':''; ?>">
                    <i class="fas fa-map-marked-alt"></i> <span>Peta Sebaran</span>
                </a>
            </li>
            <li>
                <a href="<?php echo build_url(['view'=>'table']); ?>" class="<?php echo $view==='table'?'active':''; ?>">
                    <i class="fas fa-table"></i> <span>Data Tabel</span>
                </a>
            </li>
            
            <li class="divider"></li>
            
            <li>
                <a href="api/help.pdf" target="_blank">
                    <i class="fas fa-book"></i> <span>Panduan</span>
                </a>
            </li>
            <li>
                <a href="/admin.php">
                    <i class="fas fa-lock"></i> <span>Login Admin</span>
                </a>
            </li>
        </ul>
    </nav>

    <main class="main-content">
        
        <?php if ($latest_project): ?>
        <div class="info-box">
            <h4><i class="fas fa-info-circle"></i> Proyek Aktif Terakhir</h4>
            <p>
                <strong>Kegiatan:</strong> <?php echo htmlspecialchars($latest_project['activityName']); ?> 
                &nbsp;|&nbsp; <strong>Lokasi:</strong> <?php echo htmlspecialchars($latest_project['locationName']); ?>
                &nbsp;|&nbsp; <strong>Petugas:</strong> <?php echo htmlspecialchars($latest_project['officers']); ?>
            </p>
        </div>
        <?php endif; ?>

        <form class="filter-bar" method="GET">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
            
            <div class="filter-group">
                <label>Cari Nama/Lokasi</label>
                <input type="text" name="search" class="filter-input" placeholder="Keyword..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="filter-group">
                <label>Kondisi</label>
                <select name="condition" class="filter-input">
                    <option value="all">-- Semua --</option>
                    <?php foreach(['Baik','Cukup','Buruk','Rusak'] as $c): ?>
                        <option value="<?php echo $c; ?>" <?php echo $cond==$c?'selected':''; ?>><?php echo $c; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Dari Tanggal</label>
                <input type="date" name="start_date" class="filter-input" value="<?php echo htmlspecialchars($start); ?>">
            </div>
            
            <div class="filter-group">
                <label>Sampai Tanggal</label>
                <input type="date" name="end_date" class="filter-input" value="<?php echo htmlspecialchars($end); ?>">
            </div>
            
            <div style="display:flex; gap:5px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <?php if($search || $cond!='all' || $start): ?>
                    <a href="index.php?view=<?php echo $view; ?>" class="btn btn-reset"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </div>
        </form>

        <div class="view-section <?php echo $view==='map'?'active':''; ?>">
            <div class="card" style="padding:0; overflow:hidden;">
                <div id="map"></div>
            </div>
            <p style="font-size:13px; color:#666;"><i class="fas fa-lightbulb"></i> Klik ikon di peta untuk melihat detail dan foto.</p>
        </div>

        <div class="view-section <?php echo $view==='table'?'active':''; ?>">
            <div class="card" style="padding:0;">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Jenis Pohon</th>
                                <th>Lokasi</th>
                                <th>Koordinat</th>
                                <th>Kondisi</th>
                                <th>Waktu</th>
                                <th>Foto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($geotags)): ?>
                                <tr><td colspan="7" align="center" style="padding:30px;">Tidak ada data ditemukan.</td></tr>
                            <?php else: foreach($geotags as $row): 
                                $img = $row['photoPath'] ? ((strpos($row['photoPath'], 'http')===0) ? $row['photoPath'] : $photo_base_url.$row['photoPath']) : '';
                            ?>
                            <tr onclick="window.location='?view=map&search=<?php echo urlencode($row['itemType']); ?>'"> <td>#<?php echo $row['id']; ?></td>
                                <td><?php echo htmlspecialchars($row['itemType']); ?></td>
                                <td><?php echo htmlspecialchars($row['locationName']); ?></td>
                                <td><?php echo number_format($row['latitude'], 5) . ', ' . number_format($row['longitude'], 5); ?></td>
                                <td><span class="badge <?php echo $row['condition']; ?>"><?php echo $row['condition']; ?></span></td>
                                <td><?php echo date('d/M/Y H:i', strtotime($row['timestamp'])); ?></td>
                                <td>
                                    <?php if($img): ?>
                                        <img src="<?php echo $img; ?>" class="thumb" onclick="event.stopPropagation(); openModal('<?php echo $img; ?>', '<?php echo htmlspecialchars($row['itemType']); ?>')">
                                    <?php else: echo '-'; endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php 
                // Pertahankan semua parameter GET saat paging
                $queryParams = $_GET; 
                if($page > 1) {
                    $queryParams['page'] = $page - 1;
                    echo '<a href="?' . http_build_query($queryParams) . '">&laquo; Prev</a>';
                }
                
                for($i=1; $i<=$total_pages; $i++) {
                    $queryParams['page'] = $i;
                    $cls = ($i == $page) ? 'current' : '';
                    echo '<a href="?' . http_build_query($queryParams) . '" class="'.$cls.'">'.$i.'</a>';
                }

                if($page < $total_pages) {
                    $queryParams['page'] = $page + 1;
                    echo '<a href="?' . http_build_query($queryParams) . '">Next &raquo;</a>';
                }
                ?>
            </div>
            <?php endif; ?>
        </div>

    </main>

    <div id="photoModal" class="modal" onclick="closeModal()">
        <button class="modal-close">&times;</button>
        <img id="modalImg" src="" onclick="event.stopPropagation()"> <div id="modalCaption" class="modal-caption"></div>
    </div>

    <script>
        // --- 1. SETUP PETA ---
        const mapData = <?php echo $geotags_json; ?>;
        const photoBase = '<?php echo $photo_base_url; ?>';
        let map;

        function initMap() {
            if(!document.getElementById('map')) return;

            // Default: Jakarta
            let center = [-6.2088, 106.8456];
            // Jika ada data, pusatkan ke data pertama
            if(mapData.length > 0) {
                center = [parseFloat(mapData[0].latitude), parseFloat(mapData[0].longitude)];
            }

            map = L.map('map').setView(center, 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);

            let bounds = [];
            mapData.forEach(d => {
                let lat = parseFloat(d.latitude);
                let lng = parseFloat(d.longitude);
                if(isNaN(lat) || isNaN(lng)) return;

                // Siapkan URL Foto
                let imgUrl = '';
                if(d.photoPath) {
                    imgUrl = d.photoPath.startsWith('http') ? d.photoPath : photoBase + d.photoPath;
                }

                // Konten Popup
                let popupContent = `
                    <div style="min-width:150px">
                        <h4 style="margin:0 0 5px; color:#2E7D32;">${d.itemType}</h4>
                        <p style="margin:0; font-size:12px;"><b>Kondisi:</b> ${d.condition}</p>
                        <p style="margin:0 0 8px; font-size:12px;"><b>Lokasi:</b> ${d.locationName}</p>
                        ${imgUrl ? `<img src="${imgUrl}" style="width:100%; height:100px; object-fit:cover; border-radius:4px; cursor:pointer;" onclick="openModal('${imgUrl}', '${d.itemType}')">` : ''}
                    </div>
                `;

                L.marker([lat, lng]).addTo(map).bindPopup(popupContent);
                bounds.push([lat, lng]);
            });

            // Fit Bounds agar semua marker terlihat
            if(bounds.length > 0) {
                map.fitBounds(bounds, {padding: [50, 50]});
            }
        }

        // Jalankan initMap jika view aktif adalah map
        <?php if($view === 'map'): ?>
            document.addEventListener('DOMContentLoaded', initMap);
        <?php endif; ?>

        // --- 2. MODAL LOGIC ---
        function openModal(src, caption) {
            document.getElementById('modalImg').src = src;
            document.getElementById('modalCaption').innerText = caption;
            document.getElementById('photoModal').classList.add('open');
        }

        function closeModal() {
            document.getElementById('photoModal').classList.remove('open');
            document.getElementById('modalImg').src = '';
        }

        // Keyboard close
        document.addEventListener('keydown', function(e) {
            if(e.key === "Escape") closeModal();
        });
    </script>
</body>
</html>