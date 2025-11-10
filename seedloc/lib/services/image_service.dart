import 'dart:io';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:path/path.dart' as path;

class ImageService {
  static final ImagePicker _picker = ImagePicker();

  static Future<String?> pickImage() async {
    try {
      final XFile? image = await _picker.pickImage(source: ImageSource.camera);
      if (image != null) {
        return await _saveImageToAppDirectory(image);
      }
    } catch (e) {
      print('Error picking image: $e');
    }
    return null;
  }

  static Future<String> _saveImageToAppDirectory(XFile image) async {
    final Directory appDir = await getApplicationDocumentsDirectory();
    final String imagesDirPath = path.join(appDir.path, 'images');

    // Create images directory if it doesn't exist
    final Directory imagesDir = Directory(imagesDirPath);
    if (!await imagesDir.exists()) {
      await imagesDir.create(recursive: true);
    }

    // Generate unique filename
    final String fileName = '${DateTime.now().millisecondsSinceEpoch}.jpg';
    final String filePath = path.join(imagesDirPath, fileName);

    // Copy image to app directory
    await image.saveTo(filePath);

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
