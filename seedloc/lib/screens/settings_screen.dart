import 'package:flutter/material.dart';
import '../services/sync_service.dart';
import '../services/export_service.dart'; 
import '../database/database_helper.dart';
import '../models/project.dart'; // Digunakan untuk Project? _activeProject
import 'package:permission_handler/permission_handler.dart'; 

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  // NEW STATE VARIABLE
  Project? _activeProject; 

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
    _loadActiveProject(); // <-- NEW CALL
  }
  
  // NEW METHOD: Load Active Project
  Future<void> _loadActiveProject() async {
    DatabaseHelper dbHelper = DatabaseHelper();
    List<Project> projects = await dbHelper.getProjects();
    if (mounted) {
      setState(() {
        // Asumsi proyek pertama adalah proyek aktif
        _activeProject = projects.isNotEmpty ? projects.first : null;
      });
    }
  }

  // --- NEW WIDGETS ---
  // Widget untuk menampilkan informasi proyek
  Widget _buildProjectInfoCard() {
    if (_activeProject == null) {
      return Card(
        color: Colors.red.shade50,
        child: const Padding(
          padding: EdgeInsets.all(16.0),
          child: Text(
            'Tidak ada proyek aktif. Harap mulai proyek baru dari Home Screen.',
            style: TextStyle(color: Colors.red),
          ),
        ),
      );
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.folder_open, color: Colors.green),
                const SizedBox(width: 8),
                const Text('Proyek Aktif', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                const Spacer(),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: _activeProject!.status == 'Aktif' ? Colors.green.shade100 : Colors.blue.shade100,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    _activeProject!.status,
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.bold,
                      color: _activeProject!.status == 'Aktif' ? Colors.green.shade700 : Colors.blue.shade700,
                    ),
                  ),
                ),
              ],
            ),
            const Divider(height: 20),
            _buildInfoRow('Project ID', _activeProject!.projectId.toString()),
            _buildInfoRow('Kegiatan', _activeProject!.activityName),
            _buildInfoRow('Lokasi', _activeProject!.locationName),
            _buildInfoRow('Petugas', _activeProject!.officers.join(', ')),
          ],
        ),
      ),
    );
  }
  
  // Helper untuk baris informasi
  Widget _buildInfoRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 4.0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 100,
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
              style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
            ),
          ),
        ],
      ),
    );
  }
  // --- END NEW WIDGETS ---


  // --- EXISTING METHODS (Disederhanakan) ---

  Future<void> _loadSyncStats() async {
    try {
      SyncService syncService = SyncService();
      Map<String, int> stats = await syncService.getSyncStats();
      if (mounted) {
        setState(() {
          _syncStats = stats;
        });
      }
    } catch (e) {
      print('Error loading sync stats: $e');
    }
  }

  Future<void> _checkApiConnection() async {
    setState(() {
      _isCheckingConnection = true;
      _connectionStatus = 'Memeriksa koneksi...';
    });
    // ... (rest of _checkApiConnection logic)
    try {
      SyncService syncService = SyncService();
      bool isConnected = await syncService.checkConnection();
      if (mounted) {
        setState(() {
          _connectionStatus = isConnected 
              ? '✓ Terhubung ke API' 
              : '✗ Tidak dapat terhubung ke API';
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _connectionStatus = '✗ Error: ${e.toString()}';
        });
      }
    } finally {
      if (mounted) {
        setState(() {
          _isCheckingConnection = false;
        });
      }
    }
  }
  
  Future<void> _syncData() async {
     // ... (Sync logic remains the same)
     setState(() {
      _isSyncing = true;
      _syncStatus = 'Menyinkronkan...';
    });

    Map<String, dynamic> result; 

    try {
      SyncService syncService = SyncService();
      
      bool isConnected = await syncService.checkConnection();
      if (!isConnected) {
        if (mounted) {
          setState(() {
            _syncStatus = '✗ Tidak dapat terhubung ke API. Periksa koneksi internet Anda.';
          });
        }
        return;
      }

      result = await syncService.syncGeotags();

      await _loadSyncStats();

      if (mounted) {
        setState(() {
          _syncStatus = result['success'] 
              ? '✓ Sinkronisasi berhasil! (${result['synced']} data)' 
              : '⚠ ${result['message']} (Berhasil: ${result['synced']}, Gagal: ${result['failed']})';
        });
      }

      String? detailedError = result['detailedError'] as String?;
      if (!result['success'] && detailedError != null && mounted) {
        _showDetailedErrorDialog(detailedError);
      }

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(result['message']),
            backgroundColor: result['success'] ? Colors.green : Colors.orange,
            duration: const Duration(seconds: 4),
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _syncStatus = '✗ Error sinkronisasi: $e';
        });
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Error: $e'),
            backgroundColor: Colors.red,
          ),
        );
      }
    } finally {
      if (mounted) {
        setState(() {
          _isSyncing = false;
        });
      }
    }
  }


  void _showDetailedErrorDialog(String detailedError) {
    // ... (Dialog logic remains the same)
    showDialog(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          title: const Text('Detail Error Sinkronisasi'),
          content: SingleChildScrollView(
            child: ListBody(
              children: <Widget>[
                const Text(
                  'Terjadi kegagalan saat sinkronisasi data/foto. Ini adalah pesan detail dari server/aplikasi:',
                  style: TextStyle(fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 10),
                SizedBox(
                  width: double.maxFinite,
                  child: SelectableText( 
                    detailedError,
                    style: const TextStyle(fontFamily: 'monospace', color: Colors.red),
                  ),
                ),
                const SizedBox(height: 15),
                const Text(
                  'Tips: Jika Anda melihat "Raw Server Output", itu adalah pesan mentah dari server PHP Anda (cek izin folder uploads).',
                  style: TextStyle(fontSize: 12, fontStyle: FontStyle.italic),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Tutup'),
            ),
          ],
        );
      },
    );
  }

  Future<void> _exportData() async {
    // ... (Export logic remains the same)
    setState(() {
      _isExporting = true;
      _exportStatus = 'Mengekspor...';
    });

    try {
      PermissionStatus status = await Permission.storage.request();
      
      if (status.isDenied) {
        if (mounted) {
          setState(() {
            _exportStatus = '✗ Ekspor dibatalkan: Izin penyimpanan ditolak.';
          });
        }
        if (status.isPermanentlyDenied) {
          openAppSettings();
        }
        return;
      }

      DatabaseHelper dbHelper = DatabaseHelper();
      List<Project> projects = await dbHelper.getProjects();

      if (projects.isEmpty) {
        if (mounted) {
          setState(() {
            _exportStatus = 'Tidak ada proyek untuk diekspor';
          });
        }
        return;
      }

      String exportPath = await ExportService.exportGeotagsToCsv(projects.first.projectId);

      if (mounted) {
        setState(() {
          _exportStatus = 'Ekspor selesai: $exportPath';
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _exportStatus = 'Error ekspor: $e';
        });
      }
    } finally {
      if (mounted) {
        setState(() {
          _isExporting = false;
        });
      }
    }
  }

  Future<void> _stopProject() async {
    // ... (Stop Project logic remains the same)
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
    // ... (Confirm Stop Project logic remains the same)
    try {
      DatabaseHelper dbHelper = DatabaseHelper();
      await dbHelper.clearAllData();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Project dihentikan. Membuat project baru...')),
        );
        Navigator.of(context).pushNamedAndRemoveUntil('/home', (Route<dynamic> route) => false);
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error menghentikan project: $e')),
        );
      }
    }
  }


  Widget _buildStatCard(String label, int value, Color color) {
    // ... (Stat Card logic remains the same)
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
          // NEW: Project Information Card
          _buildProjectInfoCard(),
          
          const SizedBox(height: 20),
          
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
                  // const SizedBox(height: 5),
                  // const Text('API: https://seedloc.my.id/api', style: TextStyle(fontSize: 12, color: Colors.grey)),
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

          // // Export Section
          // Card(
          //   child: Padding(
          //     padding: const EdgeInsets.all(16.0),
          //     child: Column(
          //       crossAxisAlignment: CrossAxisAlignment.start,
          //       children: [
          //         const Text('Ekspor Data', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          //         const SizedBox(height: 10),
          //         const Text('Ekspor geotag ke CSV dengan foto'),
          //         const SizedBox(height: 10),
          //         ElevatedButton(
          //           onPressed: _isExporting ? null : _exportData,
          //           child: Text(_isExporting ? 'Mengekspor...' : 'Ekspor Data'),
          //         ),
          //         if (_exportStatus.isNotEmpty)
          //           Padding(
          //             padding: const EdgeInsets.only(top: 10),
          //             child: Text(_exportStatus),
          //           ),
          //       ],
          //     ),
          //   ),
          // ),

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
                  const Text('Latsar CPNS Kemenhut 2025'),
                  const SizedBox(height: 10),
                  const Text('Pengembang: Ali Syaifarudin'),
                  const Text('Kontak: alisyaifarudin04@gmail.com'),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}