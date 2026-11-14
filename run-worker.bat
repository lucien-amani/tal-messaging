@echo off
chcp 65001 >nul
cd /d "%~dp0"
set PHP=C:\xampp\php\php.exe
if not exist "%PHP%" set PHP=php
echo Envoi des messages en file d attente. Laissez cette fenetre ouverte (Ctrl+C pour arreter).
"%PHP%" scripts\worker.php --loop --sleep=3
pause
