import 'dart:convert';
import 'dart:io';
import 'package:dio/dio.dart';
import 'package:http/http.dart' as http;
import '../models/geotag.dart';
import '../models/project.dart';
import '../database/database_helper.dart';

class SyncService {
  // IMPORTANT: Ganti dengan URL server Anda
  static const String baseUrl = 'https://seedloc.my.id/api';
  
  final Dio _dio = Dio();

  SyncService() {
    _dio.options.connectTimeout = const Duration(seconds: 30);
    _dio.options.receiveTimeout = const Duration(seconds: 30);
    _dio.options.headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
  }

  // Test koneksi ke server
  Future<bool> testConnection() async {
    try {
      final response = await _dio.get('$baseUrl/');
      print('API Status Check: HTTP ${response.statusCode}, Status: ${response.data['status']}');
      return response.statusCode == 200;
    } catch (e) {
      print('Connection test failed: $e');
      return false;
    }
  }

  // Sync semua geotags yang belum tersinkronisasi (Ditingkatkan untuk Upload Foto & Logging)
  Future<Map<String, dynamic>> syncGeotags() async {
    DatabaseHelper dbHelper = DatabaseHelper();
    List<Geotag> unsyncedGeotags = await dbHelper.getUnsyncedGeotags();

    if (unsyncedGeotags.isEmpty) {
      return {
        'success': true,
        'message': 'Tidak ada data untuk disinkronkan',
        'synced': 0,
        'failed': 0,
        'detailedError': null, // Tambahan field
      };
    }

    int syncedCount = 0;
    int failedCount = 0;
    List<String> errors = [];
    String? lastDetailedError; // NEW: Field untuk menangkap error detail
    
    // LOGGING: Mulai sinkronisasi
    print('--- START SYNC: Found ${unsyncedGeotags.length} unsynced geotags ---');
    
    try {
      // Sync satu per satu
      for (var geotag in unsyncedGeotags) {
        print('Processing Geotag ID: ${geotag.id}');
        
        String finalPhotoPath = geotag.photoPath;
        bool localPhotoExists = geotag.photoPath.isNotEmpty && File(geotag.photoPath).existsSync();
        bool isUploadSuccessful = true;

        // 1. UPLOAD FOTO
        if (localPhotoExists) {
            print('  > Local photo found. Starting upload for: ${geotag.photoPath.split('/').last}');
            try {
                String? photoUrl = await uploadPhoto(geotag.photoPath);
                finalPhotoPath = photoUrl!;
                print('  > Photo upload SUCCESS. URL: $photoUrl');
            } catch (e) {
                // UPLOAD FAILED. Tangkap error dan jadikan error detail untuk UI
                isUploadSuccessful = false;
                failedCount++;
                lastDetailedError = e.toString(); 
                errors.add('Geotag ${geotag.id}: Gagal upload foto. Detail: ${e.toString()}');
                print('  > Photo upload FAILED. Skipping geotag data sync for ID: ${geotag.id}. Error: $e');
                continue; // Lanjutkan ke geotag berikutnya jika upload gagal
            }
        } else if (geotag.photoPath.isNotEmpty) {
            print('  > Warning: Photo path exists but local file not found. Syncing geotag data with current photoPath.');
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
            
            // 3. UPDATE DATABASE LOKAL DAN HAPUS FOTO LOKAL
            if (localPhotoExists && isUploadSuccessful) {
                await File(geotag.photoPath).delete();
                print('  > Local photo deleted: ${geotag.photoPath.split('/').last}');
            }
            await dbHelper.updateGeotag(geotagToSync); 
            syncedCount++;
        } catch (e) {
            failedCount++;
            lastDetailedError = e.toString(); // Tangkap error detail
            errors.add('Geotag ${geotag.id}: Gagal sinkronisasi data. Detail: ${e.toString()}');
            print('  > Geotag data sync FAILED for ID: ${geotag.id}. Error: $e');
        }
      }

      // LOGGING: Ringkasan Sync
      print('--- SYNC COMPLETE: Success: $syncedCount, Failed: $failedCount ---');

      String message;
      if (failedCount == 0) {
        message = 'Sinkronisasi berhasil: $syncedCount data.';
      } else {
        message = 'Sinkronisasi selesai. Berhasil: $syncedCount, Gagal: $failedCount. Cek detail error.';
      }

      return {
        'success': failedCount == 0,
        'message': message,
        'synced': syncedCount,
        'failed': failedCount,
        'errors': errors,
        'detailedError': lastDetailedError, // RETURN NEW FIELD
      };
    } catch (e) {
      print('FATAL Sync FAILED: $e');
      return {
        'success': false,
        'message': 'Sinkronisasi gagal total: ${e.toString()}',
        'synced': 0,
        'failed': unsyncedGeotags.length,
        'detailedError': e.toString(), // RETURN NEW FIELD
      };
    }
  }

  // Upload photo to server (MODIFIED: Throw Exception with detailed server response)
  Future<String?> uploadPhoto(String filePath) async {
    var request = http.MultipartRequest(
      'POST',
      Uri.parse('$baseUrl/upload'),
    );

    request.files.add(await http.MultipartFile.fromPath('photo', filePath));
    
    try {
      var streamedResponse = await request.send();
      var response = await http.Response.fromStream(streamedResponse);
      
      print('  > Upload HTTP Status: ${response.statusCode}');
      
      String responseBody = response.body;
      print('  > Upload Response Body: $responseBody'); // Mencetak body mentah

      if (response.statusCode == 200) {
        try {
          var jsonResponse = jsonDecode(responseBody);
          if (jsonResponse['success']) {
            return jsonResponse['url'];
          } else {
            // Failure: HTTP 200 tapi API logic error (mis. pesan error dari PHP di dalam JSON)
            throw Exception('API Logic Error: ${jsonResponse['message']} (Response: $responseBody)');
          }
        } catch (e) {
          // Failure: Cannot parse JSON (mis. raw PHP/HTML error)
          throw Exception('Invalid Response Format (Gagal Parse JSON). Raw Server Output: $responseBody');
        }
      } else {
        // Failure: Non-200 HTTP Status
        throw Exception('HTTP Error ${response.statusCode}. Raw Server Output: $responseBody');
      }
    } catch (e) {
      // Failure: Network atau connection exception
      print('  > Upload FAILED (Network Exception): $e');
      rethrow; 
    }
  }

  // Sync single geotag (helper function) - menggunakan Dio
  Future<void> _syncSingleGeotag(Geotag geotag) async {
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
      print('  > Failed to sync geotag data. Server Response: ${response.data}');
      throw Exception('Failed to sync geotag: ${response.data['message']}');
    }
  }

  // Check if API is reachable (alias dari testConnection)
  Future<bool> checkConnection() async {
    return testConnection();
  }

  // Get sync statistics dari database lokal
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
      print('Failed to get local sync stats: $e');
      return {'total': 0, 'synced': 0, 'unsynced': 0};
    }
  }
}