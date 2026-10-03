@echo off
REM Starts everything FitGym needs, each in its own window:
REM   - the web server (http://127.0.0.1:8000)
REM   - Vite (front-end assets)
REM   - the scheduler (daily 8:00 AM membership expiry reminders by SMS + email)
REM Close the three windows to stop FitGym.
cd /d "%~dp0"

start "FitGym - Server" cmd /k php artisan serve
start "FitGym - Vite" cmd /k npm run dev
start "FitGym - Scheduler" cmd /k php artisan schedule:work

echo FitGym is starting. Open http://127.0.0.1:8000 in your browser in a few seconds.
timeout /t 5 >nul
start "" http://127.0.0.1:8000
