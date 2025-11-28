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
import '../services/metadata_service.dart'; // Import Service Metadata
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

  // --- DATA DINAMIS (Tidak lagi hardcoded) ---
  List<String> _locations = [];
  List<String> _treeTypes = [];
  bool _isLoadingMeta = true;

  String? _selectedLocation;
  String? _itemType;
  // -------------------------------------------

  String _condition = 'Baik';
  final List<String> _conditions = ['Hidup', 'Merana', 'Mati'];

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
  
  final BackgroundLocationService _bgLocationService = BackgroundLocationService();

  @override
  void initState() {
    super.initState();
    _loadMetadata(); // Load data saat screen dibuka
    _startUIUpdates();
  }

  // FUNGSI LOAD DATA DARI MEMORI LOKAL
  Future<void> _loadMetadata() async {
    var trees = await MetadataService.getTreeTypes();
    var locs = await MetadataService.getLocations();

    if (mounted) {
      setState(() {
        _treeTypes = trees;
        _locations = locs;

        // Set default value (item pertama)
        if (_treeTypes.isNotEmpty) _itemType = _treeTypes.first;
        if (_locations.isNotEmpty) _selectedLocation = _locations.first;
        
        _isLoadingMeta = false;
      });
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _uiUpdateTimer?.cancel();
    _detailsController.dispose();
    super.dispose();
  }

  void _startUIUpdates() {
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

          if (position.accuracy < 5.0) {
            timer.cancel();
            await _finishCapture(samples, sampleCount);
            return;
          }

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
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error: $e')));
    }
  }

  Future<void> _finishCapture(List<Position> samples, int sampleCount) async {
    if (samples.isEmpty) {
      setState(() => _isCapturingLocation = false);
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Gagal mendapatkan lokasi')));
      return;
    }

    List<Position> filtered = _removeOutliers(samples);
    Position averaged = _calculateWeightedAverage(filtered);

    setState(() {
      _averagedPosition = averaged;
      _isCapturingLocation = false;
      _progress = 1.0;
      _locationNameStatus = 'Akurasi Final: ${averaged.accuracy.toStringAsFixed(1)} m';
    });

    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('Lokasi ditangkap! Akurasi: ${averaged.accuracy.toStringAsFixed(1)}m (dari $sampleCount sampel)'),
        backgroundColor: Colors.green,
        duration: const Duration(seconds: 3),
      ));
    }
  }

  List<Position> _removeOutliers(List<Position> positions) {
    if (positions.length < 4) return positions;

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
      if (distances[i] <= threshold) filtered.add(positions[i]);
    }

    return filtered.isEmpty ? positions : filtered;
  }

  double _calculateMedian(List<double> values) {
    if (values.isEmpty) return 0;
    int middle = values.length ~/ 2;
    return (values.length % 2 == 0) ? (values[middle - 1] + values[middle]) / 2 : values[middle];
  }

  Position _calculateWeightedAverage(List<Position> positions) {
    if (positions.isEmpty) throw Exception('No positions to average');
    if (positions.length == 1) return positions.first;

    double totalWeight = 0, weightedLat = 0, weightedLng = 0, totalAccuracy = 0;

    for (var pos in positions) {
      double weight = 1.0 / (pos.accuracy * pos.accuracy);
      totalWeight += weight;
      weightedLat += pos.latitude * weight;
      weightedLng += pos.longitude * weight;
      totalAccuracy += pos.accuracy;
    }

    double bestAccuracy = positions.map((p) => p.accuracy).reduce((a, b) => a < b ? a : b);
    double avgAccuracy = totalAccuracy / positions.length;
    double finalAccuracy = (bestAccuracy * 0.7 + avgAccuracy * 0.3);

    return Position(
      latitude: weightedLat / totalWeight,
      longitude: weightedLng / totalWeight,
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

  String _buildGeotagWatermarkInfo() {
    final DateTime now = DateTime.now();
    final String formattedDate = '${now.day}/${now.month}/${now.year} ${now.hour}:${now.minute.toString().padLeft(2, '0')}:${now.second.toString().padLeft(2, '0')}';

    return 'Lokasi: ${_selectedLocation ?? "Unknown"}\n'
        'Koordinat: ${_averagedPosition!.latitude.toStringAsFixed(6)}, ${_averagedPosition!.longitude.toStringAsFixed(6)}\n'
        'Akurasi: ${_averagedPosition!.accuracy.toStringAsFixed(1)} m\n'
        'Waktu: $formattedDate\n'
        'Tipe Item: ${_itemType ?? "Unknown"}\n'
        'Kondisi: $_condition\n'
        'Detail: ${_detailsController.text}';
  }

  String _buildPhotoFileName() {
    final DateTime now = DateTime.now();
    final String formattedDateTime = '${now.year}${now.month.toString().padLeft(2, '0')}${now.day.toString().padLeft(2, '0')}_'
        '${now.hour.toString().padLeft(2, '0')}${now.minute.toString().padLeft(2, '0')}${now.second.toString().padLeft(2, '0')}';

    final String safeItemType = (_itemType ?? "Item").replaceAll(RegExp(r'[^a-zA-Z0-9_]'), '_');
    final String safeCondition = _condition.replaceAll(RegExp(r'[^a-zA-Z0-9_]'), '_');

    return '${safeItemType}_${safeCondition}_$formattedDateTime';
  }

  Future<void> _takePhoto() async {
    if (_averagedPosition == null) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Harap tangkap lokasi terlebih dahulu')));
      return;
    }

    String? photoPath = await ImageService.pickImage(
        geotagInfo: _buildGeotagWatermarkInfo(),
        customFileName: _buildPhotoFileName(),
        tempPath: 'unused_path');

    if (photoPath != null) {
      setState(() => _photoPath = photoPath);
    }
  }

  Future<void> _saveGeotag() async {
    if (!_formKey.currentState!.validate() || _averagedPosition == null) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Harap lengkapi semua kolom dan tangkap lokasi')));
      return;
    }

    if (_averagedPosition!.accuracy < 1.0 || _averagedPosition!.accuracy > 5.0) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Akurasi lokasi tidak memenuhi syarat (harus 1-5 meter)')));
      return;
    }

    String deviceId = await _getDeviceId();

    Geotag geotag = Geotag(
      projectId: widget.projectId,
      latitude: _averagedPosition!.latitude,
      longitude: _averagedPosition!.longitude,
      locationName: _selectedLocation!,
      timestamp: DateTime.now().toIso8601String(),
      itemType: _itemType!,
      condition: _condition,
      details: _detailsController.text,
      photoPath: _photoPath ?? '',
      deviceId: deviceId,
    );

    DatabaseHelper dbHelper = DatabaseHelper();
    await dbHelper.insertGeotag(geotag);

    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Geotag berhasil disimpan')));

    if (mounted) Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    // Tampilkan Loading jika data metadata belum siap
    if (_isLoadingMeta) {
      return Scaffold(
        appBar: AppBar(title: const Text('Pengumpulan Data Lapangan')),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

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
                                Icon(Icons.circle, size: 8, color: _bgLocationService.isTracking ? Colors.green : Colors.grey),
                                const SizedBox(width: 4),
                                Text(_bgLocationService.isTracking ? 'Live' : 'Off', style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: _bgLocationService.isTracking ? Colors.green : Colors.grey)),
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
                        decoration: BoxDecoration(color: Colors.blue.shade50, borderRadius: BorderRadius.circular(8)),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Row(children: [Icon(Icons.info_outline, size: 16, color: Colors.blue), SizedBox(width: 4), Text('Status Real-time', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12))]),
                            const SizedBox(height: 8),
                            Text(_accuracyText, style: const TextStyle(fontSize: 12)),
                            Text(_currentLocationText, style: const TextStyle(fontSize: 12)),
                            Text(_samplesInfo, style: const TextStyle(fontSize: 12)),
                            if (_averagedPosition != null) Text(_locationNameStatus, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.green)),
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
                          style: ElevatedButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 12), backgroundColor: Colors.blue, foregroundColor: Colors.white),
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

                      // Lokasi Dropdown (Dynamic)
                      DropdownButtonFormField<String>(
                        value: _selectedLocation,
                        decoration: const InputDecoration(labelText: 'Nama Lokasi'),
                        items: _locations.map((loc) => DropdownMenuItem(value: loc, child: Text(loc))).toList(),
                        onChanged: (value) => setState(() => _selectedLocation = value!),
                        validator: (value) => value == null ? 'Harap pilih lokasi' : null,
                      ),

                      const SizedBox(height: 10),

                      // Jenis Pohon Dropdown (Dynamic)
                      DropdownButtonFormField<String>(
                        value: _itemType,
                        decoration: const InputDecoration(labelText: 'Jenis Pohon'),
                        isExpanded: true, // Agar teks panjang tidak overflow
                        items: _treeTypes.map((type) => DropdownMenuItem(value: type, child: Text(type, overflow: TextOverflow.ellipsis))).toList(),
                        onChanged: (value) => setState(() => _itemType = value!),
                        validator: (value) => value == null ? 'Harap pilih jenis pohon' : null,
                      ),

                      const SizedBox(height: 10),

                      // Condition Dropdown
                      DropdownButtonFormField<String>(
                        value: _condition,
                        decoration: const InputDecoration(labelText: 'Kondisi'),
                        items: _conditions.map((condition) => DropdownMenuItem(value: condition, child: Text(condition))).toList(),
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
                  style: ElevatedButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 16)),
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