@echo off
title ReadLog Control
rem The control panel for the locally hosted ReadLog: on, off, status, public
rem URL. Plain Windows python; nothing to install. See ops/desktop/readlogctl.py.
cd /d "%~dp0..\.."
python "ops\desktop\readlogctl.py" %*
if errorlevel 1 (
    echo.
    pause
)
