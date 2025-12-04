import 'dart:async';
import 'dart:math';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:device_info_plus/device_info_plus.dart';
import '../models/geotag.dart';
import '../database/database_helper.dart';
import '../services/location_service.dart';
import '../services/image_service.dart';
import '../services/metadata_service.dart';

class FieldDataScreen extends StatefulWidget {
  final int projectId;
  const FieldDataScreen({super.key, required this.projectId});

  @override
  State<FieldDataScreen> createState() => _FieldDataScreenState();
}

class _FieldDataScreenState extends State<FieldDataScreen> {
  final _formKey = GlobalKey<FormState>();
  final TextEditingController _detailsController = TextEditingController();

  // --- DATA INPUT FORM (Fitur Penting: Metadata Dinamis) ---
  List<String> _locations = [];
  List<String> _treeTypes = [];
  bool _isLoadingMeta = true;
  String? _selectedLocation;
  String? _itemType;
  String _condition = 'Baik';
  final List<String> _conditions = ['Hidup', 'Merana', 'Mati'];
  String? _photoPath;

  // --- LOGIC GPS SMART FILTERING (Fitur Baru: Akurasi Tinggi) ---
  StreamSubscription<Position>? _positionStream;
  final List<Position> _positionBuffer = []; // Buffer untuk menampung data GPS
  Position? _finalPosition; // Hasil perhitungan terbaik
  
  // Status UI GPS
  String _gpsStatusText = "Menunggu Sinyal...";
  Color _gpsColor = Colors.orange;
  double _currentRawAccuracy = 0; // Akurasi mentah dari satelit
  bool _isLocked = false; // Fitur kunci lokasi agar tidak berubah saat foto

  @override
  void initState() {
    super.initState();
    _loadMetadata(); // Muat data dropdown
    _startSmartTracking(); // Mulai tracking GPS otomatis
  }

  @override
  void dispose() {
    _positionStream?.cancel(); // Matikan GPS saat keluar menu ini hemat baterai
    _detailsController.dispose();
    super.dispose();
  }

  // --- 1. LOAD DATA DARI SERVER/LOKAL ---
  Future<void> _loadMetadata() async {
    try {
      var trees = await MetadataService.getTreeTypes();
      var locs = await MetadataService.getLocations();
      
      if (mounted) {
        setState(() {
          _treeTypes = trees;
          _locations = locs;
          // Auto-select item pertama jika ada
          if (_treeTypes.isNotEmpty) _itemType = _treeTypes.first;
          if (_locations.isNotEmpty) _selectedLocation = _locations.first;
          _isLoadingMeta = false;
        });
      }
    } catch (e) {
      print("Error loading metadata: $e");
      if (mounted) setState(() => _isLoadingMeta = false);
    }
  }

  // --- 2. LOGIC GPS PINTAR (SMART BUFFERING) ---
  void _startSmartTracking() async {
    // Cek Izin Lokasi
    LocationPermission permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
         setState(() {
           _gpsStatusText = "Izin Ditolak";
           _gpsColor = Colors.red;
         });
         return;
      }
    }

    // Mulai Stream (Menggunakan stream High Accuracy dari LocationService)
    _positionStream = LocationService.getHighAccuracyStream().listen((Position newPos) {
      if (!mounted || _isLocked) return; // Jangan update jika sudah dikunci user

      setState(() {
        _currentRawAccuracy = newPos.accuracy;
      });

      // LOGIKA RESET OTOMATIS:
      // Jika user berjalan lebih dari 10 meter dari titik rata-rata sebelumnya, 
      // kita anggap user pindah ke pohon baru -> Reset Buffer.
      if (_finalPosition != null) {
        double dist = LocationService.calculateDistance(
          newPos.latitude, newPos.longitude, 
          _finalPosition!.latitude, _finalPosition!.longitude
        );
        if (dist > 10.0) {
          _positionBuffer.clear(); 
          // _finalPosition = null; // Opsional: null-kan atau biarkan update perlahan
        }
      }

      // LOGIKA FILTER SAMPAH:
      // Buang data jika akurasi > 30m (kecuali belum punya data sama sekali)
      if (newPos.accuracy < 30.0 || _positionBuffer.isEmpty) {
        _positionBuffer.add(newPos);
        
        // Hanya simpan 10 data terbaik terakhir (Sliding Window)
        if (_positionBuffer.length > 10) {
          _positionBuffer.removeAt(0);
        }
        
        // HITUNG RATA-RATA TERBOBOT (Weighted Average)
        _finalPosition = _calculateWeightedAverage(_positionBuffer);
        
        // Update Tampilan Status
        _updateGpsStatus(_finalPosition!);
      }
    }, onError: (e) {
      setState(() {
        _gpsStatusText = "GPS Error";
        _gpsColor = Colors.red;
      });
    });
  }

  // ALGORITMA: Semakin kecil nilai akurasi (misal 3m), semakin besar bobotnya.
  // Data 3m jauh lebih dipercaya daripada data 15m.
  Position _calculateWeightedAverage(List<Position> positions) {
    if (positions.isEmpty) return positions.first;

    double sumLat = 0, sumLng = 0, sumWeight = 0;
    double bestAcc = 999.0;

    for (var p in positions) {
      if (p.accuracy < bestAcc) bestAcc = p.accuracy;

      // Rumus Bobot: 1 / kuadrat akurasi
      double weight = 1 / (p.accuracy * p.accuracy);
      
      sumLat += p.latitude * weight;
      sumLng += p.longitude * weight;
      sumWeight += weight;
    }

    return Position(
      latitude: sumLat / sumWeight,
      longitude: sumLng / sumWeight,
      accuracy: bestAcc, // Kita pakai akurasi terbaik yang pernah didapat sebagai patokan
      timestamp: DateTime.now(),
      altitude: positions.last.altitude,
      heading: positions.last.heading,
      speed: positions.last.speed,
      speedAccuracy: 0, 
      altitudeAccuracy: 0, 
      headingAccuracy: 0
    );
  }

  void _updateGpsStatus(Position pos) {
    setState(() {
      if (pos.accuracy <= 5.0) {
        _gpsStatusText = "SANGAT BAGUS (${pos.accuracy.toStringAsFixed(1)}m)";
        _gpsColor = Colors.green.shade700;
      } else if (pos.accuracy <= 10.0) {
        _gpsStatusText = "BAGUS (${pos.accuracy.toStringAsFixed(1)}m)";
        _gpsColor = Colors.green;
      } else if (pos.accuracy <= 15.0) {
        _gpsStatusText = "CUKUP (${pos.accuracy.toStringAsFixed(1)}m)";
        _gpsColor = Colors.orange;
      } else {
        _gpsStatusText = "LEMAH (${pos.accuracy.toStringAsFixed(1)}m)";
        _gpsColor = Colors.red;
      }
    });
  }

  // --- 3. FITUR KUNCI LOKASI ---
  void _toggleLock() {
    if (_finalPosition == null) return;
    setState(() {
      _isLocked = !_isLocked;
      if (!_isLocked) {
        // Jika buka kunci, reset buffer agar ambil data fresh untuk pohon berikutnya
        _positionBuffer.clear();
        _finalPosition = null;
        _gpsStatusText = "Mencari ulang...";
        _photoPath = null; // Reset foto juga jika lokasi direset
      }
    });
  }

  // --- 4. AMBIL FOTO (Fitur Penting: Watermark) ---
  Future<void> _takePhoto() async {
    // Validasi: Lokasi harus ada
    if (_finalPosition == null) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Tunggu sinyal GPS...')));
      return;
    }

    // Kunci lokasi otomatis saat mau foto agar data konsisten
    if (!_isLocked) setState(() => _isLocked = true);

    // Siapkan Info Watermark
    final DateTime now = DateTime.now();
    String info = 'Lokasi: ${_selectedLocation ?? "-"}\n'
        'Lat: ${_finalPosition!.latitude.toStringAsFixed(6)}\n'
        'Lng: ${_finalPosition!.longitude.toStringAsFixed(6)}\n'
        'Akurasi: ${_finalPosition!.accuracy.toStringAsFixed(1)}m\n'
        'Waktu: ${now.day}/${now.month}/${now.year} ${now.hour}:${now.minute}\n'
        'Item: ${_itemType ?? "-"}\n'
        'Kondisi: $_condition';

    String fileName = 'IMG_${now.millisecondsSinceEpoch}';

    // Panggil Service Foto
    String? path = await ImageService.pickImage(
        geotagInfo: info,
        customFileName: fileName,
        tempPath: 'unused' // Parameter pelengkap
    );

    if (path != null) {
      setState(() => _photoPath = path);
    } else {
      // Jika batal foto, buka kunci lagi (opsional)
      // setState(() => _isLocked = false); 
    }
  }

  // --- 5. SIMPAN DATA (Fitur Penting: Device ID & DB Save) ---
  Future<void> _saveData() async {
    if (!_formKey.currentState!.validate()) return;
    
    if (_finalPosition == null) {
       ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Lokasi belum terkunci!')));
       return;
    }
    
    // Warning jika akurasi masih di atas 15m
    if (_finalPosition!.accuracy > 15.0) {
      bool confirm = await showDialog(
        context: context, 
        builder: (c) => AlertDialog(
          title: const Text("Akurasi Rendah"),
          content: Text("Akurasi saat ini ${_finalPosition!.accuracy.toStringAsFixed(1)}m. Tetap simpan?"),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: const Text("Batal")),
            TextButton(onPressed: () => Navigator.pop(c, true), child: const Text("Ya, Simpan")),
          ],
        )
      ) ?? false;
      if (!confirm) return;
    }

    // Ambil Device ID (Penting untuk tracking user)
    String deviceId = "Unknown";
    try {
      AndroidDeviceInfo androidInfo = await DeviceInfoPlugin().androidInfo;
      deviceId = androidInfo.id;
    } catch (e) {
      print("Error getting device ID: $e");
    }

    // Buat Objek Geotag
    Geotag newGeotag = Geotag(
      projectId: widget.projectId,
      latitude: _finalPosition!.latitude,
      longitude: _finalPosition!.longitude,
      locationName: _selectedLocation!,
      timestamp: DateTime.now().toIso8601String(),
      itemType: _itemType!,
      condition: _condition,
      details: _detailsController.text,
      photoPath: _photoPath ?? "",
      deviceId: deviceId,
    );

    // Simpan ke SQLite
    await DatabaseHelper().insertGeotag(newGeotag);

    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Data Berhasil Disimpan!'), 
        backgroundColor: Colors.green
      ));
      
      // RESET FORM UNTUK INPUT BERIKUTNYA
      _detailsController.clear();
      setState(() { 
        _photoPath = null; 
        _isLocked = false; // Buka kunci GPS
        _positionBuffer.clear(); // Reset buffer GPS
        _gpsStatusText = "Mencari titik baru...";
        // _selectedLocation & _itemType JANGAN direset agar mempercepat input berulang
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoadingMeta) return const Scaffold(body: Center(child: CircularProgressIndicator()));

    return Scaffold(
      appBar: AppBar(title: const Text('Input Data Lapangan')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // --- PANEL GPS (Baru & Lebih Informatif) ---
              Card(
                color: _gpsColor.withOpacity(0.1),
                elevation: 0,
                shape: RoundedRectangleBorder(
                  side: BorderSide(color: _gpsColor, width: 2),
                  borderRadius: BorderRadius.circular(12)
                ),
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Row(
                    children: [
                      Icon(Icons.satellite_alt, color: _gpsColor, size: 36),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(_gpsStatusText, style: TextStyle(fontWeight: FontWeight.bold, color: _gpsColor, fontSize: 16)),
                            if (_finalPosition != null)
                              Text("Lat: ${_finalPosition!.latitude.toStringAsFixed(6)}\nLng: ${_finalPosition!.longitude.toStringAsFixed(6)}", style: const TextStyle(fontSize: 12)),
                            Text("Sinyal Mentah: ${_currentRawAccuracy.toStringAsFixed(1)}m", style: const TextStyle(fontSize: 10, color: Colors.grey)),
                          ],
                        ),
                      ),
                      // Tombol Kunci Manual
                      IconButton(
                        onPressed: _toggleLock,
                        icon: Icon(_isLocked ? Icons.lock : Icons.lock_open, color: _isLocked ? Colors.blue : Colors.grey, size: 30),
                        tooltip: _isLocked ? "Buka Kunci (Cari Ulang)" : "Kunci Posisi Ini",
                      )
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),

              // --- FORM INPUT ---
              DropdownButtonFormField<String>(
                value: _selectedLocation,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Lokasi / Blok', border: OutlineInputBorder()),
                items: _locations.map((e) => DropdownMenuItem(value: e, child: Text(e))).toList(),
                onChanged: (v) => setState(() => _selectedLocation = v),
                validator: (v) => v == null ? 'Wajib diisi' : null,
              ),
              const SizedBox(height: 12),
              
              DropdownButtonFormField<String>(
                value: _itemType,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Jenis Pohon', border: OutlineInputBorder()),
                items: _treeTypes.map((e) => DropdownMenuItem(value: e, child: Text(e, overflow: TextOverflow.ellipsis))).toList(),
                onChanged: (v) => setState(() => _itemType = v),
                validator: (v) => v == null ? 'Wajib diisi' : null,
              ),
              const SizedBox(height: 12),

              Row(
                children: [
                  Expanded(
                    child: DropdownButtonFormField<String>(
                      value: _condition,
                      decoration: const InputDecoration(labelText: 'Kondisi', border: OutlineInputBorder()),
                      items: _conditions.map((e) => DropdownMenuItem(value: e, child: Text(e))).toList(),
                      onChanged: (v) => setState(() { if (v != null) _condition = v; }),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: TextFormField(
                      controller: _detailsController,
                      decoration: const InputDecoration(labelText: 'Detail/Ket', border: OutlineInputBorder()),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),

              // --- TOMBOL AKSI ---
              Row(
                children: [
                  Expanded(
                    child: ElevatedButton.icon(
                      // Hanya bisa foto jika sudah dapat koordinat
                      onPressed: _finalPosition == null ? null : _takePhoto,
                      icon: const Icon(Icons.camera_alt),
                      label: Text(_photoPath == null ? 'Ambil Foto' : 'Foto Ulang'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: _photoPath == null ? Colors.blue : Colors.orange,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 16),
                      ),
                    ),
                  ),
                ],
              ),
              
              if (_photoPath != null)
                Padding(
                  padding: const EdgeInsets.only(top: 8, bottom: 8),
                  child: Text("✅ Foto tersimpan: ...${_photoPath!.split('/').last}", 
                    style: const TextStyle(color: Colors.green, fontWeight: FontWeight.bold), textAlign: TextAlign.center),
                ),

              const SizedBox(height: 16),

              ElevatedButton.icon(
                // Tombol simpan hanya aktif jika lokasi & foto (opsional) ada
                onPressed: (_finalPosition != null) ? _saveData : null,
                icon: const Icon(Icons.save),
                label: const Text('SIMPAN DATA'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.green.shade700,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  textStyle: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}