@echo off
setlocal

cd /d "%~dp0\.."

where pwsh.exe >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    set "POWERSHELL_EXE=pwsh.exe"
) else (
    set "POWERSHELL_EXE=powershell.exe"
)

echo Generation du ZIP de release...
"%POWERSHELL_EXE%" -NoProfile -ExecutionPolicy Bypass -File "%~dp0build-release-zip.ps1"
if not %ERRORLEVEL% EQU 0 (
    echo.
    echo ERREUR : la generation du ZIP a echoue.
    pause
    exit /b 1
)

echo.
echo ZIP genere dans : "%CD%\dist\360tranquilite.zip"
pause
endlocal
