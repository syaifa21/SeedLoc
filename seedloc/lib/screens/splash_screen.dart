import 'dart:async';
import 'package:flutter/material.dart';
import 'home_screen.dart';
import '../services/metadata_service.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  @override
  void initState() {
    super.initState();
    _initializeApp();
  }

  Future<void> _initializeApp() async {
    // 1. Download data terbaru dari Server (Metadata)
    // Kita gunakan 'await' agar yakin data sudah ada sebelum masuk Home,
    // tapi dibungkus try-catch di dalam servicenya agar tidak crash jika offline.
    await MetadataService.fetchAndSaveMetadata();

    // 2. Tambahan delay sedikit agar logo terlihat (opsional)
    await Future.delayed(const Duration(seconds: 2));

    // 3. Pindah ke Home
    if (mounted) {
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => const HomeScreen()),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            // Pastikan aset logo ada di pubspec.yaml
            Image.asset(
              'lib/assets/Logo.png', 
              width: 150,
              height: 150,
              errorBuilder: (context, error, stackTrace) => 
                  const Icon(Icons.forest, size: 100, color: Colors.green),
            ),
            const SizedBox(height: 20),
            const CircularProgressIndicator(color: Colors.green),
            const SizedBox(height: 20),
            const Text(
              'Memuat Data...',
              style: TextStyle(color: Colors.grey),
            )
          ],
        ),
      ),
    );
  }
}