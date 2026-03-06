<?php
// index.php - FIXED FILTERS (Photo & Duplicate)

require_once 'config.php';
require_once 'functions.php';
require_once 'actions.php';

// Optimization: Load Data PHP hanya untuk Dashboard & List NON-Geotags
if ($action === 'list' && $table === 'geotags') {
    $list_data = []; 
    $projects_list = $pdo->query("SELECT projectId, locationName FROM projects ORDER BY projectId DESC LIMIT 100")->fetchAll();
} else {
    require_once 'fetch_data.php';
}

// Helper Filter (Masih dipakai untuk retensi filter saat reload/aksi lain)
$hidden_filters = '';
$filter_keys = ['search', 'page', 'projectId', 'condition', 'start_date', 'end_date', 'locationName', 'itemType', 'has_photo', 'is_duplicate'];
foreach($filter_keys as $key) {
    if(isset($_GET[$key]) && $_GET[$key] !== '') {
        $hidden_filters .= '<input type="hidden" name="ret_'.htmlspecialchars($key).'" value="'.htmlspecialchars($_GET[$key]).'">';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SeedLoc Admin Panel</title>
    <link rel="icon" href="https://seedloc.my.id/logo.png" type="image/png">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css" />
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"></script>
    <script src='https://cdnjs.cloudflare.com/ajax/libs/leaflet-omnivore/0.3.4/leaflet-omnivore.min.js'></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>


    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>
    <style>
        /* BASE STYLES */
        body{font-family:'Segoe UI', sans-serif;background:#f4f6f8;margin:0;display:flex;height:100vh;overflow:hidden;color:#333}
        .sidebar{width:250px;background:#fff;border-right:1px solid #e0e0e0;display:flex;flex-direction:column;flex-shrink:0}
        .brand{padding:20px;border-bottom:1px solid #f0f0f0;font-weight:700;color:#2E7D32;display:flex;align-items:center;gap:10px;font-size:18px}
        .nav{list-style:none;padding:10px 0;margin:0;flex:1;overflow-y:auto}
        .nav a{display:flex;align-items:center;gap:10px;padding:12px 20px;color:#555;text-decoration:none;border-left:4px solid transparent;transition:all 0.2s}
        .nav a:hover,.nav a.active{background:#e8f5e9;color:#2E7D32;border-left-color:#2E7D32}
        .main{flex:1;padding:25px;overflow-y:auto;display:flex;flex-direction:column}
        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:25px}
        .card{background:#fff;padding:50px;border-radius:10px;box-shadow:0 2px 5px rgba(0,0,0,0.05);margin-bottom:20px;border:1px solid #f0f0f0}
        .btn{padding:8px 14px;border:none;border-radius:6px;color:#fff;cursor:pointer;text-decoration:none;font-size:14px;display:inline-flex;align-items:center;gap:5px;font-weight:600;transition:opacity 0.2s}
        .btn:hover{opacity:0.9}
        .btn-p{background:#2E7D32} .btn-d{background:#d32f2f} .btn-w{background:#f39c12} .btn-b{background:#2196f3} .btn-i{background:#1565c0}
        
        /* TABLE & FORMS */
        table{width:100%;border-collapse:collapse;font-size:14px} 
        th{background:#f8f9fa;font-weight:600;color:#666;text-transform:uppercase;font-size:12px;letter-spacing:0.5px}
        th,td{padding:12px 15px;border-bottom:1px solid #eee;text-align:left;vertical-align:middle}
        tr:hover{background-color:#fafafa}
        
        /* Filter Bar Responsive */
        .filter-bar{display:flex;gap:10px;flex-wrap:wrap;background:#fff;padding:15px;border-radius:10px;border:1px solid #e0e0e0;margin-bottom:20px;align-items:center}
        input,select{padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;outline:none}
        input:focus,select:focus{border-color:#2E7D32}
        
        /* PAGINATION JS */
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; flex-wrap: wrap; }
        .pagination a { padding: 8px 12px; border: 1px solid #ddd; background: #fff; color: #333; text-decoration: none; border-radius: 4px; font-size: 14px; transition: all 0.2s; cursor: pointer; }
        .pagination a:hover { background: #f0f0f0; }
        .pagination a.active { background: #2E7D32; color: #fff; border-color: #2E7D32; pointer-events: none; }
        .pagination a.disabled { color: #ccc; pointer-events: none; border-color: #eee; }

        /* MODALS & OTHERS */
        .modal{display:none;position:fixed;z-index:3000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.85);justify-content:center;align-items:center;flex-direction:column}
        .modal-content{max-width:90%;max-height:85%;border-radius:5px;box-shadow:0 0 20px rgba(0,0,0,0.5)}
        .status-badge{padding:4px 8px;border-radius:4px;font-size:11px;font-weight:bold;text-transform:uppercase}
        .status-Active{background:#e8f5e9;color:#2E7D32} .status-Completed{background:#e3f2fd;color:#1976d2}
        
        /* Map specific */
        .custom-div-icon div { width:100%; height:100%; border-radius:50%; border:2px solid white; box-shadow:0 0 3px black; }
        
        @media(max-width:768px){.sidebar{width:60px}.brand span,.nav span{display:none}.brand{justify-content:center;padding:15px}.nav a{justify-content:center;padding:15px}}
    </style>
</head>
<body>

<?php 
if(isset($_SESSION['swal_success'])){ echo "<script>Swal.fire({icon:'success',title:'Berhasil',text:'{$_SESSION['swal_success']}',timer:1500,showConfirmButton:false});</script>"; unset($_SESSION['swal_success']); }
if(isset($_SESSION['swal_error'])){ echo "<script>Swal.fire({icon:'error',title:'Gagal',text:'{$_SESSION['swal_error']}'});</script>"; unset($_SESSION['swal_error']); }
?>

<?php if($action === 'login'): ?>
    <div style="width:100%;display:flex;justify-content:center;align-items:center;background:#eef2f5;">
        <div class="card" style="width:320px;text-align:center;padding:40px 30px;">
            <img src="https://seedloc.my.id/logo.png" width="80" style="margin-bottom:20px;border-radius:15px;">
            <h2 style="color:#2E7D32;margin-bottom:5px;">Admin Login</h2>
            <form method="post" style="margin-top:25px;">
                <div style="margin-bottom:15px;"><input type="text" name="username" placeholder="Username" style="width:100%;box-sizing:border-box;" required></div>
                <div style="margin-bottom:20px;"><input type="password" name="password" placeholder="Password" style="width:100%;box-sizing:border-box;" required></div>
                <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                <button name="login" class="btn btn-p" style="width:100%;justify-content:center;padding:12px;">LOGIN</button>
            </form>
        </div>
    </div>

<?php else: ?>

<nav class="sidebar">
    <div class="brand"><img src="https://seedloc.my.id/logo.png" width="32"> <span>SeedLoc</span></div>
    <ul class="nav">
        <li><a href="<?=build_url(['action'=>'dashboard'])?>" class="<?=$action=='dashboard'?'active':''?>"><i class="fas fa-chart-pie"></i> <span>Dashboard</span></a></li>
        <li><a href="<?=build_url(['action'=>'monitor'])?>" class="<?=$action=='monitor'?'active':''?>"><i class="fas fa-bullseye"></i> <span>Monitoring</span></a></li>
        <li><a href="<?=build_url(['action'=>'map'])?>" class="<?=$action=='map'?'active':''?>"><i class="fas fa-map-marked-alt"></i> <span>Peta Sebaran</span></a></li>
        <li><a href="<?=build_url(['action'=>'layers'])?>" class="<?=$action=='layers'?'active':''?>"><i class="fas fa-layer-group"></i> <span>Lapisan Overlay</span></a></li>
        <li><a href="<?=build_url(['action'=>'list', 'table'=>'projects'])?>" class="<?=($action=='list'&&$table=='projects')?'active':''?>"><i class="fas fa-folder-open"></i> <span>Data Projects</span></a></li>
        <li><a href="<?=build_url(['action'=>'list', 'table'=>'geotags'])?>" class="<?=($action=='list'&&$table=='geotags')?'active':''?>"><i class="fas fa-leaf"></i> <span>Data Geotags</span></a></li>
        <li><a href="<?=build_url(['action'=>'gallery'])?>" class="<?=$action=='gallery'?'active':''?>"><i class="fas fa-images"></i> <span>Galeri Foto</span></a></li>
        <li style="border-top:1px solid #f0f0f0;margin:10px 0;"></li>
        <li><a href="?action=users" class="<?=$action=='users'?'active':''?>"><i class="fas fa-user-shield"></i> <span>Admin Users</span></a></li>
        <li><a href="?action=logout" style="color:#d32f2f;"><i class="fas fa-sign-out-alt"></i> <span>Keluar</span></a></li>
    </ul>
</nav>

<main class="main">
    
   

    <?php if($action === 'dashboard'): ?>
        <div class="header"><h2>Overview Dashboard</h2></div>
        
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-bottom:25px;">
            <div class="card" style="border-left:5px solid #2E7D32;display:flex;align-items:center;gap:15px;">
                <div style="background:#e8f5e9;padding:15px;border-radius:50%;color:#2E7D32;"><i class="fas fa-leaf fa-2x"></i></div>
                <div><h2 style="margin:0;font-size:28px;"><?=$stats['geotags']?></h2><small style="color:#888;">Total Geotags</small></div>
            </div>
            <div class="card" style="border-left:5px solid #1976d2;display:flex;align-items:center;gap:15px;">
                <div style="background:#e3f2fd;padding:15px;border-radius:50%;color:#1976d2;"><i class="fas fa-folder fa-2x"></i></div>
                <div><h2 style="margin:0;font-size:28px;"><?=$stats['projects']?></h2><small style="color:#888;">Active Projects</small></div>
            </div>
        </div>

        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:20px;">
            <div class="card" style="flex:1;min-width:300px;"><h4>Kondisi Tanaman</h4><canvas id="c1"></canvas></div>
            <div class="card" style="flex:2;min-width:400px;"><h4>Aktivitas Harian</h4><canvas id="c2"></canvas></div>
        </div>
        <div class="card" style="width:100%; box-sizing:border-box;"><h4>Jumlah Data per Lokasi</h4><div style="height:350px;"><canvas id="c3"></canvas></div></div>

        <div class="card" style="width:100%; box-sizing:border-box; margin-top:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <h4><i class="fas fa-trophy" style="color:#f39c12;"></i> Top 10 Perolehan Petugas</h4>
            </div>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th width="50" style="text-align:center;">Rank</th>
                            <th>Nama Petugas</th>
                            <th style="text-align:right;">Total Perolehan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($stats['officer_stats'])): ?>
                            <tr><td colspan="3" align="center" style="padding:20px;color:#999;">Belum ada data masuk.</td></tr>
                        <?php else: $rank=1; foreach($stats['officer_stats'] as $off): ?>
                            <tr>
                                <td align="center">
                                    <?php if($rank==1): ?><i class="fas fa-medal" style="color:#FFD700;"></i>
                                    <?php elseif($rank==2): ?><i class="fas fa-medal" style="color:#C0C0C0;"></i>
                                    <?php elseif($rank==3): ?><i class="fas fa-medal" style="color:#CD7F32;"></i>
                                    <?php else: echo $rank; endif; ?>
                                </td>
                                <td>
                                    <div style="font-weight:bold;color:#333;"><?=$off['officers']?></div>
                                </td>
                                <td align="right">
                                    <span class="status-badge" style="background:#e8f5e9;color:#2E7D32;font-size:14px;padding:5px 10px;">
                                        <?=number_format($off['total'])?> Pohon
                                    </span>
                                </td>
                            </tr>
                        <?php $rank++; endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <script>
            new Chart(document.getElementById('c1'),{type:'doughnut',data:{labels:<?=json_encode(array_keys($stats['cond']))?>,datasets:[{data:<?=json_encode(array_values($stats['cond']))?>,backgroundColor:['#4caf50','#ffeb3b','#f44336','#ff9800']}]}});
            new Chart(document.getElementById('c2'),{type:'line',data:{labels:<?=json_encode(array_keys($stats['daily']))?>,datasets:[{label:'Geotag Masuk',data:<?=json_encode(array_values($stats['daily']))?>,borderColor:'#2E7D32',tension:0.3,fill:true,backgroundColor:'rgba(46,125,50,0.1)'}]}});
            new Chart(document.getElementById('c3'), {type: 'bar',data: {labels: <?=json_encode(array_keys($stats['loc_stats']))?>,datasets: [{label: 'Jumlah Data',data: <?=json_encode(array_values($stats['loc_stats']))?>,backgroundColor: '#1976d2',borderRadius: 4}]},options: {responsive: true,maintainAspectRatio: false,scales: {y: {beginAtZero: true,ticks: { precision: 0 }}} }});
        </script>

    <?php elseif($action === 'monitor'): ?>
        <div class="header"><h2>Monitoring Target Penanaman</h2></div>
        <div class="card">
            <div style="margin-bottom:20px;"><select id="locationSelect" onchange="switchTable(this.value)" style="padding:10px; border:1px solid #2E7D32; min-width:200px;"><?php $first_id = ''; foreach ($loc_keys as $id => $name): if ($first_id === '') $first_id = $id; ?><option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select></div>
            <?php foreach ($monitoring_tables as $id => $rows): ?>
                <div id="<?= $id ?>" class="monitor-table" style="display: <?= ($id === $first_id) ? 'block' : 'none' ?>;">
                    <table class="table">
                        <thead><tr style="background:#f1f8e9;"><th style="padding:15px;">Jenis Bibit</th><th style="text-align:center;">Target</th><th style="text-align:center;">Tertanam</th><th style="width:40%;">Progress</th></tr></thead>
                        <tbody>
                            <?php if(empty($rows)): ?><tr><td colspan="4" align="center">Belum ada target.</td></tr><?php else: foreach ($rows as $r): $color = $r['percent'] < 50 ? '#c62828' : ($r['percent'] < 100 ? '#f57f17' : '#2E7D32'); ?>
                            <tr><td style="padding:15px;"><b><?= $r['jenis'] ?></b></td><td align="center"><?= number_format($r['target']) ?></td><td align="center"><b><?= number_format($r['real']) ?></b></td>
                                <td style="padding:15px;"><div style="background:#eee;height:10px;border-radius:10px;overflow:hidden;"><div style="width:<?=$r['percent'] > 100 ? 100 : $r['percent']?>%;background:<?=$color?>;height:100%;"></div></div><small style="color:<?=$color?>;font-weight:bold;"><?=$r['percent']?>%</small></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
        <script>function switchTable(id){document.querySelectorAll('.monitor-table').forEach(el=>el.style.display='none');if(document.getElementById(id))document.getElementById(id).style.display='block';}</script>

    <?php elseif($action === 'map'): ?>
        <
        <div class="header"><h2>Peta Sebaran Real-time</h2></div>
        
        <form class="filter-bar">
            <input type="hidden" name="action" value="map">
            
            <input type="text" name="search" placeholder="Cari ID, Petugas, atau Detail..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="max-width:200px; flex:1;">
            
            <select name="locationName" style="max-width:150px;">
                <option value="all">Semua Lokasi</option>
                <?php foreach($locations_list as $loc): ?>
                    <option value="<?=htmlspecialchars($loc)?>" <?=($_GET['locationName']??'')==$loc?'selected':''?>><?=htmlspecialchars($loc)?></option>
                <?php endforeach; ?>
            </select>
            
            <select name="itemType" style="max-width:150px;">
                <option value="all">Semua Jenis</option>
                <?php foreach($tree_types as $type): ?>
                    <option value="<?=htmlspecialchars($type)?>" <?=($_GET['itemType']??'')==$type?'selected':''?>><?=htmlspecialchars($type)?></option>
                <?php endforeach; ?>
            </select>
            
            <select name="condition">
                <option value="all">Semua Kondisi</option>
                <option value="Hidup" <?=($_GET['condition']??'')=='Hidup'?'selected':''?>>Hidup</option>
                <option value="Merana" <?=($_GET['condition']??'')=='Merana'?'selected':''?>>Merana</option>
                <option value="Mati" <?=($_GET['condition']??'')=='Mati'?'selected':''?>>Mati</option>
            </select>
            
            <button class="btn btn-p">Filter</button> 
            <a href="?action=map" class="btn btn-d">Reset</a>
        </form>
        
        <div class="card" style="padding:0; overflow:hidden; position:relative;">
            <div id="map" style="height:650px; z-index: 1;"></div>
        </div>
        
        <script>
            // A. Inisialisasi Peta & Base Layers
            var m = L.map('map').setView([-6.9, 107.6], 10); // Default View (Jabar)

            var streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { 
                attribution: '&copy; OpenStreetMap', maxZoom: 19 
            });

            var satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { 
                attribution: '&copy; Esri', maxZoom: 19 
            });

            // Restore Layer Pilihan User
            var savedLayer = localStorage.getItem('SelectedLayer');
            if (savedLayer === 'Satelit') { m.addLayer(satellite); } else { m.addLayer(streets); }

            var baseMaps = { "Peta Jalan": streets, "Satelit": satellite };
            var overlayMaps = {};

            // B. KML LAYER & ZOOM BUTTON
            <?php if(file_exists($kml_file_path)): ?>
                var kmlLayer = omnivore.kml('<?=$kml_url_path?>?t=<?=time()?>').on('ready', function() {
                    this.eachLayer(function(layer) { 
                        if (layer.feature && layer.feature.properties) { 
                            var desc = layer.feature.properties.description || layer.feature.properties.name || "Area Batas"; 
                            layer.bindPopup(desc); 
                        } 
                    });
                });
                
                overlayMaps["Batas Area (KML)"] = kmlLayer;
                m.addLayer(kmlLayer); // Default ON

                // Tombol Custom: Zoom to KML Area
                var ZoomControl = L.Control.extend({
                    options: { position: 'topright' },
                    onAdd: function (map) {
                        var container = L.DomUtil.create('div', 'leaflet-bar leaflet-control custom-map-btn');
                        container.innerHTML = '<i class="fas fa-expand-arrows-alt"></i>';
                        container.title = "Fokus ke Area Batas";
                        container.style.backgroundColor = 'white';
                        container.style.width = '30px'; container.style.height = '30px';
                        container.style.lineHeight = '30px'; container.style.textAlign = 'center';
                        container.style.cursor = 'pointer'; container.style.fontSize = '14px';
                        
                        container.onclick = function(){
                            if(kmlLayer && kmlLayer.getBounds().isValid()){ map.fitBounds(kmlLayer.getBounds()); } 
                            else { Swal.fire('Info', 'Layer area tidak ditemukan.', 'info'); }
                        };
                        return container;
                    }
                });
                m.addControl(new ZoomControl());
            <?php endif; ?>

            L.control.layers(baseMaps, overlayMaps).addTo(m);
            m.on('baselayerchange', function(e) { localStorage.setItem('SelectedLayer', e.name); });

            // C. MARKER CLUSTERING & DATA RENDERING
            var markers = L.markerClusterGroup({
                chunkedLoading: true, // Anti-Freeze untuk data banyak
                chunkInterval: 200,
                chunkDelay: 50
            });

            // C. MARKER CLUSTERING & DATA RENDERING
            var markers = L.markerClusterGroup({ chunkedLoading: true, chunkInterval: 200, chunkDelay: 50 });

            // FIX: Tambahkan fallback "[]" jika data kosong agar JS tidak error
            var pts = <?= json_encode($map_data ?? []) ?>; 
            if (!Array.isArray(pts)) pts = []; // Safety check tambahan

            var bounds = [];
            var mapDataStore = {}; // Global store untuk akses data di Modal

            // Helper: Buka Modal Detail dari Klik Marker
            window.openDetailFromMap = function(id) {
                if(mapDataStore[id]) {
                    viewDetail(mapDataStore[id]); // Panggil fungsi global viewDetail
                }
            };

            // Loading Indicator
            if(pts.length > 2000) {
                Swal.fire({
                    title: 'Memuat Peta...', 
                    text: 'Menampilkan ' + pts.length + ' titik...',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });
            }

            // Render Marker (Async)
            setTimeout(function() {
                pts.forEach(p => {
                    var lat = parseFloat(p.latitude);
                    var lng = parseFloat(p.longitude);
                    
                    if(!isNaN(lat) && !isNaN(lng)){
                        // Simpan data untuk modal
                        mapDataStore[p.id] = p;

                        // Warna Marker
                        var color = (p.condition == 'Hidup' || p.condition == 'Baik') ? 'green' : ((p.condition == 'Merana') ? 'orange' : 'red');
                        
                        // Icon Bulat
                        var icon = L.divIcon({
                            className: 'custom-div-icon', 
                            html: `<div style="background-color:${color}; width:12px; height:12px; border-radius:50%; border:2px solid white; box-shadow:0 0 3px black;"></div>`, 
                            iconSize: [12, 12], iconAnchor: [6, 6]
                        });

                        var photoUrl = p.photoPath ? (p.photoPath.startsWith('http') ? p.photoPath : '<?=$photo_base_url?>' + p.photoPath) : '';
                        
                        // Isi Popup
                        var popupContent = `
                            <div style="min-width:200px; text-align:center;">
                                <b style="font-size:14px;">${p.itemType}</b> <br>
                                <span style="font-size:10px; color:#666;">#${p.id} - ${p.locationName}</span><br>
                                <span style="color:${color}; font-weight:bold;">${p.condition}</span><br>
                                ${photoUrl ? `<img src="${photoUrl}" style="width:100%; height:120px; object-fit:cover; margin-top:5px; border-radius:4px;">` : ''}
                                
                                <div style="margin-top:10px; display:flex; gap:5px;">
                                    <button onclick="openDetailFromMap('${p.id}')" class="btn btn-i" style="flex:1; padding:5px; font-size:11px; border-radius:4px;">
                                        <i class="fas fa-info-circle"></i> Detail
                                    </button>
                                    <a href="?action=edit&table=geotags&id=${p.id}" class="btn btn-b" style="flex:1; padding:5px; font-size:11px; text-decoration:none; color:white; border-radius:4px;">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                </div>
                            </div>
                        `;

                        var mkr = L.marker([lat,lng], {icon: icon});
                        mkr.bindPopup(popupContent);
                        markers.addLayer(mkr);
                        bounds.push([lat,lng]);
                    }
                });

                m.addLayer(markers);
                Swal.close();

                // Auto Zoom ke Data (Jika tidak ada KML)
                <?php if(!file_exists($kml_file_path)): ?>
                    if(bounds.length) m.fitBounds(bounds);
                <?php endif; ?>
                
            }, 500); // Delay render agar UI siap
        </script>


    <?php elseif($action === 'layers'): ?>
        <div class="header"><h2>Manajemen Layer</h2></div>
        <div class="card" style="text-align:center;">
            <h3>Upload File KML/KMZ</h3>
            <?php if(file_exists($kml_file_path)): ?><p style="color:green;">File terpasang: <b>admin_layer.kml</b></p><?php endif; ?>
            <?php if(is_admin()): ?>
                <form method="post" enctype="multipart/form-data" style="margin-top:20px;">
                    <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                    <input type="file" name="kml_file" required style="border:1px solid #ddd;padding:10px;width:100%;margin-bottom:10px;">
                    <button name="upload_kml" class="btn btn-p">Upload</button> <button name="delete_kml" class="btn btn-d">Hapus Layer</button>
                </form>
            <?php else: ?><p style="color:red;">Akses terbatas.</p><?php endif; ?>
        </div>

    <?php elseif(in_array($action, ['list', 'users'])): ?>
        <div class="header"><h2 style="margin:0;">Data <?=ucfirst($table)?></h2><a href="?action=create&table=<?=$action=='users'?'admin_users':$table?>" class="btn btn-p"><i class="fas fa-plus-circle"></i> Tambah Data</a></div>
        
        <?php if($action=='list'): ?>
        <form class="filter-bar" id="filterForm">
            <input type="hidden" name="action" value="list"><input type="hidden" name="table" value="<?=$table?>">
            
            <?php if($table=='geotags'): ?>
                <input type="text" name="search" placeholder="Cari ID, Jenis, Lokasi, Petugas..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="min-width:200px; flex:1;">
                
                <select name="locationName" style="max-width:150px;"><option value="all">Lokasi</option><?php foreach($locations_list as $loc): ?><option value="<?=htmlspecialchars($loc)?>" <?=($_GET['locationName']??'')==$loc?'selected':''?>><?=htmlspecialchars($loc)?></option><?php endforeach; ?></select>
                
                <select name="itemType" style="max-width:150px;"><option value="all">Jenis</option><?php foreach($tree_types as $type): ?><option value="<?=htmlspecialchars($type)?>" <?=($_GET['itemType']??'')==$type?'selected':''?>><?=htmlspecialchars($type)?></option><?php endforeach; ?></select>
                
                <select name="projectId" style="max-width:150px;"><option value="all">Project</option><?php foreach($projects_list as $p) echo "<option value='{$p['projectId']}' ". (($_GET['projectId']??'') == $p['projectId'] ? 'selected' : '') .">{$p['locationName']}</option>"; ?></select>
                
                <select name="condition" style="max-width:150px;"><option value="all">Kondisi</option><option value="Hidup">Hidup</option><option value="Merana">Merana</option><option value="Mati">Mati</option></select>

                <select name="has_photo" style="max-width:150px;">
                    <option value="all">Semua Foto</option>
                    <option value="yes" <?=($_GET['has_photo']??'')=='yes'?'selected':''?>>Ada Foto</option>
                    <option value="no" <?=($_GET['has_photo']??'')=='no'?'selected':''?>>Tanpa Foto</option>
                </select>

                <select name="is_duplicate" style="max-width:180px;">
                    <option value="all">Semua Data</option>
                    <option value="exact" <?=($_GET['is_duplicate']??'')=='exact'?'selected':''?>>Koordinat Persis</option>
                    <option value="near" <?=($_GET['is_duplicate']??'')=='near'?'selected':''?>>Koordinat Dekat (1m)</option>
                    <option value="photo" <?=($_GET['is_duplicate']??'')=='photo'?'selected':''?>>File Foto Sama</option>
                </select>

                <div style="display:flex;align-items:center;gap:5px;">
                    <input type="date" name="start_date" value="<?=htmlspecialchars($_GET['start_date']??'')?>"> - <input type="date" name="end_date" value="<?=htmlspecialchars($_GET['end_date']??'')?>">
                </div>
            <?php else: ?>
                <input type="text" name="search" placeholder="Cari..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="flex:1;">
            <?php endif; ?>
            
            <button class="btn btn-b" type="submit"><i class="fas fa-search"></i> Cari</button> 
            <a href="?action=list&table=<?=$table?>" class="btn btn-d"><i class="fas fa-sync"></i></a>
            
            <?php if($table=='geotags'): ?>
                <div style="display:flex; gap:5px; align-items:center; background:#e8f5e9; padding:5px 10px; border-radius:6px; margin-left:auto;">
                    <span style="font-size:11px; font-weight:bold; color:#2E7D32;">EXPORT:</span>
                    <a href="javascript:void(0)" onclick="downloadExport('csv')" class="btn btn-i" style="padding:4px 8px; font-size:11px;">CSV</a>
                    <a href="javascript:void(0)" onclick="downloadExport('download_zip')" class="btn btn-w" style="padding:4px 8px; font-size:11px;">ZIP</a>
                    <a href="javascript:void(0)" onclick="downloadExport('kml')" class="btn btn-b" style="padding:4px 8px; font-size:11px;">KML</a>
                    <a href="javascript:void(0)" onclick="downloadExport('pdf')" class="btn btn-d" style="padding:4px 8px; font-size:11px; background:#d32f2f;">PDF</a>
                </div>
            <?php endif; ?>
        </form>

        <form method="post" id="bulkForm">
            <?=$hidden_filters?>
            <?php if($table == 'geotags'): ?>
            <div style="background:#e8f5e9;padding:12px;border-radius:8px;margin-bottom:15px;display:flex;gap:10px;align-items:center;border:1px solid #c8e6c9;flex-wrap:wrap;">
                <i class="fas fa-check-square" style="color:#2E7D32;"></i> <b>Aksi Terpilih:</b> 
                <select name="bulk_action_type" required style="border-color:#2E7D32;" onchange="toggleBulkInputs(this.value)">
                    <option value="">-- Pilih Aksi --</option><option value="mass_edit_type">Ubah Jenis Pohon</option><option value="download_zip">Download Foto (ZIP)</option><option value="export_csv">Export CSV</option><option value="delete_selected">Hapus Data</option>
                </select>
                <select name="new_tree_type" id="newTreeTypeInput" style="display:none; border-color:#1976d2;"><option value="">-- Pilih Jenis Baru --</option><?php foreach($tree_types as $t): ?><option value="<?=htmlspecialchars($t)?>"><?=htmlspecialchars($t)?></option><?php endforeach; ?></select>
                <button type="button" onclick="confirmBulk()" class="btn btn-w">Proses</button> <button name="bulk_action" id="realBulkBtn" style="display:none;"></button><input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
            </div>
            <script>function toggleBulkInputs(val) {var t=document.getElementById('newTreeTypeInput');if(val==='mass_edit_type'){t.style.display='inline-block';t.required=true;}else{t.style.display='none';t.required=false;}}</script>
            <?php endif; ?>

            <div class="card" style="padding:0;overflow:hidden;"><div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <?php if($table=='geotags'): ?>
                                <th width="30"><input type="checkbox" onclick="toggle(this)"></th><th width="40">No</th><th>Foto</th><th>ID</th><th>Jenis</th><th>Petugas</th><th>Lokasi</th><th>Koordinat</th><th>Tanggal</th><th>Kondisi</th>
                            <?php else: ?>
                                <th>ID</th><th>Nama Kegiatan</th><th>Lokasi Project</th><th>Petugas</th><th>Status</th>
                            <?php endif; ?>
                            <th style="text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="<?= ($table=='geotags') ? 'geotagsTableBody' : '' ?>">
                        <?php if($table != 'geotags'): // Render PHP Biasa untuk Projects ?>
                            <?php if(empty($list_data)): ?><tr><td colspan="11" align="center" style="padding:30px;">Tidak ada data.</td></tr><?php else: foreach($list_data as $r): ?>
                                <tr>
                                    <td><b>#<?=$r['projectId']?></b></td><td><?=$r['activityName']?></td><td><?=$r['locationName']?></td><td><?=$r['officers']?></td><td><span class="status-badge status-<?=$r['status']?>"><?=$r['status']?></span></td>
                                    <td style="text-align:right;"><a href="?action=edit&table=projects&id=<?=$r['projectId']?>" class="btn btn-b"><i class="fas fa-edit"></i></a> <button type="button" onclick="confirmDel('<?=$r['projectId']?>')" class="btn btn-d"><i class="fas fa-trash"></i></button></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </form>
        
        <?php if($table=='geotags'): ?>
            <div id="jsPagination" class="pagination"></div>
        <?php elseif($total_pages > 1): ?>
             <div class="pagination">
                <?php $qp=$_GET; unset($qp['page']); for($i=1;$i<=$total_pages;$i++): ?><a href="?<?=http_build_query(array_merge($qp,['page'=>$i]))?>" class="<?=($i==$page)?'active':''?>"><?=$i?></a><?php endfor; ?>
            </div>
        <?php endif; ?>

        <form method="post" id="delForm"><input type="hidden" name="delete" value="1"><input type="hidden" name="delete_id" id="delId"><input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>"><?=$hidden_filters?></form>

        <?php elseif($action=='users'): $table='admin_users'; ?>
            <div class="card" style="padding:0;overflow:hidden;"><table><thead><tr><th>ID</th><th>Username</th><th>Role</th><th style="text-align:right;">Aksi</th></tr></thead><tbody><?php foreach($list_data as $u): ?><tr><td>#<?=$u['id']?></td><td><b><?=$u['username']?></b></td><td><?=$u['role']?></td><td style="text-align:right;"><a href="?action=edit&table=admin_users&id=<?=$u['id']?>" class="btn btn-b"><i class="fas fa-edit"></i></a></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
        
        <?php elseif($action === 'gallery'): ?>
            <div class="header"><h2>Galeri Foto</h2></div>
            <form class="filter-bar"><input type="hidden" name="action" value="gallery"><input type="text" name="search" placeholder="Cari..." value="<?=htmlspecialchars($_GET['search']??'')?>"><button class="btn btn-p">Cari</button></form>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:15px;">
                <?php if(!empty($list_data)): foreach($list_data as $r): $i=get_photo_url($r['photoPath']??'', $photo_base_url); if(!$i) continue; ?>
                <div class="card" style="padding:0;overflow:hidden;" onclick="viewDetail(<?=htmlspecialchars(json_encode($r))?>)">
                    <img src="<?=$i?>" style="width:100%;height:150px;object-fit:cover;">
                    <div style="padding:10px;"><b style="font-size:14px;"><?=$r['itemType']?></b><br><small><?=$r['locationName']?></small></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
            
        <?php elseif($action === 'edit' || $action === 'create'): 
        $is_edit = ($action=='edit'); 
        $d = $is_edit ? $pdo->query("SELECT * FROM `$table` WHERE `$pk`='{$_GET['id']}'")->fetch() : []; 
    ?>
         <div class="header"><h2><?=$is_edit ? 'Edit' : 'Tambah'?> Data</h2></div>
         <div class="card" style="max-width:800px;margin:0 auto;">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                
                <?=$hidden_filters?> 

                <?php if($table=='geotags'): ?>
                    <input type="hidden" name="id" value="<?=$d['id']??''?>">
                    
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:bold; color:#2E7D32;">Titik Koordinat (Geser Marker untuk Update)</label>
                        <div id="mapPicker" style="height:300px; width:100%; border:1px solid #ccc; border-radius:8px; margin-top:5px;"></div>
                        <small style="color:#666;">*Geser pin biru di peta, atau isi manual di bawah.</small>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
                        <div><label>Latitude</label><input type="text" name="latitude" id="inputLat" value="<?=$d['latitude']??''?>" style="width:100%;" required></div>
                        <div><label>Longitude</label><input type="text" name="longitude" id="inputLng" value="<?=$d['longitude']??''?>" style="width:100%;" required></div>
                    </div>
                    
                    <div style="margin-bottom:15px;"><label>Project ID</label><input type="number" name="projectId" value="<?=$d['projectId']??''?>" style="width:100%;"></div>

                    <div style="margin-bottom:15px;"><label>Tipe Item</label><select name="itemType" style="width:100%;"><?php foreach($tree_types as $t) { $sel = ($d['itemType']??'') == $t ? 'selected' : ''; echo "<option value='$t' $sel>$t</option>"; } ?></select></div>
                    <div style="margin-bottom:15px;"><label>Lokasi</label><select name="locationName" style="width:100%;"><?php foreach($locations_list as $l) { $sel = ($d['locationName']??'') == $l ? 'selected' : ''; echo "<option value='$l' $sel>$l</option>"; } ?></select></div>
                    <div style="margin-bottom:15px;"><label>Kondisi</label><select name="condition" style="width:100%;"><option value="Hidup" <?=($d['condition']??'')=='Hidup'?'selected':''?>>Hidup</option><option value="Mati" <?=($d['condition']??'')=='Mati'?'selected':''?>>Mati</option><option value="Merana" <?=($d['condition']??'')=='Merana'?'selected':''?>>Merana</option></select></div>
                    <div style="margin-bottom:15px;"><label>Detail</label><input type="text" name="details" value="<?=$d['details']??''?>" style="width:100%;"></div>
                    <div style="margin-bottom:15px;"><label>Synced?</label><select name="isSynced" style="width:100%;"><option value="1" <?=($d['isSynced']??0)==1?'selected':''?>>Ya</option><option value="0" <?=($d['isSynced']??0)==0?'selected':''?>>Tidak</option></select></div>

                    <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var lat = parseFloat(document.getElementById('inputLat').value) || -6.9; // Default Jabar
                        var lng = parseFloat(document.getElementById('inputLng').value) || 107.6;
                        
                        // 1. Inisialisasi Peta
                        var mapP = L.map('mapPicker').setView([lat, lng], 15);
                        
                        // 2. Base Layers
                        var streetsP = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' }).addTo(mapP);
                        var satP = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: '&copy; Esri' });
                        
                        // 3. Layer Control
                        var baseMapsP = { "Peta Jalan": streetsP, "Satelit": satP };
                        var overlayMapsP = {};
                        
                        // 4. LOAD KML LAYER (Fitur Baru)
                        <?php if(file_exists($kml_file_path)): ?>
                            // Tambahkan layer KML ke peta edit
                            var kmlLayerP = omnivore.kml('<?=$kml_url_path?>?t=<?=time()?>').on('ready', function() {
                                this.addTo(mapP); // Langsung tampilkan
                                // Opsional: Zoom ke area KML jika ini data baru (bukan edit)
                                <?php if(!$is_edit): ?>
                                    mapP.fitBounds(this.getBounds());
                                <?php endif; ?>
                            });
                            overlayMapsP["Batas Area (KML)"] = kmlLayerP;
                        <?php endif; ?>
                        
                        L.control.layers(baseMapsP, overlayMapsP).addTo(mapP);

                        // 5. Marker Draggable
                        var markerP = L.marker([lat, lng], {draggable: true}).addTo(mapP);

                        markerP.on('dragend', function(event) {
                            var position = event.target.getLatLng();
                            document.getElementById('inputLat').value = position.lat.toFixed(7);
                            document.getElementById('inputLng').value = position.lng.toFixed(7);
                        });

                        function updateMapFromInput() {
                            var la = parseFloat(document.getElementById('inputLat').value);
                            var lo = parseFloat(document.getElementById('inputLng').value);
                            if(!isNaN(la) && !isNaN(lo)) {
                                markerP.setLatLng([la, lo]);
                                mapP.panTo([la, lo]);
                            }
                        }
                        
                        document.getElementById('inputLat').addEventListener('change', updateMapFromInput);
                        document.getElementById('inputLng').addEventListener('change', updateMapFromInput);

                        setTimeout(function(){ mapP.invalidateSize(); }, 500);
                    });
                    </script>

                <?php elseif($table=='admin_users'): ?>
                     <input type="text" name="username" value="<?=$d['username']??''?>" required style="width:100%;margin-bottom:15px;" placeholder="Username">
                     <input type="password" name="password" style="width:100%;margin-bottom:15px;" placeholder="Password Baru (Kosongkan jika tetap)">
                     <select name="role" style="width:100%;"><option value="Admin" <?=($d['role']??'')=='Admin'?'selected':''?>>Admin</option><option value="Viewer" <?=($d['role']??'')=='Viewer'?'selected':''?>>Viewer</option></select>
                <?php else: /* Projects */ ?>
                     <input type="number" name="projectId" value="<?=$d['projectId']??''?>" required style="width:100%;margin-bottom:15px;" placeholder="ID Project">
                     <input type="text" name="activityName" value="<?=$d['activityName']??''?>" required style="width:100%;margin-bottom:15px;" placeholder="Nama Kegiatan">
                     <input type="text" name="locationName" value="<?=$d['locationName']??''?>" required style="width:100%;margin-bottom:15px;" placeholder="Lokasi">
                     <input type="text" name="officers" value="<?=$d['officers']??''?>" required style="width:100%;margin-bottom:15px;" placeholder="Petugas">
                     <select name="status" style="width:100%;"><option value="Active">Active</option><option value="Completed">Completed</option></select>
                <?php endif; ?>
                
                <button name="<?=$is_edit?'update':'create'?>" class="btn btn-p" style="margin-top:20px;">Simpan Data</button>
            </form>
         </div>
    <?php endif; ?>
</main>

<div id="detailModal" class="modal" style="display:none;"><div class="modal-content" style="width:500px;background:#fff;border-radius:8px;overflow:hidden;"><div style="background:#2E7D32;padding:15px;color:#fff;font-weight:bold;">Detail Data <span onclick="document.getElementById('detailModal').style.display='none'" style="float:right;cursor:pointer;">&times;</span></div><div id="detailContent" style="padding:20px;max-height:80vh;overflow-y:auto;"></div></div></div>
<div id="imgModal" class="modal" onclick="this.style.display='none'"><img class="modal-content" id="modalImg"></div>

<script>
/**
 * ==========================================
 * BAGIAN 1: FUNGSI GLOBAL & HELPER
 * ==========================================
 */

function toggle(s) {
    var c = document.querySelectorAll('input[name="selected_ids[]"]');
    for (var i = 0; i < c.length; i++) c[i].checked = s.checked;
}

function showModal(s) {
    document.getElementById('imgModal').style.display = "flex";
    document.getElementById('modalImg').src = s;
}

function switchTable(id) {
    document.querySelectorAll('.monitor-table').forEach(el => el.style.display = 'none');
    if (document.getElementById(id)) document.getElementById(id).style.display = 'block';
}

// ---------------------------------------------------------
// LOGIKA EXPORT (SERVER SIDE VS CLIENT SIDE)
// ---------------------------------------------------------

async function downloadExport(type) {
    // Label Tipe
    let titleType = type === 'download_zip' ? 'ZIP' : (type === 'csv' ? 'CSV' : (type === 'pdf' ? 'PDF' : 'KML'));

    // HTML Pilihan
    const choice = await Swal.fire({
        title: `Export ${titleType}`,
        icon: 'question',
        html: `
            <div style="text-align:left; font-size:14px; line-height:1.5;">
                <p><b>1. Server Side</b><br>
                Proses di Server. Cepat. (Tidak tersedia untuk PDF Foto).</p>
                <p><b>2. Client Side (Laptop)</b><br>
                Proses di Laptop. Ada progress bar. Wajib untuk PDF dengan Foto.</p>
            </div>
        `,
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: (type === 'pdf') ? 'Tidak Tersedia' : '<i class="fas fa-server"></i> Server Side',
        confirmButtonColor: (type === 'pdf') ? '#ccc' : '#2E7D32',
        denyButtonText: '<i class="fas fa-laptop"></i> Client Side',
        denyButtonColor: '#1976d2',
        cancelButtonText: 'Batal',
        didOpen: () => {
            // Disable tombol server side khusus PDF (karena berat)
            if(type === 'pdf') {
                Swal.getConfirmButton().disabled = true;
                Swal.getConfirmButton().style.cursor = 'not-allowed';
            }
        }
    });

    if (choice.isConfirmed && type !== 'pdf') {
        processServerExport(type);
    } else if (choice.isDenied) {
        processClientExport(type);
    }
}

// Opsi 1: Server Side (Redirect Biasa)
function processServerExport(type) {
    const form = document.getElementById('filterForm');
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    params.append('action', 'export_full');
    params.append('type', type);

    Swal.fire({
        title: 'Memproses di Server...',
        html: 'Harap tunggu file terunduh.',
        didOpen: () => Swal.showLoading()
    });

    window.location.href = 'actions.php?' + params.toString();
    setTimeout(() => Swal.close(), 3000);
}

// Opsi 2: Client Side (Fetch JSON -> Build File)
async function processClientExport(type) {
    const form = document.getElementById('filterForm');
    const formData = new FormData(form);
    
    // PDF juga butuh data full seperti CSV/KML
    let jsonAction = (type === 'download_zip') ? 'get_json_photos' : 'get_json_full';
    
    formData.append('action', 'export_full');
    formData.append('type', jsonAction);

    Swal.fire({ title: 'Mengambil Data...', didOpen: () => Swal.showLoading() });

    try {
        const response = await fetch('actions.php', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.status !== 'success' || !result.data || result.data.length === 0) {
            throw new Error("Tidak ada data ditemukan.");
        }

        const data = result.data;
        
        // Pilihan Generator
        if (type === 'download_zip') await generateZipClient(data);
        else if (type === 'csv') generateCSVClient(data);
        else if (type === 'kml') generateKMLClient(data);
        else if (type === 'pdf') await generatePDFClient(data); // <-- Fungsi Baru

    } catch (error) {
        Swal.fire('Gagal', error.message || 'Terjadi kesalahan.', 'error');
    }
}

// ---------------------------------------------------------
// GENERATOR PDF (BARU)
// ---------------------------------------------------------
async function generatePDFClient(data) {
    const { jsPDF } = window.jspdf;
    
    // Setting Kertas: Landscape ('l'), satuan mm, ukuran A4
    const doc = new jsPDF('l', 'mm', 'a4'); 

    const total = data.length;
    let processed = 0;

    // --- Loading Bar ---
    Swal.fire({
        title: 'Menyiapkan PDF',
        html: `Memuat gambar: <b id="pdf-c">0</b> / ${total}<br><div style="background:#ddd;height:10px;margin-top:5px;"><div id="pdf-b" style="background:#d32f2f;height:100%;width:0%"></div></div>`,
        showConfirmButton: false, allowOutsideClick: false
    });

    // 1. Pre-load Gambar ke Base64
    for (let i = 0; i < data.length; i++) {
        const row = data[i];
        try {
            if(row.full_photo_url) {
                const blob = await fetch(row.full_photo_url).then(r => r.blob());
                row.base64Img = await new Promise((resolve) => {
                    const reader = new FileReader();
                    reader.onloadend = () => resolve(reader.result);
                    reader.readAsDataURL(blob);
                });
            }
        } catch (e) { console.warn('Skip img', e); }
        processed++;
        document.getElementById('pdf-c').innerText = processed;
        document.getElementById('pdf-b').style.width = Math.round((processed/total)*100) + '%';
    }

    Swal.update({ html: 'Menyusun Halaman PDF...' });

    // 2. Header & Judul
    const pageWidth = doc.internal.pageSize.getWidth();
    
    doc.setFontSize(18);
    doc.setTextColor(46, 125, 50);
    doc.text("LAPORAN MONITORING SEEDLOC", pageWidth / 2, 15, { align: 'center' });
    
    doc.setFontSize(10);
    doc.setTextColor(100);
    doc.text(`Dicetak pada: ${new Date().toLocaleString('id-ID')}`, pageWidth / 2, 22, { align: 'center' });
    
    doc.setDrawColor(46, 125, 50);
    doc.setLineWidth(1);
    doc.line(10, 25, pageWidth - 10, 25);

    // [PERBAIKAN UTAMA] Definisikan resultData DI SINI (Sebelum autoTable)
    const resultData = data; 

    // 3. Tabel Data
    doc.autoTable({
        startY: 30,
        pageBreak: 'auto',      // Mode standar
        rowPageBreak: 'avoid',  // PENTING: Jika baris tidak muat, pindahkan ke halaman baru
        margin: { top: 30, bottom: 20},
        head: [['No', 'Foto', 'Lokasi Project', 'Jenis Pohon', 'Kondisi', 'Koordinat', 'Petugas']],
        body: data.map((r, index) => [
            index + 1,
            '', // Placeholder Foto
            r.locationName,
            r.itemType,
            r.condition,
            `${r.latitude}, \n${r.longitude}`,
            r.officers || '-'
        ]),
        theme: 'grid',
        headStyles: { 
            fillColor: [46, 125, 50],
            textColor: [255, 255, 255],
            halign: 'center',
            fontSize: 10
        },
        bodyStyles: { valign: 'middle', fontSize: 9 },
        columnStyles: {
            0: { cellWidth: 10, halign: 'center' },
            1: { cellWidth: 20, minCellHeight: 25},
            2: { cellWidth: 40 },
            3: { cellWidth: 35 },
            4: { cellWidth: 25, halign: 'center' },
            5: { cellWidth: 40, halign: 'center' },
            6: { cellWidth: 'auto' }
        },
        didDrawCell: function(dataHook) {
            // Kita ubah nama parameternya jadi dataHook biar gak bingung
            if (dataHook.column.index === 1 && dataHook.cell.section === 'body') {
                const rowIndex = dataHook.row.index;
                
                // Ambil dari variabel resultData yang SUDAH didefinisikan di atas
                if (resultData[rowIndex] && resultData[rowIndex].base64Img) {
                    const imgData = resultData[rowIndex].base64Img;
                    doc.addImage(imgData, 'JPEG', dataHook.cell.x + 2, dataHook.cell.y + 2, 15, 20);
                }
            }
        }
    });

    // 4. Save File
    doc.save(`Laporan_SeedLoc_${new Date().toISOString().slice(0,10)}.pdf`);
    Swal.fire('Selesai', 'PDF Berhasil dibuat!', 'success');
}
// ---------------------------------------------------------
// GENERATOR LAINNYA (CSV, KML, ZIP - TETAP SAMA)
// ---------------------------------------------------------

function generateCSVClient(data) {
    let csvContent = "\uFEFFID,ProjectID,Officer,Lat,Lng,Loc,Time,Type,Cond,Detail,Photo URL\n";
    data.forEach(r => {
        const clean = (text) => `"${(text || '').toString().replace(/"/g, '""')}"`;
        let row = [clean(r.id), clean(r.projectId), clean(r.officers), clean(r.latitude), clean(r.longitude), clean(r.locationName), clean(r.timestamp), clean(r.itemType), clean(r.condition), clean(r.details), clean(r.full_photo_url)].join(",");
        csvContent += row + "\n";
    });
    const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8" });
    saveAs(blob, `Export_Client.csv`);
    Swal.fire('Selesai', 'File CSV dibuat.', 'success');
}

function generateKMLClient(data) {
    let kml = '<'+'?xml version="1.0" encoding="UTF-8"?'+'>'
    '<kml xmlns="http://www.opengis.net/kml/2.2">'
    '<Document>\n'
    '<name>Export Client</name>';
    data.forEach(r => {
        const desc = `Tipe: ${r.itemType}\nKondisi: ${r.condition}\nPetugas: ${r.officers||'-'}\nWaktu: ${r.timestamp}`.replace(/&/g, '&amp;');
        kml += `<Placemark><name>#${r.id}</name><description>${desc}</description><Point><coordinates>${r.longitude},${r.latitude}</coordinates></Point></Placemark>`;
    });
    kml += `</Document></kml>`;
    const blob = new Blob([kml], { type: "application/vnd.google-earth.kml+xml" });
    saveAs(blob, `Export_Client.kml`);
    Swal.fire('Selesai', 'File KML dibuat.', 'success');
}

async function generateZipClient(photos) {
    const zip = new JSZip();
    const total = photos.length;
    let count = 0;

    Swal.fire({
        title: 'Download Foto',
        html: `Proses: <b id="zip-c">0</b> / ${total}<br><div style="background:#ddd;height:10px;margin-top:5px;"><div id="zip-b" style="background:#2E7D32;height:100%;width:0%"></div></div>`,
        showConfirmButton: false, allowOutsideClick: false
    });

    for (const photo of photos) {
        try {
            const blob = await fetch(photo.url).then(r => r.ok ? r.blob() : null);
            if(blob) { zip.file(photo.name, blob); count++; document.getElementById('zip-c').innerText = count; document.getElementById('zip-b').style.width = Math.round((count/total)*100) + '%'; }
        } catch(e){}
    }
    
    const content = await zip.generateAsync({ type: "blob" });
    saveAs(content, `Export_Photos.zip`);
    Swal.fire('Selesai', `ZIP ${count} foto siap.`, 'success');
}

// ---------------------------------------------------------
// INITIAL LOAD & EVENTS
// ---------------------------------------------------------

function loadGeotagsData(page) {
    const form = document.getElementById('filterForm');
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    params.append('page', page);
    window.history.pushState(null, '', '?' + params.toString());

    // Animasi Loading
    let timerInterval;
    Swal.fire({
        title: 'Memuat Data...',
        html: 'Mengambil data.<br><b style="font-size:24px;color:#2E7D32;">0%</b>',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
            const b = Swal.getHtmlContainer().querySelector('b');
            let progress = 0;
            timerInterval = setInterval(() => {
                if(progress < 90) progress += Math.floor(Math.random() * 5) + 1;
                if(b) b.textContent = progress + '%';
            }, 300);
        },
        willClose: () => clearInterval(timerInterval)
    });

    fetch('load_geotags.php?' + params.toString())
    .then(r => r.ok ? r.text() : Promise.reject(r.status))
    .then(text => {
        try {
            const res = JSON.parse(text);
            if(res.status === 'success') {
                const b = Swal.getHtmlContainer().querySelector('b');
                if(b) b.textContent = '100%';
                setTimeout(() => { Swal.close(); renderTable(res.data); renderPagination(res.pagination); }, 200);
            } else { Swal.fire('Error', res.message, 'error'); }
        } catch(e) { Swal.fire('Error', 'Format data invalid.', 'error'); }
    })
    .catch(e => Swal.fire('Error', 'Koneksi bermasalah.', 'error'));
}

function renderTable(rows) {
    const tbody = document.getElementById('geotagsTableBody');
    tbody.innerHTML = ''; 
    if(rows.length === 0) { tbody.innerHTML = '<tr><td colspan="11" align="center">Tidak ada data.</td></tr>'; return; }
    const qp = new URLSearchParams(window.location.search);

    rows.forEach(r => {
        const safe = JSON.stringify(r.raw).replace(/"/g, '&quot;');
        qp.set('action', 'edit'); qp.set('table', 'geotags'); qp.set('id', r.id);
        const img = r.photoUrl ? `<img src="${r.photoUrl}" width="150" height="200" style="object-fit:cover;border-radius:4px;cursor:pointer;" onclick="viewDetail(${safe})">` : '-';

        tbody.insertAdjacentHTML('beforeend', `
            <tr>
                <td><input type="checkbox" name="selected_ids[]" value="${r.id}"></td>
                <td>${r.no}</td>
                <td>${img}</td>
                <td>#${r.id}</td>
                <td><b>${r.itemType}</b></td>
                <td><small>${r.officers}</small></td>
                <td>${r.locationName}</td>
                <td><small style="font-family:monospace;">${r.lat}<br>${r.lng}</small></td>
                <td>${r.date}</td>
                <td><span class="status-badge" style="${r.badgeStyle}">${r.condition}</span></td>
                <td align="right">
                    <button type="button" onclick="viewDetail(${safe})" class="btn btn-i"><i class="fas fa-eye"></i></button>
                    <a href="?${qp.toString()}" class="btn btn-b"><i class="fas fa-edit"></i></a>
                    <button type="button" onclick="confirmDel('${r.id}')" class="btn btn-d"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `);
    });
}

function renderPagination(pg) {
    const container = document.getElementById('jsPagination');
    container.innerHTML = '';
    if(pg.total_pages <= 1) return;
    let html = (pg.current_page > 1) ? `<a onclick="loadGeotagsData(${pg.current_page-1})">&laquo; Prev</a>` : `<a class="disabled">&laquo; Prev</a>`;
    // Simplifikasi pagination logic agar pendek
    html += `<a class="active">${pg.current_page} / ${pg.total_pages}</a>`;
    html += (pg.current_page < pg.total_pages) ? `<a onclick="loadGeotagsData(${pg.current_page+1})">Next &raquo;</a>` : `<a class="disabled">Next &raquo;</a>`;
    container.innerHTML = html;
}

// Helpers Form & Detail
function injectFiltersToForm(fid) {
    const f = document.getElementById(fid);
    if(!f) return;
    f.querySelectorAll('.dynamic-ret').forEach(el=>el.remove());
    new URLSearchParams(window.location.search).forEach((v,k)=>{
        if(['search','page','condition','start_date','end_date','locationName','itemType','projectId'].includes(k)){
            const i = document.createElement('input'); i.type='hidden'; i.name='ret_'+k; i.value=v; i.className='dynamic-ret'; f.appendChild(i);
        }
    });
}

function confirmDel(id){ 
    Swal.fire({title:'Hapus?',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33'}).then(r=>{if(r.isConfirmed){
        document.getElementById('delId').value=id; injectFiltersToForm('delForm'); document.getElementById('delForm').submit();
    }}); 
}

function confirmBulk(){ 
    if(document.querySelector('select[name="bulk_action_type"]').value==''){Swal.fire('Pilih aksi dulu');return;}
    Swal.fire({title:'Proses Massal?',icon:'warning',showCancelButton:true}).then(r=>{if(r.isConfirmed){
        injectFiltersToForm('bulkForm'); document.getElementById('realBulkBtn').click();
    }}); 
}

function viewDetail(d) {
    document.getElementById('detailContent').innerHTML = `<table style="width:100%"><tr><td>JENIS</td><td>${d.itemType}</td></tr><tr><td>LOKASI</td><td>${d.locationName}</td></tr><tr><td>KOORDINAT</td><td>${d.latitude}, ${d.longitude}</td></tr><tr><td>WAKTU</td><td>${d.timestamp}</td></tr></table>`;
    document.getElementById('detailModal').style.display='flex';
}

document.addEventListener("DOMContentLoaded", function() {
    // Nav Interceptor
    document.querySelectorAll('.nav a, .btn-d[href^="?action="]').forEach(l => {
        l.addEventListener('click', function(e) {
            if(this.href.includes('logout') || this.getAttribute('href').startsWith('javascript')) return;
            e.preventDefault();
            const t = new URL(this.href, location.origin);
            new URLSearchParams(location.search).forEach((v,k)=>{ if(!t.searchParams.has(k)) t.searchParams.append(k,v); });
            if(new URLSearchParams(location.search).get('action') !== t.searchParams.get('action')) t.searchParams.delete('page');
            location.href = t.pathname + '?' + t.searchParams.toString();
        });
    });

    // Initial Load
    if(new URLSearchParams(location.search).get('table') === 'geotags') {
        loadGeotagsData(new URLSearchParams(location.search).get('page')||1);
        const f = document.getElementById('filterForm');
        if(f) f.addEventListener('submit', e=>{ e.preventDefault(); loadGeotagsData(1); });
    }
});
</script>

<?php endif; ?>
</body>
</html>