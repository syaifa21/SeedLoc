import 'dart:io';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:path/path.dart' as path;
import 'package:image/image.dart' as img;

class ImageService {
  static final ImagePicker _picker = ImagePicker();

  static Future<String?> pickImage({
    required String geotagInfo, 
    required String customFileName, // NEW: Nama file yang diinginkan
    required String tempPath
  }) async {
    try {
      final XFile? image = await _picker.pickImage(source: ImageSource.camera);
      if (image != null) {
        final String rawImagePath = image.path;
        
        // Baca gambar dari file mentah
        final File rawFile = File(rawImagePath);
        img.Image? originalImage = img.decodeImage(await rawFile.readAsBytes());
        
        if (originalImage == null) {
            print('Error: Could not decode image from path: $rawImagePath');
            await rawFile.delete();
            return null;
        }

        // Meneruskan customFileName
        final String stampedImagePath = await _stampImageWithGeotagData(
          originalImage, 
          geotagInfo, 
          customFileName // Meneruskan nama file
        );
        
        // Hapus foto mentah
        await rawFile.delete();

        return stampedImagePath;
      }
    } catch (e) {
      print('Error picking image: $e');
    }
    return null;
  }
  
  static Future<String> _stampImageWithGeotagData(
    img.Image originalImage,
    String geotagInfo,
    String customFileName, // Nama file yang digunakan
  ) async {
    // Menggunakan font yang lebih besar dan jelas
    final img.BitmapFont font = img.arial14;

    // Warna yang lebih kontras: Putih dengan background hitam semi-transparan
    final img.Color fontColor = img.ColorRgba8(255, 255, 255, 255); // Putih solid
    final img.Color backgroundColor = img.ColorRgba8(0, 0, 0, 180); // Hitam lebih gelap

    final List<String> lines = geotagInfo.split('\n');
    final int lineHeight = font.lineHeight + 6; // Spacing lebih besar
    final int textHeight = lines.length * lineHeight + 16; // Padding lebih besar

    // Tentukan area untuk overlay text (di bagian bawah)
    final int startY = originalImage.height - textHeight;

    // Background rectangle untuk text
    img.fillRect(
      originalImage,
      x1: 0,
      y1: startY,
      x2: originalImage.width,
      y2: originalImage.height,
      color: backgroundColor
    );

    int currentY = startY + 12; // Padding atas lebih besar

    for (String line in lines) {
      // Draw text dengan warna putih solid
      img.drawString(
        originalImage,
        line,
        font: font,
        x: 12, // Padding kiri lebih besar
        y: currentY,
        color: fontColor,
      );

      currentY += lineHeight;
    }

    // Tentukan direktori penyimpanan final
    final Directory appDir = await getApplicationDocumentsDirectory();
    final String imagesDirPath = path.join(appDir.path, 'images');

    final Directory imagesDir = Directory(imagesDirPath);
    if (!await imagesDir.exists()) {
      await imagesDir.create(recursive: true);
    }

    // Menggunakan nama file custom
    final String fileName = '$customFileName.jpg';
    final String filePath = path.join(imagesDirPath, fileName);

    // KOMPRESI BERKUALITAS TINGGI: Quality 85 untuk balance ukuran dan kualitas
    // Jika gambar sangat besar (>5MB), gunakan quality 75
    final int originalSizeEstimate = originalImage.width * originalImage.height * 3; // RGB estimate
    final int quality = originalSizeEstimate > 5000000 ? 75 : 85; // Adaptive quality

    await File(filePath).writeAsBytes(img.encodeJpg(originalImage, quality: quality));

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