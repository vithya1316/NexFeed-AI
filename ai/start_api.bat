@echo off
title NEXFEEDAI Food AI API

echo ==========================================
echo   NEXFEEDAI Food Freshness AI Server
echo   Keep this window open while testing
echo ==========================================
echo.

cd /d "D:\xampp\htdocs\NexFeedAI\ai"

echo Starting Flask API on http://localhost:5000 ...
echo.


"D:\python\python.exe" food_api.py

pause