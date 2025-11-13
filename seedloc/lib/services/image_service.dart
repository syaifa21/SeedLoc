import 'dart:io';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:path/path.dart' as path;
import 'package:image/image.dart' as img; // Import untuk manipulasi gambar
import 'geotagging_service.dart';

class ImageService {
  static final ImagePicker _picker = ImagePicker();

  static Future<String?> pickImage({
    required String geotagInfo, 
    required String tempPath,
    double? latitude,
    double? longitude,
    double? altitude,
    double? accuracy,
    double? bearing,
  }) async {
    try {
      final XFile? image = await _picker.pickImage(source: ImageSource.camera);
      if (image != null) {
        final String rawImagePath = image.path;
        
        // Stamping Foto dengan text overlay
        final String stampedImagePath = await _stampImageWithGeotagData(rawImagePath, geotagInfo);
        
        // Embed EXIF GPS metadata jika koordinat tersedia
        if (latitude != null && longitude != null) {
          await GeotaggingService.embedGpsMetadata(
            imagePath: stampedImagePath,
            latitude: latitude,
            longitude: longitude,
            altitude: altitude,
            timestamp: DateTime.now(),
            accuracy: accuracy,
            bearing: bearing,
          );
        }
        
        // Hapus foto mentah yang diambil kamera (XFile)
        await File(rawImagePath).delete();

        return stampedImagePath;
      }
    } catch (e) {
      print('Error picking image: $e');
    }
    return null;
  }
  
  static Future<String> _stampImageWithGeotagData(String rawImagePath, String geotagInfo) async {
    // Baca gambar dari file mentah
    final File rawFile = File(rawImagePath);
    img.Image? originalImage = img.decodeImage(await rawFile.readAsBytes());
    
    if (originalImage == null) {
      return rawImagePath;
    }
    
    // Menggunakan font yang paling dasar
    final img.BitmapFont font = img.arial14;
    
    // Menggunakan img.ColorRgba8 untuk warna
    final img.Color fontColor = img.ColorRgba8(255, 255, 0, 150); // Kuning
    final img.Color backgroundColor = img.ColorRgba8(0, 0, 0, 128); // Hitam transparan
    
    final List<String> lines = geotagInfo.split('\n');
    final int lineHeight = font.lineHeight + 4; 
    final int textHeight = lines.length * lineHeight + 8;

    // Tentukan area untuk overlay text (di bagian bawah)
    final int startY = originalImage.height - textHeight;

    // Koreksi fillRect: Menggunakan named arguments
    img.fillRect(
      originalImage, 
      x1: 0, 
      y1: startY, 
      x2: originalImage.width, 
      y2: originalImage.height, 
      color: backgroundColor 
    );

    int currentY = startY + 8; // Padding atas
    
    for (String line in lines) {
      // --- KOREKSI drawString FINAL ---
      // Arg 1 (Positional): Image
      // Arg 2 (Positional): String (text)
      // Arg named: font, x, y, color
      img.drawString(
        originalImage, 
        line, // Positional Arg 2: String text
        font: font, // Named Arg: required BitmapFont
        x: 10, // Named Arg: int? x
        y: currentY, // Named Arg: int? y
        color: fontColor, // Named Arg: Color? color
      );
      // --- AKHIR KOREKSI ---

      currentY += lineHeight; // Pindah ke baris berikutnya
    }
    
    // Tentukan direktori penyimpanan final
    final Directory appDir = await getApplicationDocumentsDirectory();
    final String imagesDirPath = path.join(appDir.path, 'images');

    final Directory imagesDir = Directory(imagesDirPath);
    if (!await imagesDir.exists()) {
      await imagesDir.create(recursive: true);
    }
    
    // Generate unique filename dan simpan gambar yang sudah di-stamp
    final String fileName = 'STAMPED_${DateTime.now().millisecondsSinceEpoch}.jpg';
    final String filePath = path.join(imagesDirPath, fileName);
    
    await File(filePath).writeAsBytes(img.encodeJpg(originalImage, quality: 90));

    return filePath;
  }
  
  // Fungsi untuk menghapus gambar
  static Future<void> deleteImage(String imagePath) async {
    try {
      final File imageFile = File(imagePath);
      if (await imageFile.exists()) {
        await imageFile.delete();
      }
    } catch (e) {
      print('Error deleting image: $e');
    }
  }
}