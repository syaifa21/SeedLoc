import 'package:flutter/material.dart';
import 'package:flutter/services.dart'; // Import ini untuk FilteringTextInputFormatter
import '../models/project.dart';
import '../database/database_helper.dart';
import 'field_data_screen.dart';
// import 'geotag_list_screen.dart'; // Tidak perlu lagi diimpor langsung
import 'home_screen.dart'; // <-- Pastikan ini diimpor!

class ProjectCreationScreen extends StatefulWidget {
  const ProjectCreationScreen({super.key});

  @override
  State<ProjectCreationScreen> createState() => _ProjectCreationScreenState();
}

class _ProjectCreationScreenState extends State<ProjectCreationScreen> {
  final _formKey = GlobalKey<FormState>();
  // NEW: Controller untuk Project ID
  final TextEditingController _projectIdController = TextEditingController();
  final TextEditingController _activityNameController = TextEditingController();
  final TextEditingController _locationNameController = TextEditingController();
  final TextEditingController _officersController = TextEditingController();

  bool _isCreating = false;

  @override
  void dispose() {
    // NEW: Dispose controller baru
    _projectIdController.dispose(); 
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

    // Validasi dan konversi Project ID
    final int? projectId = int.tryParse(_projectIdController.text);
    if (projectId == null) {
        setState(() { _isCreating = false; });
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Error: Project ID harus berupa angka valid.')),
        );
        return;
    }


    try {
      Project project = Project(
        // MENGGUNAKAN INPUT DARI USER
        projectId: projectId, 
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

      // --- PERBAIKAN KRITIS: Navigasi kembali ke HomeScreen ---
      // Ini akan memicu HomeScreen untuk memeriksa proyek lagi dan menampilkan GeotagListScreen DENGAN BottomNavigationBar.
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => const HomeScreen()), // <-- PERUBAHAN DI SINI
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
// ... (Bagian build tetap sama)
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

                      // NEW: Project ID Input Field
                      TextFormField(
                        controller: _projectIdController,
                        decoration: const InputDecoration(
                          labelText: 'Project ID',
                          hintText: 'Masukkan ID unik Proyek (misal: 1234)',
                        ),
                        keyboardType: TextInputType.number,
                        inputFormatters: [
                          FilteringTextInputFormatter.digitsOnly
                        ],
                        validator: (value) {
                            if (value == null || value.isEmpty) {
                                return 'Harap masukkan Project ID';
                            }
                            if (int.tryParse(value) == null) {
                                return 'Project ID harus berupa angka';
                            }
                            return null;
                        }
                      ),
                      
                      const SizedBox(height: 16),

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