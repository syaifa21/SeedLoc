import 'dart:io';
import 'dart:typed_data';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:path/path.dart' as path;
import 'package:image/image.dart' as img;

class ImageService {
  static final ImagePicker _picker = ImagePicker();

  static Future<String?> pickImage({
    required String geotagInfo, 
    required String customFileName, 
    required String tempPath
  }) async {
    try {
      final XFile? image = await _picker.pickImage(source: ImageSource.camera);
      if (image != null) {
        final String rawImagePath = image.path;
        // Baca gambar dari file mentah
        final File rawFile = File(rawImagePath);
        final Uint8List bytes = await rawFile.readAsBytes();
        img.Image? originalImage = img.decodeImage(bytes);
        
        if (originalImage == null) {
            print('Error: Could not decode image from path: $rawImagePath');
            await rawFile.delete();
            return null;
        }

        // Proses Stamping
        final String stampedImagePath = await _stampImageWithGeotagData(
          originalImage, 
          geotagInfo, 
          customFileName 
        );
        
        // Hapus foto mentah asli untuk menghemat ruang
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
    String customFileName,
  ) async {
    // 1. RESIZE DULUAN (Solusi agar font terlihat besar)
    // Kita kecilkan kanvas gambar SEBELUM menempel teks. 
    // Dengan begitu, font arial48 akan terlihat jauh lebih besar relatif terhadap gambar.
    img.Image processedImage = originalImage;
    
    // Target resolusi Full HD (cukup detail tapi file hemat)
    const int targetWidth = 1920; 
    const int targetHeight = 1080;

    if (originalImage.width > targetWidth || originalImage.height > targetHeight) {
      processedImage = img.copyResize(
        originalImage, 
        width: originalImage.width > originalImage.height ? targetWidth : null, // Landscape
        height: originalImage.height > originalImage.width ? targetWidth : null, // Portrait (gunakan width sbg patokan max sisi)
        maintainAspect: true
      );
    }

    // 2. SIAPKAN FONT & WARNA
    // arial48 adalah font bitmap bawaan terbesar di library ini.
    // Karena gambar sudah di-resize ke ukuran standar layar HP/Monitor, font ini akan terbaca jelas.
    final img.BitmapFont font = img.arial48; 

    // Warna Putih Solid & Background Hitam Transparan
    final img.ColorUint8 fontColor = img.ColorUint8.rgba(255, 255, 255, 255); 
    final img.ColorUint8 backgroundColor = img.ColorUint8.rgba(0, 0, 0, 255); // Kita atur transparansi di fungsi fillRect nanti jika didukung, atau solid block

    final List<String> lines = geotagInfo.split('\n');
    final int lineHeight = font.lineHeight + 10; // Jarak antar baris lebih lega
    final int totalTextHeight = (lines.length * lineHeight) + 40; // Total tinggi kotak teks (+ padding)

    // 3. GAMBAR KOTAK HITAM DI BAWAH
    // Kita tempel di bagian paling bawah gambar
    final int startBoxY = processedImage.height - totalTextHeight;

    // Gambar kotak hitam semi-transparan (alpha 180 dari 255)
    // Catatan: img.fillRect di versi terbaru mendukung color int langsung
    // Untuk transparansi manual pixel-by-pixel agak lambat, kita pakai solid block atau library feature jika ada.
    // Di sini kita pakai solid block hitam agar tulisan kontras maksimal.
    img.fillRect(
      processedImage,
      x1: 0,
      y1: startBoxY,
      x2: processedImage.width,
      y2: processedImage.height,
      color: img.ColorUint8.rgba(0, 0, 0, 150) // Alpha 150 (transparan gelap)
    );

    // 4. TULIS TEKS
    int currentTextY = startBoxY + 20; // Padding atas teks dalam kotak

    for (String line in lines) {
      img.drawString(
        processedImage,
        line,
        font: font,
        x: 30, // Padding kiri (indent)
        y: currentTextY,
        color: fontColor,
      );
      currentTextY += lineHeight;
    }

    // 5. SIMPAN FILE
    final Directory appDir = await getApplicationDocumentsDirectory();
    final String imagesDirPath = path.join(appDir.path, 'images');

    final Directory imagesDir = Directory(imagesDirPath);
    if (!await imagesDir.exists()) {
      await imagesDir.create(recursive: true);
    }

    final String fileName = '$customFileName.jpg';
    final String filePath = path.join(imagesDirPath, fileName);

    // Simpan sebagai JPG dengan kualitas 85%
    await File(filePath).writeAsBytes(img.encodeJpg(processedImage, quality: 85));

    return filePath;
  }
  
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