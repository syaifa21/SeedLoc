import 'package:flutter/material.dart';
import '../services/sync_service.dart';
import '../services/export_service.dart';
import '../database/database_helper.dart';
import '../models/project.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  bool _isSyncing = false;
  bool _isExporting = false;
  bool _isCheckingConnection = false;
  String _syncStatus = '';
  String _exportStatus = '';
  String _connectionStatus = 'Belum diperiksa';
  Map<String, int> _syncStats = {'total': 0, 'synced': 0, 'unsynced': 0};

  @override
  void initState() {
    super.initState();
    _loadSyncStats();
    _checkApiConnection();
  }

  Future<void> _loadSyncStats() async {
    try {
      SyncService syncService = SyncService();
      Map<String, int> stats = await syncService.getSyncStats();
      setState(() {
        _syncStats = stats;
      });
    } catch (e) {
      print('Error loading sync stats: $e');
    }
  }

  Future<void> _checkApiConnection() async {
    setState(() {
      _isCheckingConnection = true;
      _connectionStatus = 'Memeriksa koneksi...';
    });

    try {
      SyncService syncService = SyncService();
      bool isConnected = await syncService.checkConnection();
      setState(() {
        _connectionStatus = isConnected 
            ? '✓ Terhubung ke API' 
            : '✗ Tidak dapat terhubung ke API';
      });
    } catch (e) {
      setState(() {
        _connectionStatus = '✗ Error: ${e.toString()}';
      });
    } finally {
      setState(() {
        _isCheckingConnection = false;
      });
    }
  }

  Future<void> _syncData() async {
    setState(() {
      _isSyncing = true;
      _syncStatus = 'Menyinkronkan...';
    });

    try {
      SyncService syncService = SyncService();
      
      // First check connection
      bool isConnected = await syncService.checkConnection();
      if (!isConnected) {
        setState(() {
          _syncStatus = '✗ Tidak dapat terhubung ke API. Periksa koneksi internet Anda.';
        });
        return;
      }

      // Sync geotags
      bool success = await syncService.syncGeotags();

      // Reload stats
      await _loadSyncStats();

      setState(() {
        _syncStatus = success 
            ? '✓ Sinkronisasi berhasil!' 
            : '⚠ Sinkronisasi selesai dengan beberapa error';
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(success ? 'Data berhasil disinkronkan!' : 'Sinkronisasi selesai dengan error'),
            backgroundColor: success ? Colors.green : Colors.orange,
          ),
        );
      }
    } catch (e) {
      setState(() {
        _syncStatus = '✗ Error sinkronisasi: $e';
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Error: $e'),
            backgroundColor: Colors.red,
          ),
        );
      }
    } finally {
      setState(() {
        _isSyncing = false;
      });
    }
  }

  Future<void> _exportData() async {
    setState(() {
      _isExporting = true;
      _exportStatus = 'Mengekspor...';
    });

    try {
      DatabaseHelper dbHelper = DatabaseHelper();
      List<Project> projects = await dbHelper.getProjects();

      if (projects.isEmpty) {
        setState(() {
          _exportStatus = 'Tidak ada proyek untuk diekspor';
        });
        return;
      }

      // For simplicity, export the first project. In a real app, you'd let the user choose.
      String exportPath = await ExportService.exportGeotagsToCsv(projects.first.projectId);

      setState(() {
        _exportStatus = 'Ekspor selesai: $exportPath';
      });
    } catch (e) {
      setState(() {
        _exportStatus = 'Error ekspor: $e';
      });
    } finally {
      setState(() {
        _isExporting = false;
      });
    }
  }

  Future<void> _stopProject() async {
    showDialog(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          title: const Text('Stop Project'),
          content: const Text('Apakah Anda yakin ingin menghentikan project ini? Semua data akan dihapus dan Anda akan kembali ke halaman pembuatan project baru.'),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Batal'),
            ),
            TextButton(
              onPressed: () async {
                Navigator.of(context).pop();
                await _confirmStopProject();
              },
              style: TextButton.styleFrom(foregroundColor: Colors.red),
              child: const Text('Stop Project'),
            ),
          ],
        );
      },
    );
  }

  Future<void> _confirmStopProject() async {
    try {
      DatabaseHelper dbHelper = DatabaseHelper();
      await dbHelper.clearAllData();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Project dihentikan. Membuat project baru...')),
      );
      // Force restart the app to refresh state
      Navigator.of(context).pushNamedAndRemoveUntil('/home', (Route<dynamic> route) => false);
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error menghentikan project: $e')),
      );
    }
  }

  Widget _buildStatCard(String label, int value, Color color) {
    return Column(
      children: [
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: color.withOpacity(0.1),
            borderRadius: BorderRadius.circular(8),
          ),
          child: Text(
            value.toString(),
            style: TextStyle(
              fontSize: 24,
              fontWeight: FontWeight.bold,
              color: color,
            ),
          ),
        ),
        const SizedBox(height: 8),
        Text(
          label,
          style: const TextStyle(
            fontSize: 12,
            color: Colors.grey,
          ),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Pengaturan & Info'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16.0),
        children: [
          // API Connection Status
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text('Status Koneksi API', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                      IconButton(
                        icon: const Icon(Icons.refresh),
                        onPressed: _isCheckingConnection ? null : _checkApiConnection,
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Text(
                    _connectionStatus,
                    style: TextStyle(
                      color: _connectionStatus.contains('✓') ? Colors.green : Colors.red,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  const SizedBox(height: 5),
                  const Text('API: https://seedloc.my.id/api', style: TextStyle(fontSize: 12, color: Colors.grey)),
                ],
              ),
            ),
          ),

          const SizedBox(height: 20),

          // Sync Statistics
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Statistik Sinkronisasi', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 15),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceAround,
                    children: [
                      _buildStatCard('Total', _syncStats['total']!, Colors.blue),
                      _buildStatCard('Tersinkron', _syncStats['synced']!, Colors.green),
                      _buildStatCard('Belum', _syncStats['unsynced']!, Colors.orange),
                    ],
                  ),
                ],
              ),
            ),
          ),

          const SizedBox(height: 20),

          // Sync Section
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Sinkronisasi Data', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 10),
                  Text(
                    'Sinkronkan ${_syncStats['unsynced']} geotag yang belum tersinkronkan ke server',
                    style: const TextStyle(color: Colors.grey),
                  ),
                  const SizedBox(height: 15),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton.icon(
                      onPressed: (_isSyncing || _syncStats['unsynced']! == 0) ? null : _syncData,
                      icon: _isSyncing 
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                            )
                          : const Icon(Icons.cloud_upload),
                      label: Text(_isSyncing ? 'Menyinkronkan...' : 'Sinkronkan Data'),
                      style: ElevatedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        backgroundColor: Colors.blue,
                        foregroundColor: Colors.white,
                      ),
                    ),
                  ),
                  if (_syncStatus.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 10),
                      child: Text(
                        _syncStatus,
                        style: TextStyle(
                          color: _syncStatus.contains('✓') ? Colors.green : 
                                 _syncStatus.contains('⚠') ? Colors.orange : Colors.red,
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),

          const SizedBox(height: 20),

          // Export Section
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Ekspor Data', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 10),
                  const Text('Ekspor geotag ke CSV dengan foto'),
                  const SizedBox(height: 10),
                  ElevatedButton(
                    onPressed: _isExporting ? null : _exportData,
                    child: Text(_isExporting ? 'Mengekspor...' : 'Ekspor Data'),
                  ),
                  if (_exportStatus.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 10),
                      child: Text(_exportStatus),
                    ),
                ],
              ),
            ),
          ),

          const SizedBox(height: 20),

          // Stop Project Section
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Manajemen Project',
                    style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 8),
                  const Text(
                    'Hentikan project saat ini dan buat project baru',
                    style: TextStyle(color: Colors.grey),
                  ),
                  const SizedBox(height: 16),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton.icon(
                      onPressed: _stopProject,
                      icon: const Icon(Icons.stop),
                      label: const Text('Stop Project'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: Colors.red,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 12),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),

          const SizedBox(height: 20),

          // App Info Section
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Informasi Aplikasi', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 10),
                  const Text('SeedLoc v1.0.0'),
                  const Text('Aplikasi Pengumpulan Data Lapangan Geotagging'),
                  const SizedBox(height: 10),
                  const Text('Mode: Offline (Database lokal SQLite)'),
                  const Text('Sinkronisasi: Siap untuk backend'),
                  const SizedBox(height: 10),
                  const Text('Pengembang: Nama Anda'),
                  const Text('Kontak: email.anda@contoh.com'),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
