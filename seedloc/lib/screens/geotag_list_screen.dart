import 'package:flutter/material.dart';
import 'dart:async';
import 'package:geolocator/geolocator.dart';
import '../models/geotag.dart';
import '../database/database_helper.dart';
import 'field_data_screen.dart';
import 'geotag_detail_screen.dart';
import '../services/location_service.dart';
import '../models/project.dart'; // <-- IMPORT BARU

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
  Project? _activeProject; // <-- STATE BARU UNTUK MENYIMPAN PROJECT AKTIF

  @override
  void initState() {
    super.initState();
    // Memastikan project dimuat sebelum memuat geotags
    _loadActiveProject().then((_) { 
      _loadGeotags();
    });
    _startLocationTracking();
  }
  
  // METHOD BARU: Load Active Project ID
  Future<void> _loadActiveProject() async {
    DatabaseHelper dbHelper = DatabaseHelper();
    List<Project> projects = await dbHelper.getProjects();
    if (mounted) {
      setState(() {
        // Asumsi proyek pertama adalah proyek aktif yang sedang dikerjakan
        _activeProject = projects.isNotEmpty ? projects.first : null;
      });
    }
  }

  @override
  void dispose() {
    LocationService.stopContinuousTracking();
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
            _currentLocationText = 'Error';
          });
        }
      }
    });
  }

  Future<void> _loadGeotags() async {
    setState(() {
      _isLoading = true;
    });

    try {
      DatabaseHelper dbHelper = DatabaseHelper();
      
      if (_activeProject != null) {
        // MENGAMBIL HANYA GEOTAG DARI PROYEK YANG AKTIF
        _geotags = await dbHelper.getGeotagsByProject(_activeProject!.projectId);
      } else {
        // Fallback: Jika tidak ada proyek aktif, tampilkan pesan kosong
        _geotags = []; 
      }
      
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
    // ... (Logika delete tetap sama)
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
    // ... (Logika dialog tetap sama)
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
    String projectTitle = _activeProject != null 
        ? 'Data Proyek ID: ${_activeProject!.projectId}' 
        : (_isLoading ? 'Memuat Data...' : 'Tidak Ada Proyek Aktif');

    return Scaffold(
      appBar: AppBar(
        title: Text(projectTitle), // Judul menggunakan ID Proyek aktif
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
              ? Center(
                  child: Text(
                    _activeProject == null 
                        ? 'Tidak ada proyek aktif. Silakan mulai proyek baru dari layar Home.'
                        : 'Belum ada data geotag untuk Project ID ${_activeProject!.projectId}\nTekan tombol + untuk menambah data',
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 16, color: Colors.grey),
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
                          backgroundColor: geotag.isSynced ? Colors.green : Colors.orange,
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
          // CEK KRITIS: Pastikan Project ID sudah dimuat
          if (_activeProject == null) {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('Tidak ada Proyek aktif. Gagal menambah data.')),
              );
              // Coba muat ulang proyek aktif
              await _loadActiveProject();
              return;
          }
          
          final result = await Navigator.push(
            context,
            MaterialPageRoute(
              // FIX KRITIS: Menggunakan Project ID dari state _activeProject
              builder: (_) => FieldDataScreen(projectId: _activeProject!.projectId),
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