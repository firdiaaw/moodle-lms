# Moodle LMS dengan ProFormA Auto Grader

LMS berbasis Moodle dengan fitur auto-grading untuk soal programming Python menggunakan plugin ProFormA.

## Requirements

- PHP 8.3 atau 8.4
- MySQL/MariaDB
- Python 3.x
- Composer
- npm

---

## Instalasi Windows (Laragon)

### 1. Install Laragon

Download dan install [Laragon Full](https://laragon.org/download/)

### 2. Clone Repository

Buka Terminal di Laragon (klik kanan icon Laragon > Terminal):

```
git clone <repository-url> C:\laragon\www\moodle-lms
```

### 3. Install Dependencies

Buka folder project di Terminal Laragon:

```
cd C:\laragon\www\moodle-lms\moodle
composer install --no-dev
npm install
```

### 4. Setup Database

1. Buka **HeidiSQL** dari Laragon (klik kanan > MySQL > HeidiSQL)
2. Connect ke localhost
3. Klik kanan > Create new > Database
4. Nama: `moodle_lms`
5. Collation: `utf8mb4_unicode_ci`
6. OK

### 5. Konfigurasi

1. Copy file config:
   - Copy `moodle\public\config.php.example` menjadi `moodle\public\config.php`
   - Copy `moodle\config.php.example` menjadi `moodle\config.php`

2. Edit kedua file `config.php`:
   - `$CFG->dbuser = 'root';`
   - `$CFG->dbpass = '';` (kosong untuk Laragon default)
   - `$CFG->dataroot = 'C:/laragon/www/moodle-lms/moodledata';`

### 6. Buat Folder Data

Buat folder `moodledata` di `C:\laragon\www\moodle-lms\`

### 7. Setup Virtual Host Laragon

1. Klik kanan Laragon > Apache > sites-enabled > Add new
2. Atau edit manual `C:\laragon\etc\apache2\sites-enabled\auto.moodle-lms.conf`:

```apache
<VirtualHost *:80>
    DocumentRoot "C:/laragon/www/moodle-lms/moodle/public"
    ServerName moodle-lms.test
    <Directory "C:/laragon/www/moodle-lms/moodle/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

3. Reload Apache dari Laragon

### 8. Update Config untuk Virtual Host

Edit `moodle\public\config.php`:

```php
$CFG->wwwroot = 'http://moodle-lms.test';
```

### 9. Install Moodle

1. Buka browser: `http://moodle-lms.test`
2. Ikuti wizard instalasi
3. Buat akun admin

### 10. Jalankan Python Grader

Buka Command Prompt baru:

```
cd C:\laragon\www\moodle-lms\python-grader
pip install flask
python server.py
```

Grader jalan di `http://localhost:5001`

### 11. Konfigurasi ProFormA Grader

Di HeidiSQL, jalankan query:

```sql
UPDATE mdl_config_plugins 
SET value='http://localhost:5001' 
WHERE plugin='qtype_proforma' AND name='graderuri_host';
```

---

## Instalasi macOS/Linux

### 1. Clone Repository

```bash
git clone <repository-url>
cd moodle-lms
```

### 2. Install Dependencies

```bash
cd moodle
composer install --no-dev
npm install
```

### 3. Setup Database

```bash
mysql -u root -p
```

```sql
CREATE DATABASE moodle_lms DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

### 4. Konfigurasi

Copy dan edit config file:

```bash
cp moodle/public/config.php.example moodle/public/config.php
cp moodle/config.php.example moodle/config.php
```

Edit `moodle/public/config.php` dan `moodle/config.php`:
- `$CFG->dbuser` - Username database
- `$CFG->dbpass` - Password database  
- `$CFG->dataroot` - Path folder data (harus writable)

### 5. Buat Folder Data

```bash
mkdir ~/moodledata
chmod 777 ~/moodledata
```

Update `$CFG->dataroot` di config.php sesuai path folder.

### 6. Jalankan Moodle

```bash
cd moodle
php -d max_input_vars=5000 -S localhost:8080 -t public
```

Buka `http://localhost:8080` dan ikuti wizard instalasi.

### 7. Jalankan Python Grader

Terminal baru:

```bash
cd python-grader
pip install flask
python server.py
```

Grader jalan di `http://localhost:5001`

### 8. Konfigurasi ProFormA Grader

```bash
mysql -u root moodle_lms -e "UPDATE mdl_config_plugins SET value='http://localhost:5001' WHERE plugin='qtype_proforma' AND name='graderuri_host';"
```

## Struktur Project

```
moodle-lms/
├── moodle/                 # Moodle source code
│   ├── public/             # Web root
│   │   ├── question/type/proforma/  # ProFormA plugin
│   │   └── config.php      # Web config
│   └── config.php          # CLI config
├── python-grader/          # Custom Python grader
│   └── server.py           # Grader API server
├── moodledata/             # Moodle data files (gitignored)
└── README.md
```

## Penggunaan

### Membuat Soal Quiz dengan Auto Grading

1. Buat Course baru
2. Tambah Activity → Quiz
3. Edit Quiz → Add Question → ProFormA Task
4. Isi soal dan test code (Python unittest)
5. Save

### Contoh Test Code

**Soal: Print "Hello World!" 5 kali**

```python
import unittest
import subprocess
import os

class TestOutput(unittest.TestCase):
    def test_hello_world_5_times(self):
        student_file = None
        for f in os.listdir('.'):
            if f.endswith('.py') and not f.startswith('test'):
                student_file = f
                break
        
        self.assertIsNotNone(student_file, "File Python tidak ditemukan")
        
        result = subprocess.run(
            ['python3', student_file],
            capture_output=True,
            text=True,
            timeout=10
        )
        
        output = result.stdout.strip()
        expected = "Hello World!\nHello World!\nHello World!\nHello World!\nHello World!"
        self.assertEqual(output, expected)

if __name__ == '__main__':
    unittest.main()
```

## Troubleshooting

### Error: Database connection failed
- Pastikan MySQL sudah jalan
- Cek username/password di config.php

### Error: URL is blocked
- Pastikan `$CFG->disableurlblocking = true` di config.php

### Grader tidak merespon
- Pastikan python-grader/server.py sudah jalan di port 5001
- Cek dengan: `curl http://localhost:5001/status`

## License

Moodle - GNU GPL v3
