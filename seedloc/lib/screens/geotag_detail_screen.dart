import 'package:flutter/material.dart';
import 'dart:io';
import 'dart:async';
import 'package:geolocator/geolocator.dart';
import '../models/geotag.dart';
import '../services/location_service.dart';

class GeotagDetailScreen extends StatefulWidget {
  final Geotag geotag;

  const GeotagDetailScreen({super.key, required this.geotag});

  @override
  State<GeotagDetailScreen> createState() => _GeotagDetailScreenState();
}

class _GeotagDetailScreenState extends State<GeotagDetailScreen> {
  Position? _currentPosition;
  String _currentAccuracy = '--';
  String _currentLocationText = '--';
  Timer? _locationTimer;

  @override
  void initState() {
    super.initState();
    _startLocationTracking();
  }

  @override
  void dispose() {
    _locationTimer?.cancel();
    super.dispose();
  }

  void _startLocationTracking() {
    _locationTimer = Timer.periodic(const Duration(seconds: 2), (timer) async {
      try {
        Position position = await LocationService.getCurrentPosition();
        if (mounted) {
          setState(() {
            _currentPosition = position;
            _currentAccuracy = '${position.accuracy.toStringAsFixed(1)} m';
            _currentLocationText = '${position.latitude.toStringAsFixed(6)}, ${position.longitude.toStringAsFixed(6)}';
          });
        }
      } catch (e) {
        if (mounted) {
          setState(() {
            _currentAccuracy = 'Error';
            _currentLocationText = 'Error mendapatkan lokasi';
          });
        }
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Detail Data Geotag'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Header Card
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      widget.geotag.itemType.isNotEmpty ? widget.geotag.itemType : 'Tanpa Nama',
                      style: const TextStyle(
                        fontSize: 24,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                      decoration: BoxDecoration(
                        color: _getConditionColor(widget.geotag.condition).withOpacity(0.1),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: _getConditionColor(widget.geotag.condition)),
                      ),
                      child: Text(
                        'Kondisi: ${widget.geotag.condition}',
                        style: TextStyle(
                          color: _getConditionColor(widget.geotag.condition),
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 16),

            // Real-time Location Information
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Lokasi Real-time',
                      style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: 12),
                    _buildInfoRow('Lokasi Saat Ini', _currentLocationText),
                    _buildInfoRow('Akurasi Saat Ini', _currentAccuracy),
                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.all(8),
                      decoration: BoxDecoration(
                        color: Colors.blue.shade50,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: const Text(
                        'Lokasi diperbarui setiap 2 detik',
                        style: TextStyle(
                          fontSize: 12,
                          color: Colors.blue,
                          fontStyle: FontStyle.italic,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 16),

            // Saved Location Information
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Lokasi Tersimpan',
                      style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: 12),
                    _buildInfoRow('Koordinat', '${widget.geotag.latitude.toStringAsFixed(6)}, ${widget.geotag.longitude.toStringAsFixed(6)}'),
                    _buildInfoRow('Lokasi', widget.geotag.locationName),
                    _buildInfoRow('Device ID', widget.geotag.deviceId),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 16),

            // Timestamp Information
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Informasi Waktu',
                      style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: 12),
                    _buildInfoRow('Tanggal & Waktu', _formatDateTime(widget.geotag.timestamp)),
                    _buildInfoRow('Timestamp', widget.geotag.timestamp),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 16),

            // Details
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Detail Data',
                      style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: 12),
                    _buildInfoRow('Detail', widget.geotag.details),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 16),

            // Photo Section
            if (widget.geotag.photoPath.isNotEmpty)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Foto',
                        style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      const SizedBox(height: 12),
                      Container(
                        width: double.infinity,
                        height: 200,
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(8),
                          image: DecorationImage(
                            image: FileImage(File(widget.geotag.photoPath)),
                            fit: BoxFit.cover,
                          ),
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'Path: ${widget.geotag.photoPath.split('/').last}',
                        style: const TextStyle(
                          fontSize: 12,
                          color: Colors.grey,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildInfoRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8.0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(
              '$label:',
              style: const TextStyle(
                fontWeight: FontWeight.w500,
                color: Colors.grey,
              ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(fontSize: 16),
            ),
          ),
        ],
      ),
    );
  }

  Color _getConditionColor(String condition) {
    switch (condition.toLowerCase()) {
      case 'baik':
        return Colors.green;
      case 'cukup':
        return Colors.orange;
      case 'buruk':
        return Colors.red;
      case 'rusak':
        return Colors.red;
      default:
        return Colors.grey;
    }
  }

  String _formatDateTime(String timestamp) {
    try {
      DateTime dateTime = DateTime.parse(timestamp).toLocal();
      return '${dateTime.day}/${dateTime.month}/${dateTime.year} ${dateTime.hour}:${dateTime.minute.toString().padLeft(2, '0')}:${dateTime.second.toString().padLeft(2, '0')}';
    } catch (e) {
      return timestamp;
    }
  }
}
