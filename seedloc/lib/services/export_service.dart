import 'dart:io';
import 'package:csv/csv.dart';
import 'package:path_provider/path_provider.dart';
import 'package:path/path.dart' as path;
import 'package:archive/archive_io.dart'; // Digunakan untuk membuat file ZIP
import 'package:share_plus/share_plus.dart';
import '../models/geotag.dart';
import '../database/database_helper.dart';
import 'geotagging_service.dart';

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
            
            // Copy foto
            await sourceFile.copy(destPath);
            
            // Verifikasi dan re-embed EXIF metadata jika belum ada
            bool hasGps = await GeotaggingService.hasGpsMetadata(destPath);
            if (!hasGps) {
              print('Re-embedding GPS metadata for: $fileName');
              await GeotaggingService.embedGpsMetadata(
                imagePath: destPath,
                latitude: geotag.latitude,
                longitude: geotag.longitude,
                timestamp: DateTime.parse(geotag.timestamp),
              );
            }
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

  /// Share ZIP file ke WhatsApp atau aplikasi lain
  /// 
  /// Parameters:
  /// - zipFilePath: Path ke file ZIP yang akan di-share
  /// - projectId: ID project untuk pesan share
  /// 
  /// Returns: true jika berhasil share, false jika gagal
  static Future<bool> shareZipFile(String zipFilePath, int projectId) async {
    try {
      final File zipFile = File(zipFilePath);
      
      if (!await zipFile.exists()) {
        print('ZIP file tidak ditemukan: $zipFilePath');
        return false;
      }
      
      // Get file size
      final int fileSize = await zipFile.length();
      final double fileSizeMB = fileSize / (1024 * 1024);
      
      print('Sharing ZIP file: ${path.basename(zipFilePath)}');
      print('File size: ${fileSizeMB.toStringAsFixed(2)} MB');
      
      // Share file dengan pesan
      final result = await Share.shareXFiles(
        [XFile(zipFilePath)],
        text: 'Data SeedLoc Project #$projectId\n'
              'File: ${path.basename(zipFilePath)}\n'
              'Size: ${fileSizeMB.toStringAsFixed(2)} MB\n\n'
              'Berisi:\n'
              '- Data geotag (CSV)\n'
              '- Foto dengan GPS metadata\n\n'
              'Foto dapat dibuka di Google Photos/Gallery dan akan tampil di map.',
        subject: 'SeedLoc Export - Project #$projectId',
      );
      
      print('Share result: $result');
      return true;
    } catch (e) {
      print('Error sharing ZIP file: $e');
      return false;
    }
  }

  /// Export dan langsung share ke WhatsApp
  /// 
  /// Parameters:
  /// - projectId: ID project yang akan di-export
  /// 
  /// Returns: Map dengan status dan pesan
  static Future<Map<String, dynamic>> exportAndShare(int projectId) async {
    try {
      // 1. Export ke ZIP
      print('📦 Membuat ZIP file untuk project $projectId...');
      final String zipFilePath = await exportGeotagsToCsv(projectId);
      
      // 2. Verifikasi file
      final File zipFile = File(zipFilePath);
      if (!await zipFile.exists()) {
        return {
          'success': false,
          'message': 'Gagal membuat file ZIP',
        };
      }
      
      final int fileSize = await zipFile.length();
      final double fileSizeMB = fileSize / (1024 * 1024);
      
      print('✅ ZIP file berhasil dibuat: ${path.basename(zipFilePath)}');
      print('   Size: ${fileSizeMB.toStringAsFixed(2)} MB');
      
      // 3. Share file
      print('📤 Membuka dialog share...');
      final bool shareSuccess = await shareZipFile(zipFilePath, projectId);
      
      if (shareSuccess) {
        return {
          'success': true,
          'message': 'Export berhasil! File: ${path.basename(zipFilePath)}',
          'filePath': zipFilePath,
          'fileSize': fileSizeMB,
        };
      } else {
        return {
          'success': false,
          'message': 'Export berhasil, tapi gagal membuka dialog share',
          'filePath': zipFilePath,
        };
      }
    } catch (e) {
      print('❌ Error export and share: $e');
      return {
        'success': false,
        'message': 'Error: ${e.toString()}',
      };
    }
  }

  /// Get list of exported ZIP files
  static Future<List<Map<String, dynamic>>> getExportedFiles() async {
    try {
      final Directory baseDir = await getExternalStorageDirectory() ?? await getApplicationDocumentsDirectory();
      final String seedLocDir = path.join(baseDir.path, 'SeedLoc');
      final Directory dir = Directory(seedLocDir);
      
      if (!await dir.exists()) {
        return [];
      }
      
      final List<FileSystemEntity> files = dir.listSync();
      final List<Map<String, dynamic>> zipFiles = [];
      
      for (var file in files) {
        if (file is File && file.path.endsWith('.zip')) {
          final FileStat stat = await file.stat();
          final int fileSize = stat.size;
          final double fileSizeMB = fileSize / (1024 * 1024);
          
          zipFiles.add({
            'path': file.path,
            'name': path.basename(file.path),
            'size': fileSizeMB,
            'date': stat.modified,
          });
        }
      }
      
      // Sort by date (newest first)
      zipFiles.sort((a, b) => (b['date'] as DateTime).compareTo(a['date'] as DateTime));
      
      return zipFiles;
    } catch (e) {
      print('Error getting exported files: $e');
      return [];
    }
  }

  /// Delete exported ZIP file
  static Future<bool> deleteExportedFile(String filePath) async {
    try {
      final File file = File(filePath);
      if (await file.exists()) {
        await file.delete();
        print('Deleted: ${path.basename(filePath)}');
        return true;
      }
      return false;
    } catch (e) {
      print('Error deleting file: $e');
      return false;
    }
  }
}