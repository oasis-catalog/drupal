@ECHO OFF
setlocal DISABLEDELAYEDEXPANSION
SET BIN_TARGET=%~dp0/../vendor/drush/drush/drush
php "%BIN_TARGET%" %*
