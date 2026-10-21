@echo off
where py >nul 2>nul
if %errorlevel%==0 (
  py -3 "%~dp0pull.py" %*
  exit /b
)
where python >nul 2>nul
if %errorlevel%==0 (
  python "%~dp0pull.py" %*
  exit /b
)
echo Python is required: https://www.python.org/downloads/
echo Install Python 3.9 or newer and enable Add Python to PATH.
pause
