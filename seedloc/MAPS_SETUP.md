# Setup Peta OpenStreetMap untuk SeedLoc

## 🎉 100% GRATIS & OFFLINE CAPABLE!

Aplikasi SeedLoc menggunakan **OpenStreetMap (OSM)** yang:
- ✅ **Sepenuhnya GRATIS** - Tidak perlu API Key
- ✅ **Tidak ada biaya** - Tidak ada quota atau billing
- ✅ **Offline Ready** - Tiles otomatis di-cache
- ✅ **Open Source** - Data peta dari komunitas global

## Fitur Peta

### 1. **Tampilan Lokasi Real-time**
- Marker biru menunjukkan posisi Anda saat ini
- Circle biru menunjukkan radius akurasi GPS
- Info card menampilkan koordinat dan akurasi

### 2. **Auto-Update Lokasi**
- Lokasi diperbarui otomatis setiap 5 detik
- Indikator "Live" menunjukkan status real-time
- Tombol refresh manual jika diperlukan

### 3. **Kontrol Peta**
- Zoom in/out dengan pinch gesture
- Pan/drag untuk menjelajah peta
- Tombol "Pusatkan ke Lokasi Saya" untuk kembali ke posisi Anda

### 4. **Offline Capability**
- Tiles peta otomatis di-cache saat online
- Saat offline, tiles yang sudah di-cache tetap muncul
- Indikator "Offline Ready" di pojok kanan atas

## Cara Menggunakan Fitur Offline

### Persiapan (Saat Online):
1. Buka aplikasi dan masuk ke tab **"Peta"**
2. Tunggu peta selesai loading
3. **Zoom in dan zoom out** di area yang akan Anda gunakan
4. **Pan/geser** peta ke berbagai arah untuk memuat tiles
5. Semakin banyak area yang Anda lihat, semakin banyak yang ter-cache

### Penggunaan Offline:
1. Matikan koneksi internet
2. Buka tab "Peta"
3. Area yang sudah pernah dilihat akan tetap muncul
4. Lokasi GPS tetap berfungsi tanpa internet

## Tips & Trik

### Maksimalkan Cache Offline:
```
1. Saat masih online, buka area yang akan digunakan
2. Zoom dari level 10 (overview) hingga level 18 (detail)
3. Geser peta ke semua arah di area tersebut
4. Tiles akan tersimpan otomatis di device
```

### Menghemat Data:
- Cache tiles hanya saat WiFi tersedia
- Hindari zoom terlalu sering saat menggunakan data seluler
- Tiles yang sudah di-cache tidak perlu download ulang

### Membersihkan Cache:
- Cache tersimpan di app data
- Hapus cache: Settings → Apps → SeedLoc → Clear Cache
- Atau uninstall dan install ulang aplikasi

## Perbandingan dengan Google Maps

| Fitur | OpenStreetMap | Google Maps |
|-------|---------------|-------------|
| **Biaya** | 100% Gratis | Gratis dengan quota |
| **API Key** | Tidak perlu | Perlu setup |
| **Offline** | Auto-cache | Perlu download manual |
| **Quota** | Unlimited | 28,000/bulan |
| **Billing** | Tidak ada | Perlu kartu kredit |
| **Setup** | Plug & play | Kompleks |

## Sumber Tiles Alternatif

Jika ingin menggunakan tile server lain, edit file `lib/screens/map_screen.dart`:

### OpenTopoMap (Peta Topografi):
```dart
urlTemplate: 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
```

### Humanitarian OSM (Fokus kemanusiaan):
```dart
urlTemplate: 'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
```

### CartoDB (Minimalis):
```dart
urlTemplate: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
```

## Troubleshooting

### Peta tidak muncul (layar putih)
**Solusi:**
1. Pastikan ada koneksi internet (untuk pertama kali)
2. Tunggu beberapa detik untuk loading tiles
3. Coba zoom in/out atau pan peta
4. Restart aplikasi

### Lokasi tidak akurat
**Solusi:**
1. Pastikan GPS aktif di device
2. Gunakan di area terbuka (bukan dalam ruangan)
3. Tunggu beberapa detik untuk GPS lock
4. Lihat circle akurasi - semakin kecil semakin akurat

### Tiles tidak ter-cache
**Solusi:**
1. Pastikan ada ruang penyimpanan cukup
2. Buka area yang ingin di-cache saat online
3. Zoom dan pan untuk memuat tiles
4. Cache otomatis tersimpan

### Peta lambat loading
**Solusi:**
1. Gunakan koneksi internet yang stabil
2. Tiles yang sudah di-cache akan loading instant
3. Hindari zoom terlalu cepat
4. Tunggu tiles selesai loading sebelum pan

## Permissions yang Diperlukan

Aplikasi memerlukan permissions berikut:
- ✅ **Location** - Untuk mendapatkan posisi GPS
- ✅ **Internet** - Untuk download tiles peta (opsional saat offline)
- ✅ **Storage** - Untuk menyimpan cache tiles

## Privasi & Data

- **OpenStreetMap** tidak melacak lokasi Anda
- Tiles di-download dari server OSM publik
- Cache tersimpan lokal di device
- Tidak ada data lokasi yang dikirim ke server
- 100% privacy-friendly

## Kontribusi ke OpenStreetMap

Jika Anda ingin berkontribusi ke peta:
1. Kunjungi [openstreetmap.org](https://www.openstreetmap.org)
2. Buat akun gratis
3. Edit peta di area Anda
4. Tambahkan jalan, bangunan, POI, dll
5. Kontribusi Anda akan muncul di peta global!

## Lisensi

- **OpenStreetMap Data**: © OpenStreetMap contributors
- **Lisensi**: Open Database License (ODbL)
- **Tiles**: © OpenStreetMap contributors
- **flutter_map**: BSD-3-Clause License

## Support & Bantuan

Jika ada pertanyaan atau masalah:
- Dokumentasi flutter_map: [pub.dev/packages/flutter_map](https://pub.dev/packages/flutter_map)
- OpenStreetMap Wiki: [wiki.openstreetmap.org](https://wiki.openstreetmap.org)
- Hubungi developer aplikasi

---

**Catatan**: Tidak perlu setup tambahan! Peta langsung bisa digunakan setelah install aplikasi. 🎉
