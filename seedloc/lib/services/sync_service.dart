import 'dart:convert';
import 'dart:io';
import 'package:dio/dio.dart';
import 'package:http/http.dart' as http;
import '../models/geotag.dart';
import '../models/project.dart';
import '../database/database_helper.dart';

class SyncService {
  // URL dasar API
  static const String baseUrl = 'https://seedloc.my.id/api';
  
  // API KEY - HARUS SAMA DENGAN PHP
  static const String _apiKey = 'SeedLoc_Secret_Key_2025_Secure';

  final Dio _dio = Dio();

  SyncService() {
    _dio.options.connectTimeout = const Duration(seconds: 30);
    _dio.options.receiveTimeout = const Duration(seconds: 30);
    // Masukkan API Key ke Header Global Dio
    _dio.options.headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-API-KEY': _apiKey, 
    };
  }

  // Test koneksi ke server (Utama)
  Future<bool> testConnection() async {
    try {
      final response = await _dio.get('$baseUrl/status'); 
      print('API Status Check: HTTP ${response.statusCode}, Data: ${response.data}');
      return response.statusCode == 200;
    } catch (e) {
      print('Connection test failed: $e');
      return false;
    }
  }

  // --- PERBAIKAN: TAMBAHKAN INI (Alias untuk SettingsScreen) ---
  Future<bool> checkConnection() async {
    return testConnection();
  }
  // ------------------------------------------------------------

  // Sync semua geotags yang belum tersinkronisasi
  Future<Map<String, dynamic>> syncGeotags() async {
    DatabaseHelper dbHelper = DatabaseHelper();
    List<Geotag> unsyncedGeotags = await dbHelper.getUnsyncedGeotags();

    if (unsyncedGeotags.isEmpty) {
      return {
        'success': true,
        'message': 'Tidak ada data untuk disinkronkan',
        'synced': 0,
        'failed': 0,
        'detailedError': null,
      };
    }

    int syncedCount = 0;
    int failedCount = 0;
    List<String> errors = [];
    String? lastDetailedError;
    
    print('--- START SYNC: Found ${unsyncedGeotags.length} unsynced geotags ---');
    
    try {
      for (var geotag in unsyncedGeotags) {
        print('Processing Geotag ID: ${geotag.id}');
        
        String finalPhotoPath = geotag.photoPath;
        bool localPhotoExists = geotag.photoPath.isNotEmpty && File(geotag.photoPath).existsSync();
        bool isUploadSuccessful = true;

        // 1. UPLOAD FOTO
        if (localPhotoExists) {
            print('  > Local photo found. Starting upload...');
            try {
                String? photoUrl = await uploadPhoto(geotag.photoPath);
                finalPhotoPath = photoUrl!;
                print('  > Photo upload SUCCESS. URL: $photoUrl');
            } catch (e) {
                isUploadSuccessful = false;
                failedCount++;
                lastDetailedError = e.toString(); 
                errors.add('Geotag ${geotag.id}: Gagal upload foto. ${e.toString()}');
                print('  > Photo upload FAILED. Error: $e');
                continue; 
            }
        }
        
        // 2. SYNC GEOTAG DATA
        try {
            Geotag geotagToSync = Geotag(
                id: geotag.id,
                projectId: geotag.projectId,
                latitude: geotag.latitude,
                longitude: geotag.longitude,
                locationName: geotag.locationName,
                timestamp: geotag.timestamp,
                itemType: geotag.itemType,
                condition: geotag.condition,
                details: geotag.details,
                photoPath: finalPhotoPath, 
                isSynced: true, 
                deviceId: geotag.deviceId
            );

            await _syncSingleGeotag(geotagToSync);
            print('  > Geotag data sync SUCCESS for ID: ${geotag.id}');
            
            // 3. UPDATE DATABASE LOKAL & HAPUS FOTO
            if (localPhotoExists && isUploadSuccessful) {
                try { await File(geotag.photoPath).delete(); } catch(e) {}
            }
            await dbHelper.updateGeotag(geotagToSync); 
            syncedCount++;
        } catch (e) {
            failedCount++;
            lastDetailedError = e.toString();
            errors.add('Geotag ${geotag.id}: Gagal sync data. ${e.toString()}');
            print('  > Geotag data sync FAILED. Error: $e');
        }
      }

      print('--- SYNC COMPLETE: Success: $syncedCount, Failed: $failedCount ---');

      return {
        'success': failedCount == 0,
        'message': failedCount == 0 ? 'Sinkronisasi berhasil: $syncedCount data.' : 'Selesai. Berhasil: $syncedCount, Gagal: $failedCount.',
        'synced': syncedCount,
        'failed': failedCount,
        'errors': errors,
        'detailedError': lastDetailedError,
      };
    } catch (e) {
      print('FATAL Sync FAILED: $e');
      return {
        'success': false,
        'message': 'Sinkronisasi gagal total: ${e.toString()}',
        'synced': 0,
        'failed': unsyncedGeotags.length,
        'detailedError': e.toString(),
      };
    }
  }

  // Upload photo to server (HTTP MULTIPART REQUEST)
  Future<String?> uploadPhoto(String filePath) async {
    var request = http.MultipartRequest('POST', Uri.parse('$baseUrl/upload'));

    // PENTING: Tambahkan API Key ke Header Multipart
    request.headers['X-API-KEY'] = _apiKey;
    
    request.files.add(await http.MultipartFile.fromPath('photo', filePath));
    
    try {
      var streamedResponse = await request.send();
      var response = await http.Response.fromStream(streamedResponse);
      
      if (response.statusCode == 200) {
        var jsonResponse = jsonDecode(response.body);
        if (jsonResponse['success']) {
          return jsonResponse['url']; // URL foto yang sudah dikompres server
        } else {
          throw Exception('API Error: ${jsonResponse['message']}');
        }
      } else {
        throw Exception('HTTP Error ${response.statusCode}: ${response.body}');
      }
    } catch (e) {
      print('  > Upload FAILED: $e');
      rethrow; 
    }
  }

  // Sync single geotag (DIO REQUEST)
  Future<void> _syncSingleGeotag(Geotag geotag) async {
    // Header X-API-KEY sudah ada di _dio.options.headers (Constructor)
    final response = await _dio.post(
      '$baseUrl/geotags',
      data: {
        'projectId': geotag.projectId,
        'latitude': geotag.latitude,
        'longitude': geotag.longitude,
        'locationName': geotag.locationName,
        'timestamp': geotag.timestamp,
        'itemType': geotag.itemType,
        'condition': geotag.condition,
        'details': geotag.details,
        'photoPath': geotag.photoPath,
        'deviceId': geotag.deviceId,
      },
    );

    if (response.statusCode != 200 || !response.data['success']) {
      throw Exception('Failed to sync geotag: ${response.data['message']}');
    }
  }

  // Get sync statistics
  Future<Map<String, int>> getSyncStats() async {
    try {
      DatabaseHelper dbHelper = DatabaseHelper();
      List<Geotag> allGeotags = await dbHelper.getGeotags();
      List<Geotag> unsyncedGeotags = await dbHelper.getUnsyncedGeotags();
      return {
        'total': allGeotags.length,
        'synced': allGeotags.length - unsyncedGeotags.length,
        'unsynced': unsyncedGeotags.length,
      };
    } catch (e) {
      return {'total': 0, 'synced': 0, 'unsynced': 0};
    }
  }
}