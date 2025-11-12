import 'dart:async';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:permission_handler/permission_handler.dart';
import '../models/geotag.dart';
import '../database/database_helper.dart';
import '../services/location_service.dart';
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
  final TextEditingController _itemTypeController = TextEditingController();

  String _condition = 'Baik';
  String? _photoPath;
  bool _isCapturingLocation = false;
  double _progress = 0.0;
  String _accuracyText = 'Akurasi: -- m';
  String _currentLocationText = 'Lokasi Terkini: --';
  Position? _averagedPosition;
  String _locationName = 'Koordinat: --';

  Timer? _timer;
  Timer? _continuousLocationTimer;
  int _remainingSeconds = 20;

  final List<String> _conditions = ['Baik', 'Cukup', 'Buruk', 'Rusak'];

  @override
  void dispose() {
    _timer?.cancel();
    _continuousLocationTimer?.cancel();
    _detailsController.dispose();
    _itemTypeController.dispose();
    super.dispose();
  }

  Future<void> _requestPermissions() async {
    await Permission.location.request();
    await Permission.camera.request();
    await Permission.storage.request();
  }

  Future<String> _getDeviceId() async {
    DeviceInfoPlugin deviceInfo = DeviceInfoPlugin();
    AndroidDeviceInfo androidInfo = await deviceInfo.androidInfo;
    return androidInfo.id;
  }

  Future<void> _startLocationCapture() async {
    await _requestPermissions();

    setState(() {
      _isCapturingLocation = true;
      _progress = 0.0;
      _remainingSeconds = 30; // Max time to prevent infinite loop
      _accuracyText = 'Akurasi: -- m';
      _currentLocationText = 'Lokasi Terkini: --';
    });

    // Start continuous location tracking for UI updates
    _startContinuousLocationTracking();

    // Initialize list to collect positions for averaging
    List<Position> capturedPositions = [];

    _timer = Timer.periodic(const Duration(seconds: 1), (timer) async {
      try {
        Position position = await LocationService.getCurrentPosition();
        capturedPositions.add(position);

        // Check if accuracy is good enough (between 1m and 5m)
        if (position.accuracy >= 1.0 && position.accuracy <= 5.0) {
          timer.cancel();
          await _finishLocationCapture(capturedPositions);
          return;
        }
      } catch (e) {
        // Handle error if needed
      }

      setState(() {
        _remainingSeconds--;
        _progress = (_remainingSeconds > 0) ? (30 - _remainingSeconds) / 30.0 : 1.0;
      });

      // Stop after max time if accuracy not reached
      if (_remainingSeconds <= 0) {
        timer.cancel();
        await _finishLocationCapture(capturedPositions);
      }
    });
  }

  void _startContinuousLocationTracking() {
    _continuousLocationTimer = Timer.periodic(const Duration(seconds: 1), (timer) async {
      if (!_isCapturingLocation) {
        timer.cancel();
        return;
      }

      try {
        Position currentPos = await LocationService.getCurrentPosition();
        if (mounted) {
          setState(() {
            _accuracyText = 'Akurasi: ${currentPos.accuracy.toStringAsFixed(1)} m';
            _currentLocationText = 'Lokasi Terkini: ${currentPos.latitude.toStringAsFixed(6)}, ${currentPos.longitude.toStringAsFixed(6)}';
          });
        }
      } catch (e) {
        if (mounted) {
          setState(() {
            _accuracyText = 'Akurasi: Error';
            _currentLocationText = 'Lokasi Terkini: Error';
          });
        }
      }
    });
  }

  Future<void> _finishLocationCapture(List<Position> capturedPositions) async {
    try {
      // Calculate average position from captured positions
      Position averagedPosition = LocationService.calculateAveragePosition(capturedPositions);

      // Apply Kalman filter to the averaged position
      Position filteredPosition = LocationService.applyKalmanFilter(averagedPosition);

      String locationName = await LocationService.getLocationName(
        filteredPosition.latitude,
        filteredPosition.longitude,
      );

      setState(() {
        _averagedPosition = filteredPosition;
        _locationName = 'Koordinat: $locationName';
        _isCapturingLocation = false;
        _progress = 1.0; // Ensure progress is complete
      });

      // Stop continuous tracking
      _continuousLocationTimer?.cancel();
    } catch (e) {
      setState(() {
        _isCapturingLocation = false;
        _progress = 0.0; // Reset progress on error
      });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error menangkap lokasi: $e')),
      );
    }
  }

  Future<void> _takePhoto() async {
    String? photoPath = await ImageService.pickImage();
    if (photoPath != null) {
      setState(() {
        _photoPath = photoPath;
      });
    }
  }

  Future<void> _saveGeotag() async {
    if (!_formKey.currentState!.validate() || _averagedPosition == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Harap lengkapi semua kolom dan tangkap lokasi')),
      );
      return;
    }

    String deviceId = await _getDeviceId();

    Geotag geotag = Geotag(
      projectId: widget.projectId,
      latitude: _averagedPosition!.latitude,
      longitude: _averagedPosition!.longitude,
      locationName: _locationName.replaceFirst('Koordinat: ', ''),
      timestamp: DateTime.now().toIso8601String(),
      itemType: _itemTypeController.text,
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
      Navigator.of(context).pop(true); // Return true to indicate data was saved
    }
  }

  void _resetForm() {
    setState(() {
      _averagedPosition = null;
      _photoPath = null;
      _locationName = 'Koordinat: --';
      _accuracyText = 'Akurasi: -- m';
      _currentLocationText = 'Lokasi Terkini: --';
      _progress = 0.0;
    });
    _detailsController.clear();
    _itemTypeController.clear();
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
                      const Text('Penangkapan Lokasi', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                      const SizedBox(height: 10),
                      LinearProgressIndicator(value: _progress),
                      const SizedBox(height: 10),
                      Text('Waktu tersisa: $_remainingSeconds detik'),
                      Text(_accuracyText),
                      Text(_currentLocationText),
                      Text(_locationName),
                      const SizedBox(height: 10),
                      ElevatedButton(
                        onPressed: _isCapturingLocation ? null : _startLocationCapture,
                        child: Text(_isCapturingLocation ? 'Menangkap...' : 'Mulai Penangkapan Lokasi (20d)'),
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

                      // Item Type Text Field (changed from dropdown)
                      TextFormField(
                        controller: _itemTypeController,
                        decoration: const InputDecoration(labelText: 'Nama Pohon'),
                        validator: (value) => value!.isEmpty ? 'Harap masukkan nama pohon' : null,
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
