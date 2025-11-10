import 'package:sqflite/sqflite.dart';
import 'package:path/path.dart';
import '../models/project.dart';
import '../models/geotag.dart';

class DatabaseHelper {
  static final DatabaseHelper _instance = DatabaseHelper._internal();
  static Database? _database;

  factory DatabaseHelper() => _instance;

  DatabaseHelper._internal();

  Future<Database> get database async {
    if (_database != null) return _database!;
    _database = await _initDatabase();
    return _database!;
  }

  Future<Database> _initDatabase() async {
    String path = join(await getDatabasesPath(), 'seedloc.db');
    return await openDatabase(
      path,
      version: 1,
      onCreate: _onCreate,
    );
  }

  Future<void> _onCreate(Database db, int version) async {
    await db.execute('''
      CREATE TABLE projects(
        projectId INTEGER PRIMARY KEY,
        activityName TEXT,
        locationName TEXT,
        officers TEXT,
        status TEXT
      )
    ''');

    await db.execute('''
      CREATE TABLE geotags(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        projectId INTEGER,
        latitude REAL,
        longitude REAL,
        locationName TEXT,
        timestamp TEXT,
        itemType TEXT,
        condition TEXT,
        details TEXT,
        photoPath TEXT,
        isSynced INTEGER,
        deviceId TEXT,
        FOREIGN KEY (projectId) REFERENCES projects (projectId)
      )
    ''');

    // Create indexes for performance
    await db.execute('CREATE INDEX idx_projectId ON geotags(projectId)');
    await db.execute('CREATE INDEX idx_isSynced ON geotags(isSynced)');
  }

  // Project operations
  Future<int> insertProject(Project project) async {
    Database db = await database;
    return await db.insert('projects', project.toMap());
  }

  Future<List<Project>> getProjects() async {
    Database db = await database;
    final List<Map<String, dynamic>> maps = await db.query('projects');
    return List.generate(maps.length, (i) => Project.fromMap(maps[i]));
  }

  Future<int> updateProject(Project project) async {
    Database db = await database;
    return await db.update(
      'projects',
      project.toMap(),
      where: 'projectId = ?',
      whereArgs: [project.projectId],
    );
  }

  // Geotag operations
  Future<int> insertGeotag(Geotag geotag) async {
    Database db = await database;
    return await db.insert('geotags', geotag.toMap());
  }

  Future<List<Geotag>> getGeotagsByProject(int projectId) async {
    Database db = await database;
    final List<Map<String, dynamic>> maps = await db.query(
      'geotags',
      where: 'projectId = ?',
      whereArgs: [projectId],
    );
    return List.generate(maps.length, (i) => Geotag.fromMap(maps[i]));
  }

  Future<List<Geotag>> getUnsyncedGeotags() async {
    Database db = await database;
    final List<Map<String, dynamic>> maps = await db.query(
      'geotags',
      where: 'isSynced = ?',
      whereArgs: [0],
    );
    return List.generate(maps.length, (i) => Geotag.fromMap(maps[i]));
  }

  Future<int> updateGeotagSyncStatus(int id, bool isSynced) async {
    Database db = await database;
    return await db.update(
      'geotags',
      {'isSynced': isSynced ? 1 : 0},
      where: 'id = ?',
      whereArgs: [id],
    );
  }

  Future<int> deleteGeotag(int id) async {
    Database db = await database;
    return await db.delete(
      'geotags',
      where: 'id = ?',
      whereArgs: [id],
    );
  }

  Future<List<Geotag>> getGeotags() async {
    Database db = await database;
    final List<Map<String, dynamic>> maps = await db.query('geotags');
    return List.generate(maps.length, (i) => Geotag.fromMap(maps[i]));
  }
}
