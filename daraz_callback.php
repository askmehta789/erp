<?php
/* =====================================================================
   daraz_callback.php — OAuth2 redirect target registered with Daraz
   Open Platform (Settings → Channel / Daraz API → Redirect/Callback URL).

   Daraz's own servers/browser land here after the store owner logs in
   and approves access — there is no ERP session at that point, so this
   file is intentionally public (no require_login()).

   On a real authorization (?code=...) this exchanges the one-time code
   for an access/refresh token via daraz_api.php's signed /auth/token/create
   call, and saves them (Daraz → Settings → Channel / Daraz API handles
   the App Key/Secret side of this).
   ===================================================================== */
require_once __DIR__.'/daraz_api.php';

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

try {
    $daraz = new Daraz();
    $tokenData = $daraz->tokenCreate($code);
    try { log_activity('Daraz seller authorization completed ('.($tokenData['account'] ?? 'account').')', 'Daraz'); } catch (Exception $e) {}
    http_response_code(200);
    echo "Daraz account connected successfully. Orders will now be pulled into Luprah ERP automatically.";
} catch (Exception $ex) {
    try { q("INSERT INTO settings(skey,svalue) VALUES('daraz_last_auth_error',?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)", [$ex->getMessage().' @ '.date('Y-m-d H:i:s')]); } catch (Exception $e) {}
    http_response_code(200);
    echo "Daraz authorization code was received, but connecting failed: ".$ex->getMessage();
}
