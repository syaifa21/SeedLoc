import 'dart:async';
import 'package:geolocator/geolocator.dart';
import 'location_service.dart';

class BackgroundLocationService {
  static final BackgroundLocationService _instance = BackgroundLocationService._internal();
  factory BackgroundLocationService() => _instance;
  BackgroundLocationService._internal();

  StreamSubscription<Position>? _positionStream;
  final List<Position> _recentPositions = [];
  Position? _currentBestPosition;
  bool _isTracking = false;
  
  // Keep last 30 positions (30 seconds of data)
  static const int _maxPositions = 30;
  
  // Callbacks for position updates
  final List<Function(Position)> _listeners = [];

  bool get isTracking => _isTracking;
  Position? get currentBestPosition => _currentBestPosition;
  List<Position> get recentPositions => List.unmodifiable(_recentPositions);

  // Start continuous background tracking
  Future<void> startTracking() async {
    if (_isTracking) return;

    _isTracking = true;
    _recentPositions.clear();

    // Start continuous location tracking with Kalman filter
    LocationService.startContinuousTracking((Position position) {
      _addPosition(position);
      _notifyListeners(position);
    });
  }

  // Stop background tracking
  void stopTracking() {
    if (!_isTracking) return;

    _isTracking = false;
    LocationService.stopContinuousTracking();
    _positionStream?.cancel();
    _positionStream = null;
  }

  // Add position to buffer
  void _addPosition(Position position) {
    _recentPositions.add(position);
    
    // Keep only last N positions
    if (_recentPositions.length > _maxPositions) {
      _recentPositions.removeAt(0);
    }

    // Update current best position (lowest accuracy)
    if (_currentBestPosition == null || position.accuracy < _currentBestPosition!.accuracy) {
      _currentBestPosition = position;
    }
  }

  // Get optimal position from recent data
  Position? getOptimalPosition() {
    if (_recentPositions.isEmpty) return null;
    
    // Use at least 5 positions for better accuracy
    if (_recentPositions.length < 5) {
      return _currentBestPosition;
    }

    // Filter and calculate weighted average
    List<Position> filtered = _removeOutliers(_recentPositions);
    return _calculateWeightedAverage(filtered);
  }

  // Remove outliers using IQR method
  List<Position> _removeOutliers(List<Position> positions) {
    if (positions.length < 4) return positions;

    List<double> latitudes = positions.map((p) => p.latitude).toList()..sort();
    List<double> longitudes = positions.map((p) => p.longitude).toList()..sort();

    double medianLat = _calculateMedian(latitudes);
    double medianLng = _calculateMedian(longitudes);

    List<double> distances = positions.map((p) {
      double latDiff = p.latitude - medianLat;
      double lngDiff = p.longitude - medianLng;
      return (latDiff * latDiff + lngDiff * lngDiff);
    }).toList();

    List<double> sortedDistances = List.from(distances)..sort();
    double q1 = _calculateMedian(sortedDistances.sublist(0, sortedDistances.length ~/ 2));
    double q3 = _calculateMedian(sortedDistances.sublist(sortedDistances.length ~/ 2));
    double iqr = q3 - q1;
    double threshold = q3 + 1.5 * iqr;

    List<Position> filtered = [];
    for (int i = 0; i < positions.length; i++) {
      if (distances[i] <= threshold) {
        filtered.add(positions[i]);
      }
    }

    return filtered.isEmpty ? positions : filtered;
  }

  double _calculateMedian(List<double> values) {
    if (values.isEmpty) return 0;
    int middle = values.length ~/ 2;
    if (values.length % 2 == 0) {
      return (values[middle - 1] + values[middle]) / 2;
    }
    return values[middle];
  }

  // Calculate weighted average
  Position _calculateWeightedAverage(List<Position> positions) {
    if (positions.isEmpty) throw Exception('No positions');
    if (positions.length == 1) return positions.first;

    double totalWeight = 0;
    double weightedLat = 0;
    double weightedLng = 0;
    double totalAccuracy = 0;

    for (var pos in positions) {
      double weight = 1.0 / (pos.accuracy * pos.accuracy);
      totalWeight += weight;
      weightedLat += pos.latitude * weight;
      weightedLng += pos.longitude * weight;
      totalAccuracy += pos.accuracy;
    }

    double avgLat = weightedLat / totalWeight;
    double avgLng = weightedLng / totalWeight;
    
    double bestAccuracy = positions.map((p) => p.accuracy).reduce((a, b) => a < b ? a : b);
    double avgAccuracy = totalAccuracy / positions.length;
    double finalAccuracy = (bestAccuracy * 0.7 + avgAccuracy * 0.3);

    return Position(
      latitude: avgLat,
      longitude: avgLng,
      timestamp: DateTime.now(),
      accuracy: finalAccuracy,
      altitude: positions.last.altitude,
      heading: positions.last.heading,
      speed: positions.last.speed,
      speedAccuracy: positions.last.speedAccuracy,
      altitudeAccuracy: positions.last.altitudeAccuracy,
      headingAccuracy: positions.last.headingAccuracy,
    );
  }

  // Add listener for position updates
  void addListener(Function(Position) callback) {
    _listeners.add(callback);
  }

  // Remove listener
  void removeListener(Function(Position) callback) {
    _listeners.remove(callback);
  }

  // Notify all listeners
  void _notifyListeners(Position position) {
    for (var listener in _listeners) {
      listener(position);
    }
  }

  // Get statistics
  Map<String, dynamic> getStatistics() {
    if (_recentPositions.isEmpty) {
      return {
        'totalSamples': 0,
        'bestAccuracy': 0.0,
        'averageAccuracy': 0.0,
        'isReady': false,
      };
    }

    double bestAccuracy = _recentPositions.map((p) => p.accuracy).reduce((a, b) => a < b ? a : b);
    double avgAccuracy = _recentPositions.map((p) => p.accuracy).reduce((a, b) => a + b) / _recentPositions.length;

    return {
      'totalSamples': _recentPositions.length,
      'bestAccuracy': bestAccuracy,
      'averageAccuracy': avgAccuracy,
      'isReady': _recentPositions.length >= 5 && bestAccuracy <= 5.0,
    };
  }

  // Clear all data
  void clear() {
    _recentPositions.clear();
    _currentBestPosition = null;
  }
}
