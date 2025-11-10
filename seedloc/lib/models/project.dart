class Project {
  final int projectId;
  final String activityName;
  final String locationName;
  final List<String> officers;
  final String status; // 'Active' or 'Completed'

  Project({
    required this.projectId,
    required this.activityName,
    required this.locationName,
    required this.officers,
    this.status = 'Active',
  });

  Map<String, dynamic> toMap() {
    return {
      'projectId': projectId,
      'activityName': activityName,
      'locationName': locationName,
      'officers': officers.join(','),
      'status': status,
    };
  }

  factory Project.fromMap(Map<String, dynamic> map) {
    return Project(
      projectId: map['projectId'],
      activityName: map['activityName'],
      locationName: map['locationName'],
      officers: (map['officers'] as String).split(','),
      status: map['status'] ?? 'Active',
    );
  }
}
