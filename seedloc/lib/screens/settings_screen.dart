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
  String _syncStatus = '';
  String _exportStatus = '';

  Future<void> _syncData() async {
    setState(() {
      _isSyncing = true;
      _syncStatus = 'Menyinkronkan...';
    });

    try {
      SyncService syncService = SyncService();
      bool success = await syncService.syncGeotags();

      setState(() {
        _syncStatus = success ? 'Sinkronisasi berhasil' : 'Sinkronisasi gagal';
      });
    } catch (e) {
      setState(() {
        _syncStatus = 'Error sinkronisasi: $e';
      });
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Pengaturan & Info'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16.0),
        children: [
          // Sync Section
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Sinkronisasi Data', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 10),
                  const Text('Sinkronkan geotag yang belum tersinkronkan ke REST API'),
                  const SizedBox(height: 10),
                  ElevatedButton(
                    onPressed: _isSyncing ? null : _syncData,
                    child: Text(_isSyncing ? 'Menyinkronkan...' : 'Sinkronkan Data'),
                  ),
                  if (_syncStatus.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 10),
                      child: Text(_syncStatus),
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
