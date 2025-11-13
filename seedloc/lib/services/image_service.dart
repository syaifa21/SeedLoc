import 'dart:io';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:path/path.dart' as path;
import 'package:image/image.dart' as img; // Import untuk manipulasi gambar

class ImageService {
  static final ImagePicker _picker = ImagePicker();

  static Future<String?> pickImage({required String geotagInfo, required String tempPath}) async {
    try {
      final XFile? image = await _picker.pickImage(source: ImageSource.camera);
      if (image != null) {
        final String rawImagePath = image.path;
        
        // --- NEW LOGIC: Ensure image is decoded/converted to avoid HEIC/HEIF issues ---
        // Baca gambar dari file mentah
        final File rawFile = File(rawImagePath);
        img.Image? originalImage = img.decodeImage(await rawFile.readAsBytes());
        
        if (originalImage == null) {
            print('Error: Could not decode image from path: $rawImagePath');
            // Hapus file mentah jika decoding gagal
            await rawFile.delete();
            return null;
        }

        // 1. Konversi dan Stamping Foto (Format akan menjadi JPEG saat disimpan)
        final String stampedImagePath = await _stampImageWithGeotagData(originalImage, geotagInfo, rawImagePath);
        
        // 2. Hapus foto mentah yang diambil kamera (XFile)
        await rawFile.delete();

        return stampedImagePath;
      }
    } catch (e) {
      print('Error picking image: $e');
    }
    return null;
  }
  
  // Mengubah parameter rawImagePath menjadi originalImage (sudah di-decode)
  static Future<String> _stampImageWithGeotagData(img.Image originalImage, String geotagInfo, String rawImagePathForLogging) async {
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
      // Menambahkan string watermark
      img.drawString(
        originalImage, 
        line,
        font: font,
        x: 10,
        y: currentY,
        color: fontColor,
      );

      currentY += lineHeight; // Pindah ke baris berikutnya
    }
    
    // Tentukan direktori penyimpanan final
    final Directory appDir = await getApplicationDocumentsDirectory();
    final String imagesDirPath = path.join(appDir.path, 'images');

    final Directory imagesDir = Directory(imagesDirPath);
    if (!await imagesDir.exists()) {
      await imagesDir.create(recursive: true);
    }
    
    // Generate unique filename dan simpan gambar yang sudah di-stamp dalam format JPEG
    final String fileName = 'STAMPED_${DateTime.now().millisecondsSinceEpoch}.jpg'; // EXTENSION .jpg DIPASTIKAN
    final String filePath = path.join(imagesDirPath, fileName);
    
    // Simpan dalam format JPEG
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