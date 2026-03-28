@echo off
echo ===================================================
echo 🧪 Starte Backend Tests...
echo ===================================================
cd backend
call php artisan test
if %errorlevel% neq 0 (
    echo ❌ Backend Tests fehlgeschlagen! Deployment abgebrochen.
    cd ..
    exit /b %errorlevel%
)
cd ..

echo.
echo ===================================================
echo 🧪 Starte Frontend E2E Tests...
echo ===================================================
cd frontend
call pnpm test:e2e
if %errorlevel% neq 0 (
    echo ❌ Frontend E2E Tests fehlgeschlagen! Deployment abgebrochen.
    cd ..
    exit /b %errorlevel%
)

echo.
echo ===================================================
echo 🚀 Baue Frontend für Produktion...
echo ===================================================
call pnpm install
call pnpm build
if %errorlevel% neq 0 (
    echo ❌ Frontend Build fehlgeschlagen! Deployment abgebrochen.
    cd ..
    exit /b %errorlevel%
)
cd ..

echo.
call sync.bat
