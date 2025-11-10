import 'dart:convert';
import 'package:dio/dio.dart';
import '../models/geotag.dart';
import '../database/database_helper.dart';

class SyncService {
  static const String baseUrl = 'https://your-api-endpoint.com'; // Replace with actual API endpoint
  final Dio _dio = Dio();

  SyncService() {
    _dio.options.connectTimeout = const Duration(seconds: 10);
    _dio.options.receiveTimeout = const Duration(seconds: 10);
  }

  Future<bool> syncGeotags() async {
    try {
      DatabaseHelper dbHelper = DatabaseHelper();
      List<Geotag> unsyncedGeotags = await dbHelper.getUnsyncedGeotags();

      if (unsyncedGeotags.isEmpty) {
        return true; // Nothing to sync
      }

      for (var geotag in unsyncedGeotags) {
        await _syncSingleGeotag(geotag);
        await dbHelper.updateGeotagSyncStatus(geotag.id!, true);
      }

      return true;
    } catch (e) {
      print('Sync failed: $e');
      return false;
    }
  }

  Future<void> _syncSingleGeotag(Geotag geotag) async {
    final response = await _dio.post(
      '$baseUrl/geotags',
      data: jsonEncode({
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
      }),
    );

    if (response.statusCode != 200) {
      throw Exception('Failed to sync geotag: ${response.statusCode}');
    }
  }
}
