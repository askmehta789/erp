<?php
/* ============================================================
   daraz_api.php — Daraz Open Platform (Seller Inhouse) API client

   OAuth2 "code for token" flow + signed REST calls, per
   https://open.daraz.com/doc (Developer's Guide → Call API with
   HTTP Requests). Signature algorithm verified against the docs'
   own worked example: HMAC-SHA256(app_secret, apiPath + sorted
   "key"+"value" pairs), uppercase hex.

   Base REST host: Daraz's docs show a generic "https://api.daraz.com/rest"
   in their SDK samples, but that host doesn't actually resolve — DNS
   confirms only the country-specific hosts exist (same ones used for
   /oauth/authorize), so Nepal's real token/business-API host is
   https://api.daraz.com.np/rest.
   ============================================================ */
require_once __DIR__.'/functions.php';

class Daraz {
  private string $base = 'https://api.daraz.com.np/rest';
  private string $appKey;
  private string $appSecret;
  private $ch = null;

  public function __construct() {
    $this->appKey    = trim((string)setting('daraz_app_key',''));
    $this->appSecret = trim((string)setting('daraz_app_secret',''));
  }

  public function configured(): bool { return $this->appKey !== '' && $this->appSecret !== ''; }

  /* the one-time browser link that sends the seller to Daraz to approve this app (Nepal) */
  public function authorizeUrl(): string {
    $redirect = trim((string)setting('daraz_redirect_uri',''));
    return 'https://api.daraz.com.np/oauth/authorize?' . http_build_query([
      'response_type' => 'code',
      'force_auth'    => 'true',
      'redirect_uri'  => $redirect,
      'client_id'     => $this->appKey,
    ]);
  }

  /* HMAC-SHA256(app_secret, apiPath + sorted "key"+"value" pairs), uppercase hex */
  private function sign(string $apiPath, array $params): string {
    ksort($params, SORT_STRING);
    $concat = $apiPath;
    foreach ($params as $k => $v) $concat .= $k . $v;
    return strtoupper(hash_hmac('sha256', $concat, $this->appSecret));
  }

  /* POSTs a signed request to $apiPath (e.g. "/auth/token/create") with the given business params */
  private function call(string $apiPath, array $params = []): array {
    if (!$this->configured()) throw new Exception('Daraz App Key/Secret not set. Add them in Settings → Channel / Daraz API.');

    $common = [
      'app_key'     => $this->appKey,
      'timestamp'   => (string)round(microtime(true) * 1000),
      'sign_method' => 'sha256',
    ];
    $all = array_merge($params, $common);
    $all['sign'] = $this->sign($apiPath, $all);

    $url = $this->base . $apiPath;

    if (function_exists('curl_init')) {
      if ($this->ch === null) $this->ch = curl_init();
      $ch = $this->ch;
      curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($all),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
      ]);
      $body = curl_exec($ch);
      $err  = curl_error($ch);
      if ($body === false) throw new Exception('Could not reach Daraz: ' . $err);
    } else {
      $opts = ['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($all),
        'timeout' => 15,
        'ignore_errors' => true,
      ]];
      $body = @file_get_contents($url, false, stream_context_create($opts));
      if ($body === false) throw new Exception('Could not reach Daraz (cURL not available on server).');
    }

    $data = json_decode($body, true);
    if (!is_array($data)) throw new Exception('Daraz returned an unreadable response: ' . substr((string)$body, 0, 200));
    /* Daraz/Lazada Open Platform: success responses carry code "0" (or no "code" at all);
       anything else is an error, with "message" describing it */
    if (isset($data['code']) && (string)$data['code'] !== '0') {
      throw new Exception('Daraz API error (' . $data['code'] . '): ' . ($data['message'] ?? 'unknown error'));
    }
    return $data;
  }

  /* exchange a one-time authorization code (from daraz_callback.php) for access+refresh tokens */
  public function tokenCreate(string $code): array {
    $data = $this->call('/auth/token/create', ['code' => $code]);
    $this->saveTokens($data);
    return $data;
  }

  /* refresh the access token using the stored refresh token */
  public function tokenRefresh(): array {
    $refresh = trim((string)setting('daraz_refresh_token',''));
    if ($refresh === '') throw new Exception('No Daraz refresh token saved yet — the seller needs to authorize the app first.');
    $data = $this->call('/auth/token/refresh', ['refresh_token' => $refresh]);
    $this->saveTokens($data);
    return $data;
  }

  private function saveTokens(array $data): void {
    $now = time();
    $set = function($k, $v) { q("INSERT INTO settings(skey,svalue) VALUES(?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)", [$k, (string)$v]); };
    if (isset($data['access_token']))      $set('daraz_access_token', $data['access_token']);
    if (isset($data['refresh_token']))     $set('daraz_refresh_token', $data['refresh_token']);
    if (isset($data['expires_in']))        $set('daraz_token_expires_at', $now + (int)$data['expires_in']);
    if (isset($data['refresh_expires_in']))$set('daraz_refresh_expires_at', $now + (int)$data['refresh_expires_in']);
    if (isset($data['account']))           $set('daraz_account', $data['account']);
  }

  /* a currently-valid access token, auto-refreshing when it's within 30 min of expiry
     (per Daraz's own "refresh 30 minutes before it expires" recommendation) */
  public function getValidAccessToken(): string {
    $token     = trim((string)setting('daraz_access_token',''));
    $expiresAt = (int)setting('daraz_token_expires_at', '0');
    if ($token === '') throw new Exception('Daraz is not connected yet — the seller needs to authorize the app first.');
    if ($expiresAt > 0 && $expiresAt - time() < 1800) {
      $refreshExpiresAt = (int)setting('daraz_refresh_expires_at', '0');
      if ($refreshExpiresAt > time()) {
        $this->tokenRefresh();
        $token = trim((string)setting('daraz_access_token',''));
      }
    }
    return $token;
  }

  public function connected(): bool { return trim((string)setting('daraz_access_token','')) !== ''; }
}
