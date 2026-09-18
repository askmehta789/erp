<?php
/* ============================================================
   gdrive_api.php — Google Drive backup uploader
   Uses standard OAuth2 "installed app" flow: a one-time consent
   (gdrive_auth.php) gets a refresh_token, stored in settings.
   Every cron run exchanges that for a short-lived access_token —
   no re-consent ever needed unless the user disconnects.
   Scope used is drive.file — this app can only see/manage files
   IT creates, never the rest of the person's Google Drive.
   ============================================================ */
require_once __DIR__.'/functions.php';

define('GDRIVE_REDIRECT_URI', 'https://luprah.online/erp/gdrive_auth.php');
define('GDRIVE_SCOPE', 'https://www.googleapis.com/auth/drive.file');

function gdrive_configured(): bool {
  return trim((string)setting('gdrive_client_id','')) !== ''
      && trim((string)setting('gdrive_client_secret','')) !== ''
      && trim((string)setting('gdrive_refresh_token','')) !== '';
}

/* the URL to send the user to for the one-time consent screen */
function gdrive_auth_url(): string {
  $clientId = trim((string)setting('gdrive_client_id',''));
  $params = [
    'client_id'     => $clientId,
    'redirect_uri'  => GDRIVE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => GDRIVE_SCOPE,
    'access_type'   => 'offline',   /* required to receive a refresh_token */
    'prompt'        => 'consent',   /* forces a refresh_token even on repeat connects */
  ];
  return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params);
}

function gdrive_http(string $url, array $opts = []) {
  $ch = curl_init($url);
  curl_setopt_array($ch, $opts + [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
  ]);
  $resp = curl_exec($ch);
  if ($resp === false) { $err = curl_error($ch); curl_close($ch); throw new Exception('Network error: '.$err); }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $data = json_decode((string)$resp, true);
  return [$code, is_array($data) ? $data : ['raw'=>$resp]];
}

/* exchange the one-time authorization code for tokens (called once, from gdrive_auth.php) */
function gdrive_exchange_code(string $code): array {
  $clientId = trim((string)setting('gdrive_client_id',''));
  $clientSecret = trim((string)setting('gdrive_client_secret',''));
  [$httpCode,$data] = gdrive_http('https://oauth2.googleapis.com/token', [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
      'code'=>$code,'client_id'=>$clientId,'client_secret'=>$clientSecret,
      'redirect_uri'=>GDRIVE_REDIRECT_URI,'grant_type'=>'authorization_code',
    ]),
  ]);
  if ($httpCode!==200 || empty($data['refresh_token'])) {
    throw new Exception($data['error_description'] ?? $data['error'] ?? ('Google did not return a refresh token (HTTP '.$httpCode.')'));
  }
  return $data;   // has access_token + refresh_token
}

/* fresh access_token for this run, from the stored refresh_token */
function gdrive_access_token(): string {
  $clientId = trim((string)setting('gdrive_client_id',''));
  $clientSecret = trim((string)setting('gdrive_client_secret',''));
  $refreshToken = trim((string)setting('gdrive_refresh_token',''));
  if ($clientId==='' || $clientSecret==='' || $refreshToken==='') throw new Exception('Google Drive is not connected.');
  [$httpCode,$data] = gdrive_http('https://oauth2.googleapis.com/token', [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
      'client_id'=>$clientId,'client_secret'=>$clientSecret,
      'refresh_token'=>$refreshToken,'grant_type'=>'refresh_token',
    ]),
  ]);
  if ($httpCode!==200 || empty($data['access_token'])) {
    throw new Exception($data['error_description'] ?? $data['error'] ?? ('Token refresh failed (HTTP '.$httpCode.')'));
  }
  return $data['access_token'];
}

/* find (or create) the backups folder, returns its Drive file id */
function gdrive_ensure_folder(string $accessToken, string $name='Luprah ERP Backups'): string {
  $q = "name='".str_replace("'","\\'",$name)."' and mimeType='application/vnd.google-apps.folder' and trashed=false";
  [$searchCode,$found] = gdrive_http('https://www.googleapis.com/drive/v3/files?'.http_build_query(['q'=>$q,'fields'=>'files(id,name)']), [
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"],
  ]);
  if (!empty($found['files'][0]['id'])) return $found['files'][0]['id'];
  if ($searchCode < 200 || $searchCode >= 300) {
    throw new Exception('Drive folder search failed (HTTP '.$searchCode.'): '.($found['error']['message'] ?? json_encode($found)));
  }
  [$httpCode,$data] = gdrive_http('https://www.googleapis.com/drive/v3/files?fields=id', [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken","Content-Type: application/json"],
    CURLOPT_POSTFIELDS => json_encode(['name'=>$name,'mimeType'=>'application/vnd.google-apps.folder']),
  ]);
  if ($httpCode>=200 && $httpCode<300 && !empty($data['id'])) return $data['id'];
  throw new Exception('Could not create the Drive backups folder (HTTP '.$httpCode.'): '.($data['error']['message'] ?? json_encode($data)));
}

/* upload one file to Drive. Returns the new file's Drive id. */
function gdrive_upload_file(string $filePath, string $filename, string $mime='application/gzip'): string {
  if (!is_file($filePath)) throw new Exception('File not found: '.$filePath);
  if (filesize($filePath) > 60*1024*1024) throw new Exception('File too large for simple upload (>60MB) — skipped.');

  $accessToken = gdrive_access_token();
  $folderId = trim((string)setting('gdrive_folder_id',''));
  if ($folderId==='') { $folderId = gdrive_ensure_folder($accessToken); set_setting('gdrive_folder_id',$folderId); }

  $boundary = 'gdrive'.bin2hex(random_bytes(12));
  $metadata = json_encode(['name'=>$filename,'parents'=>[$folderId]]);
  $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$metadata\r\n"
        . "--$boundary\r\nContent-Type: $mime\r\n\r\n" . file_get_contents($filePath) . "\r\n--$boundary--";

  [$httpCode,$data] = gdrive_http('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id', [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken","Content-Type: multipart/related; boundary=$boundary"],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_TIMEOUT => 120,
  ]);
  if ($httpCode>=200 && $httpCode<300 && !empty($data['id'])) return $data['id'];
  /* the folder may have been deleted from Drive since we last checked — retry once, fresh folder */
  if ($httpCode===404) {
    $folderId = gdrive_ensure_folder($accessToken);
    set_setting('gdrive_folder_id',$folderId);
    return gdrive_upload_file($filePath,$filename,$mime);
  }
  throw new Exception($data['error']['message'] ?? ('Drive upload failed (HTTP '.$httpCode.')'));
}

function gdrive_disconnect(): void {
  set_setting('gdrive_refresh_token','');
  set_setting('gdrive_folder_id','');
}