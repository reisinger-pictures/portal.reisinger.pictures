@echo off
set FORCE_COLOR=0
set NO_COLOR=1
echo ===================================================
echo 🧪 Starte Backend Tests...
echo ===================================================
cd backend
call php artisan test --no-ansi
if %errorlevel% neq 0 (
    echo ❌ Backend Tests fehlgeschlagen! Deployment abgebrochen.
    cd ..
    exit /b %errorlevel%
)
cd ..

echo.
echo ===================================================
echo 🧪 Starte Frontend Unit Tests...
echo ===================================================
cd frontend
call pnpm vitest run
if %errorlevel% neq 0 (
    echo ❌ Frontend Unit Tests fehlgeschlagen! Deployment abgebrochen.
    cd ..
    exit /b %errorlevel%
)

echo.
echo ===================================================
echo 🔧 Lint Frontend...
echo ===================================================
call pnpm lint:fix
if %errorlevel% neq 0 (
    echo ❌ Frontend Lint fehlgeschlagen! Deployment abgebrochen.
    cd ..
    exit /b %errorlevel%
)

echo.
echo ===================================================
echo 🧪 Starte Frontend E2E Tests...
echo ===================================================
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
