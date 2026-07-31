@echo off
echo ===================================================
echo 🔄 Starte reinen Rclone Sync zum Server...
echo ===================================================

:: 1. API Bereich (Backend)
echo 📦 Sync: API-Ordner...
rclone sync ./backend reisinger.pictures:/api-portal.reisinger.pictures --filter-from rclone-backend-filter.txt --transfers=128 --track-renames --progress

:: 2. Web Bereich (Frontend Dist)
echo 🎨 Sync: Frontend (dist)...
rclone sync ./frontend/dist reisinger.pictures:/web-portal.reisinger.pictures/dist --transfers=128 --track-renames --progress

echo.
echo ✅ Sync abgeschlossen!
