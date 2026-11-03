<?php
/* =====================================================================
   daraz_callback.php — OAuth2 redirect target registered with Daraz
   Open Platform (Settings → Channel / Daraz API → Redirect/Callback URL).

   Daraz's own servers/browser land here after the store owner logs in
   and approves access — there is no ERP session at that point, so this
   file is intentionally public (no require_login()).

   Right now this only RECEIVES the authorization code and stores it —
   it does NOT yet exchange it for an access/refresh token. Daraz Open
   Platform signs every token-exchange request with HMAC-SHA256 over the
   API path + sorted params, keyed by the App Secret; that exchange will
   be added once real App Key/Secret values are entered in Settings and
   this flow has been run once against the live API to confirm the exact
   signing details for this account.
   ===================================================================== */
require_once __DIR__.'/db.php';

header('Content-Type: text/plain; charset=utf-8');

$code  = trim((string)($_GET['code']  ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

/* plain reachability check (Daraz's app form may ping this URL with no params) */
if ($code === '' && $error === '') {
    http_response_code(200);
    echo "OK — Luprah ERP Daraz callback endpoint is reachable.";
    exit;
}

if ($error !== '') {
    try { q("INSERT INTO settings(skey,svalue) VALUES('daraz_last_auth_error',?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)", [$error.' @ '.date('Y-m-d H:i:s')]); } catch (Exception $e) {}
    http_response_code(200);
    echo "Daraz authorization was not completed: ".$error;
    exit;
}

/* store the one-time authorization code + when it arrived; the actual
   token exchange (code -> access_token/refresh_token) is a follow-up
   step once App Key/Secret are configured in Settings */
try {
    q("INSERT INTO settings(skey,svalue) VALUES('daraz_last_auth_code',?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)", [$code]);
    q("INSERT INTO settings(skey,svalue) VALUES('daraz_last_auth_at',?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)", [date('Y-m-d H:i:s')]);
} catch (Exception $e) {}

http_response_code(200);
echo "Daraz authorization received. The ERP admin still needs to finish connecting this — nothing syncs yet.";
