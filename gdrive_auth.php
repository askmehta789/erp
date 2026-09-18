<?php
require_once __DIR__.'/functions.php'; require_login(); require_role(['Super Admin']);
require_once __DIR__.'/gdrive_api.php';

$err  = $_GET['error'] ?? '';
$code = $_GET['code'] ?? '';

if ($err !== '') {
  flash('Google Drive connection cancelled: '.$err);
} elseif ($code === '') {
  flash('No authorization code received from Google — please try Connect again.');
} else {
  try {
    $tokens = gdrive_exchange_code($code);
    set_setting('gdrive_refresh_token', $tokens['refresh_token']);
    try {
      $folderId = gdrive_ensure_folder($tokens['access_token']);
      set_setting('gdrive_folder_id', $folderId);
    } catch (Exception $e) {
      /* non-fatal — uploads will just create/find the folder again on first backup */
    }
    log_activity('Google Drive connected for automatic backups','Settings');
    flash('✅ Google Drive connected — your next daily backup will upload here automatically.');
  } catch (Exception $e) {
    flash('Google Drive connection failed: '.$e->getMessage());
  }
}
header('Location: settings.php'); exit;
