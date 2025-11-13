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
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
        /* Palet Warna Modern: Hijau Tua (#1e8449), Hijau Sedang (#27ae60), Abu-abu Cerah (#f4f6f8) */
        :root {
            --primary-color: #1e8449; 
            --secondary-color: #27ae60;
            --background-light: #f4f6f8;
            --text-dark: #2c3e50;
            --shadow-subtle: 0 2px 8px rgba(0,0,0,0.05);
            --radius-default: 8px;
        }

        body { 
            font-family: 'Poppins', sans-serif; /* Mengganti Font ke Poppins jika tersedia, atau Segoe UI */
            margin: 0; 
            background-color: var(--background-light); 
            color: var(--text-dark); 
            line-height: 1.6;
        }
        .container { 
            max-width: 1300px; 
            margin: 30px auto; 
            padding: 30px; 
            background-color: #fff; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.08); /* Bayangan lebih halus */
            border-radius: 15px; 
        }
        h1 { 
            color: var(--primary-color); 
            border-bottom: none; 
            padding-bottom: 0; 
            margin-bottom: 10px; 
            font-size: 2.2em;
            display: flex;
            align-items: center;
        }
        h1 i {
            margin-right: 15px;
            font-size: 1.2em;
        }
        h2 { 
            color: var(--primary-color); 
            margin-top: 40px; 
            margin-bottom: 20px; 
            font-size: 1.6em; 
            border-bottom: 2px solid #eee; 
            padding-bottom: 8px;
        }
        
        /* Area Peta */
        #mapid { 
            height: 550px; /* Sedikit lebih pendek */
            border-radius: var(--radius-default); 
            margin-bottom: 30px; 
            border: 2px solid #e0e0e0; /* Border lebih jelas */
            box-shadow: var(--shadow-subtle);
        }
        
        /* Formulir Pencarian */
        .search-form { 
            display: flex; 
            margin-bottom: 25px; 
            border-radius: var(--radius-default);
            overflow: hidden; /* Agar input dan tombol menyatu */
        }
        .search-form input[type="text"] { 
            flex-grow: 1; 
            padding: 14px; 
            border: 1px solid #ccc; 
            border-right: none;
            font-size: 16px; 
            transition: border-color 0.3s, box-shadow 0.3s; 
            border-radius: var(--radius-default) 0 0 var(--radius-default);
        }
        .search-form input[type="text"]:focus { 
            border-color: var(--secondary-color); 
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.2); /* Efek fokus */
            outline: none; 
        }
        .search-form button { 
            padding: 14px 25px; 
            background-color: var(--primary-color); 
            color: white; 
            border: none; 
            cursor: pointer; 
            font-size: 16px; 
            font-weight: 600; 
            transition: background-color 0.3s, transform 0.2s; 
            border-radius: 0 var(--radius-default) var(--radius-default) 0;
            display: flex;
            align-items: center;
        }
        .search-form button:hover { 
            background-color: var(--secondary-color); 
            transform: translateY(-1px);
        }
        .search-form button i {
            margin-right: 8px;
        }

        /* Statistik Data */
        .data-stats { 
            background-color: #e8f8f3; /* Warna hijau muda */
            padding: 20px; 
            border-radius: var(--radius-default); 
            margin-bottom: 25px; 
            border-left: 5px solid var(--secondary-color); 
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .data-stats p { 
            margin: 5px 15px 5px 0; 
            font-weight: 500; 
            color: #2c3e50; 
            font-size: 1.05em;
        }
        .data-stats p strong {
            font-weight: 700;
            color: var(--primary-color);
        }
        .search-info {
            font-style: italic;
            color: #7f8c8d;
        }

        /* Tabel Data */
        .data-table-container { 
            overflow-x: auto; 
            max-height: 500px; /* Lebih tinggi untuk menampung lebih banyak data */
            border: 1px solid #ddd;
            border-radius: var(--radius-default);
            box-shadow: var(--shadow-subtle);
        }
        .data-table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        .data-table th, .data-table td { 
            padding: 15px; 
            text-align: left; 
            border-bottom: 1px solid #eee; 
            font-size: 15px; 
            vertical-align: middle;
        }
        .data-table th { 
            background-color: #eaf1f7; /* Header abu-abu kebiruan */
            color: var(--text-dark); 
            position: sticky; 
            top: 0; 
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9em;
        }
        .data-table tr:hover { 
            background-color: #f0f8ff; /* Warna hover yang lebih cerah */
        }
        .photo-thumb { 
            width: 70px; /* Ukuran lebih besar */
            height: 70px; 
            object-fit: cover; 
            border-radius: 8px; 
            cursor: pointer; 
            border: 2px solid #ddd; 
            transition: transform 0.2s, box-shadow 0.2s; 
        }
        .photo-thumb:hover { 
            transform: scale(1.08); 
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border-color: var(--secondary-color);
        }
        
        /* Pop-up Leaflet */
        .popup-content strong { 
            color: var(--primary-color); 
            font-size: 15px; 
        }
        .popup-content p { 
            font-size: 13px; 
            margin: 4px 0; 
        }
        .popup-content a {
            font-size: 13px;
            display: inline-block;
            margin-top: 5px;
        }

        /* Modal Foto */
        .modal { 
            display: none; 
            position: fixed; 
            z-index: 9999; 
            padding-top: 60px; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0,0,0,0.95); 
            backdrop-filter: blur(5px);
        }
        .modal-content { 
            margin: auto; 
            display: block; 
            width: 95%; 
            max-width: 1000px;
            animation: zoom 0.6s;
            border-radius: 10px;
        }
        @keyframes zoom {
            from {transform: scale(0.1)} 
            to {transform: scale(1)}
        }
        .close { 
            position: absolute; 
            top: 15px; 
            right: 35px; 
            color: #fff; 
            font-size: 50px; /* Lebih besar */
            font-weight: 300; 
            transition: 0.3s; 
            cursor: pointer;
            text-shadow: 0 0 5px rgba(0,0,0,0.5);
        }
        .close:hover, .close:focus { 
            color: #f1f1f1; 
        }

        /* Responsif untuk Mobile */
        @media (max-width: 768px) {
            .container { 
                margin: 10px; 
                padding: 15px; 
                border-radius: 10px;
            }
            h1 { 
                font-size: 1.8em; 
            }
            .search-form { 
                flex-direction: column;
            }
            .search-form input[type="text"], .search-form button {
                border-radius: 8px; 
                width: 100%; 
                border-right: 1px solid #ccc; /* Perbaiki border */
                margin-bottom: 10px;
            }
            .search-form button {
                border-radius: 8px;
                margin-bottom: 0;
            }
            .data-table th, .data-table td {
                padding: 10px;
            }
            .data-stats {
                flex-direction: column;
                align-items: flex-start;
            }
            .data-stats p {
                margin: 3px 0;
            }
            .data-table-container {
                max-height: 350px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h1><i class="fas fa-tree"></i> SeedLoc Web Dashboard</h1>
    
    <form class="search-form" method="GET" action="index.php">
        <input type="text" name="search" placeholder="Cari Nama Pohon (itemType) atau Nama Lokasi..." value="<?php echo htmlspecialchars($search_query); ?>">
        <button type="submit"><i class="fas fa-search"></i> Cari</button>
    </form>
    
    <div class="data-stats">
        <p>Total Data: <strong><?php echo count($geotags); ?></strong></p>
        <?php if ($search_query): ?>
            <p class="search-info">Menampilkan hasil pencarian untuk: "<strong><?php echo htmlspecialchars($search_query); ?></strong>"</p>
            <p><a href="index.php" style="color: #c0392b; text-decoration: none; font-weight: 600;"><i class="fas fa-times-circle"></i> Hapus Filter</a></p>
        <?php endif; ?>
    </div>

    <div id="mapid"></div>

    <h2><i class="fas fa-list-alt"></i> Daftar Data Geotag Terbaru</h2>
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama Pohon</th>
                    <th>Lokasi</th>
                    <th>Koordinat (Lat, Lng)</th>
                    <th>Waktu</th>
                    <th>Kondisi</th>
                    <th>Foto</th>
                </tr>
            </thead>
            <tbody id="data-table-body">
                <?php if (empty($geotags)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: gray; padding: 20px;">
                            <i class="fas fa-info-circle"></i> Tidak ada data geotag yang ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($geotags as $tag): 
                        // PHP untuk Tampilan Tabel
                        $formatted_timestamp = date("d/m/Y H:i:s", strtotime($tag['timestamp']));
                        
                        $photo_path_in_db = $tag['photoPath'];
                        $photo_full_url = '';
                        
                        // LOGIC PHOTO PATH
                        if (!empty($photo_path_in_db)) {
                            if (strpos($photo_path_in_db, 'http') === 0 || strpos($photo_path_in_db, 'https') === 0) {
                                $photo_full_url = $photo_path_in_db;
                            } else {
                                $photo_full_url = $photo_base_url . $photo_path_in_db;
                            }
                        }
                    ?>
                    <tr data-lat="<?php echo $tag['latitude']; ?>" data-lng="<?php echo $tag['longitude']; ?>">
                        <td><?php echo $tag['id']; ?></td>
                        <td><?php echo htmlspecialchars($tag['itemType']); ?></td>
                        <td><?php echo htmlspecialchars($tag['locationName']); ?></td>
                        <td><?php echo number_format($tag['latitude'], 6) . ', ' . number_format($tag['longitude'], 6); ?></td>
                        <td><?php echo $formatted_timestamp; ?></td>
                        <td><?php echo htmlspecialchars($tag['condition']); ?></td>
                        <td>
                            <?php if ($photo_full_url): ?>
                                <img src="<?php echo $photo_full_url; ?>" 
                                     onerror="this.onerror=null;this.src='https://via.placeholder.com/70?text=NO+IMG';"
                                     alt="Foto" 
                                     class="photo-thumb" 
                                     title="Klik untuk Lihat"
                                     onclick="event.stopPropagation(); openModal('<?php echo $photo_full_url; ?>')">
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

<div id="photoModal" class="modal" onclick="closeModal()">
  <span class="close">&times;</span>
  <img class="modal-content" id="img01">
</div>

<script>
    // --- JavaScript untuk Peta Leaflet ---
    
    // Data Geotag dari PHP
    const geotagsData = <?php echo $geotags_json; ?>;
    const PHOTO_BASE_URL = '<?php echo $photo_base_url; ?>';

    // Koordinat pusat default (Jakarta)
    const defaultCenter = [-6.2088, 106.8456]; 
    const mapContainerId = 'mapid';

    // PENTING: Panggil fungsi inisialisasi peta di dalam event listener DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById(mapContainerId)) {
            initializeMap();
        }
    });

    function initializeMap() {
        let initialCenter = defaultCenter;
        let bounds = [];
        let map;

        // 1. Cek data untuk menentukan pusat awal
        for (const geotag of geotagsData) {
            const lat = parseFloat(geotag.latitude);
            const lng = parseFloat(geotag.longitude);

            if (!isNaN(lat) && !isNaN(lng) && (lat !== 0 || lng !== 0)) {
                initialCenter = [lat, lng];
                break; 
            }
        }
        
        // Inisialisasi Peta
        try {
            map = L.map(mapContainerId).setView(initialCenter, 13);
        } catch (e) {
            console.error("Gagal menginisialisasi Leaflet Map:", e);
            document.getElementById(mapContainerId).innerHTML = '<div style="text-align: center; padding: 50px; color: #c0392b;">Peta gagal dimuat. Pastikan Leaflet dimuat dengan benar dan kontainer map tersedia.</div>';
            return;
        }

        // Tambahkan OpenStreetMap Tiles
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            subdomains: ['a', 'b', 'c'], 
            attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);
        
        // 2. Tambahkan Marker dan kumpulkan bounds
        geotagsData.forEach(geotag => {
            const lat = parseFloat(geotag.latitude);
            const lng = parseFloat(geotag.longitude);

            // Validasi: Lewati jika koordinat tidak valid
            if (isNaN(lat) || isNaN(lng) || (lat === 0 && lng === 0)) {
                return; 
            }
            
            bounds.push([lat, lng]);

            let photoUrl = '';
            const photoPathInDb = geotag.photoPath;

            if (photoPathInDb) {
                if (photoPathInDb.startsWith('http') || photoPathInDb.startsWith('https')) {
                    photoUrl = photoPathInDb;
                } else {
                    photoUrl = PHOTO_BASE_URL + photoPathInDb;
                }
            }
            
            // Mengatasi masalah format waktu di beberapa browser
            let dateObj;
            try {
                // Coba parsing ISO 8601 (format MySQL)
                dateObj = new Date(geotag.timestamp.replace(' ', 'T'));
            } catch (e) {
                dateObj = new Date(geotag.timestamp);
            }
            const formattedTime = dateObj.toLocaleString('id-ID', {day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit'});


            // Buat konten pop-up HTML ala WebGIS
            const popupContent = `
                <div class="popup-content" style="min-width: 200px;">
                    <h5 style="margin: 0; color: var(--primary-color); font-size: 1.1em;">
                        <i class="fas fa-seedling"></i> ${geotag.itemType || 'Data Geotag'} 
                        <span style="font-size: 0.7em; color: #999;">(ID: ${geotag.id})</span>
                    </h5>
                    <hr style="margin: 8px 0;">
                    <p><strong>Lokasi:</strong> ${geotag.locationName || '-'}</p>
                    <p><strong>Koordinat:</strong> ${lat.toFixed(6)}, ${lng.toFixed(6)}</p>
                    <p><strong>Kondisi:</strong> <span style="font-weight: 600; color: #2980b9;">${geotag.condition || '-'}</span></p>
                    <p><strong>Detail:</strong> ${geotag.details || '-'}</p>
                    <p><strong>Waktu:</strong> <i class="far fa-clock"></i> ${formattedTime}</p>
                    <hr style="margin: 8px 0;">
                    ${photoUrl ? `<a href="javascript:void(0)" onclick="openModal('${photoUrl.replace(/'/g, "\\'")}')" style="color: var(--secondary-color); font-weight: 600;"><i class="fas fa-camera"></i> Lihat Foto Detail</a>` : 'Tidak ada Foto'}
                </div>
            `;
            
            // Buat Marker dan tambahkan Pop-up
            L.marker([lat, lng])
                .addTo(map)
                .bindPopup(popupContent);
        });

        // 3. Sesuaikan view agar mencakup semua marker
        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
        } else {
             // Jika tidak ada marker, atur ke lokasi pusat default
             map.setView(initialCenter, 13);
        }
    }


    // --- JavaScript untuk Modal Foto ---
    var modal = document.getElementById("photoModal");
    var modalImg = document.getElementById("img01");
    var originalOverflow = ''; // Menyimpan status overflow body

    function openModal(photoUrl) {
        // Hapus penanganan event sebelumnya
        modalImg.onclick = null; 

        if (photoUrl && (photoUrl.startsWith('http') || photoUrl.startsWith('https'))) {
            // Cegah event menyebar ke modal (yang akan menutupnya)
            modalImg.onclick = function(event) { event.stopPropagation(); }; 
            
            modal.style.display = "block";
            modalImg.src = photoUrl;
            
            // Nonaktifkan scrolling background
            originalOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';

        } else {
            console.error("URL Foto tidak valid:", photoUrl);
            alert("URL Foto tidak valid.");
        }
    }

    function closeModal() {
        modal.style.display = "none";
        // Kembalikan scrolling background
        document.body.style.overflow = originalOverflow; 
    }

    // Tutup modal jika tombol ESC ditekan
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && modal.style.display === 'block') {
            closeModal();
        }
    });

</script>

</body>
</html>