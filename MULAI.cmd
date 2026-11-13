@echo off
cd /d "%~dp0"
echo CIRCLE SIMPLE
echo Buka http://127.0.0.1:8788 di browser setelah layanan berjalan.
echo Tekan Ctrl+C di jendela ini untuk berhenti.
if not exist "C:\xampp\php\php.exe" (
  echo PHP XAMPP belum ditemukan di C:\xampp\php\php.exe
  pause
  exit /b 1
)
rem Jika layanan sudah aktif, buka aplikasi tanpa mencoba menjalankan port yang sama.
powershell -NoProfile -Command "try { $response = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8788' -TimeoutSec 2; if ($response.Content -like '*CIRCLE*') { exit 0 } } catch {}; exit 1" >nul 2>&1
if not errorlevel 1 (
  echo CIRCLE sudah berjalan. Membuka browser...
  start "" "http://127.0.0.1:8788"
  exit /b 0
)
"C:\xampp\php\php.exe" -d extension=gd -S 127.0.0.1:8788 -t "%~dp0." "%~dp0index.php"
pause
