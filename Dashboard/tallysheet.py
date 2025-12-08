import pandas as pd
import numpy as np

# 1. Load Data
df = pd.read_csv('All_Data_Data_20251208023051.csv')

# --- MULAI SIMULASI (Hapus blok ini jika kolom 'Accuracy' sudah ada) ---
np.random.seed(42) # Untuk hasil yang konsisten
# Membuat data dummy akurasi antara 0.5m sampai 3.5m
df['Accuracy'] = np.random.uniform(0.5, 3.5, size=len(df))
# --- SELESAI SIMULASI ---

# 2. Kategorisasi Akurasi (Binning)
# Bins: 0-1, 1-2, >2
bins = [0, 1, 2, np.inf]
labels = ['Akurasi < 1m', 'Akurasi 1-2m', 'Akurasi > 2m']
df['Kategori_Akurasi'] = pd.cut(df['Accuracy'], bins=bins, labels=labels)

# 3. Membuat Tally Sheet (Pivot Table)
tally_sheet = df.pivot_table(
    index='Type',               # Baris berdasarkan Jenis Tanaman
    columns='Kategori_Akurasi', # Kolom berdasarkan Range Akurasi
    values='ID',                # Value yang dihitung (bisa ID atau kolom lain)
    aggfunc='count',            # Fungsi hitung jumlah data
    fill_value=0                # Isi 0 jika data kosong
)

# 4. Tambah Kolom Total
tally_sheet['Grand Total'] = tally_sheet.sum(axis=1)

# 5. Output
print(tally_sheet)
tally_sheet.to_csv('TallySheet_Akurasi_Processed.csv')