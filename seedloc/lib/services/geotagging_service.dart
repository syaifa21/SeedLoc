import 'dart:io';
// ignore: depend_on_referenced_packages
import 'package:image/image.dart' as img;

class GeotaggingService {
  /// Embed EXIF GPS metadata ke foto
  /// 
  /// Parameters:
  /// - imagePath: Path ke file foto yang akan di-embed metadata
  /// - latitude: Koordinat latitude
  /// - longitude: Koordinat longitude
  /// - altitude: Ketinggian (opsional)
  /// - timestamp: Waktu pengambilan foto
  /// - accuracy: Akurasi GPS (opsional)
  /// - bearing: Arah kompas (opsional)
  /// 
  /// Returns: Path ke file foto yang sudah di-embed metadata
  static Future<String> embedGpsMetadata({
    required String imagePath,
    required double latitude,
    required double longitude,
    double? altitude,
    required DateTime timestamp,
    double? accuracy,
    double? bearing,
  }) async {
    try {
      // Baca file foto
      final File imageFile = File(imagePath);
      final img.Image? image = img.decodeImage(await imageFile.readAsBytes());
      
      if (image == null) {
        print('Failed to decode image');
        return imagePath;
      }

      // Konversi koordinat ke format EXIF
      final gpsData = _convertToExifGps(latitude, longitude, altitude);
      
      // Set EXIF data
      image.exif.imageIfd.clear();
      
      // GPS IFD (GPS Info)
      final gpsIfd = img.IfdContainer();
      
      // GPS Version (2.2.0.0)
      gpsIfd[img.ExifTag.gpsVersionId] = img.IfdValueBytes([2, 2, 0, 0]);
      
      // Latitude
      gpsIfd[img.ExifTag.gpsLatitudeRef] = img.IfdValueAscii(gpsData['latRef']!);
      gpsIfd[img.ExifTag.gpsLatitude] = img.IfdValueRational([
        img.Rational(gpsData['latDeg']!, 1),
        img.Rational(gpsData['latMin']!, 1),
        img.Rational((gpsData['latSec']! * 1000000).toInt(), 1000000),
      ]);
      
      // Longitude
      gpsIfd[img.ExifTag.gpsLongitudeRef] = img.IfdValueAscii(gpsData['lonRef']!);
      gpsIfd[img.ExifTag.gpsLongitude] = img.IfdValueRational([
        img.Rational(gpsData['lonDeg']!, 1),
        img.Rational(gpsData['lonMin']!, 1),
        img.Rational((gpsData['lonSec']! * 1000000).toInt(), 1000000),
      ]);
      
      // Altitude (jika ada)
      if (altitude != null) {
        gpsIfd[img.ExifTag.gpsAltitudeRef] = img.IfdValueByte(altitude >= 0 ? 0 : 1);
        gpsIfd[img.ExifTag.gpsAltitude] = img.IfdValueRational([
          img.Rational((altitude.abs() * 100).toInt(), 100),
        ]);
      }
      
      // Timestamp
      final dateStr = '${timestamp.year}:${timestamp.month.toString().padLeft(2, '0')}:${timestamp.day.toString().padLeft(2, '0')}';
      final timeStr = '${timestamp.hour.toString().padLeft(2, '0')}:${timestamp.minute.toString().padLeft(2, '0')}:${timestamp.second.toString().padLeft(2, '0')}';
      
      gpsIfd[img.ExifTag.gpsDateStamp] = img.IfdValueAscii(dateStr);
      gpsIfd[img.ExifTag.gpsTimeStamp] = img.IfdValueRational([
        img.Rational(timestamp.hour, 1),
        img.Rational(timestamp.minute, 1),
        img.Rational(timestamp.second, 1),
      ]);
      
      // Bearing (arah kompas) jika ada
      if (bearing != null) {
        gpsIfd[img.ExifTag.gpsImgDirectionRef] = img.IfdValueAscii('T'); // True North
        gpsIfd[img.ExifTag.gpsImgDirection] = img.IfdValueRational([
          img.Rational((bearing * 100).toInt(), 100),
        ]);
      }
      
      // DOP (Dilution of Precision) - gunakan accuracy sebagai estimasi
      if (accuracy != null) {
        // HDOP = accuracy / 5 (estimasi kasar)
        final hdop = (accuracy / 5.0).clamp(0.0, 99.9);
        gpsIfd[img.ExifTag.gpsHPositioningError] = img.IfdValueRational([
          img.Rational((accuracy * 100).toInt(), 100),
        ]);
      }
      
      // Processing Method
      gpsIfd[img.ExifTag.gpsProcessingMethod] = img.IfdValueAscii('GPS');
      
      // Set GPS IFD ke image
      image.exif.gpsIfd = gpsIfd;
      
      // Set DateTime di Image IFD
      image.exif.imageIfd[img.ExifTag.dateTime] = img.IfdValueAscii('$dateStr $timeStr');
      
      // Set Software/Make info
      image.exif.imageIfd[img.ExifTag.software] = img.IfdValueAscii('SeedLoc App');
      image.exif.imageIfd[img.ExifTag.make] = img.IfdValueAscii('SeedLoc');
      image.exif.imageIfd[img.ExifTag.model] = img.IfdValueAscii('Geotagging Camera');
      
      // Encode kembali dengan EXIF
      final List<int> encodedImage = img.encodeJpg(image, quality: 90);
      
      // Simpan kembali ke file yang sama
      await imageFile.writeAsBytes(encodedImage);
      
      print('✅ EXIF GPS metadata embedded successfully');
      print('   Lat: $latitude, Lon: $longitude');
      if (altitude != null) print('   Alt: $altitude m');
      if (accuracy != null) print('   Accuracy: $accuracy m');
      
      return imagePath;
    } catch (e) {
      print('❌ Error embedding GPS metadata: $e');
      return imagePath; // Return original path jika gagal
    }
  }
  
  /// Konversi koordinat desimal ke format EXIF (Degrees, Minutes, Seconds)
  static Map<String, dynamic> _convertToExifGps(double latitude, double longitude, double? altitude) {
    // Latitude
    final latRef = latitude >= 0 ? 'N' : 'S';
    final latAbs = latitude.abs();
    final latDeg = latAbs.floor();
    final latMinDecimal = (latAbs - latDeg) * 60;
    final latMin = latMinDecimal.floor();
    final latSec = (latMinDecimal - latMin) * 60;
    
    // Longitude
    final lonRef = longitude >= 0 ? 'E' : 'W';
    final lonAbs = longitude.abs();
    final lonDeg = lonAbs.floor();
    final lonMinDecimal = (lonAbs - lonDeg) * 60;
    final lonMin = lonMinDecimal.floor();
    final lonSec = (lonMinDecimal - lonMin) * 60;
    
    return {
      'latRef': latRef,
      'latDeg': latDeg,
      'latMin': latMin,
      'latSec': latSec,
      'lonRef': lonRef,
      'lonDeg': lonDeg,
      'lonMin': lonMin,
      'lonSec': lonSec,
    };
  }
  
  /// Verifikasi apakah foto memiliki GPS metadata
  static Future<bool> hasGpsMetadata(String imagePath) async {
    try {
      final File imageFile = File(imagePath);
      final img.Image? image = img.decodeImage(await imageFile.readAsBytes());
      
      if (image == null) return false;
      
      final gpsIfd = image.exif.gpsIfd;
      return gpsIfd.containsKey(img.ExifTag.gpsLatitude) && 
             gpsIfd.containsKey(img.ExifTag.gpsLongitude);
    } catch (e) {
      print('Error checking GPS metadata: $e');
      return false;
    }
  }
  
  /// Baca GPS metadata dari foto
  static Future<Map<String, dynamic>?> readGpsMetadata(String imagePath) async {
    try {
      final File imageFile = File(imagePath);
      final img.Image? image = img.decodeImage(await imageFile.readAsBytes());
      
      if (image == null) return null;
      
      final gpsIfd = image.exif.gpsIfd;
      
      if (!gpsIfd.containsKey(img.ExifTag.gpsLatitude) || 
          !gpsIfd.containsKey(img.ExifTag.gpsLongitude)) {
        return null;
      }
      
      // Parse latitude
      final latRef = gpsIfd[img.ExifTag.gpsLatitudeRef]?.toString() ?? 'N';
      final latRationals = gpsIfd[img.ExifTag.gpsLatitude] as img.IfdValueRational?;
      
      // Parse longitude
      final lonRef = gpsIfd[img.ExifTag.gpsLongitudeRef]?.toString() ?? 'E';
      final lonRationals = gpsIfd[img.ExifTag.gpsLongitude] as img.IfdValueRational?;
      
      if (latRationals == null || lonRationals == null) return null;
      
      // Convert to decimal
      final latitude = _rationalToDecimal(latRationals.rationals, latRef);
      final longitude = _rationalToDecimal(lonRationals.rationals, lonRef);
      
      return {
        'latitude': latitude,
        'longitude': longitude,
      };
    } catch (e) {
      print('Error reading GPS metadata: $e');
      return null;
    }
  }
  
  static double _rationalToDecimal(List<img.Rational> rationals, String ref) {
    if (rationals.length < 3) return 0.0;
    
    final degrees = rationals[0].toDouble();
    final minutes = rationals[1].toDouble();
    final seconds = rationals[2].toDouble();
    
    double decimal = degrees + (minutes / 60.0) + (seconds / 3600.0);
    
    if (ref == 'S' || ref == 'W') {
      decimal = -decimal;
    }
    
    return decimal;
  }
}
