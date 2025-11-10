import 'dart:async';
import 'package:geolocator/geolocator.dart';
import 'package:geocoding/geocoding.dart';

class LocationService {
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
      desiredAccuracy: LocationAccuracy.high,
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
    try {
      List<Placemark> placemarks = await placemarkFromCoordinates(latitude, longitude);
      if (placemarks.isNotEmpty) {
        Placemark place = placemarks.first;
        return '${place.locality}, ${place.country}';
      }
    } catch (e) {
      print('Error getting location name: $e');
    }
    return 'Unknown Location';
  }
}
