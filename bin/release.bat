@ECHO OFF
setlocal DISABLEDELAYEDEXPANSION
SET BIN_TARGET=%~dp0/../vendor/consolidation/self-update/scripts/release
php "%BIN_TARGET%" %*
