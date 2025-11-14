import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:geolocator/geolocator.dart';
import 'package:path_provider/path_provider.dart'; // Diperlukan untuk Hive Store
import '../services/location_service.dart';

// FIX KRITIS #1: Impor yang BENAR untuk CachedTileProvider (CacheStore, HiveCacheStore)
import 'package:flutter_map_cache/flutter_map_cache.dart'; 
import 'package:http_cache_hive_store/http_cache_hive_store.dart'; 


class MapScreen extends StatefulWidget {
  const MapScreen({super.key});

  @override
  State<MapScreen> createState() => _MapScreenState();
}

class _MapScreenState extends State<MapScreen> {
  final MapController _mapController = MapController();
  Position? _currentPosition;
  bool _isLoading = true;
  String _errorMessage = '';
  Timer? _locationUpdateTimer;
  
  // FIX KRITIS #2: Undefined class 'CacheStore' -> Diimpor di atas
  HiveCacheStore? _hiveCacheStore; 
  Future<void>? _initializationFuture;

  // Default location (Indonesia - Jakarta)
  LatLng _currentLocation = const LatLng(-6.2088, 106.8456);

  @override
  void initState() {
    super.initState();
    // Memastikan Hive diinisialisasi sebelum peta dibuat
    _initializationFuture = _initializeHiveCache();
    _getCurrentLocation();
    _startLocationUpdates();
  }

  // FIX KRITIS #3: The method 'build' isn't defined for the type 'HiveCacheStore'
  Future<void> _initializeHiveCache() async {
    try {
      final appDir = await getApplicationDocumentsDirectory(); 
      
      // Menggunakan HiveCacheStore.build() yang bersifat async. 
      // Jika ini tetap error, masalahnya 100% pada Dart Analyzer/Cache.
      _hiveCacheStore =  HiveCacheStore(
        appDir.path,
        hiveBoxName: 'SeedLocMapCache', 
      );
    } catch (e) {
      if (mounted) {
        setState(() {
          if (_errorMessage.isEmpty) {
             _errorMessage = 'Error saat inisialisasi Cache Peta: ${e.toString()}';
          }
        });
      }
      print('Error Inisialisasi Hive: $e');
    }
  }

  @override
  void dispose() {
    _locationUpdateTimer?.cancel();
    _mapController.dispose();
    super.dispose();
  }

  void _startLocationUpdates() {
    _locationUpdateTimer = Timer.periodic(const Duration(seconds: 5), (timer) {
      _getCurrentLocation();
    });
  }

  Future<void> _getCurrentLocation() async {
    try {
      Position position = await LocationService.getCurrentPosition();
      
      if (mounted) {
        setState(() {
          _currentPosition = position;
          _currentLocation = LatLng(position.latitude, position.longitude);
          _isLoading = false;
          _errorMessage = ''; 
        });

        _mapController.move(_currentLocation, 17.0);
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _isLoading = false;
          if (_errorMessage.isEmpty) {
             _errorMessage = 'Error Lokasi: ${e.toString()}';
          }
        });
      }
    }
  }

  void _centerOnCurrentLocation() {
    if (_currentPosition != null) {
      _mapController.move(_currentLocation, 17.0);
    }
  }

  // Widget Builder utama yang dibungkus FutureBuilder
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Peta Lokasi'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _getCurrentLocation,
            tooltip: 'Refresh Lokasi',
          ),
        ],
      ),
      body: FutureBuilder(
        future: _initializationFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          // Cek error atau jika store belum berhasil dibuat
          if (_hiveCacheStore == null || snapshot.hasError || _errorMessage.contains('Error saat inisialisasi Cache')) {
             return Center(
               child: Text('Gagal memuat peta: Cache Peta tidak dapat diinisialisasi. Error: ${_errorMessage.isNotEmpty ? _errorMessage : snapshot.error}',
                 textAlign: TextAlign.center,
                 style: const TextStyle(color: Colors.red),
               ),
             );
          }
          
          // Jika Cache Store sudah siap
          return _buildMapStack(context);
        },
      ),
    );
  }

  // Widget yang berisi Stack (peta dan overlay lainnya)
  Widget _buildMapStack(BuildContext context) {
    
    return Stack(
      children: [
        // OpenStreetMap
        FlutterMap(
          mapController: _mapController,
          options: MapOptions(
            initialCenter: _currentLocation,
            initialZoom: 17.0,
            minZoom: 5.0,
            maxZoom: 18.0,
            interactionOptions: const InteractionOptions(
              flags: InteractiveFlag.all,
            ),
          ),
          children: [
            // TileLayer menggunakan CachedTileProvider dengan Hive Store
            TileLayer(
              urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
              userAgentPackageName: 'com.example.seedloc',
              maxZoom: 19,
              subdomains: const ['a', 'b', 'c'],
              
              // FIX: Menggunakan CachedTileProvider (sudah benar setelah import)
              // Hapus parameter placeholder/errorWidget yang tidak didukung oleh versi ini
              tileProvider: CachedTileProvider(
                store: _hiveCacheStore!, // CacheStore dijamin ada karena sudah dicek di FutureBuilder
                maxStale: const Duration(days: 365), // Cache permanen (1 tahun)
              ),
            ),
            
            // Marker untuk lokasi terkini
            if (_currentPosition != null)
              MarkerLayer(
                markers: [
                  Marker(
                    point: _currentLocation,
                    width: 80,
                    height: 80,
                    child: Column(
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          decoration: BoxDecoration(
                            color: Colors.blue,
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: const Text(
                            'Anda di sini',
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 10,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                        const Icon(
                          Icons.location_on,
                          color: Colors.blue,
                          size: 40,
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            
            if (_currentPosition != null)
              CircleLayer(
                circles: [
                  CircleMarker(
                    point: _currentLocation,
                    radius: _currentPosition!.accuracy,
                    useRadiusInMeter: true,
                    color: Colors.blue.withOpacity(0.2),
                    borderColor: Colors.blue.withOpacity(0.5),
                    borderStrokeWidth: 2,
                  ),
                ],
              ),
          ],
        ),

        // Loading indicator
        if (_isLoading)
          Container(
            color: Colors.black54,
            child: const Center(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  CircularProgressIndicator(color: Colors.white),
                  SizedBox(height: 16),
                  Text(
                    'Memuat peta...',
                    style: TextStyle(color: Colors.white),
                  ),
                ],
              ),
            ),
          ),

        // Error message
        if (_errorMessage.isNotEmpty && !_errorMessage.contains('Error saat inisialisasi Cache'))
          Positioned(
            top: 16,
            left: 16,
            right: 16,
            child: Card(
              color: Colors.red.shade100,
              child: Padding(
                padding: const EdgeInsets.all(12.0),
                child: Row(
                  children: [
                    const Icon(Icons.error, color: Colors.red),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        _errorMessage,
                        style: const TextStyle(color: Colors.red),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),

        // Location info card
        if (_currentPosition != null)
            Positioned(
              bottom: 80,
              left: 16,
              right: 16,
              child: Card(
                elevation: 4,
                child: Padding(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.location_on, color: Colors.blue, size: 20),
                          const SizedBox(width: 8),
                          const Text(
                            'Lokasi Terkini',
                            style: TextStyle(
                              fontSize: 16,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          const Spacer(),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                            decoration: BoxDecoration(
                              color: Colors.green.shade100,
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: const Row(
                              children: [
                                Icon(Icons.circle, color: Colors.green, size: 8),
                                SizedBox(width: 4),
                                Text(
                                  'Live',
                                  style: TextStyle(
                                    fontSize: 10,
                                    fontWeight: FontWeight.bold,
                                    color: Colors.green,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      const Divider(),
                      Row(
                        children: [
                          const Icon(Icons.my_location, size: 16, color: Colors.grey),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              'Lat: ${_currentPosition!.latitude.toStringAsFixed(6)}',
                              style: const TextStyle(fontSize: 12),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Row(
                        children: [
                          const Icon(Icons.my_location, size: 16, color: Colors.grey),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              'Lng: ${_currentPosition!.longitude.toStringAsFixed(6)}',
                              style: const TextStyle(fontSize: 12),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Row(
                        children: [
                          const Icon(Icons.gps_fixed, size: 16, color: Colors.grey),
                          const SizedBox(width: 8),
                          Text(
                            'Akurasi: ${_currentPosition!.accuracy.toStringAsFixed(1)} meter',
                            style: const TextStyle(fontSize: 12),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
        
        // Offline indicator with cache status
        Positioned(
            top: 16,
            right: 16,
            child: Card(
              color: Colors.green.shade100,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.offline_bolt, color: Colors.green.shade700, size: 16),
                    const SizedBox(width: 4),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          'Offline Ready',
                          style: TextStyle(
                            color: Colors.green.shade700,
                            fontSize: 12,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        Text(
                          'Cache: Permanent',
                          style: TextStyle(
                            color: Colors.green.shade600,
                            fontSize: 10,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ),
        
        // Center on location button
        Positioned(
            bottom: 16,
            right: 16,
            child: FloatingActionButton(
              onPressed: _centerOnCurrentLocation,
              backgroundColor: Colors.blue,
              tooltip: 'Pusatkan ke Lokasi Saya',
              child: const Icon(Icons.my_location, color: Colors.white),
            ),
          ),
      ],
    );
  }
}