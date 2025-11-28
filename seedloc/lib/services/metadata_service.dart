import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class MetadataService {
  // Pastikan URL mengarah ke endpoint 'meta'
  static const String _baseUrl = 'https://seedloc.my.id/api/index.php?path=meta';
  
  // Masukkan API KEY yang SAMA dengan di PHP
  static const Map<String, String> _headers = {
    'X-API-KEY': 'SeedLoc_Secret_Key_2025_Secure',
    'Accept': 'application/json',
  };

  static const String _keyTreeTypes = 'meta_tree_types';
  static const String _keyLocations = 'meta_locations';

  // 1. Fetch dari API & Simpan ke Lokal
  static Future<void> fetchAndSaveMetadata() async {
    try {
      print("Fetching metadata from: $_baseUrl");
      // Menggunakan Header API Key
      final response = await http.get(Uri.parse(_baseUrl), headers: _headers);
      
      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true) {
          final prefs = await SharedPreferences.getInstance();
          
          List<String> treeTypes = List<String>.from(data['data']['treeTypes']);
          List<String> locations = List<String>.from(data['data']['locations']);

          await prefs.setStringList(_keyTreeTypes, treeTypes);
          await prefs.setStringList(_keyLocations, locations);
          print("Metadata updated successfully: ${treeTypes.length} trees, ${locations.length} locations");
        }
      } else {
        print("Failed to fetch metadata. Status: ${response.statusCode}");
      }
    } catch (e) {
      print("Failed to fetch metadata (Offline?): $e");
    }
  }

  // 2. Ambil List Pohon (Lokal)
  static Future<List<String>> getTreeTypes() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getStringList(_keyTreeTypes) ?? ['Saninten (Castanopsis argentea)', 'Lainnya'];
  }

  // 3. Ambil List Lokasi (Lokal)
  static Future<List<String>> getLocations() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getStringList(_keyLocations) ?? ['Sayangkaak', 'Lainnya'];
  }
}