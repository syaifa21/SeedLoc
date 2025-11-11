import 'dart:async';
import 'dart:math';
import 'package:geolocator/geolocator.dart';

class LocationService {
  static StreamSubscription<Position>? _positionStreamSubscription;

  // High accuracy location settings
  static final LocationSettings highAccuracySettings = LocationSettings(
    accuracy: LocationAccuracy.bestForNavigation,
    distanceFilter: 5, // Update every 5 meters
  );

  static Future<Position> getCurrentPosition() async {
    bool serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) {
      throw Exception('Location services are disabled.');
    }

    LocationPermission permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied) {
        throw Exception('Location permissions are denied');
      }
    }

    if (permission == LocationPermission.deniedForever) {
      throw Exception('Location permissions are permanently denied');
    }

    return await Geolocator.getCurrentPosition(
      locationSettings: highAccuracySettings,
    );
  }

  static Future<List<Position>> getAveragedPositions(int durationSeconds) async {
    List<Position> positions = [];
    const interval = Duration(seconds: 1);

    for (int i = 0; i < durationSeconds; i++) {
      Position position = await getCurrentPosition();
      positions.add(position);
      await Future.delayed(interval);
    }

    return positions;
  }

  static Position calculateAveragePosition(List<Position> positions) {
    if (positions.isEmpty) {
      throw Exception('No positions to average');
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
    // Return coordinates only as requested
    return '$latitude, $longitude';
  }

  // Kalman Filter implementation for GPS accuracy improvement
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

  // Start continuous location tracking with Kalman filter
  static void startContinuousTracking(Function(Position) onPositionUpdate) {
    if (_positionStreamSubscription != null) {
      _positionStreamSubscription!.cancel();
    }

    _positionStreamSubscription = Geolocator.getPositionStream(
      locationSettings: highAccuracySettings,
    ).listen((Position position) {
      // Apply Kalman filter to reduce GPS jitter
      Position filteredPosition = applyKalmanFilter(position);
      onPositionUpdate(filteredPosition);
    });
  }

  // Stop continuous location tracking
  static void stopContinuousTracking() {
    _positionStreamSubscription?.cancel();
    _positionStreamSubscription = null;
    _kalmanFilter = null;
  }
}

// Simple Kalman Filter implementation for GPS coordinates
class KalmanFilterPosition {
  double latitude;
  double longitude;
  double accuracy;

  // Kalman filter parameters
  double _processNoise = 0.001; // Process noise
  double _measurementNoise = 10.0; // Measurement noise (GPS accuracy)
  double _errorCovarianceLat = 1.0;
  double _errorCovarianceLng = 1.0;

  KalmanFilterPosition({
    required this.latitude,
    required this.longitude,
    required this.accuracy,
  });

  void update({required double latitude, required double longitude, required double accuracy}) {
    // Update measurement noise based on GPS accuracy
    _measurementNoise = accuracy * accuracy;

    // Kalman gain for latitude
    double kalmanGainLat = _errorCovarianceLat / (_errorCovarianceLat + _measurementNoise);
    // Kalman gain for longitude
    double kalmanGainLng = _errorCovarianceLng / (_errorCovarianceLng + _measurementNoise);

    // Update latitude
    this.latitude = this.latitude + kalmanGainLat * (latitude - this.latitude);
    // Update longitude
    this.longitude = this.longitude + kalmanGainLng * (longitude - this.longitude);

    // Update error covariance
    _errorCovarianceLat = (1 - kalmanGainLat) * _errorCovarianceLat + _processNoise;
    _errorCovarianceLng = (1 - kalmanGainLng) * _errorCovarianceLng + _processNoise;

    // Update accuracy estimate
    this.accuracy = sqrt(_errorCovarianceLat + _errorCovarianceLng);
  }
}
