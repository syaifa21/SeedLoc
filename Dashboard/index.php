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
    die("<h1>Dashboard Error</h1><p>Koneksi database gagal. Pastikan <code>api/config.php</code> sudah benar. Detail: " . $e->getMessage() . "</p>");
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

    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; background-color: #f8f8f8; color: #333; }
        .container { max-width: 1300px; margin: 20px auto; padding: 20px; background-color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border-radius: 12px; }
        h1 { color: #2e8b57; border-bottom: 3px solid #2e8b57; padding-bottom: 10px; margin-bottom: 25px; }
        #mapid { height: 600px; border-radius: 10px; margin-bottom: 30px; border: 1px solid #ddd; }
        .search-form { display: flex; margin-bottom: 25px; }
        .search-form input[type="text"] { flex-grow: 1; padding: 12px; border: 2px solid #ccc; border-radius: 8px 0 0 8px; font-size: 16px; transition: border-color 0.3s; }
        .search-form input[type="text"]:focus { border-color: #3cb371; outline: none; }
        .search-form button { padding: 12px 20px; background-color: #2e8b57; color: white; border: none; border-radius: 0 8px 8px 0; cursor: pointer; font-size: 16px; font-weight: bold; transition: background-color 0.3s; }
        .search-form button:hover { background-color: #3cb371; }
        .data-stats { background-color: #e6f7ff; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 5px solid #1890ff; }
        .data-stats p { margin: 0; font-weight: 600; color: #1890ff; }
        .data-table-container { overflow-x: auto; max-height: 400px; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 15px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
        .data-table th { background-color: #f2f2f2; color: #555; position: sticky; top: 0; }
        .data-table tr:hover { background-color: #f9f9f9; }
        .photo-thumb { width: 60px; height: 60px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 1px solid #ddd; transition: transform 0.2s; }
        .photo-thumb:hover { transform: scale(1.05); }
        .popup-content strong { color: #2e8b57; font-size: 16px; }
        .popup-content p { font-size: 12px; margin: 5px 0; }
        .modal { display: none; position: fixed; z-index: 9999; padding-top: 60px; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.9); }
        .modal-content { margin: auto; display: block; width: 90%; max-width: 900px; }
        .close { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; transition: 0.3s; }
        .close:hover, .close:focus { color: #bbb; text-decoration: none; cursor: pointer; }
    </style>
</head>
<body>

<div class="container">
    <h1>Dashboard Data Geotag </h1>
    
    <form class="search-form" method="GET" action="index.php">
        <input type="text" name="search" placeholder="Cari Nama Pohon (itemType) atau Nama Lokasi..." value="<?php echo htmlspecialchars($search_query); ?>">
        <button type="submit">Cari</button>
    </form>
    
    <div class="data-stats">
        <p>Total Data Ditemukan: <?php echo count($geotags); ?></p>
        <?php if ($search_query): ?>
            <p>Hasil Pencarian untuk: "<?php echo htmlspecialchars($search_query); ?>"</p>
        <?php endif; ?>
    </div>

    <div id="mapid"></div>

    <h2>Daftar Data Geotag</h2>
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
                        <td colspan="7" style="text-align: center; color: gray;">Tidak ada data geotag yang ditemukan.</td>
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
                    <tr>
                        <td><?php echo $tag['id']; ?></td>
                        <td><?php echo htmlspecialchars($tag['itemType']); ?></td>
                        <td><?php echo htmlspecialchars($tag['locationName']); ?></td>
                        <td><?php echo number_format($tag['latitude'], 6) . ', ' . number_format($tag['longitude'], 6); ?></td>
                        <td><?php echo $formatted_timestamp; ?></td>
                        <td><?php echo htmlspecialchars($tag['condition']); ?></td>
                        <td>
                            <?php if ($photo_full_url): ?>
                                <img src="<?php echo $photo_full_url; ?>" 
                                     onerror="this.onerror=null;this.src='//via.placeholder.com/60?text=Error';"
                                     alt="Foto" 
                                     class="photo-thumb" 
                                     title="Klik untuk Lihat"
                                     onclick="openModal('<?php echo $photo_full_url; ?>')">
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
    
    // PENTING: Panggil fungsi inisialisasi peta di dalam event listener DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('mapid')) {
            initializeMap();
        }
    });

    function initializeMap() {
        let initialCenter = defaultCenter;
        let bounds = [];

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
        const map = L.map('mapid').setView(initialCenter, 13);

        // Tambahkan OpenStreetMap Tiles
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            subdomains: ['a', 'b', 'c'], 
            attribution: '© OpenStreetMap contributors'
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

            const formattedTime = new Date(geotag.timestamp).toLocaleString('id-ID', {day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit'});

            // Buat konten pop-up HTML ala WebGIS
            const popupContent = `
                <div class="popup-content">
                    <h5 style="margin: 0; color: #2e8b57;">${geotag.itemType || 'Data Geotag'} (ID: ${geotag.id})</h5>
                    <hr style="margin: 5px 0;">
                    <p><strong>Lokasi:</strong> ${geotag.locationName}</p>
                    <p><strong>Koordinat:</strong> ${lat.toFixed(6)}, ${lng.toFixed(6)}</p>
                    <p><strong>Kondisi:</strong> <span style="font-weight: 600;">${geotag.condition}</span></p>
                    <p><strong>Detail:</strong> ${geotag.details || '-'}</p>
                    <p><strong>Waktu:</strong> ${formattedTime}</p>
                    <hr style="margin: 5px 0;">
                    ${photoUrl ? `<a href="javascript:void(0)" onclick="openModal('${photoUrl}')" style="color: #1890ff; font-weight: 600;">Lihat Foto Detail</a>` : 'Tidak ada Foto'}
                </div>
            `;
            
            // Buat Marker dan tambahkan Pop-up
            L.marker([lat, lng])
                .addTo(map)
                .bindPopup(popupContent);
        });

        // 3. Sesuaikan view agar mencakup semua marker
        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [20, 20], maxZoom: 16 });
        } else {
             // Jika tidak ada marker, atur ke lokasi pusat default
             map.setView(initialCenter, 13);
        }
    }


    // --- JavaScript untuk Modal Foto ---
    var modal = document.getElementById("photoModal");
    var modalImg = document.getElementById("img01");

    function openModal(photoUrl) {
        if (photoUrl && photoUrl.indexOf('http') === 0) {
            modalImg.onclick = function(event) { event.stopPropagation(); }; 
            modal.style.display = "block";
            modalImg.src = photoUrl;
        } else {
            alert("URL Foto tidak valid.");
        }
    }

    function closeModal() {
        modal.style.display = "none";
    }

</script>

</body>
</html>