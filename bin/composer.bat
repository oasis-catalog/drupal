@ECHO OFF
setlocal DISABLEDELAYEDEXPANSION
SET BIN_TARGET=%~dp0/../vendor/composer/composer/bin/composer
php "%BIN_TARGET%" %*
