import 'dart:io';
// HANYA menggunakan satu impor dengan alias 'as img'
import 'package:image/image.dart' as img; 

class GeotaggingService {
  /// Embed EXIF GPS metadata ke foto
  // ... (Dokumentasi dihilangkan untuk brevity)
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
      
      // GPS IFD (GPS Info) - Menggunakan img.ExifData
      final img.ExifData gpsIfd = img.ExifData();
      
      // GPS Version (2.2.0.0) -> Tag: 0x0000 (GpsVersionID)
      // FIX: Semua penggunaan Rational, ExifValue, getExifTagId diprefiks dengan img.
      gpsIfd[img.getExifTagId('GpsVersionID')] = img.ExifValue.fromBytes([2, 2, 0, 0]);
      
      // Latitude -> Tag: 0x0002 (GPSLatitude), 0x0001 (GPSLatitudeRef)
      gpsIfd[img.getExifTagId('GPSLatitudeRef')] = img.ExifValue.fromAscii(gpsData['latRef']!);
      gpsIfd[img.getExifTagId('GPSLatitude')] = img.ExifValue.fromRationalList([
        img.Rational(gpsData['latDeg']!, 1),
        img.Rational(gpsData['latMin']!, 1),
        img.Rational((gpsData['latSec']! * 1000000).toInt(), 1000000),
      ]);
      
      // Longitude -> Tag: 0x0004 (GPSLongitude), 0x0003 (GPSLongitudeRef)
      gpsIfd[img.getExifTagId('GPSLongitudeRef')] = img.ExifValue.fromAscii(gpsData['lonRef']!);
      gpsIfd[img.getExifTagId('GPSLongitude')] = img.ExifValue.fromRationalList([
        img.Rational(gpsData['lonDeg']!, 1),
        img.Rational(gpsData['lonMin']!, 1),
        img.Rational((gpsData['lonSec']! * 1000000).toInt(), 1000000),
      ]);
      
      // Altitude (jika ada) -> Tag: 0x0006 (GPSAltitude), 0x0005 (GPSAltitudeRef)
      if (altitude != null) {
        gpsIfd[img.getExifTagId('GPSAltitudeRef')] = img.ExifValue.fromByte(altitude >= 0 ? 0 : 1);
        gpsIfd[img.getExifTagId('GPSAltitude')] = img.ExifValue.fromRationalList([
          img.Rational((altitude.abs() * 100).toInt(), 100),
        ]);
      }
      
      // Timestamp -> Tag: 0x001D (GPSDateStamp), 0x0007 (GPSTimeStamp)
      final dateStr = '${timestamp.year}:${timestamp.month.toString().padLeft(2, '0')}:${timestamp.day.toString().padLeft(2, '0')}';
      final timeStr = '${timestamp.hour.toString().padLeft(2, '0')}:${timestamp.minute.toString().padLeft(2, '0')}:${timestamp.second.toString().padLeft(2, '0')}';
      
      gpsIfd[img.getExifTagId('GPSDateStamp')] = img.ExifValue.fromAscii(dateStr);
      gpsIfd[img.getExifTagId('GPSTimeStamp')] = img.ExifValue.fromRationalList([
        img.Rational(timestamp.hour, 1),
        img.Rational(timestamp.minute, 1),
        img.Rational(timestamp.second, 1),
      ]);
      
      // Bearing (arah kompas) jika ada -> Tag: 0x0011 (GPSImgDirection), 0x0010 (GPSImgDirectionRef)
      if (bearing != null) {
        gpsIfd[img.getExifTagId('GPSImgDirectionRef')] = img.ExifValue.fromAscii('T'); // True North
        gpsIfd[img.getExifTagId('GPSImgDirection')] = img.ExifValue.fromRationalList([
          img.Rational((bearing * 100).toInt(), 100),
        ]);
      }
      
      // DOP (Dilution of Precision) - gunakan accuracy sebagai estimasi -> Tag: 0x001B (GPSHPositioningError)
      if (accuracy != null) {
        gpsIfd[img.getExifTagId('GPSHPositioningError')] = img.ExifValue.fromRationalList([
          img.Rational((accuracy * 100).toInt(), 100),
        ]);
      }
      
      // Processing Method -> Tag: 0x001B (GPSProcessingMethod)
      gpsIfd[img.getExifTagId('GPSProcessingMethod')] = img.ExifValue.fromAscii('GPS');
      
      // Set GPS IFD ke image di main IFD (Tag 0x8825 - GPSInfo)
      // FIX: mainIfd, getExifTagId & ExifValue diprefiks
      image.exif.mainIfd[img.getExifTagId('GPSInfo')] = img.ExifValue.fromExifData(gpsIfd);
      
      // Set DateTime di Image IFD (Main IFD)
      image.exif.mainIfd[img.getExifTagId('DateTime')] = img.ExifValue.fromAscii('$dateStr $timeStr');
      
      // Set Software/Make info
      image.exif.mainIfd[img.getExifTagId('Software')] = img.ExifValue.fromAscii('SeedLoc App');
      image.exif.mainIfd[img.getExifTagId('Make')] = img.ExifValue.fromAscii('SeedLoc');
      image.exif.mainIfd[img.getExifTagId('Model')] = img.ExifValue.fromAscii('Geotagging Camera');
      
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
      
      // Ambil GPS IFD dari main IFD (Tag 0x8825 - GPSInfo)
      // FIX: getExifTagId diprefiks
      final gpsIfdValue = image.exif.mainIfd[img.getExifTagId('GPSInfo')];
      // FIX: img.ExifData
      final img.ExifData? gpsIfd = gpsIfdValue?.toExifData();
      
      if (gpsIfd == null) return false;

      // Cek ketersediaan GPS Latitude (Tag 0x0002) dan GPS Longitude (Tag 0x0004)
      return gpsIfd.containsKey(img.getExifTagId('GPSLatitude')) && 
             gpsIfd.containsKey(img.getExifTagId('GPSLongitude'));
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
      
      // Ambil GPS IFD dari main IFD (Tag 0x8825 - GPSInfo)
      final gpsIfdValue = image.exif.mainIfd[img.getExifTagId('GPSInfo')];
      final img.ExifData? gpsIfd = gpsIfdValue?.toExifData();

      if (gpsIfd == null || 
          !gpsIfd.containsKey(img.getExifTagId('GPSLatitude')) || 
          !gpsIfd.containsKey(img.getExifTagId('GPSLongitude'))) {
        return null;
      }
      
      // Parse latitude (Tag 0x0001: GPSLatitudeRef, Tag 0x0002: GPSLatitude)
      final latRefValue = gpsIfd[img.getExifTagId('GPSLatitudeRef')];
      final latRef = latRefValue?.toString() ?? 'N';
      final latRationals = gpsIfd[img.getExifTagId('GPSLatitude')];
      
      // Parse longitude (Tag 0x0003: GPSLongitudeRef, Tag 0x0004: GPSLongitude)
      final lonRefValue = gpsIfd[img.getExifTagId('GPSLongitudeRef')];
      final lonRef = lonRefValue?.toString() ?? 'E';
      final lonRationals = gpsIfd[img.getExifTagId('GPSLongitude')];
      
      // Konversi ke List<Rational> 
      // FIX: Gunakan img.Rational di type definition
      // toRationalList() pada IfdValueRational tersedia di versi 4.x
      final List<img.Rational>? latList = latRationals?.toRationalList();
      final List<img.Rational>? lonList = lonRationals?.toRationalList();
      
      if (latList == null || lonList == null) return null;
      
      // Convert to decimal
      final latitude = _rationalToDecimal(latList, latRef);
      final longitude = _rationalToDecimal(lonList, lonRef);
      
      return {
        'latitude': latitude,
        'longitude': longitude,
      };
    } catch (e) {
      print('Error reading GPS metadata: $e');
      return null;
    }
  }
  
  // Memperbaiki type hint List<img.Rational> dan logika pembacaan
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