import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:geolocator/geolocator.dart';
import 'package:path_provider/path_provider.dart';
import 'package:flutter_map_cache/flutter_map_cache.dart';
import 'package:http_cache_hive_store/http_cache_hive_store.dart';
import '../services/location_service.dart';

class MapScreen extends StatefulWidget {
  const MapScreen({super.key});

  @override
  State<MapScreen> createState() => _MapScreenState();
}

class _MapScreenState extends State<MapScreen> {
  final MapController _mapController = MapController();
  
  // Posisi Default (Jakarta) - hanya placeholder jika GPS mati total
  LatLng _currentLocation = const LatLng(-6.2088, 106.8456);
  double _currentAccuracy = 0;
  
  bool _isFirstLoad = true; // Status loading awal
  String _errorMessage = '';
  
  // Stream Subscription pengganti Timer
  StreamSubscription<Position>? _positionStream;
  
  HiveCacheStore? _hiveCacheStore; 
  Future<void>? _initializationFuture;

  @override
  void initState() {
    super.initState();
    _initializationFuture = _initializeHiveCache();
    _initLocationService();
  }

  // 1. Inisialisasi Cache Peta
  Future<void> _initializeHiveCache() async {
    try {
      final appDir = await getApplicationDocumentsDirectory(); 
      _hiveCacheStore = HiveCacheStore(
        appDir.path,
        hiveBoxName: 'SeedLocMapCache', 
      );
    } catch (e) {
      debugPrint('Error Hive Cache: $e');
    }
  }

  // 2. Inisialisasi Lokasi (Cepat & Akurat)
  void _initLocationService() async {
    // A. Ambil Last Known Position (Instan, agar user tidak menunggu)
    final lastKnown = await LocationService.getLastKnownPosition();
    if (lastKnown != null && mounted) {
      setState(() {
        _currentLocation = LatLng(lastKnown.latitude, lastKnown.longitude);
        _currentAccuracy = lastKnown.accuracy;
        _isFirstLoad = false; // Matikan loading segera
      });
      // Pindahkan kamera peta ke lokasi terakhir
      _mapController.move(_currentLocation, 16.0);
    }

    // B. Mulai Listen Stream (Real-time update)
    _positionStream = LocationService.getPositionStream().listen(
      (Position position) {
        if (!mounted) return;
        
        setState(() {
          _currentLocation = LatLng(position.latitude, position.longitude);
          _currentAccuracy = position.accuracy;
          
          // Jika ini update pertama dan kita masih status loading, matikan loading
          if (_isFirstLoad) {
            _isFirstLoad = false;
            _mapController.move(_currentLocation, 17.0);
          }
        });
      },
      onError: (e) {
        if (mounted) {
          setState(() {
            _errorMessage = 'Gagal mengambil lokasi GPS: $e';
            _isFirstLoad = false; // Stop loading walau error
          });
        }
      },
    );
  }

  @override
  void dispose() {
    _positionStream?.cancel(); // Wajib cancel stream
    _mapController.dispose();
    super.dispose();
  }

  void _centerOnCurrentLocation() {
    _mapController.move(_currentLocation, 17.0);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Peta Lokasi'),
        actions: [
          IconButton(
            icon: const Icon(Icons.my_location),
            onPressed: _centerOnCurrentLocation,
            tooltip: 'Pusatkan Lokasi',
          ),
        ],
      ),
      body: FutureBuilder(
        future: _initializationFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting && _hiveCacheStore == null) {
            return const Center(child: CircularProgressIndicator());
          }

          return Stack(
            children: [
              // 1. PETA UTAMA
              FlutterMap(
                mapController: _mapController,
                options: MapOptions(
                  initialCenter: _currentLocation,
                  initialZoom: 15.0,
                  maxZoom: 18.0,
                  minZoom: 5.0,
                  interactionOptions: const InteractionOptions(
                    flags: InteractiveFlag.all & ~InteractiveFlag.rotate, // Disable putar peta
                  ),
                ),
                children: [
                  // Layer Tile (Peta Gambar)
                  TileLayer(
                    urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                    userAgentPackageName: 'com.example.seedloc',
                    tileProvider: _hiveCacheStore != null 
                        ? CachedTileProvider(
                            store: _hiveCacheStore!,
                            maxStale: const Duration(days: 365), // Cache 1 tahun
                          )
                        : null, // Fallback jika cache error
                  ),

                  // Layer Lingkaran Akurasi (Biru Transparan)
                  if (!_isFirstLoad)
                    CircleLayer(
                      circles: [
                        CircleMarker(
                          point: _currentLocation,
                          radius: _currentAccuracy, // Radius sesuai akurasi GPS
                          useRadiusInMeter: true,
                          color: Colors.blue.withOpacity(0.15),
                          borderColor: Colors.blue.withOpacity(0.4),
                          borderStrokeWidth: 1,
                        ),
                      ],
                    ),

                  // Layer Marker (Icon Orang/Pin)
                  if (!_isFirstLoad)
                    MarkerLayer(
                      markers: [
                        Marker(
                          point: _currentLocation,
                          width: 60,
                          height: 60,
                          child: const Icon(
                            Icons.location_on, 
                            color: Colors.red, 
                            size: 40,
                            shadows: [Shadow(blurRadius: 10, color: Colors.black45)],
                          ),
                        ),
                      ],
                    ),
                ],
              ),

              // 2. LOADING INDICATOR (Hanya jika belum dapat lokasi sama sekali)
              if (_isFirstLoad)
                Container(
                  color: Colors.white.withOpacity(0.8),
                  child: const Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        CircularProgressIndicator(),
                        SizedBox(height: 10),
                        Text("Mencari sinyal GPS...", style: TextStyle(fontWeight: FontWeight.bold)),
                      ],
                    ),
                  ),
                ),

              // 3. ERROR MESSAGE
              if (_errorMessage.isNotEmpty)
                Positioned(
                  top: 20, left: 20, right: 20,
                  child: Card(
                    color: Colors.red.shade50,
                    child: Padding(
                      padding: const EdgeInsets.all(10),
                      child: Text(_errorMessage, style: const TextStyle(color: Colors.red)),
                    ),
                  ),
                ),

              // 4. INFO PANEL (Floating di bawah)
              Positioned(
                bottom: 20, left: 16, right: 16,
                child: Card(
                  elevation: 6,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    child: Row(
                      children: [
                        const Icon(Icons.satellite_alt, color: Colors.green),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                "Lat: ${_currentLocation.latitude.toStringAsFixed(6)}",
                                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                              ),
                              Text(
                                "Lng: ${_currentLocation.longitude.toStringAsFixed(6)}",
                                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                              ),
                            ],
                          ),
                        ),
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.end,
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Text("Akurasi", style: TextStyle(fontSize: 10, color: Colors.grey)),
                            Text(
                              "${_currentAccuracy.toStringAsFixed(1)} m", 
                              style: TextStyle(
                                fontSize: 14, 
                                fontWeight: FontWeight.bold,
                                color: _currentAccuracy <= 5 ? Colors.green : Colors.orange,
                              ),
                            ),
                          ],
                        )
                      ],
                    ),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}