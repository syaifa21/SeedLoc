# TODO: Implement Real-Time Location Bar and Navigation Changes

## 1. Add Real-Time Location Bar to GeotagListScreen
- [ ] Import Timer and LocationService
- [ ] Add state variables: _currentPosition, _currentAccuracy, _currentLocationText
- [ ] Add _startLocationTracking method with Timer (2 seconds interval)
- [ ] Add dispose method to cancel timer
- [ ] Add banner widget below AppBar to display real-time location

## 2. Change Navigation After Project Creation
- [ ] Import GeotagListScreen in project_creation_screen.dart
- [ ] Change navigation from '/home' to direct pushReplacement to GeotagListScreen

## 3. Optimize Location Capture Time in FieldDataScreen
- [ ] Modify _finishLocationCapture to use getCurrentPosition() instead of getAveragedPositions(20)
- [ ] Remove averaging logic, use single accurate position directly
- [ ] Ensure total capture time is max 20 seconds (UI countdown only)

## Testing
- [ ] Test real-time location updates in Geotag List Screen
- [ ] Test direct navigation to Geotag List after project creation
- [ ] Test optimized location capture (20 seconds total)
