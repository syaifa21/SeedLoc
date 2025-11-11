import 'package:flutter/material.dart';
import '../models/project.dart';
import '../database/database_helper.dart';
import 'field_data_screen.dart';
import 'geotag_list_screen.dart';

class ProjectCreationScreen extends StatefulWidget {
  const ProjectCreationScreen({super.key});

  @override
  State<ProjectCreationScreen> createState() => _ProjectCreationScreenState();
}

class _ProjectCreationScreenState extends State<ProjectCreationScreen> {
  final _formKey = GlobalKey<FormState>();
  final TextEditingController _activityNameController = TextEditingController();
  final TextEditingController _locationNameController = TextEditingController();
  final TextEditingController _officersController = TextEditingController();

  bool _isCreating = false;

  @override
  void dispose() {
    _activityNameController.dispose();
    _locationNameController.dispose();
    _officersController.dispose();
    super.dispose();
  }

  Future<void> _createProject() async {
    if (!_formKey.currentState!.validate()) {
      return;
    }

    setState(() {
      _isCreating = true;
    });

    try {
      Project project = Project(
        projectId: 210103, // Fixed project ID
        activityName: _activityNameController.text,
        locationName: _locationNameController.text,
        officers: _officersController.text.split(',').map((e) => e.trim()).toList(),
        status: 'Aktif',
      );

      DatabaseHelper dbHelper = DatabaseHelper();
      await dbHelper.insertProject(project);

      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Proyek berhasil dibuat')),
      );

      // Navigate directly to Geotag List Screen
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => const GeotagListScreen()),
      );
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error membuat proyek: $e')),
      );
    } finally {
      setState(() {
        _isCreating = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Buat Proyek Baru'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    children: [
                      const Text(
                        'Informasi Proyek',
                        style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                      ),
                      const SizedBox(height: 20),

                      // Activity Name
                      TextFormField(
                        controller: _activityNameController,
                        decoration: const InputDecoration(
                          labelText: 'Nama Kegiatan',
                          hintText: 'Masukkan nama kegiatan',
                        ),
                        validator: (value) =>
                            value!.isEmpty ? 'Harap masukkan nama kegiatan' : null,
                      ),

                      const SizedBox(height: 16),

                      // Location Name
                      TextFormField(
                        controller: _locationNameController,
                        decoration: const InputDecoration(
                          labelText: 'Nama Lokasi',
                          hintText: 'Masukkan nama lokasi',
                        ),
                        validator: (value) =>
                            value!.isEmpty ? 'Harap masukkan nama lokasi' : null,
                      ),

                      const SizedBox(height: 16),

                      // Officers
                      TextFormField(
                        controller: _officersController,
                        decoration: const InputDecoration(
                          labelText: 'Daftar Petugas',
                          hintText: 'Pisahkan dengan koma (contoh: Petugas A, Petugas B)',
                        ),
                        validator: (value) =>
                            value!.isEmpty ? 'Harap masukkan daftar petugas' : null,
                      ),

                      const SizedBox(height: 20),

                      // Info Text
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: Colors.blue.shade50,
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: const Text(
                          'Selamat Datang\n'
                          'SeedLoc',
                          style: TextStyle(fontSize: 14, color: Colors.blue),
                        ),
                      ),
                    ],
                  ),
                ),
              ),

              const SizedBox(height: 24),

              // Create Project Button
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: _isCreating ? null : _createProject,
                  style: ElevatedButton.styleFrom(
                    padding: const EdgeInsets.symmetric(vertical: 16),
                  ),
                  child: Text(
                    _isCreating ? 'Membuat Proyek...' : 'Buat Proyek',
                    style: const TextStyle(fontSize: 18),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
