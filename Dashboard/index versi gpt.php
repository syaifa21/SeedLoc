<?php
// Pastikan path ke file config dan db.php di dalam folder api/ sudah benar
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

// --- 2. FUNGSI AMBIL DATA & PENCARIAN ---
function fetch_geotags($conn, $search_query = null) {
    // Menggunakan `condition` (dengan backtick)
    $sql = "SELECT id, latitude, longitude, locationName, timestamp, itemType, `condition`, photoPath, details FROM geotags";
    $params = [];

    // Filter berdasarkan query pencarian
    if ($search_query) {
        $sql .= " WHERE itemType LIKE ? OR locationName LIKE ?";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }
    
    $sql .= " ORDER BY id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params); 
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Ambil data pencarian dari URL
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$geotags = fetch_geotags($conn, $search_query);

// Encode data untuk JavaScript
$geotags_json = json_encode($geotags);

// --- 3. BASE URL untuk Foto ---
// Ganti menjadi https://seedloc.my.id/api/ jika foto tidak bisa diakses
$photo_base_url = 'https://seedloc.my.id/api/';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SeedLoc Web Dashboard</title>

    <!-- Leaflet CSS & Font Awesome (seperti sebelumnya) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
        /* Warna identitas: hijau utama sesuai pilihan (#2E7D32) */
        :root {
            --primary-color: #2E7D32; 
            --secondary-color: #66BB6A;
            --background-light: #f4f6f8;
            --text-dark: #2c3e50;
            --muted: #7f8c8d;
            --shadow-subtle: 0 6px 20px rgba(0,0,0,0.06);
            --radius-default: 10px;
            --glass: rgba(255,255,255,0.7);
        }

        /* Reset & base */
        * { box-sizing: border-box; }
        body { 
            font-family: 'Poppins', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial; 
            margin: 0; 
            background-color: var(--background-light); 
            color: var(--text-dark); 
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Container utama */
        .container { 
            max-width: 1220px; 
            margin: 28px auto; 
            padding: 26px; 
            background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(255,255,255,0.98));
            box-shadow: var(--shadow-subtle);
            border-radius: 14px; 
            min-height: 60vh;
        }

        /* Header */
        header.app-header {
            display: flex;
            align-items: center;
            gap: 16px;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .brand {
            display: flex;
            gap: 12px;
            align-items: center;
            text-decoration: none;
            color: inherit;
        }
        .brand .logo {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: #fff;
            box-shadow: 0 6px 18px rgba(46,125,50,0.15);
            font-size: 22px;
            font-weight: 600;
        }
        .brand h1 { font-size: 20px; margin: 0; }
        .brand p { margin: 0; color: var(--muted); font-size: 13px; }

        /* Header actions */
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid rgba(0,0,0,0.06);
            background: white;
            cursor: pointer;
            font-weight: 600;
            color: var(--text-dark);
            text-decoration: none;
        }
        .btn.primary {
            background: var(--primary-color);
            color: white;
            border: none;
            box-shadow: 0 6px 16px rgba(46,125,50,0.12);
        }

        /* Search form (preserve layout) */
        .search-form { 
            display: flex; 
            margin: 12px 0 18px 0; 
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #e6e9ec;
            background: #fff;
        }
        .search-form input[type="text"] { 
            flex-grow: 1; 
            padding: 14px 16px; 
            border: none;
            font-size: 15px; 
            outline: none;
        }
        .search-form button { 
            padding: 12px 18px; 
            background-color: var(--primary-color); 
            color: white; 
            border: none; 
            cursor: pointer; 
            font-size: 15px; 
            font-weight: 700;
        }
        .search-form button i { margin-right: 8px; }

        /* Info stats */
        .data-stats {
            background-color: rgba(46,125,50,0.06);
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 18px;
            display: flex;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            border-left: 4px solid var(--secondary-color);
        }
        .stats-left { display:flex; gap:14px; align-items:center; }
        .stats-left p { margin: 0; color: var(--muted); font-weight: 600; }
        .stats-left p strong { color: var(--primary-color); font-size: 1.1em; }

        /* Tabs untuk Peta/Tabel */
        .tabs {
            display:flex;
            gap:8px;
            margin-bottom: 12px;
        }
        .tab {
            padding: 8px 14px;
            border-radius: 10px;
            border: 1px solid transparent;
            background: rgba(255,255,255,0.9);
            cursor: pointer;
            font-weight: 600;
        }
        .tab[aria-selected="true"] {
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 8px 20px rgba(46,125,50,0.12);
            border-color: rgba(0,0,0,0.04);
        }

        /* Area map & table wrapper */
        .panel {
            display: none;
        }
        .panel.active { display: block; }

        /* Map area */
        #mapid { 
            height: 560px; 
            border-radius: 12px; 
            margin-bottom: 18px; 
            border: 1px solid #e6e9ec; 
            box-shadow: var(--shadow-subtle);
        }

        /* Table styles (retain original but improved) */
        .data-table-container { 
            overflow: auto; 
            max-height: 560px;
            border: 1px solid #e6e9ec;
            border-radius: 12px;
            box-shadow: var(--shadow-subtle);
            background: white;
        }
        .data-table { 
            width: 100%; 
            border-collapse: collapse; 
            min-width: 900px;
        }
        .data-table th, .data-table td { 
            padding: 14px 16px; 
            text-align: left; 
            border-bottom: 1px solid #f1f4f6; 
            font-size: 14px; 
            vertical-align: middle;
        }
        .data-table th { 
            background-color: #fbfdfe; 
            color: var(--muted); 
            position: sticky; 
            top: 0; 
            z-index: 2;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.04em;
        }
        .data-table tr:hover { background: #fbfff9; cursor: pointer; }
        .row-action { font-size: 13px; color: var(--primary-color); font-weight: 700; text-decoration: none; }

        .photo-thumb { 
            width: 72px; 
            height: 72px; 
            object-fit: cover; 
            border-radius: 8px; 
            cursor: pointer; 
            border: 2px solid #f0f0f0; 
            transition: transform 0.18s, box-shadow 0.18s; 
        }
        .photo-thumb:hover { transform: scale(1.06); box-shadow: 0 8px 22px rgba(0,0,0,0.08); border-color: var(--secondary-color); }

        /* Popup styling for leaflet (keep small) */
        .popup-content strong { color: var(--primary-color); }
        .popup-content p { font-size: 13px; margin: 4px 0; color: #384048; }

        /* Modal Foto (lebih elegan) */
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

        /* Loading overlay for map */
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

        /* Footer */
        footer { margin-top: 18px; color: var(--muted); font-size: 13px; text-align: center; }

        /* Responsif */
        @media (max-width: 900px) {
            #mapid, .data-table-container { max-height: 420px; }
            .brand h1 { font-size: 18px; }
            .search-form { flex-direction: column; gap:8px; }
            .data-table { min-width: 760px; }
        }
    </style>
</head>
<body>

<div class="container" role="main">
    <header class="app-header" role="banner">
        <a class="brand" href="index.php" aria-label="SeedLoc Dashboard">
            <div class="logo" aria-hidden="true">SL</div>
            <div>
                <h1>SeedLoc Dashboard</h1>
                <p>Geotag & dokumentasi lapangan — Sistem internal</p>
            </div>
        </a>

        <div class="header-actions" role="navigation" aria-label="Aksi cepat">
            <a class="btn" href="api/help.pdf" target="_blank" rel="noopener"> <i class="fas fa-file-alt"></i> Panduan </a>
            <a class="btn primary" href="#" onclick="document.getElementById('searchInput').focus(); return false;"><i class="fas fa-search"></i> Cari</a>
        </div>
    </header>

    <!-- Search -->
    <form class="search-form" method="GET" action="index.php" role="search" aria-label="Pencarian geotag">
        <input id="searchInput" type="text" name="search" placeholder="Cari Nama Pohon (itemType) atau Nama Lokasi..." value="<?php echo htmlspecialchars($search_query); ?>" aria-label="Masukkan kata kunci pencarian">
        <button type="submit" aria-label="Cari data"><i class="fas fa-search"></i> Cari</button>
    </form>

    <!-- Statistik ringkas -->
    <div class="data-stats" role="status" aria-live="polite">
        <div class="stats-left">
            <p>Total Data: <strong><?php echo count($geotags); ?></strong></p>
            <?php if ($search_query): ?>
                <p class="search-info">Menampilkan hasil pencarian untuk: "<strong><?php echo htmlspecialchars($search_query); ?></strong>"</p>
            <?php endif; ?>
        </div>
        <div>
            <?php if ($search_query): ?>
                <a href="index.php" class="btn" title="Hapus filter"><i class="fas fa-times-circle"></i> Hapus Filter</a>
            <?php else: ?>
                <span style="color: var(--muted); font-weight:600;">Versi: 1.0 — <?php echo date('Y'); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabs: Map / Table -->
    <div class="tabs" role="tablist" aria-label="Tampilan">
        <button class="tab" role="tab" aria-selected="true" data-target="panel-map" id="tab-map"> <i class="fas fa-map-marked-alt"></i> Peta </button>
        <button class="tab" role="tab" aria-selected="false" data-target="panel-table" id="tab-table"> <i class="fas fa-table"></i> Tabel </button>
    </div>

    <!-- Panel Map -->
    <div id="panel-map" class="panel active" role="tabpanel" aria-labelledby="tab-map">
        <div style="position:relative;">
            <div id="mapid" aria-label="Peta geotag"></div>
            <div id="mapLoading" class="loading-overlay" aria-hidden="true">
                <div class="spinner" role="status" aria-label="Memuat peta"></div>
            </div>
        </div>
    </div>

    <!-- Panel Table -->
    <div id="panel-table" class="panel" role="tabpanel" aria-labelledby="tab-table">
        <h2 style="margin: 12px 0 10px 0; font-size: 18px; color: var(--primary-color);"><i class="fas fa-list-alt"></i> Daftar Data Geotag Terbaru</h2>

        <div class="data-table-container" aria-live="polite">
            <table class="data-table" id="dataTable" role="table" aria-label="Tabel data geotag">
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Nama Pohon</th>
                        <th scope="col">Lokasi</th>
                        <th scope="col">Koordinat (Lat, Lng)</th>
                        <th scope="col">Waktu</th>
                        <th scope="col">Kondisi</th>
                        <th scope="col">Foto</th>
                    </tr>
                </thead>
                <tbody id="data-table-body">
                    <?php if (empty($geotags)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: gray; padding: 30px;">
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
                                         onclick="event.stopPropagation(); openModal('<?php echo $photo_full_url; ?>', '<?php echo htmlspecialchars($tag['itemType']); ?>', '<?php echo htmlspecialchars($tag['locationName']); ?>')">
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
    </div>

    <footer>
        © <?php echo date('Y'); ?> SeedLoc — Aplikasi pencatatan geotag. Hubungi admin untuk bantuan.
    </footer>
</div>

<!-- Modal Foto (aksesibel) -->
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

    // Tab switching (Map / Table)
    (function(){
        const tabs = document.querySelectorAll('.tab');
        tabs.forEach(tab => {
            tab.addEventListener('click', function(){
                // Deselect all
                tabs.forEach(t => t.setAttribute('aria-selected','false'));
                this.setAttribute('aria-selected','true');

                // Toggle panels
                document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
                const target = document.getElementById(this.dataset.target);
                if (target) target.classList.add('active');

                // If map tab opened, invalidate map size after a moment
                if (this.dataset.target === 'panel-map' && window._seedloc_map) {
                    setTimeout(() => {
                        try { window._seedloc_map.invalidateSize(); } catch(e) {}
                    }, 260);
                }
            });
        });
    })();

    // --- Modal Foto ---
    const modal = document.getElementById('photoModal');
    const modalImage = document.getElementById('modalImage');
    const modalCaption = document.getElementById('modalCaption');
    let lastFocused = null;

    function openModal(photoUrl, title = '', location = '') {
        if (!photoUrl) return;
        lastFocused = document.activeElement;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden','false');
        modalImage.src = photoUrl;
        modalImage.alt = title ? `${title} - ${location}` : 'Foto geotag';
        modalCaption.textContent = title ? `${title} — ${location}` : location || '';
        // trap focus to close button
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

    // close modal on overlay click
    modal.addEventListener('click', function(e){
        if (e.target === modal) closeModal();
    });
    // close on ESC
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });

    // --- Leaflet Map Initialization ---
    function initializeMap() {
        const mapEl = document.getElementById('mapid');
        const loadingOverlay = document.getElementById('mapLoading');
        if (!mapEl) return;

        loadingOverlay.classList.add('show');

        // Default center (Jakarta) if no valid coords
        const defaultCenter = [-6.2088, 106.8456];

        // Find a valid initial center
        let initialCenter = defaultCenter;
        for (const g of geotagsData) {
            const lat = parseFloat(g.latitude);
            const lng = parseFloat(g.longitude);
            if (!isNaN(lat) && !isNaN(lng) && (lat !== 0 || lng !== 0)) {
                initialCenter = [lat, lng];
                break;
            }
        }

        // Create map
        try {
            const map = L.map('mapid', { preferCanvas: true }).setView(initialCenter, 13);
            window._seedloc_map = map; // expose for other functions

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                subdomains: ['a','b','c'],
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            // Prepare markers map keyed by id
            const markersById = {};
            const bounds = [];

            geotagsData.forEach(geotag => {
                const lat = parseFloat(geotag.latitude);
                const lng = parseFloat(geotag.longitude);
                if (isNaN(lat) || isNaN(lng) || (lat === 0 && lng === 0)) return;

                bounds.push([lat,lng]);

                let photoUrl = '';
                if (geotag.photoPath) {
                    if (geotag.photoPath.startsWith('http') || geotag.photoPath.startsWith('https')) {
                        photoUrl = geotag.photoPath;
                    } else {
                        photoUrl = PHOTO_BASE_URL + geotag.photoPath;
                    }
                }

                // Parse time safely
                let dateObj;
                try {
                    dateObj = new Date(geotag.timestamp.replace(' ', 'T'));
                } catch(e) {
                    dateObj = new Date(geotag.timestamp);
                }
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
                        <hr style="margin:8px 0;">
                        ${photoUrl ? `<a href="javascript:void(0)" onclick="openModal('${photoUrl.replace(/'/g, '\\\\\'')}', '${escapeAttr(geotag.itemType)}', '${escapeAttr(geotag.locationName)}')" style="color: var(--secondary-color); font-weight:700;"><i class="fas fa-camera"></i> Lihat Foto</a>` : 'Tidak ada Foto'}
                    </div>
                `;

                const marker = L.marker([lat,lng]).addTo(map).bindPopup(popupContent);
                markersById[String(geotag.id)] = marker;
            });

            // Fit bounds if exist
            if (bounds.length > 0) {
                map.fitBounds(bounds, { padding: [30,30], maxZoom: 16 });
            } else {
                map.setView(initialCenter, 13);
            }

            // After map settled, hide loading
            setTimeout(() => loadingOverlay.classList.remove('show'), 600);

            // Save markers map globally to allow table interaction
            window._seedloc_markers = markersById;

        } catch (err) {
            console.error('Gagal inisialisasi peta:', err);
            document.getElementById('mapid').innerHTML = '<div style="padding:40px; text-align:center; color:#c0392b;">Peta gagal dimuat. Periksa koneksi library Leaflet atau kontainer peta.</div>';
            loadingOverlay.classList.remove('show');
        }
    } // initializeMap

    // Utility: escape HTML for popup content
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
        });
    }
    function escapeAttr(str) {
        return escapeHtml(str).replace(/"/g, '&quot;');
    }

    // Table row click to focus marker on map
    (function(){
        document.addEventListener('click', function(e){
            const tr = e.target.closest('.data-row');
            if (!tr) return;
            const id = tr.dataset.id;
            const lat = parseFloat(tr.dataset.lat);
            const lng = parseFloat(tr.dataset.lng);

            // Switch to map tab if not active
            const mapTab = document.getElementById('tab-map');
            if (mapTab.getAttribute('aria-selected') !== 'true') {
                mapTab.click();
            }

            // Wait until map exists
            setTimeout(() => {
                if (window._seedloc_markers && window._seedloc_markers[id]) {
                    const marker = window._seedloc_markers[id];
                    try {
                        marker.openPopup();
                        window._seedloc_map.setView(marker.getLatLng(), 16, { animate: true });
                    } catch(e) {
                        // fallback: panTo
                        window._seedloc_map.panTo([lat,lng], { animate: true });
                    }
                } else if (!isNaN(lat) && !isNaN(lng)) {
                    // If no marker exists (maybe filtered), pan to coordinates
                    try { window._seedloc_map.setView([lat,lng], 15, { animate: true }); } catch(e){}
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
