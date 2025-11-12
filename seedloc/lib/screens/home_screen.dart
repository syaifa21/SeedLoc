import 'package:flutter/material.dart';
import 'settings_screen.dart';
import 'project_creation_screen.dart';
import 'geotag_list_screen.dart';
import 'map_screen.dart';
import '../database/database_helper.dart';
import '../models/project.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _selectedIndex = 0;
  bool _projectExists = false;

  @override
  void initState() {
    super.initState();
    _checkProjectExists();
  }

  Future<void> _checkProjectExists() async {
    DatabaseHelper dbHelper = DatabaseHelper();
    List<Project> projects = await dbHelper.getProjects();
    setState(() {
      _projectExists = projects.isNotEmpty;
    });
  }

  Widget _getCurrentScreen() {
    if (!_projectExists) {
      return const ProjectCreationScreen();
    }

    switch (_selectedIndex) {
      case 0:
        return const GeotagListScreen();
      case 1:
        return const MapScreen();
      case 2:
        return const SettingsScreen();
      default:
        return const GeotagListScreen();
    }
  }

  void _onItemTapped(int index) {
    if (_projectExists) {
      setState(() {
        _selectedIndex = index;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: _getCurrentScreen(),
      bottomNavigationBar: _projectExists
          ? BottomNavigationBar(
              items: const <BottomNavigationBarItem>[
                BottomNavigationBarItem(
                  icon: Icon(Icons.list),
                  label: 'Data Lapangan',
                ),
                BottomNavigationBarItem(
                  icon: Icon(Icons.map),
                  label: 'Peta',
                ),
                BottomNavigationBarItem(
                  icon: Icon(Icons.settings),
                  label: 'Pengaturan',
                ),
              ],
              currentIndex: _selectedIndex,
              selectedItemColor: Colors.blue,
              onTap: _onItemTapped,
            )
          : null,
    );
  }
}
