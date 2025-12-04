<?php
// Dashboard/index.php - Updated: Separate Data Fetching for Map (All) & Table (Paginated)

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

function fetch_latest_project($conn) {
    $stmt = $conn->query("SELECT * FROM projects ORDER BY created_at DESC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Helper untuk Filter Query
function build_filter_query($search, $condition, $start, $end) {
    $sql = " FROM geotags";
    $where = [];
    $params = [];

    if ($search) {
        $where[] = "(itemType LIKE ? OR locationName LIKE ? OR details LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
    }
    if ($condition && $condition !== 'all') {
        $where[] = "`condition` = ?";
        $params[] = $condition;
    }
    if ($start) { $where[] = "timestamp >= ?"; $params[] = $start . ' 00:00:00'; }
    if ($end) { $where[] = "timestamp <= ?"; $params[] = $end . ' 23:59:59'; }

    if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
    
    return ['sql' => $sql, 'params' => $params];
}

// Fetch untuk TABEL (Pakai Limit & Offset)
function fetch_geotags_table($conn, $base_query, $params, $limit, $offset) {
    $sql = "SELECT * " . $base_query . " ORDER BY id DESC LIMIT $limit OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch untuk PETA (AMBIL SEMUA DATA tanpa limit)
function fetch_geotags_map($conn, $base_query, $params) {
    // Kita ambil field yg diperlukan peta saja biar ringan jika datanya ribuan
    $sql = "SELECT id, latitude, longitude, itemType, locationName, `condition`, photoPath, details, timestamp " . $base_query . " ORDER BY id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Hitung Total Data
function count_records($conn, $base_query, $params) {
    $sql = "SELECT COUNT(*) " . $base_query;
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

// Fetch Statistik Chart
function fetch_stats_data($conn, $base_query, $params) {
    $sql = "SELECT `condition`, COUNT(*) as total " . $base_query . " GROUP BY `condition`";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// --- 3. PROSES REQUEST ---
$limit = 20; // Limit Tabel
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Ambil Parameter Filter
$search = $_GET['search'] ?? '';
$cond = $_GET['condition'] ?? 'all';
$start = $_GET['start_date'] ?? '';
$end = $_GET['end_date'] ?? '';
$view = $_GET['view'] ?? 'map';

// Build Query Dasar
$filter = build_filter_query($search, $cond, $start, $end);

// 1. Ambil Data Tabel (Terbatas 20)
$geotags_table = fetch_geotags_table($conn, $filter['sql'], $filter['params'], $limit, $offset);

// 2. Ambil Data Peta (SEMUA DATA)
$geotags_map = fetch_geotags_map($conn, $filter['sql'], $filter['params']);

// 3. Hitung Total & Stats
$total_records = count_records($conn, $filter['sql'], $filter['params']);
$total_pages = ceil($total_records / $limit);
$chart_data = fetch_stats_data($conn, $filter['sql'], $filter['params']);
$latest_project = fetch_latest_project($conn);

// Encode JSON (Gunakan data MAP yang full)
$geotags_json = json_encode($geotags_map); 
$chart_json = json_encode($chart_data);

// Base URL Foto
$photo_base_url = 'https://seedloc.my.id/api/';

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
    <title>SeedLoc Dashboard</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css" />
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root { --primary: #2E7D32; --light: #f4f6f8; --white: #fff; --text: #2c3e50; --border: #eee; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--light); margin: 0; color: var(--text); display: flex; height: 100vh; overflow: hidden; }

        /* --- Sidebar --- */
        .sidebar { width: 260px; background: var(--white); border-right: 1px solid var(--border); display: flex; flex-direction: column; flex-shrink: 0; z-index: 1000; height: 100%; transition: width 0.3s; }
        .brand { padding: 25px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--border); }
        .brand img { width: 40px; }
        .brand div h2 { font-size: 18px; margin: 0; color: var(--primary); font-weight: 700; }
        .brand div p { margin: 0; font-size: 12px; color: #888; }
        
        .nav-links { list-style: none; padding: 20px 0; margin: 0; flex: 1; }
        .nav-links li a { display: flex; align-items: center; gap: 12px; padding: 12px 25px; color: #555; text-decoration: none; font-weight: 500; transition: 0.2s; border-left: 4px solid transparent; }
        .nav-links li a:hover, .nav-links li a.active { background: #e8f5e9; color: var(--primary); border-left-color: var(--primary); }
        .nav-links li.divider { height: 1px; background: var(--border); margin: 10px 25px; }

        /* --- Main Content --- */
        .main-content { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; position: relative; }
        
        /* --- Cards & Layout --- */
        .top-grid { display: grid; grid-template-columns: 3fr 1fr; gap: 20px; margin-bottom: 20px; }
        .card { background: var(--white); padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); border: 1px solid var(--border); }
        
        /* --- Filters --- */
        .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; background: var(--white); padding: 15px; border-radius: 10px; border: 1px solid var(--border); margin-bottom: 20px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 140px; }
        .filter-group label { font-size: 11px; font-weight: 700; color: #777; margin-bottom: 4px; display: block; text-transform: uppercase; }
        .filter-input { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; box-sizing: border-box; }
        .btn { padding: 9px 15px; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; font-size: 13px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-reset { background: #e74c3c; color: white; }

        /* --- Map Area --- */
        .view-section { display: none; flex: 1; position: relative; }
        .view-section.active { display: block; }
        #map { width: 100%; height: 600px; border-radius: 12px; z-index: 1; border: 1px solid var(--border); }

        /* --- Side Panel (Slide Out) --- */
        .side-panel {
            position: fixed; top: 0; right: -400px; width: 380px; height: 100vh;
            background: white; box-shadow: -5px 0 15px rgba(0,0,0,0.1);
            z-index: 2000; transition: right 0.3s ease; display: flex; flex-direction: column;
        }
        .side-panel.open { right: 0; }
        .sp-header { padding: 20px; background: var(--primary); color: white; display: flex; justify-content: space-between; align-items: center; }
        .sp-close { background: none; border: none; color: white; font-size: 20px; cursor: pointer; }
        .sp-content { padding: 20px; overflow-y: auto; flex: 1; }
        .sp-img { width: 100%; height: 250px; object-fit: cover; border-radius: 8px; margin-bottom: 15px; cursor: pointer; border: 1px solid #eee; }
        .sp-info-row { margin-bottom: 12px; border-bottom: 1px solid #f9f9f9; padding-bottom: 8px; }
        .sp-label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; display: block; }
        .sp-value { font-size: 14px; font-weight: 500; color: #333; }

        /* --- Table --- */
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; }
        th { background: #f8f9fa; font-weight: 600; color: #555; position: sticky; top: 0; }
        tr:hover { background: #f1f8e9; cursor: pointer; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge.Baik { background: #e8f5e9; color: #2e7d32; }
        .badge.Rusak { background: #ffebee; color: #c62828; }
        .badge.Mati { background: #ffebee; color: #b71c1c; }
        .badge.Cukup { background: #fff3cd; color: #f39c12; }
        .thumb { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; }

        /* --- Stats Chart --- */
        .chart-container { position: relative; height: 180px; width: 100%; display: flex; align-items: center; justify-content: center; }
        
        /* --- Pagination --- */
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px; }
        .pagination .current { background: var(--primary); color: white; border-color: var(--primary); }

        /* --- Modal --- */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); z-index: 3000; align-items: center; justify-content: center; }
        .modal.open { display: flex; }
        .modal img { max-width: 90%; max-height: 90vh; border-radius: 4px; }
        .modal-close { position: absolute; top: 20px; right: 30px; color: white; font-size: 30px; cursor: pointer; background: none; border: none; }

        @media (max-width: 900px) {
            .top-grid { grid-template-columns: 1fr; }
            .sidebar { width: 0; overflow: hidden; }
            .sidebar.active { width: 260px; }
            .side-panel { width: 100%; right: -100%; }
        }
    </style>
    <link rel="icon" href="https://seedloc.my.id/logo.png" type="image/png">
</head>
<body>

    <nav class="sidebar" id="sidebar">
        <div class="brand">
            <img src="https://seedloc.my.id/logo.png" alt="Logo">
            <div>
                <h2>SeedLoc</h2>
                <p>Dashboard</p>
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
                <a href="Dashboard/admin.php">
                    <i class="fas fa-lock"></i> <span>Login Admin</span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="side-panel" id="sidePanel">
        <div class="sp-header">
            <h3 style="margin:0; font-size:16px;" id="spTitle">Detail Data</h3>
            <button class="sp-close" onclick="closeSidePanel()">&times;</button>
        </div>
        <div class="sp-content" id="spBody">
            </div>
    </div>

    <main class="main-content">
        
        <div class="top-grid">
            <div class="card" style="border-left: 4px solid var(--primary); display: flex; flex-direction: column; justify-content: center;">
                <h4 style="margin: 0 0 10px; color: var(--primary);"><i class="fas fa-info-circle"></i> Status Proyek Terkini</h4>
                <?php if ($latest_project): ?>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div>
                            <span style="font-size:11px; color:#888;">KEGIATAN</span><br>
                            <b><?php echo htmlspecialchars($latest_project['activityName']); ?></b>
                        </div>
                        <div>
                            <span style="font-size:11px; color:#888;">LOKASI</span><br>
                            <b><?php echo htmlspecialchars($latest_project['locationName']); ?></b>
                        </div>
                        <div>
                            <span style="font-size:11px; color:#888;">PETUGAS</span><br>
                            <span style="font-size:13px;"><?php echo htmlspecialchars($latest_project['officers']); ?></span>
                        </div>
                        <div>
                            <span style="font-size:11px; color:#888;">TOTAL DATA (ALL)</span><br>
                            <b style="font-size:18px; color: var(--primary);"><?php echo $total_records; ?></b>
                        </div>
                    </div>
                <?php else: ?>
                    <p>Belum ada proyek aktif.</p>
                <?php endif; ?>
            </div>

            <div class="card" style="display:flex; flex-direction:column; align-items:center; justify-content:center;">
                <h5 style="margin:0 0 10px; font-size:13px; color:#666;">Statistik Kondisi</h5>
                <div class="chart-container">
                    <canvas id="conditionChart"></canvas>
                </div>
            </div>
        </div>

        <form class="filter-bar" method="GET">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
            
            <div class="filter-group">
                <label>Pencarian</label>
                <input type="text" name="search" class="filter-input" placeholder="Nama Pohon / Lokasi..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="filter-group">
                <label>Kondisi</label>
                <select name="condition" class="filter-input">
                    <option value="all">Semua</option>
                    <?php foreach(['Baik','Rusak','Mati','Cukup','Buruk'] as $c): ?>
                        <option value="<?php echo $c; ?>" <?php echo $cond==$c?'selected':''; ?>><?php echo $c; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Tanggal Mulai</label>
                <input type="date" name="start_date" class="filter-input" value="<?php echo htmlspecialchars($start); ?>">
            </div>
            
            <div class="filter-group">
                <label>Tanggal Akhir</label>
                <input type="date" name="end_date" class="filter-input" value="<?php echo htmlspecialchars($end); ?>">
            </div>
            
            <div style="display:flex; gap:5px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <?php if($search || $cond!='all' || $start): ?>
                    <a href="index.php?view=<?php echo $view; ?>" class="btn btn-reset"><i class="fas fa-sync"></i></a>
                <?php endif; ?>
            </div>
        </form>

        <div class="view-section <?php echo $view==='map'?'active':''; ?>">
            <div class="card" style="padding:0; overflow:hidden;">
                <div id="map"></div>
            </div>
            <p style="font-size:12px; color:#666; margin-top:5px;">
                <i class="fas fa-info-circle"></i> Menampilkan <b><?php echo count($geotags_map); ?></b> titik lokasi (Semua data sesuai filter).
            </p>
        </div>

        <div class="view-section <?php echo $view==='table'?'active':''; ?>">
            <div class="card" style="padding:0;">
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Jenis Pohon</th>
                                <th>Lokasi</th>
                                <th>Kondisi</th>
                                <th>Waktu</th>
                                <th>Foto</th>
                                <th>Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($geotags_table)): ?>
                                <tr><td colspan="7" align="center" style="padding:30px;">Tidak ada data ditemukan.</td></tr>
                            <?php else: foreach($geotags_table as $row): 
                                $img = $row['photoPath'] ? ((strpos($row['photoPath'], 'http')===0) ? $row['photoPath'] : $photo_base_url.$row['photoPath']) : '';
                                $jsonData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr onclick="openSidePanelByData(<?php echo $jsonData; ?>)">
                                <td><b>#<?php echo $row['id']; ?></b></td>
                                <td><?php echo htmlspecialchars($row['itemType']); ?></td>
                                <td><?php echo htmlspecialchars($row['locationName']); ?></td>
                                <td><span class="badge <?php echo $row['condition']; ?>"><?php echo $row['condition']; ?></span></td>
                                <td><?php echo date('d/m/y H:i', strtotime($row['timestamp'])); ?></td>
                                <td>
                                    <?php if($img): ?>
                                        <img src="<?php echo $img; ?>" class="thumb" onclick="event.stopPropagation(); openModal('<?php echo $img; ?>')">
                                    <?php else: echo '-'; endif; ?>
                                </td>
                                <td><button class="btn btn-primary" style="padding:4px 8px; font-size:11px;">Lihat</button></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php 
                $q = $_GET; 
                if($page > 1) { $q['page'] = $page - 1; echo '<a href="?'.http_build_query($q).'">&laquo; Prev</a>'; }
                
                for($i=max(1, $page-2); $i<=min($total_pages, $page+2); $i++) {
                    $q['page'] = $i;
                    $cls = ($i == $page) ? 'current' : '';
                    echo '<a href="?'.http_build_query($q).'" class="'.$cls.'">'.$i.'</a>';
                }

                if($page < $total_pages) { $q['page'] = $page + 1; echo '<a href="?'.http_build_query($q).'">Next &raquo;</a>'; }
                ?>
            </div>
            <?php endif; ?>
        </div>

    </main>

    <div id="photoModal" class="modal" onclick="closeModal()">
        <button class="modal-close">&times;</button>
        <img id="modalImg" src="" onclick="event.stopPropagation()">
    </div>

    <script>
        // Data Peta (Menggunakan FULL DATA)
        const mapData = <?php echo $geotags_json; ?>;
        const chartData = <?php echo $chart_json; ?>;
        const photoBase = '<?php echo $photo_base_url; ?>';
        let map;

        function initMap() {
            if(!document.getElementById('map')) return;

            const streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' });
            const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: '&copy; Esri' });

            let center = [-6.2088, 106.8456];
            if(mapData.length > 0) center = [parseFloat(mapData[0].latitude), parseFloat(mapData[0].longitude)];
            
            map = L.map('map', {
                center: center,
                zoom: 13,
                layers: [streets]
            });

            L.control.layers({ "Peta Jalan": streets, "Satelit": satellite }).addTo(map);

            // Marker Clustering
            const markers = L.markerClusterGroup();
            
            mapData.forEach(d => {
                let lat = parseFloat(d.latitude);
                let lng = parseFloat(d.longitude);
                if(isNaN(lat) || isNaN(lng)) return;

                let color = 'blue';
                if(d.condition === 'Baik') color = 'green';
                else if(d.condition === 'Rusak' || d.condition === 'Mati') color = 'red';
                else if(d.condition === 'Cukup') color = 'orange';

                const icon = L.divIcon({
                    className: 'custom-div-icon',
                    html: `<div style="background-color:${color}; width:12px; height:12px; border-radius:50%; border:2px solid white; box-shadow:0 0 3px black;"></div>`,
                    iconSize: [12, 12],
                    iconAnchor: [6, 6]
                });

                const marker = L.marker([lat, lng], {icon: icon});
                marker.on('click', () => {
                    openSidePanel(d);
                    map.panTo([lat, lng]);
                });
                markers.addLayer(marker);
            });

            map.addLayer(markers);

            if(markers.getBounds().isValid()) {
                map.fitBounds(markers.getBounds(), {padding: [50, 50]});
            }
        }

        function initChart() {
            const ctx = document.getElementById('conditionChart');
            if(!ctx) return;
            const labels = Object.keys(chartData);
            const data = Object.values(chartData);
            const bgColors = labels.map(l => {
                if(l === 'Baik') return '#4caf50'; 
                if(l === 'Rusak' || l === 'Mati') return '#f44336'; 
                if(l === 'Cukup') return '#ff9800'; 
                return '#2196f3';
            });

            new Chart(ctx, {
                type: 'doughnut',
                data: { labels: labels, datasets: [{ data: data, backgroundColor: bgColors, borderWidth: 1 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: {size: 11} } } } }
            });
        }

        function openSidePanel(data) {
            const panel = document.getElementById('sidePanel');
            const body = document.getElementById('spBody');
            const title = document.getElementById('spTitle');
            
            let imgUrl = data.photoPath ? (data.photoPath.startsWith('http') ? data.photoPath : photoBase + data.photoPath) : '';
            let imgHtml = imgUrl ? `<img src="${imgUrl}" class="sp-img" onclick="openModal('${imgUrl}')">` : `<div style="height:100px; background:#eee; display:flex; align-items:center; justify-content:center; border-radius:8px; margin-bottom:15px; color:#999;">Tidak ada foto</div>`;
            let condColor = data.condition === 'Baik' ? 'green' : (data.condition === 'Rusak' ? 'red' : 'orange');

            title.innerText = `Geotag #${data.id}`;
            body.innerHTML = `
                ${imgHtml}
                <div class="sp-info-row"><span class="sp-label">Jenis Pohon</span><span class="sp-value">${data.itemType}</span></div>
                <div class="sp-info-row"><span class="sp-label">Lokasi</span><span class="sp-value"><i class="fas fa-map-marker-alt" style="color:red"></i> ${data.locationName}</span></div>
                <div class="sp-info-row"><span class="sp-label">Kondisi</span><span class="sp-value" style="color:${condColor}; font-weight:bold;">${data.condition}</span></div>
                <div class="sp-info-row"><span class="sp-label">Koordinat</span><span class="sp-value" style="font-family:monospace;">${parseFloat(data.latitude).toFixed(6)}, ${parseFloat(data.longitude).toFixed(6)}</span></div>
                <div class="sp-info-row"><span class="sp-label">Waktu</span><span class="sp-value">${data.timestamp}</span></div>
                <div class="sp-info-row"><span class="sp-label">Catatan</span><span class="sp-value" style="font-size:13px; line-height:1.4;">${data.details || '-'}</span></div>
            `;
            panel.classList.add('open');
        }

        function openSidePanelByData(dataObj) { openSidePanel(dataObj); }
        function closeSidePanel() { document.getElementById('sidePanel').classList.remove('open'); }
        function openModal(src) { document.getElementById('modalImg').src = src; document.getElementById('photoModal').classList.add('open'); }
        function closeModal() { document.getElementById('photoModal').classList.remove('open'); }

        document.addEventListener('DOMContentLoaded', () => {
            if(document.getElementById('map')) initMap();
            if(document.getElementById('conditionChart')) initChart();
        });
    </script>
</body>
</html>