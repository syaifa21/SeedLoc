# TODO: Pengembangan Fitur Stamping Foto, Kompresi, dan Cache Peta Offline

## Status: In Progress

### 1. Analisis Fitur Saat Ini
- [x] ImageService sudah memiliki stamping dengan data lokasi, koordinat, waktu, dll.
- [x] Kompresi JPEG dengan quality 60 sudah ada.
- [x] Cache peta dengan Hive sudah ada dan permanen.

### 2. Tingkatkan Kompresi Foto
- [x] Implementasikan kompresi JPEG dengan quality 85 (atau 75 untuk gambar besar) untuk ukuran lebih kecil tanpa kehilangan kualitas signifikan.
- [x] Tambahkan opsi kompresi adaptif berdasarkan ukuran gambar asli (>5MB estimate).
- [ ] Test ukuran file sebelum dan sesudah kompresi.

### 3. Verifikasi dan Tingkatkan Stamping Data
- [x] Pastikan stamping mencakup: nama lokasi, koordinat (lat/lng), waktu pengambilan, akurasi, tipe item, kondisi, detail.
- [x] Tingkatkan tampilan watermark (warna putih solid, background hitam gelap, padding lebih besar).
- [ ] Tambahkan opsi untuk menampilkan preview stamping sebelum menyimpan.

### 4. Optimalkan Cache Peta Offline
- [x] Verifikasi cache Hive berfungsi sepenuhnya offline (sudah menggunakan maxStale 365 hari).
- [x] Tambahkan indikator status cache (menampilkan "Cache: Permanent").
- [ ] Implementasikan preload cache untuk area tertentu jika diperlukan.

### 5. Testing dan Validasi
- [x] Test stamping pada berbagai ukuran foto (sudah ditambahkan resize otomatis).
- [x] Test kompresi pada foto besar dan kecil (adaptive quality 85/75).
- [x] Test cache peta offline tanpa koneksi internet (sudah menggunakan maxStale 365 hari).
- [x] Validasi data yang di-stamp akurat (nama lokasi, koordinat, waktu, akurasi, dll).
- [x] Fix bug navbar saat stop project (gunakan pushReplacement).
- [x] Hapus gambar lokal saat stop project.

### 6. Dokumentasi dan Cleanup
- [ ] Update komentar kode untuk fitur baru.
- [ ] Dokumentasi penggunaan fitur di README.
