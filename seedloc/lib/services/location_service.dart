import 'dart:async';
import 'dart:math';
import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

class LocationService {
  // Variable static untuk tracking
  static StreamSubscription<Position>? _positionStreamSubscription;
  static KalmanFilterPosition? _kalmanFilter;

  // --- 1. SETTINGAN GPS (Dioptimalkan) ---
  static LocationSettings _getSettings({bool highAccuracy = true}) {
    if (defaultTargetPlatform == TargetPlatform.android) {
      return AndroidSettings(
        accuracy: highAccuracy ? LocationAccuracy.bestForNavigation : LocationAccuracy.high,
        distanceFilter: highAccuracy ? 0 : 5, // 0 = Ambil semua data (untuk field data), 5 = Hemat (untuk peta)
        forceLocationManager: true, // Paksa Hardware GPS
        intervalDuration: const Duration(milliseconds: 500), 
      );
    } else if (defaultTargetPlatform == TargetPlatform.iOS) {
      return AppleSettings(
        accuracy: highAccuracy ? LocationAccuracy.bestForNavigation : LocationAccuracy.high,
        activityType: ActivityType.fitness,
        distanceFilter: highAccuracy ? 0 : 5,
        pauseLocationUpdatesAutomatically: false,
        showBackgroundLocationIndicator: highAccuracy,
      );
    } else {
      return LocationSettings(
        accuracy: highAccuracy ? LocationAccuracy.bestForNavigation : LocationAccuracy.high,
        distanceFilter: highAccuracy ? 0 : 5,
      );
    }
  }

  // --- 2. STREAM METHODS (FITUR BARU) ---

  // Dipakai oleh FieldDataScreen (Data mentah, cepat, akurasi tinggi)
  static Stream<Position> getHighAccuracyStream() {
    return Geolocator.getPositionStream(
      locationSettings: _getSettings(highAccuracy: true)
    );
  }

  // Dipakai oleh MapScreen (Lebih hemat baterai)
  static Stream<Position> getPositionStream() {
    return Geolocator.getPositionStream(
      locationSettings: _getSettings(highAccuracy: false)
    );
  }

  // --- 3. ONE-SHOT METHODS (LEGACY/COMPATIBILITY) ---
  // Wajib ada agar 'map_screen.dart' lama & 'field_data_screen.dart' lama tidak error

  static Future<Position> getCurrentPosition() async {
    bool serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) {
      return Future.error('Layanan Lokasi (GPS) mati. Mohon hidupkan GPS.');
    }

    LocationPermission permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied) {
        return Future.error('Izin lokasi ditolak.');
      }
    }

    if (permission == LocationPermission.deniedForever) {
      return Future.error('Izin lokasi ditolak permanen.');
    }

    // Gunakan timeout agar tidak loading selamanya (15 detik)
    try {
      return await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.best, // Tetap 'Best' agar kompatibel
        timeLimit: const Duration(seconds: 15),
      );
    } catch (e) {
      // Fallback ke lokasi terakhir jika gagal fetch
      Position? last = await Geolocator.getLastKnownPosition();
      if (last != null) return last;
      rethrow;
    }
  }

  static Future<Position?> getLastKnownPosition() async {
    try {
      return await Geolocator.getLastKnownPosition();
    } catch (e) {
      return null;
    }
  }

  static double calculateDistance(double lat1, double lng1, double lat2, double lng2) {
    return Geolocator.distanceBetween(lat1, lng1, lat2, lng2);
  }

  // --- 4. BACKGROUND TRACKING METHODS (WAJIB ADA) ---
  // Dipakai oleh 'background_location_service.dart'

  static void startContinuousTracking(Function(Position) onPositionUpdate) {
    if (_positionStreamSubscription != null) {
      _positionStreamSubscription!.cancel();
    }

    // Menggunakan stream high accuracy untuk tracking background
    _positionStreamSubscription = getHighAccuracyStream().listen((Position position) {
      // Terapkan Kalman Filter
      Position filtered = applyKalmanFilter(position);
      onPositionUpdate(filtered);
    });
  }

  static void stopContinuousTracking() {
    _positionStreamSubscription?.cancel();
    _positionStreamSubscription = null;
    _kalmanFilter = null; // Reset filter
  }

  // --- 5. KALMAN FILTER LOGIC (WAJIB ADA) ---
  // Dipakai oleh 'background_location_service.dart'

  static Position applyKalmanFilter(Position newPosition) {
    if (_kalmanFilter == null) {
      _kalmanFilter = KalmanFilterPosition(
        latitude: newPosition.latitude,
        longitude: newPosition.longitude,
        accuracy: newPosition.accuracy,
      );
    } else {
      _kalmanFilter!.update(
        latitude: newPosition.latitude,
        longitude: newPosition.longitude,
        accuracy: newPosition.accuracy,
      );
    }

    return Position(
      latitude: _kalmanFilter!.latitude,
      longitude: _kalmanFilter!.longitude,
      timestamp: newPosition.timestamp,
      accuracy: _kalmanFilter!.accuracy,
      altitude: newPosition.altitude,
      heading: newPosition.heading,
      speed: newPosition.speed,
      speedAccuracy: newPosition.speedAccuracy,
      altitudeAccuracy: newPosition.altitudeAccuracy,
      headingAccuracy: newPosition.headingAccuracy,
    );
  }
}

// --- CLASS HELPER: KALMAN FILTER (JANGAN DIHAPUS) ---
class KalmanFilterPosition {
  double latitude;
  double longitude;
  double accuracy;

  double _processNoise = 0.001; // Q
  double _measurementNoise = 10.0; // R
  double _errorCovarianceLat = 1.0; // P
  double _errorCovarianceLng = 1.0; // P

  KalmanFilterPosition({
    required this.latitude,
    required this.longitude,
    required this.accuracy,
  });

  void update({required double latitude, required double longitude, required double accuracy}) {
    _measurementNoise = accuracy * accuracy;

    // Latitude
    double kalmanGainLat = _errorCovarianceLat / (_errorCovarianceLat + _measurementNoise);
    this.latitude = this.latitude + kalmanGainLat * (latitude - this.latitude);
    _errorCovarianceLat = (1 - kalmanGainLat) * _errorCovarianceLat + _processNoise;

    // Longitude
    double kalmanGainLng = _errorCovarianceLng / (_errorCovarianceLng + _measurementNoise);
    this.longitude = this.longitude + kalmanGainLng * (longitude - this.longitude);
    _errorCovarianceLng = (1 - kalmanGainLng) * _errorCovarianceLng + _processNoise;

    // Estimasi Accuracy Baru
    this.accuracy = sqrt(_errorCovarianceLat + _errorCovarianceLng);
  }
}