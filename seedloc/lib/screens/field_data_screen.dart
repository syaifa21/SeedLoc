import 'dart:async';
import 'dart:math';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:permission_handler/permission_handler.dart';
import '../models/geotag.dart';
import '../database/database_helper.dart';
import '../services/location_service.dart';
import '../services/background_location_service.dart';
import '../services/image_service.dart';
import 'package:device_info_plus/device_info_plus.dart';

class FieldDataScreen extends StatefulWidget {
  final int projectId;

  const FieldDataScreen({super.key, required this.projectId});

  @override
  State<FieldDataScreen> createState() => _FieldDataScreenState();
}

class _FieldDataScreenState extends State<FieldDataScreen> {
  final _formKey = GlobalKey<FormState>();
  final TextEditingController _detailsController = TextEditingController();
  // DIHAPUS: final TextEditingController _itemTypeController = TextEditingController(); 
  final TextEditingController _locationNameInputController = TextEditingController();

  // BARU: State dan List untuk Dropdown Jenis Pohon
  String _itemType = 'Saninten (Castanopsis argentea)'; 
  final List<String> _treeTypes = ['Saninten (Castanopsis argentea)', 'Puspa (Schima wallichii)', 'Manglid (Manglietia glauca )']; 

  String _condition = 'Baik';
  String? _photoPath;
  bool _isCapturingLocation = false;
  double _progress = 0.0;
  String _accuracyText = 'Akurasi: -- m';
  String _currentLocationText = 'Lokasi Terkini: --';
  Position? _averagedPosition;
  
  String _locationNameStatus = 'Akurasi Final: --'; 
  String _samplesInfo = 'Sampel: 0';

  Timer? _timer;
  Timer? _uiUpdateTimer;

  final List<String> _conditions = ['Baik', 'Cukup', 'Buruk', 'Rusak'];
  final BackgroundLocationService _bgLocationService = BackgroundLocationService();

  @override
  void initState() {
    super.initState();
    _startUIUpdates();
  }

  @override
  void dispose() {
    _timer?.cancel();
    _uiUpdateTimer?.cancel();
    _detailsController.dispose();
    // DIHAPUS: _itemTypeController.dispose(); 
    _locationNameInputController.dispose(); 
    super.dispose();
  }

  void _startUIUpdates() {
    // Update UI every second with background service data
    _uiUpdateTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) return;

      var stats = _bgLocationService.getStatistics();
      Position? currentPos = _bgLocationService.currentBestPosition;

      setState(() {
        if (currentPos != null) {
          _accuracyText = 'Akurasi: ${currentPos.accuracy.toStringAsFixed(1)} m';
          _currentLocationText = 'Lat: ${currentPos.latitude.toStringAsFixed(6)}, Lng: ${currentPos.longitude.toStringAsFixed(6)}';
          _samplesInfo = 'Sampel: ${stats['totalSamples']} | Terbaik: ${stats['bestAccuracy'].toStringAsFixed(1)}m';
        }
      });
    });
  }

  Future<void> _requestPermissions() async {
    await Permission.location.request();
    await Permission.camera.request();
  }

  Future<String> _getDeviceId() async {
    DeviceInfoPlugin deviceInfo = DeviceInfoPlugin();
    AndroidDeviceInfo androidInfo = await deviceInfo.androidInfo;
    return androidInfo.id;
  }

  Future<void> _captureLocation() async {
    await _requestPermissions();

    setState(() {
      _isCapturingLocation = true;
      _progress = 0.0;
      _samplesInfo = 'Sampel: 0';
      _locationNameStatus = 'Akurasi Final: --';
    });

    List<Position> samples = [];
    int maxSamples = 5;
    int sampleCount = 0;

    try {
      // Ambil sampel GPS setiap 1 detik
      _timer = Timer.periodic(const Duration(seconds: 1), (timer) async {
        try {
          Position position = await LocationService.getCurrentPosition();
          samples.add(position);
          sampleCount++;

          if (mounted) {
            setState(() {
              _progress = sampleCount / maxSamples;
              _samplesInfo = 'Sampel: $sampleCount/$maxSamples';
              _accuracyText = 'Akurasi: ${position.accuracy.toStringAsFixed(1)} m';
              _currentLocationText = 'Lat: ${position.latitude.toStringAsFixed(6)}, Lng: ${position.longitude.toStringAsFixed(6)}';
            });
          }

          // Jika dapat akurasi < 5m, langsung selesai!
          if (position.accuracy < 5.0) {
            timer.cancel();
            await _finishCapture(samples, sampleCount);
            return;
          }

          // Atau jika sudah 5 sampel, selesai
          if (sampleCount >= maxSamples) {
            timer.cancel();
            await _finishCapture(samples, sampleCount);
          }
        } catch (e) {
          print('Error getting sample: $e');
        }
      });
    } catch (e) {
      setState(() {
        _isCapturingLocation = false;
        _progress = 0.0;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error: $e')),
      );
    }
  }

  Future<void> _finishCapture(List<Position> samples, int sampleCount) async {
    if (samples.isEmpty) {
      setState(() {
        _isCapturingLocation = false;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Gagal mendapatkan lokasi')),
      );
      return;
    }

    // Remove outliers dan hitung weighted average
    List<Position> filtered = _removeOutliers(samples);
    Position averaged = _calculateWeightedAverage(filtered);

    setState(() {
      _averagedPosition = averaged;
      _isCapturingLocation = false;
      _progress = 1.0;
      // Update status dengan akurasi final yang didapat
      _locationNameStatus = 'Akurasi Final: ${averaged.accuracy.toStringAsFixed(1)} m'; 
    });

    // Show success message
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Lokasi ditangkap! Akurasi: ${averaged.accuracy.toStringAsFixed(1)}m (dari $sampleCount sampel)'
          ),
          backgroundColor: Colors.green,
          duration: const Duration(seconds: 3),
        ),
      );
    }
  }

  // Remove outliers using Interquartile Range (IQR) method
  List<Position> _removeOutliers(List<Position> positions) {
    if (positions.length < 4) {
      return positions; 
    }

    List<double> latitudes = positions.map((p) => p.latitude).toList()..sort();
    List<double> longitudes = positions.map((p) => p.longitude).toList()..sort();

    double medianLat = _calculateMedian(latitudes);
    double medianLng = _calculateMedian(longitudes);

    List<double> distances = positions.map((p) {
      double latDiff = p.latitude - medianLat;
      double lngDiff = p.longitude - medianLng;
      return sqrt(latDiff * latDiff + lngDiff * lngDiff);
    }).toList();

    List<double> sortedDistances = List.from(distances)..sort();
    double q1 = _calculateMedian(sortedDistances.sublist(0, sortedDistances.length ~/ 2));
    double q3 = _calculateMedian(sortedDistances.sublist(sortedDistances.length ~/ 2));
    double iqr = q3 - q1;
    double threshold = q3 + 1.5 * iqr;

    List<Position> filtered = [];
    for (int i = 0; i < positions.length; i++) {
      if (distances[i] <= threshold) {
        filtered.add(positions[i]);
      }
    }

    return filtered.isEmpty ? positions : filtered;
  }

  double _calculateMedian(List<double> values) {
    if (values.isEmpty) return 0;
    int middle = values.length ~/ 2;
    if (values.length % 2 == 0) {
      return (values[middle - 1] + values[middle]) / 2;
    } else {
      return values[middle];
    }
  }

  // Calculate weighted average based on accuracy (better accuracy = higher weight)
  Position _calculateWeightedAverage(List<Position> positions) {
    if (positions.isEmpty) {
      throw Exception('No positions to average');
    }

    if (positions.length == 1) {
      return positions.first;
    }

    double totalWeight = 0;
    double weightedLat = 0;
    double weightedLng = 0;
    double totalAccuracy = 0;

    for (var pos in positions) {
      double weight = 1.0 / (pos.accuracy * pos.accuracy);
      totalWeight += weight;
      weightedLat += pos.latitude * weight;
      weightedLng += pos.longitude * weight;
      totalAccuracy += pos.accuracy;
    }

    double avgLat = weightedLat / totalWeight;
    double avgLng = weightedLng / totalWeight;
    
    double bestAccuracy = positions.map((p) => p.accuracy).reduce((a, b) => a < b ? a : b);
    double avgAccuracy = totalAccuracy / positions.length;
    
    double finalAccuracy = (bestAccuracy * 0.7 + avgAccuracy * 0.3);

    return Position(
      latitude: avgLat,
      longitude: avgLng,
      timestamp: DateTime.now(),
      accuracy: finalAccuracy,
      altitude: positions.last.altitude,
      heading: positions.last.heading,
      speed: positions.last.speed,
      speedAccuracy: positions.last.speedAccuracy,
      altitudeAccuracy: positions.last.altitudeAccuracy,
      headingAccuracy: positions.last.headingAccuracy,
    );
  }

  // FUNGSI WATERMARK DENGAN INPUT LOKASI MANUAL
  String _buildGeotagWatermarkInfo() {
    final DateTime now = DateTime.now();
    final String formattedDate = '${now.day}/${now.month}/${now.year} ${now.hour}:${now.minute.toString().padLeft(2, '0')}:${now.second.toString().padLeft(2, '0')}';
    
    return 'Lokasi: ${_locationNameInputController.text}\n'
           'Koordinat: ${_averagedPosition!.latitude.toStringAsFixed(6)}, ${_averagedPosition!.longitude.toStringAsFixed(6)}\n'
           'Akurasi: ${_averagedPosition!.accuracy.toStringAsFixed(1)} m\n'
           'Waktu: $formattedDate\n'
           'Tipe Item: $_itemType\n' // MENGGUNAKAN STATE BARU
           'Kondisi: $_condition\n'
           'Detail: ${_detailsController.text}'; 
  }

  // FUNGSI BARU UNTUK NAMA FILE
  String _buildPhotoFileName() {
    final DateTime now = DateTime.now();
    // Format YYYYMMDD_HHMMSS
    final String formattedDateTime = '${now.year}${now.month.toString().padLeft(2, '0')}${now.day.toString().padLeft(2, '0')}_'
                                     '${now.hour.toString().padLeft(2, '0')}${now.minute.toString().padLeft(2, '0')}${now.second.toString().padLeft(2, '0')}';
    
    // Menghilangkan spasi dan karakter non-alphanumeric dari nama
    final String safeItemType = _itemType.replaceAll(RegExp(r'[^a-zA-Z0-9_]'), '_'); // MENGGUNAKAN STATE BARU
    final String safeCondition = _condition.replaceAll(RegExp(r'[^a-zA-Z0-9_]'), '_');
    
    // Format Final: NamaPohon_Kondisi_YYYYMMDD_HHMMSS
    return '${safeItemType}_${safeCondition}_$formattedDateTime'; 
  }

  Future<void> _takePhoto() async {
    // Pastikan lokasi sudah diambil sebelum mengambil foto
    if (_averagedPosition == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Harap tangkap lokasi terlebih dahulu')),
      );
      return;
    }
    
    // 1. Siapkan teks Watermark
    final String geotagInfo = _buildGeotagWatermarkInfo();
    // 2. Siapkan NAMA FILE BARU
    final String customFileName = _buildPhotoFileName();
    
    // 3. Ambil dan Stamp Foto
    String? photoPath = await ImageService.pickImage(
      geotagInfo: geotagInfo, 
      customFileName: customFileName, 
      tempPath: 'unused_path' 
    );
    
    if (photoPath != null) {
      setState(() {
        _photoPath = photoPath;
      });
    }
  }

  Future<void> _saveGeotag() async {
    // Tambahkan validasi untuk controller lokasi manual
    if (!_formKey.currentState!.validate() || _averagedPosition == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Harap lengkapi semua kolom dan tangkap lokasi')),
      );
      return;
    }

    // Check if accuracy is within required range (1-5 meters)
    if (_averagedPosition!.accuracy < 1.0 || _averagedPosition!.accuracy > 5.0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Akurasi lokasi tidak memenuhi syarat (harus 1-5 meter)')),
      );
      return;
    }

    String deviceId = await _getDeviceId();

    Geotag geotag = Geotag(
      projectId: widget.projectId,
      latitude: _averagedPosition!.latitude,
      longitude: _averagedPosition!.longitude,
      // MENGGUNAKAN INPUT MANUAL
      locationName: _locationNameInputController.text, 
      timestamp: DateTime.now().toIso8601String(),
      itemType: _itemType, // MENGGUNAKAN STATE BARU
      condition: _condition,
      details: _detailsController.text,
      photoPath: _photoPath ?? '', 
      deviceId: deviceId,
    );

    DatabaseHelper dbHelper = DatabaseHelper();
    await dbHelper.insertGeotag(geotag);

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Geotag berhasil disimpan')),
    );

    // Navigate back to GeotagListScreen and refresh data
    if (mounted) {
      Navigator.of(context).pop(true); 
    }
  }

  void _resetForm() {
    setState(() {
      _averagedPosition = null;
      _photoPath = null;
      _locationNameStatus = 'Akurasi Final: --'; // Reset status
      _accuracyText = 'Akurasi: -- m';
      _currentLocationText = 'Lokasi Terkini: --';
      _progress = 0.0;
      _itemType = 'Pohon 1'; // Reset item type
    });
    _detailsController.clear();
    _locationNameInputController.clear(); // NEW: Bersihkan input manual
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Pengumpulan Data Lapangan'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Location Capture Section
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.location_on, color: Colors.blue),
                          const SizedBox(width: 8),
                          const Text('Penangkapan Lokasi', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                          const Spacer(),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                            decoration: BoxDecoration(
                              color: _bgLocationService.isTracking ? Colors.green.shade100 : Colors.grey.shade200,
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: Row(
                              children: [
                                Icon(
                                  Icons.circle,
                                  size: 8,
                                  color: _bgLocationService.isTracking ? Colors.green : Colors.grey,
                                ),
                                const SizedBox(width: 4),
                                Text(
                                  _bgLocationService.isTracking ? 'Live' : 'Off',
                                  style: TextStyle(
                                    fontSize: 10,
                                    fontWeight: FontWeight.bold,
                                    color: _bgLocationService.isTracking ? Colors.green : Colors.grey,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 10),
                      if (_progress > 0) LinearProgressIndicator(value: _progress),
                      const SizedBox(height: 10),
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: Colors.blue.shade50,
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                const Icon(Icons.info_outline, size: 16, color: Colors.blue),
                                const SizedBox(width: 4),
                                const Text('Status Real-time', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                              ],
                            ),
                            const SizedBox(height: 8),
                            Text(_accuracyText, style: const TextStyle(fontSize: 12)),
                            Text(_currentLocationText, style: const TextStyle(fontSize: 12)),
                            Text(_samplesInfo, style: const TextStyle(fontSize: 12)),
                            // Menampilkan status akurasi final setelah penangkapan
                            if (_averagedPosition != null)
                              Text(_locationNameStatus, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.green)),
                          ],
                        ),
                      ),
                      const SizedBox(height: 10),
                      SizedBox(
                        width: double.infinity,
                        child: ElevatedButton.icon(
                          onPressed: _isCapturingLocation ? null : _captureLocation,
                          icon: Icon(_isCapturingLocation ? Icons.hourglass_empty : Icons.my_location),
                          label: Text(_isCapturingLocation ? 'Memproses...' : 'Gunakan Lokasi Terkini'),
                          style: ElevatedButton.styleFrom(
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            backgroundColor: Colors.blue,
                            foregroundColor: Colors.white,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),

              const SizedBox(height: 20),

              // Data Input Section
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    children: [
                      const Text('Input Data', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                      const SizedBox(height: 10),
                      
                      // NEW: Location Name Input Field
                      TextFormField(
                        controller: _locationNameInputController,
                        decoration: const InputDecoration(labelText: 'Nama Lokasi (Wajib Diisi)'),
                        validator: (value) =>
                            value!.isEmpty ? 'Harap masukkan nama lokasi' : null,
                      ),
                      const SizedBox(height: 10),

                      // NEW: Item Type Dropdown
                      DropdownButtonFormField<String>(
                        value: _itemType, 
                        decoration: const InputDecoration(labelText: 'Jenis Pohon'),
                        items: _treeTypes.map((treeType) {
                          return DropdownMenuItem(value: treeType, child: Text(treeType));
                        }).toList(),
                        onChanged: (value) => setState(() => _itemType = value!),
                        validator: (value) => value == null ? 'Harap pilih jenis pohon' : null,
                      ),
                      
                      const SizedBox(height: 10),

                      // Condition Dropdown
                      DropdownButtonFormField<String>(
                        value: _condition,
                        decoration: const InputDecoration(labelText: 'Kondisi'),
                        items: _conditions.map((condition) {
                          return DropdownMenuItem(value: condition, child: Text(condition));
                        }).toList(),
                        onChanged: (value) => setState(() => _condition = value!),
                        validator: (value) => value == null ? 'Harap pilih kondisi' : null,
                      ),

                      const SizedBox(height: 10),

                      // Details Text Field
                      TextFormField(
                        controller: _detailsController,
                        decoration: const InputDecoration(labelText: 'Detail'),
                        maxLines: 3,
                        validator: (value) => value!.isEmpty ? 'Harap masukkan detail' : null,
                      ),

                      const SizedBox(height: 10),

                      // Photo Button
                      ElevatedButton.icon(
                        onPressed: _takePhoto,
                        icon: const Icon(Icons.camera),
                        label: const Text('Ambil Foto'),
                      ),

                      if (_photoPath != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 10),
                          child: Text('Foto diambil: ${_photoPath!.split('/').last}'),
                        ),
                    ],
                  ),
                ),
              ),

              const SizedBox(height: 20),

              // Save Button
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: _saveGeotag,
                  style: ElevatedButton.styleFrom(
                    padding: const EdgeInsets.symmetric(vertical: 16),
                  ),
                  child: const Text('Simpan Geotag', style: TextStyle(fontSize: 18)),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}