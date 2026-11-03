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

/* temporary — one-time cleanup: the sync used to import cancelled Daraz orders too
   (fixed in daraz_api.php — cancelled lines are now skipped at import time, never written).
   This removes the ones that already got in under the old behavior. Token-gated with the
   same cron_token used elsewhere for no-session automation. Only touches rows the sync
   itself created (daraz_order_id set) — manually-entered cancelled orders predate this
   feature and are left alone. */
if (isset($_GET['purge_cancelled'])) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    $given = trim((string)($_GET['token'] ?? ''));
    $want  = trim((string)setting('cron_token',''));
    if ($want === '' || $given === '' || !hash_equals($want, $given)) { echo "Invalid token.\n"; exit; }
    try {
        $rowsToDel = rows("SELECT id,product_id,qty,status FROM orders WHERE daraz_order_id IS NOT NULL AND status='cancelled'");
        $ids = array_map(fn($r) => (int)$r['id'], $rowsToDel);
        if (!$ids) { echo "Nothing to purge — 0 auto-synced cancelled orders found.\n"; exit; }
        if (($_GET['purge_cancelled'] ?? '') !== 'execute') {
            echo "DRY RUN — would delete " . count($ids) . " cancelled order row(s): " . implode(',', $ids) . "\nAdd &purge_cancelled=execute to actually delete.\n";
            exit;
        }
        foreach ($rowsToDel as $r) {
            if ($r['product_id']) stock_on_status_change((int)$r['product_id'], (int)$r['qty'], (string)$r['status'], '', (int)$r['id']);   /* reverse any stock effect a cancelled status had */
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        q("DELETE FROM orders WHERE id IN ($ph)", $ids);
        log_activity("Daraz sync cleanup: purged ".count($ids)." auto-synced cancelled order(s) — cancelled orders are no longer imported", 'Daraz');
        echo "Deleted " . count($ids) . " cancelled order row(s): " . implode(',', $ids) . "\n";
    } catch (Exception $ex) { echo "error: " . $ex->getMessage() . "\n"; }
    exit;
}

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
