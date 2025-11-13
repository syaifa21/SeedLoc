import 'dart:io';
import 'package:csv/csv.dart';
import 'package:path_provider/path_provider.dart';
import 'package:path/path.dart' as path;
import 'package:archive/archive_io.dart'; // Digunakan untuk membuat file ZIP
import '../models/geotag.dart';
import '../database/database_helper.dart';

class ExportService {
  static Future<String> exportGeotagsToCsv(int projectId) async {
    DatabaseHelper dbHelper = DatabaseHelper();
    List<Geotag> geotags = await dbHelper.getGeotagsByProject(projectId);

    // 1. Persiapkan Data CSV
    List<List<String>> csvData = [
      ['ID', 'Project ID', 'Latitude', 'Longitude', 'Location Name', 'Timestamp', 'Item Type', 'Condition', 'Details', 'Photo Path', 'Is Synced', 'Device ID']
    ];

    for (var geotag in geotags) {
      // Untuk CSV, kita masukkan nama file foto di folder ZIP/photos/
      // Catatan: geotag.photoPath saat ini berisi URL publik/Path lokal. Kita ambil basename-nya.
      String photoFileName = geotag.photoPath.isNotEmpty ? 'photos/${path.basename(geotag.photoPath)}' : '';
      
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
        photoFileName, 
        geotag.isSynced.toString(),
        geotag.deviceId,
      ]);
    }

    String csv = const ListToCsvConverter().convert(csvData);

    // Tentukan direktori penyimpanan sementara dan direktori target yang aman.
    final Directory baseDir = await getExternalStorageDirectory() ?? await getApplicationDocumentsDirectory();
    
    // Direktori sementara untuk menampung CSV dan Foto sebelum di-ZIP
    final String tempExportDirPath = path.join(baseDir.path, 'SeedLoc_Export_Temp', 'Project_$projectId');
    final Directory tempExportDir = Directory(tempExportDirPath);
    if (await tempExportDir.exists()) {
      await tempExportDir.delete(recursive: true);
    }
    await tempExportDir.create(recursive: true);

    // Path File CSV Sementara
    final String csvFilePath = path.join(tempExportDirPath, 'geotags.csv');
    final File csvFile = File(csvFilePath);
    await csvFile.writeAsString(csv);

    // Copy foto ke folder sementara di dalam subfolder 'photos'
    await _copyPhotosToTempDir(geotags, path.join(tempExportDirPath, 'photos'));

    // 2. Buat File ZIP
    final String dateString = '${DateTime.now().year}${DateTime.now().month.toString().padLeft(2, '0')}${DateTime.now().day.toString().padLeft(2, '0')}';
    final String zipFileName = 'SeedLoc_Project_${projectId}_$dateString.zip';
    
    // Path ZIP Akhir: Disimpan di folder SeedLoc yang diizinkan OS.
    final String finalSeedLocDir = path.join(baseDir.path, 'SeedLoc');
    await Directory(finalSeedLocDir).create(recursive: true);
    final String zipFilePath = path.join(finalSeedLocDir, zipFileName);

    // Kompres folder sementara menjadi file ZIP
    var encoder = ZipFileEncoder();
    encoder.create(zipFilePath);
    encoder.addDirectory(tempExportDir);
    encoder.close();

    // 3. Bersihkan dan Kembalikan Path
    await tempExportDir.delete(recursive: true);

    return zipFilePath;
  }

  static Future<void> _copyPhotosToTempDir(List<Geotag> geotags, String tempPhotosDirPath) async {
    final Directory photosDir = Directory(tempPhotosDirPath);
    if (!await photosDir.exists()) {
      await photosDir.create(recursive: true);
    }

    for (var geotag in geotags) {
      if (geotag.photoPath.isNotEmpty) {
        try {
          final File sourceFile = File(geotag.photoPath);
          if (await sourceFile.exists()) {
            final String fileName = path.basename(geotag.photoPath);
            final String destPath = path.join(tempPhotosDirPath, fileName);
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