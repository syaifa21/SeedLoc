<?php
// index.php - Main Entry Point

require_once 'config.php';
require_once 'functions.php';
require_once 'actions.php';
require_once 'fetch_data.php';

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
    <style>
        body{font-family:'Segoe UI', sans-serif;background:#f4f6f8;margin:0;display:flex;height:100vh;overflow:hidden;color:#333}
        .sidebar{width:250px;background:#fff;border-right:1px solid #e0e0e0;display:flex;flex-direction:column;flex-shrink:0}
        .brand{padding:20px;border-bottom:1px solid #ffffffff;font-weight:700;color:#2E7D32;display:flex;align-items:center;gap:10px;font-size:18px}
        .nav{list-style:none;padding:10px 0;margin:0;flex:1;overflow-y:auto}
        .nav a{display:flex;align-items:center;gap:10px;padding:12px 20px;color:#555;text-decoration:none;border-left:4px solid transparent;transition:all 0.2s}
        .nav a:hover,.nav a.active{background:#e8f5e9;color:#2E7D32;border-left-color:#2E7D32}
        .main{flex:1;padding:25px;overflow-y:auto;display:flex;flex-direction:column}
        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:25px}
        .card{background:#fff;padding:50px;border-radius:10px;box-shadow:0 2px 5px rgba(0,0,0,0.05);margin-bottom:20px;border:1px solid #f0f0f0}
        .btn{padding:8px 14px;border:none;border-radius:6px;color:#fff;cursor:pointer;text-decoration:none;font-size:14px;display:inline-flex;align-items:center;gap:5px;font-weight:600;transition:opacity 0.2s}
        .btn:hover{opacity:0.9}
        .btn-p{background:#2E7D32} .btn-d{background:#d32f2f} .btn-w{background:#f39c12} .btn-b{background:#2196f3} .btn-i{background:#1565c0}
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; flex-wrap: wrap; }
        .pagination a { padding: 8px 12px; border: 1px solid #ddd; background: #fff; color: #333; text-decoration: none; border-radius: 4px; font-size: 14px; transition: all 0.2s; }
        .pagination a:hover { background: #f0f0f0; }
        .pagination a.active { background: #2E7D32; color: #fff; border-color: #2E7D32; pointer-events: none; }
        .pagination a.disabled { color: #ccc; pointer-events: none; border-color: #eee; }
        table{width:100%;border-collapse:collapse;font-size:14px} 
        th{background:#f8f9fa;font-weight:600;color:#666;text-transform:uppercase;font-size:12px;letter-spacing:0.5px}
        th,td{padding:12px 15px;border-bottom:1px solid #eee;text-align:left;vertical-align:middle}
        tr:hover{background-color:#fafafa}
        .filter-bar{display:flex;gap:10px;flex-wrap:wrap;background:#fff;padding:15px;border-radius:10px;border:1px solid #e0e0e0;margin-bottom:20px;align-items:center}
        input,select{padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;outline:none}
        input:focus,select:focus{border-color:#2E7D32}
        .modal{display:none;position:fixed;z-index:3000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.85);justify-content:center;align-items:center;flex-direction:column}
        .modal-content{max-width:90%;max-height:85%;border-radius:5px;box-shadow:0 0 20px rgba(0,0,0,0.5)}
        .status-badge{padding:4px 8px;border-radius:4px;font-size:11px;font-weight:bold;text-transform:uppercase}
        .status-Active{background:#e8f5e9;color:#2E7D32} .status-Completed{background:#e3f2fd;color:#1976d2}
        .custom-div-icon div { width:100%; height:100%; border-radius:50%; border:2px solid white; box-shadow:0 0 3px black; }
        .custom-map-btn { background-color: white; width: 30px; height: 30px; line-height: 30px; text-align: center; border-radius: 4px; cursor: pointer; box-shadow: 0 1px 5px rgba(0,0,0,0.65); font-size: 14px; color: #333; }
        .custom-map-btn:hover { background-color: #f4f4f4; color: #2E7D32; }
        @media(max-width:768px){.sidebar{width:60px}.brand span,.nav span{display:none}.brand{justify-content:center;padding:15px}.nav a{justify-content:center;padding:15px}}
    </style>
</head>
<body>

<?php 
if(isset($_SESSION['swal_success'])){ echo "<script>Swal.fire({icon:'success',title:'Berhasil',text:'{$_SESSION['swal_success']}',timer:1500,showConfirmButton:false});</script>"; unset($_SESSION['swal_success']); }
if(isset($_SESSION['swal_error'])){ echo "<script>Swal.fire({icon:'error',title:'Gagal',text:'{$_SESSION['swal_error']}'});</script>"; unset($_SESSION['swal_error']); }
if(isset($_SESSION['swal_warning'])){ echo "<script>Swal.fire({icon:'warning',title:'Perhatian',text:'{$_SESSION['swal_warning']}'});</script>"; unset($_SESSION['swal_warning']); }
?>

<?php if($action === 'login'): ?>
    <div style="width:100%;display:flex;justify-content:center;align-items:center;background:#eef2f5;">
        <div class="card" style="width:320px;text-align:center;padding:40px 30px;">
            <img src="https://seedloc.my.id/logo.png" width="80" style="margin-bottom:20px;border-radius:15px;box-shadow:0 4px 10px rgba(0,0,0,0.1)">
            <h2 style="color:#2E7D32;margin-bottom:5px;">Admin Login</h2>
            <p style="color:#888;margin-bottom:25px;font-size:14px;">Masuk untuk mengelola data</p>
            <form method="post">
                <div style="margin-bottom:15px;text-align:left;">
                    <input type="text" name="username" placeholder="Username" style="width:100%;box-sizing:border-box;" required>
                </div>
                <div style="margin-bottom:20px;text-align:left;">
                    <input type="password" name="password" placeholder="Password" style="width:100%;box-sizing:border-box;" required>
                </div>
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
        <li><a href="<?=build_url(['action'=>'monitor'])?>" class="<?=$action=='monitor'?'active':''?>"><i class="fas fa-bullseye"></i> <span>Monitoring Target</span></a></li>
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
            <h4><i class="fas fa-users" style="color:#2E7D32;"></i> Rekapitulasi Data Petugas</h4>
            <div style="overflow-x:auto;">
                <table style="margin-top:10px;">
                    <thead><tr><th width="50">No</th><th>Nama Petugas</th><th style="text-align:right;">Total Semua Data</th></tr></thead>
                    <tbody>
                        <?php if(empty($stats['officer_stats'])): ?><tr><td colspan="3" align="center" style="padding:20px;">Belum ada data masuk.</td></tr>
                        <?php else: $no=1; foreach($stats['officer_stats'] as $off): ?>
                            <tr><td><?=$no++?></td><td><div style="display:flex; align-items:center; gap:10px;"><i class="fas fa-user-circle fa-2x" style="color:#2E7D32;"></i><b><?=$off['officers']?></b></div></td><td align="right"><span style="font-size:18px; font-weight:bold; color:#333;"><?=number_format($off['total'])?></span><span style="color:#888; font-size:13px;"> Titik Geotag</span></td></tr>
                        <?php endforeach; endif; ?>
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
        
        <div class="card" style="min-height: 500px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #eee; padding-bottom:15px;">
                <label style="font-weight:bold; color:#666;">Pilih Lokasi:</label>
                <select id="locationSelect" onchange="switchTable(this.value)" style="padding:10px; border:1px solid #2E7D32; border-radius:5px; font-weight:bold; color:#2E7D32; min-width:200px; cursor:pointer; outline:none;">
                    <?php 
                    $first_id = '';
                    foreach ($loc_keys as $id => $name): 
                        if ($first_id === '') $first_id = $id;
                    ?>
                        <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php foreach ($monitoring_tables as $id => $rows): ?>
                <div id="<?= $id ?>" class="monitor-table" style="display: <?= ($id === $first_id) ? 'block' : 'none' ?>; animation: fadeIn 0.4s;">
                    <table class="table">
                        <thead>
                            <tr style="background:#f1f8e9; text-transform:uppercase; font-size:12px; letter-spacing:0.5px;">
                                <th style="padding:15px;">Jenis Bibit</th>
                                <th style="text-align:center;">Target</th>
                                <th style="text-align:center;">Tertanam</th>
                                <th style="width:40%;">Progress Realisasi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): 
                                $color = $r['percent'] < 50 ? '#c62828' : ($r['percent'] < 100 ? '#f57f17' : '#2E7D32');
                            ?>
                            <tr style="border-bottom:1px solid #f0f0f0;">
                                <td style="padding:15px; font-weight:bold;"><?= $r['jenis'] ?></td>
                                <td style="text-align:center; vertical-align:middle; color:#666;"><?= number_format($r['target']) ?></td>
                                <td style="text-align:center; vertical-align:middle; font-size:16px; font-weight:bold; color:#333;"><?= number_format($r['real']) ?></td>
                                <td style="vertical-align:middle; padding:15px;">
                                    <div style="display:flex; align-items:center; gap:15px;">
                                        <div style="flex:1; background:#eee; height:10px; border-radius:10px; overflow:hidden;">
                                            <div style="width:<?= $r['percent'] > 100 ? 100 : $r['percent'] ?>%; background:<?= $color ?>; height:100%; transition: width 1s ease-in-out;"></div>
                                        </div>
                                        <span style="font-size:13px; font-weight:bold; color:<?= $color ?>; width:45px; text-align:right;"><?= $r['percent'] ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($rows)): ?>
                                <tr><td colspan="4" style="text-align:center; padding:30px; color:#999;">Belum ada target bibit di lokasi ini.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>

        <script>
            function switchTable(id) {
                document.querySelectorAll('.monitor-table').forEach(el => el.style.display = 'none');
                if(document.getElementById(id)) document.getElementById(id).style.display = 'block';
            }
        </script>
        <style>@keyframes fadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }</style>
    <?php elseif($action === 'map'): ?>
        <div class="header"><h2>Peta Sebaran Real-time</h2></div>
        <form class="filter-bar">
            <input type="hidden" name="action" value="map">
            <input type="text" name="search" placeholder="Cari ID, Petugas, Lokasi, Detail..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="max-width:200px;">
            <select name="condition">
                <option value="all" <?=($_GET['condition']??'')=='all'?'selected':''?>>Semua Kondisi</option>
                <option value="Hidup" <?=($_GET['condition']??'')=='Hidup'?'selected':''?>>Hidup</option>
                <option value="Merana" <?=($_GET['condition']??'')=='Merana'?'selected':''?>>Merana</option>
                <option value="Mati" <?=($_GET['condition']??'')=='Mati'?'selected':''?>>Mati</option>
            </select>
            <div style="display:flex;align-items:center;gap:5px;">
                <input type="date" name="start_date" value="<?=htmlspecialchars($_GET['start_date']??'')?>"> <span>s/d</span> <input type="date" name="end_date" value="<?=htmlspecialchars($_GET['end_date']??'')?>">
            </div>
            <button class="btn btn-p">Filter</button> <a href="?action=map" class="btn btn-d">Reset</a>
        </form>
        <div class="card" style="padding:0;overflow:hidden;"><div id="map" style="height:650px;"></div></div>
        <script>
            var m = L.map('map').setView([-6.2, 106.8], 5);
            var streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' });
            var satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: '&copy; Esri' });
            var savedLayer = localStorage.getItem('SelectedLayer');
            if (savedLayer === 'Satelit') { m.addLayer(satellite); } else { m.addLayer(streets); }
            var baseMaps = { "Peta Jalan": streets, "Satelit": satellite };
            
            <?php if(file_exists($kml_file_path)): ?>
                var kmlUrl = '<?=$kml_url_path?>?t=<?=time()?>';
                var customLayer = omnivore.kml(kmlUrl).on('ready', function() { this.addTo(m); this.eachLayer(function(layer) { if (layer.feature && layer.feature.properties) { var desc = layer.feature.properties.description || layer.feature.properties.name || "Area Project"; layer.bindPopup(desc); } }); });
                var overlayMaps = { "Layer Overlay": customLayer };
                L.control.layers(baseMaps, overlayMaps).addTo(m);
                var zoomControl = L.Control.extend({
                    options: { position: 'topright' },
                    onAdd: function (map) {
                        var container = L.DomUtil.create('div', 'custom-map-btn leaflet-bar leaflet-control'); container.innerHTML = '<i class="fas fa-expand-arrows-alt"></i>'; container.title = "Fokus ke Layer";
                        container.onclick = function(){ if(customLayer && customLayer.getBounds().isValid()){ map.fitBounds(customLayer.getBounds()); } else { Swal.fire('Info', 'Layer tidak ditemukan atau kosong.', 'info'); } };
                        return container;
                    }
                });
                m.addControl(new zoomControl());
            <?php else: ?>
                L.control.layers(baseMaps).addTo(m);
            <?php endif; ?>

            m.on('baselayerchange', function(e) { localStorage.setItem('SelectedLayer', e.name); });
            var markers = L.markerClusterGroup();
            var pts = <?=json_encode($map_data)?>; 
            var bounds = [];
            pts.forEach(p=>{
                var lat=parseFloat(p.latitude),lng=parseFloat(p.longitude);
                if(!isNaN(lat)){
                    var color = (p.condition == 'Hidup' || p.condition == 'Baik') ? 'green' : ((p.condition == 'Merana' || p.condition == 'Rusak') ? 'orange' : 'red');
                    var icon = L.divIcon({className: 'custom-div-icon', html: `<div style="background-color:${color}; width:12px; height:12px; border-radius:50%; border:2px solid white; box-shadow:0 0 3px black;"></div>`, iconSize: [12, 12], iconAnchor: [6, 6]});
                    var img=p.photoPath?(p.photoPath.startsWith('http')?p.photoPath:'<?=$photo_base_url?>'+p.photoPath):'';
                    var mkr = L.marker([lat,lng],{icon:icon});
                    mkr.bindPopup(`<b>${p.itemType}</b><br><span style='color:${color}'>${p.condition}</span><br>${p.locationName}${img?'<br><img src="'+img+'" width="100%" style="margin-top:5px;border-radius:4px;">':''}`);
                    markers.addLayer(mkr);
                    bounds.push([lat,lng]);
                }
            });
            m.addLayer(markers);
            <?php if(!file_exists($kml_file_path)): ?>
                if(bounds.length) m.fitBounds(bounds, {padding:[50,50]});
            <?php endif; ?>
        </script>

    <?php elseif($action === 'layers'): ?>
        <div class="header"><h2>Manajemen Lapisan Overlay</h2></div>
        <div class="card" style="max-width: 600px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: 20px;"><i class="fas fa-map-marked-alt fa-3x" style="color: #2E7D32; margin-bottom: 10px;"></i><p>Upload file <b>.kml</b> atau <b>.kmz</b> untuk menampilkan area batas atau layer tambahan di Peta Sebaran.</p></div>
            <?php if(file_exists($kml_file_path)): ?>
                <div style="background:#e8f5e9; padding:15px; border-radius:8px; border:1px solid #c8e6c9; margin-bottom:20px; text-align: center;">
                    <h3 style="margin:0 0 5px 0; color:#2E7D32;">Status: Layer Aktif</h3>
                    <p style="margin:0; font-size:14px; color:#666;">File terpasang: <b>admin_layer.kml</b></p>
                    <p style="margin:5px 0 0 0; font-size:12px; color:#888;">Diperbarui: <?=date("d F Y, H:i", filemtime($kml_file_path))?></p>
                </div>
                <?php if(is_admin()): ?>
                    <form method="post" style="text-align: center;"><input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>"><button type="submit" name="delete_kml" class="btn btn-d"><i class="fas fa-trash"></i> Hapus Layer Saat Ini</button></form>
                    <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;"><p style="text-align: center; font-size: 14px; color: #666;">Ingin mengganti layer? Upload file baru di bawah ini:</p>
                <?php endif; ?>
            <?php else: ?><div style="background:#f5f5f5; padding:15px; border-radius:8px; border:1px solid #ddd; margin-bottom:20px; text-align: center; color: #666;">Status: <b>Belum ada layer terpasang</b></div><?php endif; ?>

            <?php if(is_admin()): ?>
                <form method="post" enctype="multipart/form-data" style="background: #fafafa; padding: 20px; border-radius: 8px; border: 1px dashed #ccc;">
                    <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                    <div style="margin-bottom: 15px;"><label style="font-weight: bold; display: block; margin-bottom: 5px;">Pilih File (KML / KMZ)</label><input type="file" name="kml_file" accept=".kml,.kmz" required style="width: 100%; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 4px;"></div>
                    <button type="submit" name="upload_kml" class="btn btn-p" style="width: 100%; justify-content: center;"><i class="fas fa-cloud-upload-alt"></i> Upload Layer</button>
                </form>
            <?php else: ?><div style="text-align: center; color: #d32f2f; background: #ffebee; padding: 10px; border-radius: 4px;"><i class="fas fa-lock"></i> Hanya Admin yang dapat mengubah layer.</div><?php endif; ?>
        </div>

    <?php elseif(in_array($action, ['list', 'users'])): ?>
        <div class="header"><h2 style="margin:0;">Data <?=ucfirst($table)?></h2><a href="?action=create&table=<?=$action=='users'?'admin_users':$table?>" class="btn btn-p"><i class="fas fa-plus-circle"></i> Tambah Data</a></div>
        <?php if($action=='list'): ?>
        <form class="filter-bar">
            <input type="hidden" name="action" value="list"><input type="hidden" name="table" value="<?=$table?>">
            <?php if($table=='geotags'): ?>
                <input type="text" name="search" placeholder="Cari ID, Jenis, Lokasi, Petugas, Detail..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="flex:1;">
                <select name="projectId"><option value="all">Semua Lokasi Project</option><?php foreach($projects_list as $p) echo "<option value='{$p['projectId']}' ". (($_GET['projectId']??'') == $p['projectId'] ? 'selected' : '') .">{$p['locationName']}</option>"; ?></select>
                <div style="display:flex;align-items:center;gap:5px;"><input type="date" name="start_date" value="<?=htmlspecialchars($_GET['start_date']??'')?>"> - <input type="date" name="end_date" value="<?=htmlspecialchars($_GET['end_date']??'')?>"></div>
            <?php else: ?><input type="text" name="search" placeholder="Cari Project, Lokasi, Petugas..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="flex:1;"><?php endif; ?>
            
            <button class="btn btn-b"><i class="fas fa-search"></i> Cari</button> <a href="?action=list&table=<?=$table?>" class="btn btn-d"><i class="fas fa-sync"></i></a>

            <?php if($table=='geotags'): $currProj = $_GET['projectId'] ?? 'all'; $labelExport = ($currProj == 'all' || empty($currProj)) ? "SEMUA DATA" : "PROJECT #$currProj"; ?>
                <div style="display:flex; gap:5px; align-items:center; background:#e8f5e9; padding:5px 10px; border-radius:6px; margin-left:auto;">
                    <span style="font-size:11px; font-weight:bold; color:#2E7D32; text-transform:uppercase;">Export (<?=$labelExport?>):</span>
                    <a href="javascript:void(0)" onclick="downloadExport('csv', '<?=$currProj?>')" class="btn btn-i" style="padding:4px 8px; font-size:11px;" title="CSV"><i class="fas fa-file-csv"></i> CSV</a>
                    <a href="javascript:void(0)" onclick="downloadExport('download_zip', '<?=$currProj?>')" class="btn btn-w" style="padding:4px 8px; font-size:11px;" title="Foto ZIP"><i class="fas fa-file-archive"></i> ZIP</a>
                    <a href="javascript:void(0)" onclick="downloadExport('kml', '<?=$currProj?>')" class="btn btn-b" style="padding:4px 8px; font-size:11px;" title="KML"><i class="fas fa-map"></i> KML</a>
                </div>
            <?php endif; ?>
        </form>

        <form method="post" id="bulkForm">
            <?php if($table == 'geotags'): ?>
            <div style="background:#e8f5e9;padding:12px;border-radius:8px;margin-bottom:15px;display:flex;gap:10px;align-items:center;border:1px solid #c8e6c9;">
                <i class="fas fa-check-square" style="color:#2E7D32;"></i> <b>Aksi Terpilih:</b> 
                <select name="bulk_action_type" required style="border-color:#2E7D32;">
                    <option value="">-- Pilih Aksi --</option><option value="download_zip">Download Foto (ZIP)</option><option value="export_csv">Export Data (Excel/CSV)</option><option value="export_kml">Export Peta (KML)</option><option value="delete_selected">Hapus Data</option>
                </select>
                <button type="button" onclick="confirmBulk()" class="btn btn-w">Proses</button> <button name="bulk_action" id="realBulkBtn" style="display:none;"></button><input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
            </div>
            <?php endif; ?>

            <div class="card" style="padding:0;overflow:hidden;"><div style="overflow-x:auto;"><table>
                    <thead>
                        <tr>
                            <?php if($table=='geotags'): ?><th width="30"><input type="checkbox" onclick="toggle(this)"></th><th>Foto</th><th>ID</th><th>Jenis</th><th>Petugas</th><th>Lokasi</th><th>Tanggal</th><th>Kondisi</th>
                            <?php else: ?><th>ID</th><th>Nama Kegiatan</th><th>Lokasi Project</th><th>Petugas</th><th>Status</th><?php endif; ?>
                            <th style="text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($list_data)): ?><tr><td colspan="10" align="center" style="padding:30px;color:#999;">Tidak ada data ditemukan.</td></tr>
                        <?php else: foreach($list_data as $r): ?>
                        <tr>
                            <?php if($table=='geotags'): ?>
                                <td><input type="checkbox" name="selected_ids[]" value="<?=$r[$pk]?>"></td>
                                <?php $i=get_photo_url($r['photoPath'], $photo_base_url); ?>
                                <td><?php if($i):?><img src="<?=$i?>" width="45" height="45" style="object-fit:cover;border-radius:4px;cursor:pointer;border:1px solid #ddd;" onclick="viewDetail(<?=htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8')?>)"><?php else: ?>-<?php endif; ?></td>
                                <td>#<?=$r['id']?></td><td><b><?=$r['itemType']?></b></td><td><small style="color:#1565c0;font-weight:600;"><i class="fas fa-user-tag"></i> <?=$r['officers'] ?? '-'?></small></td><td><?=$r['locationName']?></td><td><?=substr($r['timestamp'],0,10)?></td>
                                <td><span class="status-badge" style="<?=($r['condition']=='Hidup' || $r['condition']=='Baik') ? 'color:#2E7D32;background:#e8f5e9;' : (($r['condition']=='Merana') ? 'color:#856404;background:#fff3cd;' : 'color:#c62828;background:#ffebee;') ?>"><?=$r['condition']?></span></td>
                            <?php else: ?>
                                <td><b>#<?=$r['projectId']?></b></td><td><?=$r['activityName']?></td><td><i class="fas fa-map-marker-alt" style="color:#d32f2f;margin-right:5px;"></i> <?=$r['locationName']?></td><td><small style="color:#666;"><?=$r['officers']?></small></td><td><span class="status-badge status-<?=$r['status']?>"><?=$r['status']?></span></td>
                            <?php endif; ?>
                            <td style="text-align:right;">
                                <?php if($table=='geotags'): ?><button type="button" onclick="viewDetail(<?=htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8')?>)" class="btn btn-i" title="Detail" style="background:#00bcd4;"><i class="fas fa-eye"></i></button><?php endif; ?>
                                <a href="<?=build_url(['action'=>'edit', 'table'=>$table, 'id'=>$r[$pk]])?>" class="btn btn-b" title="Edit"><i class="fas fa-edit"></i></a>
                                <button type="button" onclick="confirmDel('<?=$r[$pk]?>')" class="btn btn-d" title="Hapus"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
            </table></div></div>
        </form>
        <form method="post" id="delForm"><input type="hidden" name="delete" value="1"><input type="hidden" name="delete_id" id="delId"><input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>"></form>

        <?php elseif($action=='users'): $table='admin_users'; ?>
            <div class="card" style="padding:0;overflow:hidden;"><table>
                    <thead><tr><th>ID</th><th>Username</th><th>Role</th><th style="text-align:right;">Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach($list_data as $u): ?><tr><td>#<?=$u['id']?></td><td><b><?=$u['username']?></b></td><td><span class="status-badge" style="background:#eee;color:#333;"><?=$u['role']?></span></td><td style="text-align:right;"><a href="?action=edit&table=admin_users&id=<?=$u['id']?>" class="btn btn-b"><i class="fas fa-edit"></i></a><?php if($u['id'] != $_SESSION['admin_id']): ?><button onclick="confirmDel('<?=$u['id']?>')" class="btn btn-d"><i class="fas fa-trash"></i></button><?php endif; ?></td></tr><?php endforeach; ?>
                    </tbody>
            </table></div>
            <form method="post" id="delForm"><input type="hidden" name="delete" value="1"><input type="hidden" name="delete_id" id="delId"><input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>"></form>
        <?php endif; ?>
        
        <?php if($action === 'list' && $total_pages > 1): $queryParams = $_GET; unset($queryParams['page']); ?>
        <div class="pagination">
            <a href="?<?=http_build_query(array_merge($queryParams, ['page' => max(1, $page-1)]))?>" class="<?=($page<=1)?'disabled':''?>">&laquo; Prev</a>
            <?php $range = 2; $start = max(1, $page - $range); $end = min($total_pages, $page + $range);
            if($start > 1) { echo '<a href="?'.http_build_query(array_merge($queryParams, ['page'=>1])).'">1</a>'; if($start > 2) echo '<span style="padding:8px;">...</span>'; }
            for($i = $start; $i <= $end; $i++): ?><a href="?<?=http_build_query(array_merge($queryParams, ['page' => $i]))?>" class="<?=($i==$page)?'active':''?>"><?=$i?></a><?php endfor; 
            if($end < $total_pages) { if($end < $total_pages-1) echo '<span style="padding:8px;">...</span>'; echo '<a href="?'.http_build_query(array_merge($queryParams, ['page'=>$total_pages])).'">'.$total_pages.'</a>'; } ?>
            <a href="?<?=http_build_query(array_merge($queryParams, ['page' => min($total_pages, $page+1)]))?>" class="<?=($page>=$total_pages)?'disabled':''?>">Next &raquo;</a>
        </div>
        <?php endif; ?>

    <?php elseif($action === 'gallery'): ?>
        <div class="header"><h2>Galeri Lapangan</h2></div>
        <form class="filter-bar"><input type="hidden" name="action" value="gallery"><input type="hidden" name="table" value="geotags"><input type="text" name="search" placeholder="Cari foto..." value="<?=htmlspecialchars($_GET['search']??'')?>" style="flex:1;"><button class="btn btn-p">Cari</button></form>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:15px;">
            <?php if(!empty($list_data)): foreach($list_data as $r): $i=get_photo_url($r['photoPath']??'', $photo_base_url); if(!$i) continue; ?>
            <div class="card" style="padding:0;overflow:hidden;cursor:pointer;transition:transform 0.2s;position:relative;" onclick="viewDetail(<?=htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8')?>)" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                <div style="position:absolute;top:10px;right:10px;padding:3px 8px;border-radius:4px;font-size:10px;font-weight:bold;box-shadow:0 2px 4px rgba(0,0,0,0.3);<?= ($r['condition']=='Hidup' || $r['condition']=='Baik') ? 'background:#e8f5e9;color:#2E7D32;' : (($r['condition']=='Merana') ? 'background:#fff3cd;color:#856404;' : 'background:#ffebee;color:#c62828;') ?>"><?=$r['condition']?></div>
                <img src="<?=$i?>" style="width:100%;height:150px;object-fit:cover;">
                <div style="padding:12px;"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;"><span style="font-size:11px;color:#999;">#<?=$r['id']?></span><span style="font-size:11px;color:#666;"><?=substr($r['timestamp'],0,10)?></span></div><b style="font-size:14px;display:block;margin-bottom:3px;color:#333;"><?=$r['itemType']?></b><small style="color:#666;display:block;margin-bottom:5px;"><i class="fas fa-map-marker-alt"></i> <?=$r['locationName']?></small><?php if(!empty($r['details'])): ?><small style="color:#888;font-style:italic;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">"<?=$r['details']?>"</small><?php endif; ?></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <div style="display:flex;justify-content:center;gap:5px;margin-top:20px;"><?php if($total_pages > 1): $q=$_GET; if($page>1){$q['page']=$page-1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p">Prev</a>';} if($page<$total_pages){$q['page']=$page+1; echo '<a href="?'.http_build_query($q).'" class="btn btn-p">Next</a>';} endif; ?></div>

    <?php elseif($action === 'edit' || $action === 'create'): 
        $is_edit = ($action=='edit');
        $d = $is_edit ? $pdo->query("SELECT * FROM `$table` WHERE `$pk`='{$_GET['id']}'")->fetch() : []; 
    ?>
        <div class="header"><h2><?=$is_edit ? 'Edit Data' : 'Tambah Data Baru'?> (<?=ucfirst(str_replace('_',' ',$table))?>)</h2></div>
        <div class="card" style="max-width:800px;margin:0 auto;">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                <?php foreach(['search', 'page', 'projectId', 'condition', 'start_date', 'end_date'] as $key): if(isset($_GET[$key])): ?><input type="hidden" name="ret_<?=$key?>" value="<?=htmlspecialchars($_GET[$key])?>"><?php endif; endforeach; ?>

                <?php if($table=='geotags'): ?>
                    <input type="hidden" name="id" value="<?=$d['id']?>">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
                        <div><label>Project ID</label><input type="number" name="projectId" value="<?=$d['projectId']??''?>" style="width:100%;"></div>
                        <div><label>Tipe Item</label><select name="itemType" style="width:100%;"><?php $opts = $tree_types; if($is_edit && !in_array($d['itemType'], $opts)) array_unshift($opts, $d['itemType']); foreach($opts as $t) { $sel = ($d['itemType'] == $t) ? 'selected' : ''; echo "<option value='".htmlspecialchars($t)."' $sel>".htmlspecialchars($t)."</option>"; } ?></select></div>
                    </div>
                    <div style="margin-bottom:15px;"><label>Lokasi</label><select name="locationName" style="width:100%;"><?php $locs = $locations_list; if($is_edit && !in_array($d['locationName'], $locs)) array_unshift($locs, $d['locationName']); foreach($locs as $l) { $sel = ($d['locationName'] == $l) ? 'selected' : ''; echo "<option value='".htmlspecialchars($l)."' $sel>".htmlspecialchars($l)."</option>"; } ?></select></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;"><div><label>Lat</label><input type="text" name="latitude" value="<?=$d['latitude']??''?>" style="width:100%;"></div><div><label>Lng</label><input type="text" name="longitude" value="<?=$d['longitude']??''?>" style="width:100%;"></div></div>
                    <div style="margin-bottom:15px;"><label>Kondisi</label><select name="condition" style="width:100%;"><option value="Hidup" <?=($d['condition']=='Hidup'?'selected':'')?>>Hidup</option><option value="Merana" <?=($d['condition']=='Merana'?'selected':'')?>>Merana</option><option value="Mati" <?=($d['condition']=='Mati'?'selected':'')?>>Mati</option></select></div>
                    <div style="margin-bottom:15px;"><label>Detail</label><input type="text" name="details" value="<?=$d['details']??''?>" style="width:100%;"></div>
                    <div style="margin-bottom:15px;"><label>Sync Status</label><select name="isSynced" style="width:100%;"><option value="1">Sudah</option><option value="0">Belum</option></select></div>

                <?php elseif($table == 'admin_users'): ?>
                    <div style="margin-bottom:15px;"><label style="font-weight:600;display:block;margin-bottom:5px;">Username</label><input type="text" name="username" value="<?=$d['username']??''?>" style="width:100%;" required></div>
                    <div style="margin-bottom:15px;"><label style="font-weight:600;display:block;margin-bottom:5px;">Role / Jabatan</label><select name="role" style="width:100%;"><option value="Admin" <?=($d['role']??'')=='Admin'?'selected':''?>>Admin (Full Access)</option><option value="Viewer" <?=($d['role']??'')=='Viewer'?'selected':''?>>Viewer (Read Only)</option></select></div>
                    <div style="margin-bottom:15px;"><label style="font-weight:600;display:block;margin-bottom:5px;">Password</label><input type="password" name="password" style="width:100%;" placeholder="<?=$is_edit ? 'Kosongkan jika tidak ingin mengubah password' : 'Masukkan password baru'?>" <?=$is_edit ? '' : 'required'?>><?php if($is_edit): ?><small style="color:#888;">Biarkan kosong jika tetap menggunakan password lama.</small><?php endif; ?></div>

                <?php else: ?>
                    <div style="margin-bottom:15px;"><label style="font-weight:600;display:block;margin-bottom:5px;">Project ID (Angka Unik)</label><input type="number" name="projectId" value="<?=$d['projectId']??''?>" style="width:100%;background:<?=$is_edit?'#eee':'#fff'?>" <?=$is_edit?'readonly':''?> required placeholder="Contoh: 101"></div>
                    <div style="margin-bottom:15px;"><label style="font-weight:600;display:block;margin-bottom:5px;">Nama Kegiatan</label><input type="text" name="activityName" value="<?=$d['activityName']??''?>" style="width:100%;" required placeholder="Contoh: Patroli Hutan Lindung"></div>
                    <div style="margin-bottom:15px;"><label style="font-weight:600;display:block;margin-bottom:5px;">Lokasi Kegiatan</label><input type="text" name="locationName" value="<?=$d['locationName']??''?>" style="width:100%;" required placeholder="Contoh: Blok A"></div>
                    <div style="margin-bottom:15px;"><label style="font-weight:600;display:block;margin-bottom:5px;">Petugas (Pisahkan dengan koma)</label><input type="text" name="officers" value="<?=$d['officers']??''?>" style="width:100%;" required placeholder="Contoh: Budi, Santoso"></div>
                    <div style="margin-bottom:15px;"><label style="font-weight:600;display:block;margin-bottom:5px;">Status</label><select name="status" style="width:100%;"><option value="Active" <?=($d['status']??'')=='Active'?'selected':''?>>Active (Berjalan)</option><option value="Completed" <?=($d['status']??'')=='Completed'?'selected':''?>>Completed (Selesai)</option></select></div>
                <?php endif; ?>

                <div style="margin-top:25px;display:flex;justify-content:flex-end;gap:10px;"><a href="?action=list&table=<?=$table?>" class="btn btn-d" style="background:#888;">Batal</a><button name="<?=$is_edit?'update':'create'?>" class="btn btn-p"><i class="fas fa-save"></i> Simpan Data</button></div>
            </form>
        </div>
    <?php endif; ?>
</main>

<div id="detailModal" class="modal" style="display:none;">
    <div class="modal-content" style="width: 500px; padding: 0; background: #fff; border-radius: 8px; position: relative; overflow: hidden;">
        <div style="background:#2E7D32; padding:15px 20px; color:#fff; font-weight:bold; font-size:16px;">Detail Data <span onclick="document.getElementById('detailModal').style.display='none'" style="float:right; cursor:pointer; font-size:20px;">&times;</span></div>
        <div id="detailContent" style="padding:20px; max-height: 80vh; overflow-y:auto;"></div>
    </div>
</div>
<div id="imgModal" class="modal" onclick="this.style.display='none'"><img class="modal-content" id="modalImg"><div id="modalCaption" style="color:#fff;margin-top:15px;font-size:16px;background:rgba(0,0,0,0.5);padding:5px 15px;border-radius:20px;"></div></div>

<script>
async function downloadExport(type, projectId) {
    let popup = Swal.fire({title: 'Export Data',html: `<div style="text-align:left; margin-bottom:5px; font-weight:bold;" id="progress-label">Menyiapkan data di server...</div><progress id="export-progress" value="0" max="100" style="width: 100%; height: 20px;"></progress><div id="progress-text" style="font-size:12px; color:#666; margin-top:5px;">Mohon tunggu...</div>`, allowOutsideClick: false, showConfirmButton: false, didOpen: () => { Swal.showLoading(); }});
    try {
        const response = await fetch(`?action=export_full&projectId=${projectId}&type=${type}`);
        if (!response.ok) throw new Error('Gagal menghubungi server.');
        const reader = response.body.getReader();
        const contentLength = +response.headers.get('Content-Length');
        const disposition = response.headers.get('Content-Disposition');
        let filename = 'Export_Data.' + (type === 'download_zip' ? 'zip' : (type === 'kml' ? 'kml' : 'csv'));
        if (disposition) { const matches = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(disposition); if (matches != null && matches[1]) filename = matches[1].replace(/['"]/g, ''); }
        Swal.hideLoading();
        let receivedLength = 0, chunks = [];
        while(true) {
            const {done, value} = await reader.read(); if (done) break;
            chunks.push(value); receivedLength += value.length;
            const progressBar = document.getElementById('export-progress'), progressText = document.getElementById('progress-text'), progressLabel = document.getElementById('progress-label');
            progressLabel.innerText = "Mengunduh file...";
            if (contentLength) { const percent = Math.round((receivedLength / contentLength) * 100); progressBar.value = percent; progressText.innerText = `${percent}% (${(receivedLength/1024/1024).toFixed(2)} MB)`; } 
            else { progressBar.removeAttribute('value'); progressText.innerText = `Terunduh: ${(receivedLength/1024).toFixed(0)} KB`; }
        }
        const blob = new Blob(chunks), url = window.URL.createObjectURL(blob), a = document.createElement('a');
        a.href = url; a.download = filename; document.body.appendChild(a); a.click(); window.URL.revokeObjectURL(url); document.body.removeChild(a);
        Swal.fire({ icon: 'success', title: 'Selesai!', text: 'File berhasil disimpan.', timer: 1500, showConfirmButton: false });
    } catch (err) { console.error(err); Swal.fire({ icon: 'error', title: 'Gagal', text: 'Terjadi kesalahan saat mengunduh data.' }); }
}
function toggle(s){var c=document.querySelectorAll('input[name="selected_ids[]"]');for(var i=0;i<c.length;i++)c[i].checked=s.checked;}
function showModal(s,c){ document.getElementById('imgModal').style.display="flex"; document.getElementById('modalImg').src=s; document.getElementById('modalCaption').innerHTML=c; }
function viewDetail(data) {
    var photoUrl = data.photoPath ? (data.photoPath.startsWith('http') ? data.photoPath : '<?=$photo_base_url?>' + data.photoPath) : '';
    var color = data.condition == 'Hidup' || data.condition == 'Baik' ? '#2E7D32' : (data.condition == 'Mati' ? '#c62828' : '#f39c12');
    var html = `<div style="text-align:center; margin-bottom:15px;">${photoUrl ? `<img src="${photoUrl}" style="max-width:100%; max-height:250px; border-radius:8px; border:1px solid #eee; cursor:pointer;" onclick="showModal('${photoUrl}', '${data.itemType}')">` : '<div style="padding:40px; background:#f5f5f5; color:#999; border-radius:8px;">Tidak Ada Foto</div>'}</div><table style="width:100%; border-collapse:collapse;"><tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold; width:100px;">JENIS POHON</td><td style="font-weight:bold; font-size:15px;">${data.itemType}</td></tr><tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold;">PETUGAS</td><td><i class="fas fa-user"></i> ${data.officers || '-'}</td></tr><tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold;">LOKASI</td><td><i class="fas fa-map-marker-alt" style="color:#d32f2f"></i> ${data.locationName}</td></tr><tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold;">KONDISI</td><td><span class="status-badge" style="background:${color}; color:#fff;">${data.condition}</span></td></tr><tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold;">KOORDINAT</td><td style="font-family:monospace;">${parseFloat(data.latitude).toFixed(6)}, ${parseFloat(data.longitude).toFixed(6)}</td></tr><tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold;">WAKTU</td><td>${data.timestamp}</td></tr><tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold; vertical-align:top;">CATATAN</td><td style="background:#f9f9f9; padding:8px; border-radius:4px; font-size:13px; line-height:1.4;">${data.details || '-'}</td></tr><tr><td style="padding:8px 0; color:#666; font-size:12px; font-weight:bold;">PROJECT ID</td><td>#${data.projectId}</td></tr></table>`;
    document.getElementById('detailContent').innerHTML = html; document.getElementById('detailModal').style.display = 'flex';
}
function confirmBulk(){ var s=document.querySelector('select[name="bulk_action_type"]'); if(s.value==''){ Swal.fire('Pilih Aksi','Silakan pilih aksi massal terlebih dahulu.','info'); return; } Swal.fire({title:'Konfirmasi Massal',text:'Yakin ingin memproses data terpilih?',icon:'warning',showCancelButton:true,confirmButtonText:'Ya, Proses!',cancelButtonText:'Batal'}).then((r)=>{ if(r.isConfirmed) document.getElementById('realBulkBtn').click(); }); }
function confirmDel(id){ Swal.fire({title:'Hapus Data?',text:'Data yang dihapus (beserta Fotonya) tidak dapat dikembalikan!',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',confirmButtonText:'Ya, Hapus!'}).then((r)=>{ if(r.isConfirmed){ document.getElementById('delId').value = id; document.getElementById('delForm').submit(); } }); }
</script>

<?php endif; ?>
</body>
</html>