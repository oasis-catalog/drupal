@ECHO OFF
setlocal DISABLEDELAYEDEXPANSION
SET BIN_TARGET=%~dp0/../vendor/consolidation/robo/robo
php "%BIN_TARGET%" %*
