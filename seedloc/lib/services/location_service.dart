import 'dart:async';
import 'dart:math';
import 'package:flutter/foundation.dart'; // Diperlukan untuk cek Platform
import 'package:geolocator/geolocator.dart';

class LocationService {
  static StreamSubscription<Position>? _positionStreamSubscription;

  // --- 1. SETTINGAN KHUSUS (Memaksa GPS Hardware / Satelit) ---
  static LocationSettings _getPlatformSpecificSettings() {
    if (defaultTargetPlatform == TargetPlatform.android) {
      return AndroidSettings(
        accuracy: LocationAccuracy.bestForNavigation, // Akurasi Tertinggi
        distanceFilter: 0, // Update sekecil apapun
        forceLocationManager: true, // PENTING: Paksa pakai GPS Hardware (bukan Wifi/Seluler)
        intervalDuration: const Duration(milliseconds: 500), // Update cepat
      );
    } else if (defaultTargetPlatform == TargetPlatform.iOS) {
      return AppleSettings(
        accuracy: LocationAccuracy.bestForNavigation,
        activityType: ActivityType.fitness,
        distanceFilter: 0,
        pauseLocationUpdatesAutomatically: false,
        showBackgroundLocationIndicator: true,
      );
    } else {
      return const LocationSettings(
        accuracy: LocationAccuracy.bestForNavigation,
        distanceFilter: 0,
      );
    }
  }

  // --- 2. FUNGSI UTAMA: Streaming Hingga Akurasi < 5 Meter ---
  static Future<Position> getCurrentPosition() async {
    // A. Cek Service & Permission
    bool serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) {
      throw Exception('Layanan Lokasi (GPS) mati. Mohon hidupkan GPS.');
    }

    LocationPermission permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied) {
        throw Exception('Izin lokasi ditolak.');
      }
    }

    if (permission == LocationPermission.deniedForever) {
      throw Exception('Izin lokasi ditolak permanen.');
    }

    // B. Logic Streaming sampai Akurasi Bagus
    Completer<Position> completer = Completer();
    StreamSubscription<Position>? streamSubscription;
    Position? bestPosition; // Menyimpan posisi terbaik sementara

    // Timer Timeout (Misal: 30 detik)
    // Jika dalam 30 detik tidak dapat < 5m, kembalikan yang terbaik yang ada.
    Timer timeoutTimer = Timer(const Duration(seconds: 30), () {
      streamSubscription?.cancel();
      if (!completer.isCompleted) {
        if (bestPosition != null) {
          print("Timeout 30s. Mengembalikan posisi terbaik: ${bestPosition!.accuracy}m");
          completer.complete(bestPosition);
        } else {
          completer.completeError("Gagal mendapatkan sinyal GPS yang memadai.");
        }
      }
    });

    // Mulai mendengarkan Stream
    streamSubscription = Geolocator.getPositionStream(
      locationSettings: _getPlatformSpecificSettings(),
    ).listen((Position position) {
      
      // Update posisi terbaik jika yang baru lebih akurat
      if (bestPosition == null || position.accuracy < bestPosition!.accuracy) {
        bestPosition = position;
      }

      print("Mencari Sinyal... Akurasi saat ini: ${position.accuracy} m");

      // SYARAT: Akurasi harus <= 5 meter
      if (position.accuracy <= 5.0) {
        timeoutTimer.cancel();
        streamSubscription?.cancel();
        
        if (!completer.isCompleted) {
          print("Posisi Akurat Ditemukan: ${position.accuracy} m");
          completer.complete(position);
        }
      }
    }, onError: (error) {
      timeoutTimer.cancel();
      streamSubscription?.cancel();
      if (!completer.isCompleted) {
        completer.completeError(error);
      }
    });

    return completer.future;
  }

  // --- 3. HELPER METHODS (Tetap dipertahankan agar tidak merusak kode lain) ---
  
  static Future<List<Position>> getAveragedPositions(int durationSeconds) async {
    List<Position> positions = [];
    // Karena getCurrentPosition() sekarang sudah menunggu akurasi tinggi,
    // loop ini akan berjalan lebih lambat tapi hasilnya sangat presisi.
    for (int i = 0; i < durationSeconds; i++) {
      try {
        Position position = await getCurrentPosition();
        positions.add(position);
      } catch (e) {
        print("Gagal ambil sampel ke-$i: $e");
      }
    }
    return positions;
  }

  static Position calculateAveragePosition(List<Position> positions) {
    if (positions.isEmpty) {
      throw Exception('Tidak ada data posisi untuk dirata-rata');
    }

    double totalLat = 0;
    double totalLng = 0;
    double totalAccuracy = 0;

    for (var pos in positions) {
      totalLat += pos.latitude;
      totalLng += pos.longitude;
      totalAccuracy += pos.accuracy;
    }

    return Position(
      latitude: totalLat / positions.length,
      longitude: totalLng / positions.length,
      timestamp: DateTime.now(),
      accuracy: totalAccuracy / positions.length,
      altitude: positions.last.altitude,
      heading: positions.last.heading,
      speed: positions.last.speed,
      speedAccuracy: positions.last.speedAccuracy,
      altitudeAccuracy: positions.last.altitudeAccuracy,
      headingAccuracy: positions.last.headingAccuracy,
    );
  }

  static Future<String> getLocationName(double latitude, double longitude) async {
    return '$latitude, $longitude';
  }

  // --- 4. KALMAN FILTER ---
  static KalmanFilterPosition? _kalmanFilter;

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

  // Tracking terus menerus untuk Peta/Background
  static void startContinuousTracking(Function(Position) onPositionUpdate) {
    if (_positionStreamSubscription != null) {
      _positionStreamSubscription!.cancel();
    }

    _positionStreamSubscription = Geolocator.getPositionStream(
      locationSettings: _getPlatformSpecificSettings(),
    ).listen((Position position) {
      Position filteredPosition = applyKalmanFilter(position);
      onPositionUpdate(filteredPosition);
    });
  }

  static void stopContinuousTracking() {
    _positionStreamSubscription?.cancel();
    _positionStreamSubscription = null;
    _kalmanFilter = null;
  }
}

// --- 5. CLASS HELPER KALMAN FILTER ---
class KalmanFilterPosition {
  double latitude;
  double longitude;
  double accuracy;

  double _processNoise = 0.001;
  double _measurementNoise = 10.0;
  double _errorCovarianceLat = 1.0;
  double _errorCovarianceLng = 1.0;

  KalmanFilterPosition({
    required this.latitude,
    required this.longitude,
    required this.accuracy,
  });

  void update({required double latitude, required double longitude, required double accuracy}) {
    _measurementNoise = accuracy * accuracy;

    double kalmanGainLat = _errorCovarianceLat / (_errorCovarianceLat + _measurementNoise);
    double kalmanGainLng = _errorCovarianceLng / (_errorCovarianceLng + _measurementNoise);

    this.latitude = this.latitude + kalmanGainLat * (latitude - this.latitude);
    this.longitude = this.longitude + kalmanGainLng * (longitude - this.longitude);

    _errorCovarianceLat = (1 - kalmanGainLat) * _errorCovarianceLat + _processNoise;
    _errorCovarianceLng = (1 - kalmanGainLng) * _errorCovarianceLng + _processNoise;

    this.accuracy = sqrt(_errorCovarianceLat + _errorCovarianceLng);
  }
}