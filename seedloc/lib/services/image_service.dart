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
    // Font yang lebih besar untuk visibilitas
    final img.BitmapFont font = img.arial24; // Naik dari arial14 ke arial24

    // Warna yang lebih kontras: Putih dengan background hitam solid
    final img.Color fontColor = img.ColorRgba8(255, 255, 255, 255); // Putih solid
    final img.Color backgroundColor = img.ColorRgba8(0, 0, 0, 220); // Hitam lebih gelap

    final List<String> lines = geotagInfo.split('\n');
    final int lineHeight = font.lineHeight + 8; // Spacing lebih besar
    final int textHeight = lines.length * lineHeight + 24; // Padding lebih besar

    // STAMPING 1/4 BAGIAN BAWAH FOTO (lebih besar dari sebelumnya)
    final int startY = originalImage.height - (originalImage.height ~/ 4); // 1/4 bagian bawah

    // Background rectangle untuk seluruh area stamping
    img.fillRect(
      originalImage,
      x1: 0,
      y1: startY,
      x2: originalImage.width,
      y2: originalImage.height,
      color: backgroundColor
    );

    int currentY = startY + 16; // Padding atas lebih besar

    for (String line in lines) {
      // Draw text dengan warna putih solid dan font besar
      img.drawString(
        originalImage,
        line,
        font: font,
        x: 16, // Padding kiri lebih besar
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

    // TAMBAHAN: Resize gambar jika terlalu besar untuk mengurangi ukuran file
    img.Image processedImage = originalImage;
    if (originalImage.width > 1920 || originalImage.height > 1080) {
      // Resize ke maksimal 1920x1080 sambil mempertahankan aspect ratio
      processedImage = img.copyResize(originalImage, width: 1920, height: 1080, maintainAspect: true);
    }

    await File(filePath).writeAsBytes(img.encodeJpg(processedImage, quality: quality));

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