import 'dart:async';
import 'dart:math';
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
      _accuracyText = 'Akurasi: -- m';
      _currentLocationText = 'Lokasi Terkini: --';
    });

    // Start continuous location tracking for UI updates
    _startContinuousLocationTracking();

    // Initialize list to collect positions for averaging
    List<Position> capturedPositions = [];
    bool hasShownAccuracyWarning = false;

    _timer = Timer.periodic(const Duration(seconds: 1), (timer) async {
      try {
        Position position = await LocationService.getCurrentPosition();
        capturedPositions.add(position);

        // Show notification if accuracy is above 5 meters
        if (position.accuracy > 5.0 && !hasShownAccuracyWarning) {
          if (mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(
                content: Text('Akurasi GPS di atas 5 meter. Tunggu hingga akurasi membaik...'),
                duration: Duration(seconds: 3),
              ),
            );
          }
          hasShownAccuracyWarning = true;
        }

        // Check if accuracy is good enough (between 1m and 5m)
        if (position.accuracy >= 1.0 && position.accuracy <= 5.0) {
          timer.cancel();
          await _finishLocationCapture(capturedPositions);
          return;
        }
      } catch (e) {
        // Handle error if needed
      }

      // Update progress to show waiting animation
      setState(() {
        _progress = (_progress + 0.1) % 1.0; // Continuous progress animation
      });
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
      // Step 1: Remove outliers using IQR method
      List<Position> filteredPositions = _removeOutliers(capturedPositions);
      
      // Step 2: Calculate weighted average (better accuracy = higher weight)
      Position weightedPosition = _calculateWeightedAverage(filteredPositions);

      // Step 3: Apply Kalman filter for final smoothing
      Position finalPosition = LocationService.applyKalmanFilter(weightedPosition);

      String locationName = await LocationService.getLocationName(
        finalPosition.latitude,
        finalPosition.longitude,
      );

      setState(() {
        _averagedPosition = finalPosition;
        _locationName = 'Koordinat: $locationName';
        _isCapturingLocation = false;
        _progress = 1.0;
      });

      // Stop continuous tracking
      _continuousLocationTimer?.cancel();
      
      // Show success message with accuracy info
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              'Lokasi berhasil ditangkap! Akurasi: ${finalPosition.accuracy.toStringAsFixed(1)}m '
              '(dari ${capturedPositions.length} sampel, ${filteredPositions.length} digunakan)'
            ),
            backgroundColor: Colors.green,
          ),
        );
      }
    } catch (e) {
      setState(() {
        _isCapturingLocation = false;
        _progress = 0.0;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error menangkap lokasi: $e')),
      );
    }
  }

  // Remove outliers using Interquartile Range (IQR) method
  List<Position> _removeOutliers(List<Position> positions) {
    if (positions.length < 4) {
      return positions; // Not enough data for outlier detection
    }

    // Calculate median position
    List<double> latitudes = positions.map((p) => p.latitude).toList()..sort();
    List<double> longitudes = positions.map((p) => p.longitude).toList()..sort();

    double medianLat = _calculateMedian(latitudes);
    double medianLng = _calculateMedian(longitudes);

    // Calculate distances from median
    List<double> distances = positions.map((p) {
      double latDiff = p.latitude - medianLat;
      double lngDiff = p.longitude - medianLng;
      return sqrt(latDiff * latDiff + lngDiff * lngDiff);
    }).toList();

    // Calculate IQR
    List<double> sortedDistances = List.from(distances)..sort();
    double q1 = _calculateMedian(sortedDistances.sublist(0, sortedDistances.length ~/ 2));
    double q3 = _calculateMedian(sortedDistances.sublist(sortedDistances.length ~/ 2));
    double iqr = q3 - q1;
    double threshold = q3 + 1.5 * iqr;

    // Filter outliers
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

    // Calculate weights (inverse of accuracy squared)
    for (var pos in positions) {
      // Weight = 1 / (accuracy^2)
      // Better accuracy (smaller value) = higher weight
      double weight = 1.0 / (pos.accuracy * pos.accuracy);
      totalWeight += weight;
      weightedLat += pos.latitude * weight;
      weightedLng += pos.longitude * weight;
      totalAccuracy += pos.accuracy;
    }

    // Calculate weighted average
    double avgLat = weightedLat / totalWeight;
    double avgLng = weightedLng / totalWeight;
    
    // Calculate improved accuracy estimate
    // Use the best accuracy from filtered positions
    double bestAccuracy = positions.map((p) => p.accuracy).reduce((a, b) => a < b ? a : b);
    double avgAccuracy = totalAccuracy / positions.length;
    
    // Final accuracy is weighted between best and average (70% best, 30% average)
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
                      Text(_isCapturingLocation ? 'Menunggu akurasi GPS di bawah 5 meter...' : 'Siap untuk menangkap lokasi'),
                      Text(_accuracyText),
                      Text(_currentLocationText),
                      Text(_locationName),
                      const SizedBox(height: 10),
                      ElevatedButton(
                        onPressed: _isCapturingLocation ? null : _startLocationCapture,
                        child: Text(_isCapturingLocation ? 'Menangkap...' : 'Mulai Penangkapan Lokasi'),
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
