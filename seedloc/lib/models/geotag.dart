class Geotag {
  final int? id;
  final int projectId;
  final double latitude;
  final double longitude;
  final String locationName;
  final String timestamp;
  final String itemType;
  final String condition;
  final String details;
  final String photoPath;
  final bool isSynced;
  final String deviceId;

  Geotag({
    this.id,
    required this.projectId,
    required this.latitude,
    required this.longitude,
    required this.locationName,
    required this.timestamp,
    required this.itemType,
    required this.condition,
    required this.details,
    required this.photoPath,
    this.isSynced = false,
    required this.deviceId,
  });

  Map<String, dynamic> toMap() {
    return {
      'id': id,
      'projectId': projectId,
      'latitude': latitude,
      'longitude': longitude,
      'locationName': locationName,
      'timestamp': timestamp,
      'itemType': itemType,
      'condition': condition,
      'details': details,
      'photoPath': photoPath,
      'isSynced': isSynced ? 1 : 0,
      'deviceId': deviceId,
    };
  }

  factory Geotag.fromMap(Map<String, dynamic> map) {
    return Geotag(
      id: map['id'],
      projectId: map['projectId'],
      latitude: map['latitude'],
      longitude: map['longitude'],
      locationName: map['locationName'],
      timestamp: map['timestamp'],
      itemType: map['itemType'],
      condition: map['condition'],
      details: map['details'],
      photoPath: map['photoPath'],
      isSynced: map['isSynced'] == 1,
      deviceId: map['deviceId'],
    );
  }
}
