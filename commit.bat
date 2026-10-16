```bat
@echo off
title Git Commit, Push & Release
setlocal

echo ================================
echo   Git Commit, Push & Release
echo ================================
echo.

git add .
if errorlevel 1 goto error

git commit -m "commit"
if errorlevel 1 goto error

git push
if errorlevel 1 goto error

echo.
set /p "RELEASE_NAME=Name des Releases: "

if "%RELEASE_NAME%"=="" (
    echo Fehler: Der Release-Name darf nicht leer sein.
    pause
    exit /b 1
)

echo.
echo Erstelle GitHub-Release: %RELEASE_NAME%
gh release create "%RELEASE_NAME%" --generate-notes

if errorlevel 1 goto error

echo.
echo Release erfolgreich erstellt!
pause
exit /b 0

:error
echo.
echo Fehler beim Ausfuehren eines Befehls!
pause
exit /b 1
```