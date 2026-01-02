<?php
// target_config.php

// 1. Cari & Load file metadata.php
// Coba cari di folder yang sama atau folder api/
$meta_file = __DIR__ . '/metadata.php';
if (!file_exists($meta_file)) {
    $meta_file = __DIR__ . '/api/metadata.php';
}

$metadata = file_exists($meta_file) ? require $meta_file : [];

// Ambil daftar dari metadata
$locations = $metadata['locations'] ?? [];
$treeTypes = $metadata['treeTypes'] ?? [];

// 2. Generate Struktur Target secara Otomatis
$final_config = [];

foreach ($locations as $loc) {
    foreach ($treeTypes as $tree) {
        // Set Default Target = 0 (Atau ganti angka ini sesuai kebutuhan)
        // Format: $final_config['Nama Lokasi']['Nama Pohon'] = Jumlah Target;
        $final_config[$loc][$tree] = 0; 
    }
}

// 3. (Opsional) Override Manual jika ada target spesifik
// Contoh: $final_config['Cisantana']['Jati'] = 1000;

return $final_config;
?>