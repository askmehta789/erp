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

/* temporary deploy-verification check — safe, read-only, no secrets.
   confirms the live daraz_api.php actually matches what was pushed, since
   stale OPcache/CDN caching can silently keep serving an old file version. */
if (isset($_GET['filecheck'])) {
    http_response_code(200);
    echo "daraz_api.php md5: " . md5_file(__DIR__.'/daraz_api.php') . "\n";
    echo "daraz_api.php mtime: " . date('Y-m-d H:i:s', filemtime(__DIR__.'/daraz_api.php')) . "\n";
    echo "daraz.php mtime: " . date('Y-m-d H:i:s', filemtime(__DIR__.'/daraz.php')) . "\n";
    echo "daraz.php has mapping panel: " . (strpos(file_get_contents(__DIR__.'/daraz.php'), 'Map Unmatched Daraz Products') !== false ? 'YES' : 'NO') . "\n";
    echo "daraz_callback.php mtime: " . date('Y-m-d H:i:s', filemtime(__FILE__)) . "\n";
    exit;
}

/* temporary — check whether today's auto-sync duplicated any Daraz order that staff had
   already entered manually before (manual entries store the bare Daraz order id in Remarks,
   with no daraz_order_id set — the sync's own dedup check never saw them). Read-only. */
if (isset($_GET['dupecheck'])) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    try {
        $rows = rows("SELECT n.id AS new_id, n.code AS new_code, n.customer AS new_customer, n.sell_price AS new_price, n.qty AS new_qty, n.daraz_order_id,
                             o.id AS old_id, o.code AS old_code, o.customer AS old_customer, o.sell_price AS old_price, o.qty AS old_qty
                      FROM orders n
                      JOIN orders o ON o.courier_id = n.courier_id AND o.daraz_order_id IS NULL AND TRIM(o.remarks) = n.daraz_order_id AND o.id <> n.id
                      WHERE n.daraz_order_id IS NOT NULL
                      ORDER BY n.id");
        echo "possible duplicate pairs found: " . count($rows) . "\n\n";
        foreach ($rows as $r) {
            echo "Daraz #{$r['daraz_order_id']}: NEW order#{$r['new_code']} (id {$r['new_id']}) {$r['new_customer']} qty={$r['new_qty']} price={$r['new_price']}"
               . "  <->  OLD order#{$r['old_code']} (id {$r['old_id']}) {$r['old_customer']} qty={$r['old_qty']} price={$r['old_price']}\n";
        }
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
