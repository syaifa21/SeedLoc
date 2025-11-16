<?php
// Dashboard/index.php - Ver. Final Comprehensive (Fix Pop-up Foto & Semua Fitur)

require_once 'api/config.php';
require_once 'api/db.php';

// Hapus header JSON agar browser bisa me-render HTML
header('Content-Type: text/html; charset=utf-8');

// --- 1. KONEKSI KE DATABASE ---
try {
    $db = new Database();
    $conn = $db->getConnection();
} catch (Exception $e) {
    // Tampilan error yang lebih halus
    die("
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; background-color: #fcebeb; color: #5a1e1e; padding: 50px; }
        .error-box { max-width: 600px; margin: 0 auto; padding: 30px; background-color: #fff; border: 1px solid #e0b4b4; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.05); }
        h1 { color: #d32f2f; border-bottom: 2px solid #d32f2f; padding-bottom: 10px; }
    </style>
    <div class='error-box'>
        <h1>Dashboard Error</h1>
        <p><strong>Koneksi database gagal.</strong> Mohon periksa kembali konfigurasi di <code>api/config.php</code>.</p>
        <p><strong>Detail Teknis:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
    </div>
    ");
}

// --- 2. FUNGSI AMBIL DATA & KONTEKS ---
function fetch_latest_project($conn) {
    $stmt = $conn->query("SELECT * FROM projects ORDER BY created_at DESC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function fetch_geotags($conn, $search_query = null, $condition_filter = null, $start_date = null, $end_date = null, $limit = 20, $offset = 0, &$total_records = 0) {
    $sql = "SELECT id, latitude, longitude, locationName, timestamp, itemType, `condition`, photoPath, details FROM geotags";
    $params = [];
    $where = [];

    // Filter Pencarian Dasar
    if ($search_query) {
        // PERUBAHAN KRITIS: Tambahkan 'details' ke dalam kriteria pencarian.
        $where[] = "(itemType LIKE ? OR locationName LIKE ? OR details LIKE ?)"; 
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%"; // Parameter ketiga untuk details
    }
    
    // Filter Kondisi
    if ($condition_filter && $condition_filter !== 'all') {
        $where[] = "`condition` = ?";
        $params[] = $condition_filter;
    }
    
    // Filter Rentang Tanggal
    if ($start_date) {
        $where[] = "timestamp >= ?";
        $params[] = $start_date . ' 00:00:00';
    }
    if ($end_date) {
        $where[] = "timestamp <= ?";
        $params[] = $end_date . ' 23:59:59';
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    
    // Hitung Total Record (Untuk Paginasi)
    $countSql = str_replace("SELECT id, latitude, longitude, locationName, timestamp, itemType, `condition`, photoPath, details", "SELECT COUNT(*)", $sql);
    $stmtCount = $conn->prepare($countSql);
    $stmtCount->execute($params);
    $total_records = $stmtCount->fetchColumn();

    // Tambahkan Ordering dan Paginasi
    $sql .= " ORDER BY id DESC LIMIT $limit OFFSET $offset";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params); 
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- 3. AMBIL DATA DAN APLIKASIKAN FILTER ---
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$condition_filter = isset($_GET['condition']) ? $_GET['condition'] : 'all';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

$total_records = 0;
$geotags = fetch_geotags($conn, $search_query, $condition_filter, $start_date, $end_date, $limit, $offset, $total_records);
$total_pages = ceil($total_records / $limit);

$geotags_json = json_encode($geotags);

// Data Konteks Proyek
$latest_project = fetch_latest_project($conn);

// --- 4. BASE URL untuk Foto ---
$photo_base_url = 'https://seedloc.my.id/api/';
$conditions_list = ['Baik', 'Cukup', 'Buruk', 'Rusak'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SeedLoc Web Dashboard</title>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* CSS yang dimodifikasi untuk Logo dan UI */
        :root { --primary-color: #2E7D32; --secondary-color: #66BB6A; --background-light: #f4f6f8; --text-dark: #2c3e50; --muted: #7f8c8d; --shadow-subtle: 0 6px 20px rgba(0,0,0,0.06); --radius-default: 10px; }
        * { box-sizing: border-box; }
        body { 
            font-family: 'Poppins', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial; 
            margin: 0; 
            background-color: var(--background-light); 
            color: var(--text-dark); 
            line-height: 1.5; 
        }
        .container { max-width: 1220px; margin: 28px auto; padding: 26px; background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(255,255,255,0.98)); box-shadow: var(--shadow-subtle); border-radius: 14px; min-height: 60vh; }
        header.app-header { display: flex; align-items: center; gap: 16px; justify-content: space-between; margin-bottom: 18px; }
        .brand { display: flex; gap: 12px; align-items: center; text-decoration: none; color: inherit; }
        .brand .logo { width: 56px; height: 56px; border-radius: 12px; box-shadow: 0 6px 18px rgba(46,125,50,0.15); font-size: 22px; font-weight: 600; padding: 0; background: transparent; }
        .brand .logo img { width: 100%; height: 100%; object-fit: contain; border-radius: 12px; }
        .brand h1 { font-size: 20px; margin: 0; }
        .brand p { margin: 0; color: var(--muted); font-size: 13px; }
        .header-actions { display: flex; gap: 10px; align-items: center; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(0,0,0,0.06); background: white; cursor: pointer; font-weight: 600; color: var(--text-dark); text-decoration: none; }
        .btn.primary { background: var(--primary-color); color: white; border: none; box-shadow: 0 6px 16px rgba(46,125,50,0.12); }

        .filter-area { display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; align-items: flex-end;}
        .filter-area .search-form, .filter-area .date-filter { flex-grow: 1; min-width: 200px;}
        .filter-area select, .filter-area input[type="text"], .filter-area input[type="date"] { padding: 10px 12px; border: 1px solid #ccc; border-radius: 8px; font-size: 14px; width: 100%; }
        .filter-area label { font-size: 12px; color: var(--muted); font-weight: 600; margin-bottom: 4px; display: block; }
        .filter-area button { padding: 10px 18px; background-color: #3498db; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 700; transition: background-color 0.3s; }
        .filter-area button:hover { background-color: #2980b9; }

        .data-stats { background-color: rgba(46,125,50,0.06); padding: 14px 18px; border-radius: 12px; margin-bottom: 18px; display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; border-left: 4px solid var(--secondary-color); }
        .stats-left { display:flex; gap:14px; align-items:center; }
        .stats-left p { margin: 0; color: var(--muted); font-weight: 600; }
        .stats-left p strong { color: var(--primary-color); font-size: 1.1em; }
        
        .tabs { display:flex; gap:8px; margin-bottom: 12px; }
        .tab { padding: 8px 14px; border-radius: 10px; border: 1px solid transparent; background: rgba(255,255,255,0.9); cursor: pointer; font-weight: 600; }
        .tab[aria-selected="true"] { 
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color)); 
            color: white; 
            box-shadow: 0 8px 20px rgba(46,125,50,0.12); 
            border-color: rgba(0,0,0,0.04); 
        }
        .panel { display: none; }
        .panel.active { display: block; }

        #mapid { height: 560px; border-radius: 12px; margin-bottom: 18px; border: 1px solid #e6e9ec; box-shadow: var(--shadow-subtle); }
        .data-table-container { overflow: auto; max-height: 560px; border: 1px solid #e6e9ec; border-radius: 12px; box-shadow: var(--shadow-subtle); background: white; }
        .data-table { width: 100%; border-collapse: collapse; min-width: 1000px; } /* UBAH MIN-WIDTH UNTUK AKOMODASI DETAIL */
        .data-table th, .data-table td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #f1f4f6; font-size: 14px; vertical-align: middle; }
        .data-table th { background-color: #fbfdfe; color: var(--muted); position: sticky; top: 0; z-index: 2; text-transform: uppercase; font-size: 12px; letter-spacing: 0.04em; }
        .data-table tr:hover { background: #fbfff9; cursor: pointer; }
        .photo-thumb { width: 72px; height: 72px; object-fit: cover; border-radius: 8px; cursor: pointer; border: 2px solid #f0f0f0; transition: transform 0.18s, box-shadow 0.18s; }
        .photo-thumb:hover { transform: scale(1.06); box-shadow: 0 8px 22px rgba(0,0,0,0.08); border-color: var(--secondary-color); }
        .popup-content strong { color: var(--primary-color); }
        .popup-content p { font-size: 13px; margin: 4px 0; color: #384048; }

        /* Paginasi */
        .pagination { margin-top: 15px; display: flex; justify-content: center; align-items: center; gap: 8px; }
        .pagination a, .pagination span { text-decoration: none; padding: 6px 12px; border-radius: 6px; border: 1px solid #ddd; color: var(--text-dark); font-weight: 600; }
        .pagination a:hover { background: #eee; }
        .pagination .current { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        
        .project-context { background-color: #ecf0f1; padding: 12px 18px; border-radius: 8px; margin-bottom: 15px; border-left: 3px solid #3498db; }
        .project-context p { margin: 5px 0; font-size: 14px; }

        /* Modal Foto */
        .modal { 
            display: none; 
            position: fixed; 
            z-index: 9999; 
            inset: 0;
            padding: 40px;
            background-color: rgba(0,0,0,0.6); 
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
        }
        .modal.open { display: flex; }
        .modal-content { 
            max-width: 1100px; 
            width: 100%;
            max-height: 90vh;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 16px 40px rgba(0,0,0,0.6);
        }
        .modal-content img { width: 100%; height: auto; display: block; object-fit: contain; background: #000; }
        .modal-close {
            position: absolute;
            right: 36px;
            top: 28px;
            background: rgba(0,0,0,0.4);
            color: #fff;
            border: none;
            width: 46px;
            height: 46px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .modal-caption { padding: 12px 18px; background: white; color: var(--muted); font-size: 13px; }
        
        /* Loading overlay */
        .loading-overlay {
            position: absolute;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 999;
        }
        .loading-overlay.show { display: flex; }
        .spinner {
            width: 56px; height: 56px; border-radius: 50%;
            border: 6px solid rgba(46,125,50,0.12);
            border-top-color: var(--primary-color);
            animation: spin 1s linear infinite;
            box-shadow: 0 8px 20px rgba(46,125,50,0.12);
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div class="container" role="main">
    <header class="app-header" role="banner">
        <a class="brand" href="index.php" aria-label="SeedLoc Dashboard">
            <div class="logo" aria-hidden="true">
                <img src="https://seedloc.my.id/logo.png" alt="SeedLoc Logo">
            </div>
            <div>
                <h1>SeedLoc Dashboard</h1>
                <p>Geotag & dokumentasi lapangan — Sistem internal</p>
            </div>
        </a>

        <div class="header-actions" role="navigation" aria-label="Aksi cepat">
            <a class="btn" href="api/help.pdf" target="_blank" rel="noopener"> <i class="fas fa-file-alt"></i> Panduan </a>
            <a class="btn" href="admin.php" target="_blank" rel="noopener"> <i class="fas fa-user-shield"></i> Admin Panel </a>
        </div>
    </header>

    <?php if ($latest_project): ?>
    <div class="project-context">
        <p><strong><i class="fas fa-folder-open"></i> Proyek Aktif Terakhir:</strong></p>
        <p>Kegiatan: <strong><?php echo htmlspecialchars($latest_project['activityName']); ?></strong> (ID: <?php echo htmlspecialchars($latest_project['projectId']); ?>) | Lokasi: <?php echo htmlspecialchars($latest_project['locationName']); ?> | Petugas: <?php echo htmlspecialchars($latest_project['officers']); ?></p>
    </div>
    <?php endif; ?>

    <form class="filter-area" method="GET" action="index.php" role="search" aria-label="Filter data geotag">
        
        <div class="search-form">
            <label for="searchInput">Cari Nama/Lokasi/Detail</label>
            <input id="searchInput" type="text" name="search" placeholder="Cari Nama Pohon, Lokasi, atau Detail..." value="<?php echo htmlspecialchars($search_query); ?>">
        </div>

        <div class="date-filter">
            <label for="conditionFilter">Filter Kondisi</label>
            <select name="condition" id="conditionFilter">
                <option value="all">-- Semua Kondisi --</option>
                <?php 
                $conditions_list = ['Baik', 'Cukup', 'Buruk', 'Rusak'];
                foreach ($conditions_list as $cond): ?>
                    <option value="<?php echo $cond; ?>" <?php echo (isset($condition_filter) && $condition_filter === $cond) ? 'selected' : ''; ?>>
                        <?php echo $cond; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="date-filter">
            <label for="startDate">Mulai Tanggal</label>
            <input type="date" name="start_date" id="startDate" value="<?php echo (isset($start_date)) ? htmlspecialchars($start_date) : ''; ?>">
        </div>
        
        <div class="date-filter">
            <label for="endDate">Sampai Tanggal</label>
            <input type="date" name="end_date" id="endDate" value="<?php echo (isset($end_date)) ? htmlspecialchars($end_date) : ''; ?>">
        </div>

        <button type="submit" aria-label="Terapkan Filter"><i class="fas fa-filter"></i> Filter</button>
        <?php if ($search_query || (isset($condition_filter) && $condition_filter !== 'all') || isset($start_date) || isset($end_date)): ?>
            <a href="index.php" class="btn" title="Hapus semua filter" style="background: #e74c3c; color: white; font-weight: 700;"><i class="fas fa-times-circle"></i> Reset</a>
        <?php endif; ?>
    </form>

    <div class="data-stats" role="status" aria-live="polite">
        <div class="stats-left">
            <p>Total Data Ditemukan: <strong><?php echo $total_records; ?></strong></p>
            <?php if ($search_query || (isset($condition_filter) && $condition_filter !== 'all') || isset($start_date) || isset($end_date)): ?>
                <p class="search-info">Hasil Filter: <strong><?php echo $total_records; ?></strong> dari Total Halaman: <strong><?php echo $total_pages; ?></strong></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="tabs" role="tablist" aria-label="Tampilan">
        <button class="tab" role="tab" aria-selected="true" data-target="panel-map" id="tab-map"> <i class="fas fa-map-marked-alt"></i> Peta </button>
        <button class="tab" role="tab" aria-selected="false" data-target="panel-table" id="tab-table"> <i class="fas fa-table"></i> Tabel </button>
    </div>

    <div id="panel-map" class="panel active" role="tabpanel" aria-labelledby="tab-map">
        <div style="position:relative;">
            <div id="mapid" aria-label="Peta geotag"></div>
            <div id="mapLoading" class="loading-overlay" aria-hidden="true">
                <div class="spinner" role="status" aria-label="Memuat peta"></div>
            </div>
            </div>
    </div>

    <div id="panel-table" class="panel" role="tabpanel" aria-labelledby="tab-table">
        <h2 style="margin: 12px 0 10px 0; font-size: 18px; color: var(--primary-color);"><i class="fas fa-list-alt"></i> Data Geotag (Halaman <?php echo $page; ?>)</h2>

        <div class="data-table-container" aria-live="polite">
            <table class="data-table" id="dataTable" role="table" aria-label="Tabel data geotag">
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Nama Pohon</th>
                        <th scope="col">Lokasi</th>
                        <th scope="col" style="min-width: 150px;">Detail Penting</th>
                        
                        <th scope="col">Koordinat (Lat, Lng)</th>
                        <th scope="col">Waktu</th>
                        <th scope="col">Kondisi</th>
                        <th scope="col">Foto</th>
                    </tr>
                </thead>
                <tbody id="data-table-body">
                    <?php if (empty($geotags)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: gray; padding: 30px;">
                                <i class="fas fa-info-circle"></i> Tidak ada data geotag yang ditemukan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($geotags as $tag):
                            // PHP untuk Tampilan Tabel
                            $formatted_timestamp = date("d/m/Y H:i:s", strtotime($tag['timestamp']));

                            $photo_path_in_db = $tag['photoPath'];
                            $photo_full_url = '';

                            // LOGIC PHOTO PATH (preserve)
                            if (!empty($photo_path_in_db)) {
                                if (strpos($photo_path_in_db, 'http') === 0 || strpos($photo_path_in_db, 'https') === 0) {
                                    $photo_full_url = $photo_path_in_db;
                                } else {
                                    $photo_full_url = $photo_base_url . $photo_path_in_db;
                                }
                            }
                        ?>
                        <tr class="data-row" data-id="<?php echo $tag['id']; ?>" data-lat="<?php echo $tag['latitude']; ?>" data-lng="<?php echo $tag['longitude']; ?>">
                            <td><?php echo $tag['id']; ?></td>
                            <td><?php echo htmlspecialchars($tag['itemType']); ?></td>
                            <td><?php echo htmlspecialchars($tag['locationName']); ?></td>
                            
                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($tag['details']); ?>">
                                <?php echo htmlspecialchars(substr($tag['details'], 0, 80)) . (strlen($tag['details']) > 80 ? '...' : ''); ?>
                            </td>
                            
                            <td><?php echo number_format($tag['latitude'], 6) . ', ' . number_format($tag['longitude'], 6); ?></td>
                            <td><?php echo $formatted_timestamp; ?></td>
                            <td><?php echo htmlspecialchars($tag['condition']); ?></td>
                            <td>
                                <?php if ($photo_full_url): ?>
                                    <img src="<?php echo $photo_full_url; ?>"
                                         onerror="this.onerror=null;this.src='https://via.placeholder.com/72?text=NO+IMG';"
                                         alt="Foto" 
                                         class="photo-thumb" 
                                         title="Klik untuk Lihat"
                                         onclick="event.stopPropagation(); openModal(<?php echo json_encode($photo_full_url); ?>, <?php echo json_encode(htmlspecialchars($tag['itemType'])); ?>, <?php echo json_encode(htmlspecialchars($tag['locationName'])); ?>)">
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination">
            <?php 
            $queryString = http_build_query(array_filter(['search' => $search_query, 'condition' => $condition_filter, 'start_date' => $start_date, 'end_date' => $end_date]));
            
            if ($page > 1): ?>
                <a href="?<?php echo $queryString; ?>&page=<?php echo $page - 1; ?>">« Sebelumnya</a>
            <?php endif; 
            
            for ($i = 1; $i <= $total_pages; $i++):
                if ($i === $page): ?>
                    <span class="current"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?<?php echo $queryString; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                <?php endif;
            endfor;

            if ($page < $total_pages): ?>
                <a href="?<?php echo $queryString; ?>&page=<?php echo $page + 1; ?>">Berikutnya »</a>
            <?php endif; ?>
        </div>
    </div>

    <footer>
        © <?php echo date('Y'); ?> SeedLoc — Aplikasi pencatatan geotag. Hubungi admin untuk bantuan.
    </footer>
</div>

<div id="photoModal" class="modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="modalCaption">
    <div style="position: relative; width: 100%; max-width: 1100px;">
        <button class="modal-close" aria-label="Tutup" onclick="closeModal()"><i class="fas fa-times"></i></button>
        <div class="modal-content" tabindex="-1">
            <img id="modalImage" src="" alt="">
        </div>
        <div id="modalCaption" class="modal-caption" aria-live="polite"></div>
    </div>
</div>

<script>
    // --- Data dari PHP ---
    const geotagsData = <?php echo $geotags_json; ?> || [];
    const PHOTO_BASE_URL = '<?php echo $photo_base_url; ?>';

    // --- Modal Foto ---
    const modal = document.getElementById('photoModal');
    const modalImage = document.getElementById('modalImage');
    const modalCaption = document.getElementById('modalCaption');
    let lastFocused = null;

    // FUNGSI INI AKAN DIPANGGIL KETIKA THUMBNAIL DI KLIK
    function openModal(photoUrl, title = '', location = '') {
        if (!photoUrl) return;
        lastFocused = document.activeElement;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden','false');
        modalImage.src = photoUrl;
        modalImage.alt = title ? `${title} - ${location}` : 'Foto geotag';
        modalCaption.textContent = title ? `${title} — ${location}` : location || '';
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) closeBtn.focus();
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden','true');
        modalImage.src = '';
        modalCaption.textContent = '';
        document.body.style.overflow = '';
        if (lastFocused) lastFocused.focus();
    }

    modal.addEventListener('click', function(e){
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });

    // --- Tab Switching Logic (Fix Tombol Tabel) ---
    (function(){
        const tabs = document.querySelectorAll('.tab');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function(){
                // 1. Ganti status selected pada tombol
                tabs.forEach(t => t.setAttribute('aria-selected','false'));
                this.setAttribute('aria-selected','true');

                // 2. Tampilkan panel yang sesuai
                document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
                const target = document.getElementById(this.dataset.target);
                if (target) target.classList.add('active');

                // 3. PENTING: Memaksa peta untuk mengukur ulang ukurannya saat tab 'Peta' diaktifkan
                if (this.dataset.target === 'panel-map' && window._seedloc_map) {
                    setTimeout(() => { 
                        try { 
                            window._seedloc_map.invalidateSize(); 
                        } catch(e) {
                            console.error('Error invalidating map size:', e);
                        }
                    }, 260);
                }
            });
        });
    })();
    // --- Akhir Tab Switching Logic ---


    // --- Leaflet Map Initialization (Versi Dasar) ---
    function initializeMap() {
        const mapEl = document.getElementById('mapid');
        const loadingOverlay = document.getElementById('mapLoading');
        if (!mapEl) return;

        loadingOverlay.classList.add('show');

        const defaultCenter = [-6.2088, 106.8456];
        let initialCenter = defaultCenter;
        
        const validGeotags = geotagsData.filter(g => {
            const lat = parseFloat(g.latitude);
            const lng = parseFloat(g.longitude);
            return !isNaN(lat) && !isNaN(lng) && (lat !== 0 || lng !== 0);
        });

        if (validGeotags.length > 0) {
            initialCenter = [parseFloat(validGeotags[0].latitude), parseFloat(validGeotags[0].longitude)];
        }

        try {
            // Inisialisasi peta dasar
            const map = L.map('mapid', { preferCanvas: true, zoomControl: true }).setView(initialCenter, 13);
            window._seedloc_map = map; 

            // Layer OSM default
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                subdomains: ['a','b','c'],
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            const markersById = {};
            const bounds = [];

            validGeotags.forEach(geotag => {
                const lat = parseFloat(geotag.latitude);
                const lng = parseFloat(geotag.longitude);

                bounds.push([lat,lng]);

                let photoUrl = '';
                if (geotag.photoPath) {
                    if (geotag.photoPath.startsWith('http') || geotag.photoPath.startsWith('https')) {
                        photoUrl = geotag.photoPath;
                    } else {
                        photoUrl = PHOTO_BASE_URL + geotag.photoPath;
                    }
                }

                let dateObj;
                try { dateObj = new Date(geotag.timestamp.replace(' ', 'T')); } catch(e) { dateObj = new Date(geotag.timestamp); }
                const formattedTime = isNaN(dateObj.getTime()) ? geotag.timestamp : dateObj.toLocaleString('id-ID', {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit'});

                const popupContent = `
                    <div class="popup-content" style="min-width:220px;">
                        <h5 style="margin:0; color: var(--primary-color); font-size:1.05em;">
                            <i class="fas fa-seedling"></i> ${escapeHtml(geotag.itemType || 'Data')} <span style="font-size:0.75em; color:#999;">(ID:${geotag.id})</span>
                        </h5>
                        <hr style="margin:8px 0;">
                        <p><strong>Lokasi:</strong> ${escapeHtml(geotag.locationName || '-')}</p>
                        <p><strong>Koordinat:</strong> ${lat.toFixed(6)}, ${lng.toFixed(6)}</p>
                        <p><strong>Kondisi:</strong> <span style="font-weight:600;color:#2980b9;">${escapeHtml(geotag.condition || '-')}</span></p>
                        <p><strong>Waktu:</strong> <i class="far fa-clock"></i> ${formattedTime}</p>
                        <p><strong>Detail:</strong> ${escapeHtml(geotag.details || '-')}</p>
                        <hr style="margin:8px 0;">
                        ${photoUrl ? `<a href="javascript:void(0)" onclick="openModal('${escapeHtml(photoUrl)}', '${escapeHtml(geotag.itemType)}', '${escapeHtml(geotag.locationName)}')" style="color: var(--secondary-color); font-weight:700;"><i class="fas fa-camera"></i> Lihat Foto</a>` : 'Tidak ada Foto'}
                    </div>
                `;

                const marker = L.marker([lat,lng]).addTo(map).bindPopup(popupContent);
                markersById[String(geotag.id)] = marker;
            });

            if (bounds.length > 0) {
                map.fitBounds(bounds, { padding: [30,30], maxZoom: 16 });
            } else {
                map.setView(initialCenter, 13);
            }

            setTimeout(() => {
                loadingOverlay.classList.remove('show');
                map.invalidateSize(); 
            }, 600);
            window._seedloc_markers = markersById;

        } catch (err) {
            console.error('Gagal inisialisasi peta:', err);
            document.getElementById('mapid').innerHTML = '<div style="padding:40px; text-align:center; color:#c0392b;">Peta gagal dimuat. Periksa koneksi library Leaflet atau kontainer peta.</div>';
            loadingOverlay.classList.remove('show');
        }
    } 

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
        });
    }

    // Table row click to focus marker on map
    (function(){
        document.addEventListener('click', function(e){
            const tr = e.target.closest('.data-row');
            // Mencegah klik di dalam thumbnail foto memicu aksi pindah peta
            if (!tr || e.target.classList.contains('photo-thumb')) return; 
            
            const id = tr.dataset.id;
            const lat = parseFloat(tr.dataset.lat);
            const lng = parseFloat(tr.dataset.lng);

            const mapTab = document.getElementById('tab-map');
            if (mapTab.getAttribute('aria-selected') !== 'true') {
                mapTab.click();
            }

            setTimeout(() => {
                if (window._seedloc_map) {
                    if (window._seedloc_markers && window._seedloc_markers[id]) {
                        const marker = window._seedloc_markers[id];
                        try {
                            marker.openPopup();
                            window._seedloc_map.setView(marker.getLatLng(), 16, { animate: true });
                        } catch(e) {
                            window._seedloc_map.panTo([lat,lng], { animate: true });
                        }
                    } else if (!isNaN(lat) && !isNaN(lng)) {
                        try { window._seedloc_map.setView([lat,lng], 15, { animate: true }); } catch(e){}
                    }
                }
            }, 350);
        });
    })();

    // Init map when DOM ready
    document.addEventListener('DOMContentLoaded', function(){
        initializeMap();
    });
</script>

</body>
</html>