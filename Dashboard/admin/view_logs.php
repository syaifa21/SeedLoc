<?php
require_once 'config.php';
require_auth(); // Memastikan Ali sudah login

$log_file = __DIR__ . '/sync_log.txt';

// Handler untuk request AJAX (Hanya mengambil isi teks log)
if (isset($_GET['fetch_raw'])) {
    if (file_exists($log_file)) {
        $content = file_get_contents($log_file);
        $lines = array_reverse(explode(PHP_EOL, trim($content)));
        foreach($lines as $line) {
            $class = '';
            if(strpos($line, 'SUKSES') !== false) $class = 'status-success';
            elseif(strpos($line, 'ERROR') !== false) $class = 'status-error';
            echo "<div class='$class'>" . htmlspecialchars($line) . "</div>";
        }
    } else {
        echo "Belum ada catatan log.";
    }
    exit;
}

// Handler untuk membersihkan log
if (isset($_POST['clear_log']) && is_admin()) {
    file_put_contents($log_file, "");
    $_SESSION['swal_success'] = "Log berhasil dibersihkan!";
    header("Location: view_logs.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Live Sync Logs - SeedLoc</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f8; padding: 20px; color: #333; }
        .container { max-width: 1000px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e8f5e9; padding-bottom: 15px; margin-bottom: 20px; }
        .log-container { background: #1e1e1e; color: #d4d4d4; padding: 20px; border-radius: 8px; font-family: 'Consolas', monospace; font-size: 13px; line-height: 1.6; height: 500px; overflow-y: auto; border: 1px solid #333; }
        .status-success { color: #4caf50; font-weight: bold; }
        .status-error { color: #f44336; font-weight: bold; }
        .btn { padding: 10px 18px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; color: white; font-weight: 600; font-size: 14px; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-red { background: #d32f2f; }
        .btn-green { background: #2E7D32; }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        .badge-live { background: #d32f2f; color: white; padding: 2px 8px; border-radius: 10px; font-size: 10px; vertical-align: middle; animation: blink 1s infinite; }
        @keyframes blink { 0% { opacity: 1; } 50% { opacity: 0.3; } 100% { opacity: 1; } }
    </style>
</head>
<body>

<?php 
if(isset($_SESSION['swal_success'])){ echo "<script>Swal.fire({icon:'success',title:'Berhasil',text:'{$_SESSION['swal_success']}',timer:1500,showConfirmButton:false});</script>"; unset($_SESSION['swal_success']); }
?>

<div class="container">
    <div class="header">
        <div>
            <h2 style="margin:0; color:#2E7D32;"><i class="fas fa-terminal"></i> Live Sync Logs <span class="badge-live">LIVE</span></h2>
            <small style="color:#666;">Memantau migrasi data MySQL ke PostgreSQL secara real-time.</small>
        </div>
        <div style="display:flex; gap:10px;">
            <a href="index.php" class="btn btn-green"><i class="fas fa-arrow-left"></i> Dashboard</a>
            <form method="POST" onsubmit="return confirm('Hapus semua catatan log?')">
                <button name="clear_log" class="btn btn-red"><i class="fas fa-trash-alt"></i> Clear Logs</button>
            </form>
        </div>
    </div>

    <div id="logViewer" class="log-container">
        <div style="text-align:center; margin-top:100px;"><i class="fas fa-circle-notch fa-spin fa-2x"></i><br>Menghubungkan ke log...</div>
    </div>
</div>

<script>
    /**
     * Fungsi Ali untuk mengambil data log terbaru tanpa reload halaman
     */
    function updateLogs() {
        fetch('view_logs.php?fetch_raw=1')
            .then(response => response.text())
            .then(data => {
                const viewer = document.getElementById('logViewer');
                viewer.innerHTML = data;
            })
            .catch(err => console.error('Gagal memuat log:', err));
    }

    // Jalankan pertama kali saat halaman dibuka
    updateLogs();

    // Set interval untuk refresh setiap 1000ms (1 detik)
    setInterval(updateLogs, 1000);
</script>

</body>
</html>