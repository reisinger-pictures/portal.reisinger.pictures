@echo off
echo ===================================================
echo 🚀 Baue Frontend für Produktion...
echo ===================================================
cd frontend
call pnpm install
call pnpm build
if %errorlevel% neq 0 (
    echo ❌ Frontend Build fehlgeschlagen! Deployment abgebrochen.
    cd ..
    pause
    exit /b %errorlevel%
)
cd ..

echo.
echo ===================================================
echo 🔄 Starte Rclone Sync zum Server...
echo ===================================================

:: 1. API Bereich (Backend)
echo 📦 Sync: API-Ordner...
rclone sync ./backend reisinger.pictures:/home/webadmin/websites/api-portal.reisinger.pictures --filter-from rclone-backend-filter.txt --transfers=128 --track-renames --progress

:: 2. Docker Compose Datei
echo 🐳 Sync: Docker-Compose...
rclone copy ./deployment/docker-compose.yml reisinger.pictures:/home/webadmin/websites/api-portal.reisinger.pictures/

:: 3. Web Bereich (Frontend Dist)
echo 🎨 Sync: Frontend (dist)...
rclone sync ./frontend/dist reisinger.pictures:/home/webadmin/websites/web-portal.reisinger.pictures/dist --transfers=128 --track-renames --progress

:: 4. Web Bereich (Frontend Config)
echo ⚙️ Sync: Nginx Config...
rclone copy ./frontend/nginx.conf reisinger.pictures:/home/webadmin/websites/web-portal.reisinger.pictures/config/

echo.
echo ✅ Deployment abgeschlossen!

