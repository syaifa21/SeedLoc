# SeedLoc API - Simple PHP Version

API sederhana untuk aplikasi SeedLoc menggunakan PHP dan MySQL.

## 📋 Konfigurasi Database

```
Database: seedlocm_apk
User: seedlocm_ali
Password: alialiali123!
Host: localhost
Port: 3306
```

## 🚀 Instalasi

### 1. Upload Files
Upload semua file di folder `api/` ke server di: `https://seedloc.my.id/api/`

### 2. Setup Database
1. Login ke phpMyAdmin
2. Buat database `seedlocm_apk` (jika belum ada)
3. Import file `database.sql`

### 3. Set Permissions
```bash
chmod 755 api/
chmod 755 api/uploads/
chmod 644 api/*.php
```

## 📡 API Endpoints

### Base URL
```
https://seedloc.my.id/api
```

### 1. Status API
```
GET /
GET /status
```
Response:
```json
{
  "status": "online",
  "message": "SeedLoc API is running",
  "version": "1.0",
  "timestamp": "2024-01-01 12:00:00"
}
```

### 2. Projects

**Get All Projects**
```
GET /projects
```

**Create Project**
```
POST /projects
Content-Type: application/json

{
  "projectId": 1,
  "activityName": "Survey Pohon",
  "locationName": "Jakarta",
  "officers": "Ali,Budi,Citra",
  "status": "Active"
}
```

### 3. Geotags

**Get All Geotags**
```
GET /geotags
```

**Get Geotags by Project**
```
GET /geotags?projectId=1
```

**Sync Single Geotag**
```
POST /geotags
Content-Type: application/json

{
  "projectId": 1,
  "latitude": -6.200000,
  "longitude": 106.816666,
  "locationName": "Jakarta",
  "timestamp": "2024-01-01T12:00:00",
  "itemType": "Pohon Mangga",
  "condition": "Baik",
  "details": "Pohon sehat",
  "photoPath": "",
  "deviceId": "device123"
}
```

**Bulk Sync Geotags**
```
POST /geotags
Content-Type: application/json

{
  "geotags": [
    {
      "projectId": 1,
      "latitude": -6.200000,
      "longitude": 106.816666,
      ...
    },
    {
      "projectId": 1,
      "latitude": -6.201000,
      "longitude": 106.817666,
      ...
    }
  ]
}
```

### 4. Upload Photo
```
POST /upload
Content-Type: multipart/form-data

photo: [file]
```

Response:
```json
{
  "success": true,
  "message": "Photo uploaded",
  "path": "uploads/1234567890_photo.jpg",
  "url": "https://seedloc.my.id/api/uploads/1234567890_photo.jpg"
}
```

### 5. Statistics
```
GET /stats
```

Response:
```json
{
  "success": true,
  "data": {
    "totalProjects": 5,
    "totalGeotags": 150,
    "syncedGeotags": 150
  }
}
```

## 🧪 Testing

### Test dengan cURL

**Test Status:**
```bash
curl https://seedloc.my.id/api/
```

**Test Create Project:**
```bash
curl -X POST https://seedloc.my.id/api/projects \
  -H "Content-Type: application/json" \
  -d '{"projectId":1,"activityName":"Test","locationName":"Jakarta","officers":"Ali","status":"Active"}'
```

**Test Sync Geotag:**
```bash
curl -X POST https://seedloc.my.id/api/geotags \
  -H "Content-Type: application/json" \
  -d '{"projectId":1,"latitude":-6.2,"longitude":106.8,"locationName":"Jakarta","timestamp":"2024-01-01T12:00:00","itemType":"Pohon","condition":"Baik","details":"Test","photoPath":"","deviceId":"test123"}'
```

**Test Upload Photo:**
```bash
curl -X POST https://seedloc.my.id/api/upload \
  -F "photo=@/path/to/photo.jpg"
```

## 📁 Struktur File

```
api/
├── index.php          # Main router
├── config.php         # Database config
├── db.php            # Database connection
├── .htaccess         # Apache rewrite rules
├── database.sql      # Database schema
├── uploads/          # Photo uploads folder
└── README.md         # This file
```

## ⚠️ Troubleshooting

### Error: Connection failed
- Cek username dan password database di `config.php`
- Pastikan database `seedlocm_apk` sudah dibuat
- Cek apakah user `seedlocm_ali` punya akses ke database

### Error: 404 Not Found
- Pastikan file `.htaccess` sudah diupload
- Cek apakah mod_rewrite Apache sudah aktif
- Coba akses langsung: `https://seedloc.my.id/api/index.php`

### Error: Permission denied
- Set permission folder uploads: `chmod 755 uploads/`
- Set permission file PHP: `chmod 644 *.php`

### CORS Error
- Sudah dihandle di `config.php` dan `.htaccess`
- Jika masih error, tambahkan di Apache config

## 📝 Notes

- API ini sangat simpel dan fokus pada fungsionalitas dasar
- Tidak ada authentication (bisa ditambahkan jika perlu)
- Photo upload disimpan di folder `uploads/`
- Semua response dalam format JSON
- CORS sudah enabled untuk semua origin

## 🔗 Links

- API URL: https://seedloc.my.id/api
- Status: https://seedloc.my.id/api/status
- Stats: https://seedloc.my.id/api/stats
