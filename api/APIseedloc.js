// APIseedloc.js (Kode Lengkap yang Dikoreksi untuk MySQL)

const express = require('express');
const knex = require('knex');
const cors = require('cors');
require('dotenv').config(); 

const app = express();
const port = process.env.PORT || 8000; 

// --- Konfigurasi Knex untuk MySQL/MariaDB ---
const knexConfig = {
    // PENTING: Ganti client ke 'mysql2'
    client: 'mysql2', 
    connection: {
        host: process.env.DB_HOST,
        port: process.env.DB_PORT || 3306, // Port default MySQL adalah 3306
        user: process.env.DB_USER,
        password: process.env.DB_PASSWORD,
        database: process.env.DB_NAME,
    },
    // Pengaturan tambahan untuk MySQL
    useNullAsDefault: true,
};

// Inisialisasi Knex
const db = knex(knexConfig);

// --- Middleware ---
app.use(cors());
app.use(express.json({ limit: '10mb' })); 

// --- Endpoint Status ---
app.get(`/`, (req, res) => {
    db.raw('SELECT 1+1 AS result')
        .then(() => {
            res.send('SeedLoc API (Node.js + MySQL) berjalan. Koneksi DB OK!');
        })
        .catch((error) => {
            console.error('Koneksi DB Error:', error);
            res.status(500).send(`SeedLoc API berjalan, TAPI KONEKSI DB GAGAL: ${error.message}`);
        });
});

// --- Endpoint POST /geotags (Sync) ---
app.post(`/geotags`, async (req, res) => {
    try {
        const geotagData = req.body;
        const dataToInsert = { 
            ...geotagData, 
            "isSynced": true 
        };

        // Insert data dan dapatkan ID yang dibuat MySQL
        const [lastInsertId] = await db('geotags').insert(dataToInsert); 

        res.status(200).json({ 
            message: 'Geotag berhasil disinkronkan',
            id: lastInsertId // Menggunakan ID yang dikembalikan MySQL
        });
    } catch (error) {
        console.error('Error saat menyimpan geotag:', error);
        res.status(500).json({ 
            message: 'Gagal menyimpan geotag', 
            error: error.message 
        });
    }
});


// --- Endpoint POST /projects ---
app.post(`/projects`, async (req, res) => {
    try {
        const projectData = req.body;

        // Cek apakah project sudah ada
        const existingProject = await db('projects')
            .where({ "projectId": projectData.projectId })
            .first();
        
        if (existingProject) {
            // Update
            await db('projects')
                .where({ "projectId": projectData.projectId })
                .update(projectData);
            
            const updatedProject = await db('projects').where({ "projectId": projectData.projectId }).first();
            return res.status(200).json({ 
                message: 'Project berhasil diupdate.', 
                project: updatedProject
            });
        }
        
        // Insert
        await db('projects').insert(projectData);

        const insertedProject = await db('projects').where({ "projectId": projectData.projectId }).first();

        res.status(201).json({ 
            message: 'Project berhasil dibuat',
            project: insertedProject
        });

    } catch (error) {
        console.error('Error saat membuat/memperbarui project:', error);
        res.status(500).json({ 
            message: 'Gagal membuat/memperbarui project', 
            error: error.message 
        });
    }
});


// Jalankan Server
app.listen(port, () => {
    console.log(`Server Node.js berjalan di port ${port}`);
});