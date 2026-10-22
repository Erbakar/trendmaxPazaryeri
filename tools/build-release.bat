@echo off
py -3 "%~dp0build-release.py" %*
exit /b %errorlevel%
