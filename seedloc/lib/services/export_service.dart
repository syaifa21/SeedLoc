// File: lib/services/export_service.dart

import 'dart:io';
import 'package:csv/csv.dart';
import 'package:path_provider/path_provider.dart';
import 'package:path/path.dart' as path;
import '../models/geotag.dart';
import '../database/database_helper.dart';

class ExportService {
  static Future<String> exportGeotagsToCsv(int projectId) async {
    DatabaseHelper dbHelper = DatabaseHelper();
    List<Geotag> geotags = await dbHelper.getGeotagsByProject(projectId);

    List<List<String>> csvData = [
      ['ID', 'Project ID', 'Latitude', 'Longitude', 'Location Name', 'Timestamp', 'Item Type', 'Condition', 'Details', 'Photo Path', 'Is Synced', 'Device ID']
    ];

    for (var geotag in geotags) {
      csvData.add([
        geotag.id.toString(),
        geotag.projectId.toString(),
        geotag.latitude.toString(),
        geotag.longitude.toString(),
        geotag.locationName,
        geotag.timestamp,
        geotag.itemType,
        geotag.condition,
        geotag.details,
        geotag.photoPath,
        geotag.isSynced.toString(),
        geotag.deviceId,
      ]);
    }

    String csv = const ListToCsvConverter().convert(csvData);

    // --- MODIFIKASI UNTUK ROOT FOLDER KUSTOM ---

    // Mengganti logic path_provider untuk menargetkan root penyimpanan bersama (/storage/emulated/0).
    // '/sdcard/' adalah alias umum untuk root penyimpanan eksternal.
    // Catatan: Ini membutuhkan izin WRITE_EXTERNAL_STORAGE dan mungkin 
    // dibatasi pada perangkat Android baru (Scoped Storage).
    const String externalRootPath = '/storage/emulated/0/'; 
    
    // Target path: /sdcard/SeedLoc/Project_X
    final String exportDirPath = path.join(externalRootPath, 'SeedLoc', 'Project_$projectId');

    // --- AKHIR MODIFIKASI ---

    final Directory exportDir = Directory(exportDirPath);
    if (!await exportDir.exists()) {
      // Pastikan izin WRITE_EXTERNAL_STORAGE sudah diberikan oleh user
      await exportDir.create(recursive: true);
    }

    final String csvFilePath = path.join(exportDirPath, 'geotags.csv');
    final File csvFile = File(csvFilePath);
    await csvFile.writeAsString(csv);

    // Copy photos to export directory
    await _copyPhotosToExportDir(geotags, exportDirPath);

    return exportDirPath;
  }

  static Future<void> _copyPhotosToExportDir(List<Geotag> geotags, String exportDirPath) async {
    final String photosDirPath = path.join(exportDirPath, 'photos');
    final Directory photosDir = Directory(photosDirPath);
    if (!await photosDir.exists()) {
      await photosDir.create(recursive: true);
    }

    for (var geotag in geotags) {
      if (geotag.photoPath.isNotEmpty) {
        try {
          final File sourceFile = File(geotag.photoPath);
          if (await sourceFile.exists()) {
            final String fileName = path.basename(geotag.photoPath);
            final String destPath = path.join(photosDirPath, fileName);
            await sourceFile.copy(destPath);
          }
        } catch (e) {
          print('Error copying photo ${geotag.photoPath}: $e');
        }
      }
    }
  }

  static Future<void> deleteLocalPhotosAfterExport(List<Geotag> geotags) async {
    for (var geotag in geotags) {
      if (geotag.photoPath.isNotEmpty) {
        await File(geotag.photoPath).delete();
      }
    }
  }
}