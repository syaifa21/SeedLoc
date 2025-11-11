import 'package:flutter/material.dart';
import 'dart:async';
import 'package:geolocator/geolocator.dart';
import '../models/geotag.dart';
import '../database/database_helper.dart';
import 'field_data_screen.dart';
import 'geotag_detail_screen.dart';
import '../services/location_service.dart';

class GeotagListScreen extends StatefulWidget {
  const GeotagListScreen({super.key});

  @override
  State<GeotagListScreen> createState() => _GeotagListScreenState();
}

class _GeotagListScreenState extends State<GeotagListScreen> {
  List<Geotag> _geotags = [];
  bool _isLoading = true;
  Position? _currentPosition;
  String _currentAccuracy = '--';
  String _currentLocationText = '--';
  Timer? _locationTimer;

  @override
  void initState() {
    super.initState();
    _loadGeotags();
    _startLocationTracking();
  }

  @override
  void dispose() {
    LocationService.stopContinuousTracking();
    super.dispose();
  }

  void _startLocationTracking() {
    LocationService.startContinuousTracking((Position position) {
      if (mounted) {
        setState(() {
          _currentPosition = position;
          _currentAccuracy = '${position.accuracy.toStringAsFixed(1)} m';
          _currentLocationText = '${position.latitude.toStringAsFixed(6)}, ${position.longitude.toStringAsFixed(6)}';
        });
      }
    });
  }

  Future<void> _loadGeotags() async {
    setState(() {
      _isLoading = true;
    });

    try {
      DatabaseHelper dbHelper = DatabaseHelper();
      _geotags = await dbHelper.getGeotags();
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error memuat data: $e')),
      );
    } finally {
      setState(() {
        _isLoading = false;
      });
    }
  }

  Future<void> _deleteGeotag(int id) async {
    try {
      DatabaseHelper dbHelper = DatabaseHelper();
      await dbHelper.deleteGeotag(id);
      await _loadGeotags(); // Reload the list
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Data berhasil dihapus')),
      );
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error menghapus data: $e')),
      );
    }
  }

  void _showDeleteDialog(int id) {
    showDialog(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          title: const Text('Konfirmasi Hapus'),
          content: const Text('Apakah Anda yakin ingin menghapus data ini?'),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Batal'),
            ),
            TextButton(
              onPressed: () {
                Navigator.of(context).pop();
                _deleteGeotag(id);
              },
              style: TextButton.styleFrom(foregroundColor: Colors.red),
              child: const Text('Hapus'),
            ),
          ],
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Daftar Data Geotag'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadGeotags,
            tooltip: 'Refresh',
          ),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(60),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            color: Colors.blue.shade50,
            child: Row(
              children: [
                const Icon(Icons.location_on, color: Colors.blue, size: 20),
                const SizedBox(width: 8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Lokasi Saat Ini: $_currentLocationText',
                        style: const TextStyle(fontSize: 12, color: Colors.blue),
                      ),
                      Text(
                        'Akurasi: $_currentAccuracy',
                        style: const TextStyle(fontSize: 10, color: Colors.blue),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : _geotags.isEmpty
              ? const Center(
                  child: Text(
                    'Belum ada data geotag\nTekan tombol + untuk menambah data',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 16, color: Colors.grey),
                  ),
                )
              : ListView.builder(
                  itemCount: _geotags.length,
                  itemBuilder: (context, index) {
                    final geotag = _geotags[index];
                    return Card(
                      margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                      child: ListTile(
                        leading: CircleAvatar(
                          backgroundColor: Colors.green,
                          child: Text('${index + 1}'),
                        ),
                        title: Text(
                          geotag.itemType.isNotEmpty ? geotag.itemType : 'Tanpa Nama',
                          style: const TextStyle(fontWeight: FontWeight.bold),
                        ),
                        subtitle: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('Lokasi: ${geotag.locationName}'),
                            Text('Koordinat: ${geotag.latitude.toStringAsFixed(6)}, ${geotag.longitude.toStringAsFixed(6)}'),
                            Text('Kondisi: ${geotag.condition}'),
                            Text('Waktu: ${DateTime.parse(geotag.timestamp).toLocal().toString().split('.')[0]}'),
                            if (geotag.photoPath.isNotEmpty)
                              const Text('📷 Foto tersedia'),
                          ],
                        ),
                        trailing: PopupMenuButton<String>(
                          onSelected: (value) {
                            if (value == 'delete') {
                              _showDeleteDialog(geotag.id!);
                            }
                          },
                          itemBuilder: (BuildContext context) => [
                            const PopupMenuItem<String>(
                              value: 'delete',
                              child: Row(
                                children: [
                                  Icon(Icons.delete, color: Colors.red),
                                  SizedBox(width: 8),
                                  Text('Hapus'),
                                ],
                              ),
                            ),
                          ],
                        ),
                        isThreeLine: true,
                        onTap: () {
                          Navigator.push(
                            context,
                            MaterialPageRoute(
                              builder: (_) => GeotagDetailScreen(geotag: geotag),
                            ),
                          );
                        },
                      ),
                    );
                  },
                ),
      floatingActionButton: FloatingActionButton(
        onPressed: () async {
          final result = await Navigator.push(
            context,
            MaterialPageRoute(
              builder: (_) => const FieldDataScreen(projectId: 2222),
            ),
          );
          // Reload data when returning from add screen if data was saved
          if (result == true) {
            _loadGeotags();
          }
        },
        tooltip: 'Tambah Data Baru',
        child: const Icon(Icons.add),
      ),
    );
  }
}
