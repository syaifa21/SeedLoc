import 'dart:convert';
import 'package:dio/dio.dart';
import '../models/geotag.dart';
import '../models/project.dart';
import '../database/database_helper.dart';

class SyncService {
  static const String baseUrl = 'https://seedloc.my.id/api';
  final Dio _dio = Dio();

  SyncService() {
    _dio.options.connectTimeout = const Duration(seconds: 30);
    _dio.options.receiveTimeout = const Duration(seconds: 30);
    _dio.options.headers['Content-Type'] = 'application/json';
  }

  /// Sync all unsynced geotags to the server
  Future<bool> syncGeotags() async {
    try {
      DatabaseHelper dbHelper = DatabaseHelper();
      List<Geotag> unsyncedGeotags = await dbHelper.getUnsyncedGeotags();

      if (unsyncedGeotags.isEmpty) {
        print('No geotags to sync');
        return true;
      }

      print('Syncing ${unsyncedGeotags.length} geotags...');
      
      int successCount = 0;
      int failCount = 0;

      for (var geotag in unsyncedGeotags) {
        try {
          await _syncSingleGeotag(geotag);
          await dbHelper.updateGeotagSyncStatus(geotag.id!, true);
          successCount++;
          print('Synced geotag ${geotag.id} successfully');
        } catch (e) {
          failCount++;
          print('Failed to sync geotag ${geotag.id}: $e');
          // Continue with next geotag even if one fails
        }
      }

      print('Sync completed: $successCount succeeded, $failCount failed');
      return failCount == 0;
    } catch (e) {
      print('Sync failed: $e');
      return false;
    }
  }

  /// Sync a single geotag to the server
  Future<void> _syncSingleGeotag(Geotag geotag) async {
    try {
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
          'isSynced': true,
        },
      );

      if (response.statusCode != 200 && response.statusCode != 201) {
        throw Exception('Failed to sync geotag: ${response.statusCode}');
      }
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionTimeout) {
        throw Exception('Connection timeout - check your internet connection');
      } else if (e.type == DioExceptionType.receiveTimeout) {
        throw Exception('Server response timeout');
      } else if (e.response != null) {
        throw Exception('Server error: ${e.response?.statusCode} - ${e.response?.data}');
      } else {
        throw Exception('Network error: ${e.message}');
      }
    }
  }

  /// Sync project to the server
  Future<bool> syncProject(Project project) async {
    try {
      final response = await _dio.post(
        '$baseUrl/projects',
        data: {
          'projectId': project.projectId,
          'activityName': project.activityName,
          'locationName': project.locationName,
          'officers': project.officers.join(','),
          'status': project.status,
        },
      );

      if (response.statusCode == 200 || response.statusCode == 201) {
        print('Project synced successfully');
        return true;
      } else {
        print('Failed to sync project: ${response.statusCode}');
        return false;
      }
    } on DioException catch (e) {
      print('Failed to sync project: ${e.message}');
      return false;
    } catch (e) {
      print('Failed to sync project: $e');
      return false;
    }
  }

  /// Check if API is reachable
  Future<bool> checkConnection() async {
    try {
      final response = await _dio.get(baseUrl);
      return response.statusCode == 200;
    } catch (e) {
      print('Connection check failed: $e');
      return false;
    }
  }

  /// Get sync statistics
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
