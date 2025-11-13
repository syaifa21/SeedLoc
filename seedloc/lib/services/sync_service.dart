import 'dart:convert';
import 'dart:io';
import 'package:dio/dio.dart';
import 'package:http/http.dart' as http;
import '../models/geotag.dart';
import '../models/project.dart';
import '../database/database_helper.dart';

class SyncService {
  // IMPORTANT: Ganti dengan URL server Anda
  // Untuk testing lokal: http://localhost:8000
  // Untuk production: https://your-domain.com
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
      return response.statusCode == 200;
    } catch (e) {
      print('Connection test failed: $e');
      return false;
    }
  }

  // Sync semua geotags yang belum tersinkronisasi
  Future<Map<String, dynamic>> syncGeotags() async {
    try {
      DatabaseHelper dbHelper = DatabaseHelper();
      List<Geotag> unsyncedGeotags = await dbHelper.getUnsyncedGeotags();

      if (unsyncedGeotags.isEmpty) {
        return {
          'success': true,
          'message': 'Tidak ada data untuk disinkronkan',
          'synced': 0,
          'failed': 0,
        };
      }

      int syncedCount = 0;
      int failedCount = 0;
      List<String> errors = [];

      // Sync satu per satu
      for (var geotag in unsyncedGeotags) {
        try {
          await _syncSingleGeotag(geotag);
          await dbHelper.updateGeotagSyncStatus(geotag.id!, true);
          syncedCount++;
        } catch (e) {
          failedCount++;
          errors.add('Geotag ${geotag.id}: ${e.toString()}');
          print('Failed to sync geotag ${geotag.id}: $e');
        }
      }

      return {
        'success': failedCount == 0,
        'message': 'Sinkronisasi selesai',
        'synced': syncedCount,
        'failed': failedCount,
        'errors': errors,
      };
    } catch (e) {
      print('Sync failed: $e');
      return {
        'success': false,
        'message': 'Sinkronisasi gagal: ${e.toString()}',
        'synced': 0,
        'failed': 0,
      };
    }
  }

  // Sync bulk (lebih efisien untuk banyak data)
  Future<Map<String, dynamic>> syncGeotagsBulk() async {
    try {
      DatabaseHelper dbHelper = DatabaseHelper();
      List<Geotag> unsyncedGeotags = await dbHelper.getUnsyncedGeotags();

      if (unsyncedGeotags.isEmpty) {
        return {
          'success': true,
          'message': 'Tidak ada data untuk disinkronkan',
          'synced': 0,
        };
      }

      // Prepare data for bulk sync
      List<Map<String, dynamic>> geotagsData = unsyncedGeotags.map((g) => {
        'projectId': g.projectId,
        'latitude': g.latitude,
        'longitude': g.longitude,
        'locationName': g.locationName,
        'timestamp': g.timestamp,
        'itemType': g.itemType,
        'condition': g.condition,
        'details': g.details,
        'photoPath': g.photoPath,
        'deviceId': g.deviceId,
      }).toList();

      final response = await _dio.post(
        '$baseUrl/geotags',
        data: {'geotags': geotagsData},
      );

      if (response.statusCode == 200 && response.data['success']) {
        // Mark all as synced
        for (var geotag in unsyncedGeotags) {
          await dbHelper.updateGeotagSyncStatus(geotag.id!, true);
        }

        return {
          'success': true,
          'message': response.data['message'],
          'synced': unsyncedGeotags.length,
        };
      } else {
        throw Exception('Bulk sync failed: ${response.data['message']}');
      }
    } catch (e) {
      print('Bulk sync failed: $e');
      return {
        'success': false,
        'message': 'Sinkronisasi bulk gagal: ${e.toString()}',
        'synced': 0,
      };
    }
  }

  // Sync single geotag
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
      throw Exception('Failed to sync geotag: ${response.data['message']}');
    }
  }

  // Sync project
  Future<bool> syncProject(Project project) async {
    try {
      final response = await _dio.post(
        '$baseUrl/projects',
        data: project.toMap(),
      );

      return response.statusCode == 200 || response.statusCode == 201;
    } catch (e) {
      print('Failed to sync project: $e');
      return false;
    }
  }

  // Upload photo to server
  Future<String?> uploadPhoto(String filePath) async {
    try {
      var request = http.MultipartRequest(
        'POST',
        Uri.parse('$baseUrl/upload'),
      );

      request.files.add(await http.MultipartFile.fromPath('photo', filePath));

      var streamedResponse = await request.send();
      var response = await http.Response.fromStream(streamedResponse);

      if (response.statusCode == 200) {
        var jsonResponse = jsonDecode(response.body);
        if (jsonResponse['success']) {
          return jsonResponse['url'];
        }
      }

      return null;
    } catch (e) {
      print('Failed to upload photo: $e');
      return null;
    }
  }

  // Get statistics from server
  Future<Map<String, dynamic>?> getStatistics() async {
    try {
      final response = await _dio.get('$baseUrl/stats');
      
      if (response.statusCode == 200 && response.data['success']) {
        return response.data['data'];
      }
      
      return null;
    } catch (e) {
      print('Failed to get statistics: $e');
      return null;
    }
  }

  // Get all projects from server
  Future<List<Project>?> getProjects() async {
    try {
      final response = await _dio.get('$baseUrl/projects');
      
      if (response.statusCode == 200 && response.data['success']) {
        List<dynamic> projectsData = response.data['data'];
        return projectsData.map((p) => Project.fromMap(p)).toList();
      }
      
      return null;
    } catch (e) {
      print('Failed to get projects: $e');
      return null;
    }
  }

  // Get geotags for a project from server
  Future<List<Geotag>?> getGeotagsByProject(int projectId) async {
    try {
      final response = await _dio.get(
        '$baseUrl/geotags',
        queryParameters: {'projectId': projectId},
      );
      
      if (response.statusCode == 200 && response.data['success']) {
        List<dynamic> geotagsData = response.data['data'];
        return geotagsData.map((g) => Geotag.fromMap(g)).toList();
      }
      
      return null;
    } catch (e) {
      print('Failed to get geotags: $e');
      return null;
    }
  }

  // Check if API is reachable
  Future<bool> checkConnection() async {
    try {
      final response = await _dio.get('$baseUrl/');
      return response.statusCode == 200;
    } catch (e) {
      print('Connection check failed: $e');
      return false;
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
      print('Failed to get sync stats: $e');
      return {'total': 0, 'synced': 0, 'unsynced': 0};
    }
  }
}
