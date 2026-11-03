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

/* temporary — deletes exactly the duplicate rows ?dupecheck=1 identified (today's sync
   re-importing orders staff had already entered manually). Token-gated with the same
   cron_token this app already uses for other no-session automation (Settings > Automation),
   since this file has no login. product_id was null on every duplicate (unmatched SKU), so
   none of them touched stock — plain DELETE is safe, nothing to reverse. */
if (isset($_GET['cleanup_dupes'])) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    $given = trim((string)($_GET['token'] ?? ''));
    $want  = trim((string)setting('cron_token',''));
    if ($want === '' || $given === '' || !hash_equals($want, $given)) { echo "Invalid token.\n"; exit; }
    try {
        $dupeIds = rows("SELECT DISTINCT n.id
                          FROM orders n
                          JOIN orders o ON o.courier_id = n.courier_id AND o.daraz_order_id IS NULL AND TRIM(o.remarks) = n.daraz_order_id AND o.id <> n.id
                          WHERE n.daraz_order_id IS NOT NULL");
        $ids = array_map(fn($r) => (int)$r['id'], $dupeIds);
        if (!$ids) { echo "Nothing to clean up — 0 duplicates found.\n"; exit; }
        if (($_GET['cleanup_dupes'] ?? '') !== 'execute') {
            echo "DRY RUN — would delete " . count($ids) . " order row(s): " . implode(',', $ids) . "\nAdd &cleanup_dupes=execute to actually delete.\n";
            exit;
        }
        /* safety: refuse to touch any row with product_id set (never true for these, but guard anyway) */
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $unsafe = (int)val("SELECT COUNT(*) FROM orders WHERE id IN ($ph) AND product_id IS NOT NULL", $ids);
        if ($unsafe > 0) { echo "Refusing — $unsafe of these rows unexpectedly have a product_id set. Aborted, nothing deleted.\n"; exit; }
        q("DELETE FROM orders WHERE id IN ($ph)", $ids);
        log_activity("Daraz sync cleanup: deleted ".count($ids)." duplicate order(s) that were already entered manually", 'Daraz');
        echo "Deleted " . count($ids) . " duplicate order row(s): " . implode(',', $ids) . "\n";
    } catch (Exception $ex) { echo "error: " . $ex->getMessage() . "\n"; }
    exit;
}

/* temporary — the very first sync ran before the daraz_sku/daraz_item_name columns existed
   (added in a later commit), so those still-unmatched rows have no SKU recorded for the
   mapping panel to show, even though they're otherwise fine. Same pattern as cleanup_dupes:
   delete just the orphaned-format rows (courier=Daraz, unmatched, no daraz_sku) and let a
   fresh "Sync Orders Now" recreate them correctly — Daraz's own dedup-on-remarks fix means
   nothing else gets touched. Token-gated, dry-run by default. */
if (isset($_GET['resync_legacy'])) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    $given = trim((string)($_GET['token'] ?? ''));
    $want  = trim((string)setting('cron_token',''));
    if ($want === '' || $given === '' || !hash_equals($want, $given)) { echo "Invalid token.\n"; exit; }
    try {
        $dzId = (int)val("SELECT id FROM couriers WHERE LOWER(name)='daraz' LIMIT 1");
        $rowsToFix = rows("SELECT id FROM orders WHERE courier_id=? AND daraz_order_id IS NOT NULL AND product_id IS NULL AND (daraz_sku IS NULL OR daraz_sku='')", [$dzId]);
        $ids = array_map(fn($r) => (int)$r['id'], $rowsToFix);
        if (!$ids) { echo "Nothing to fix — 0 orphaned-format rows found.\n"; exit; }
        if (($_GET['resync_legacy'] ?? '') !== 'execute') {
            echo "DRY RUN — would delete " . count($ids) . " order row(s) so a fresh sync recreates them with SKU recorded: " . implode(',', $ids) . "\nAdd &resync_legacy=execute to actually delete, then click Sync Orders Now.\n";
            exit;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $unsafe = (int)val("SELECT COUNT(*) FROM orders WHERE id IN ($ph) AND product_id IS NOT NULL", $ids);
        if ($unsafe > 0) { echo "Refusing — $unsafe of these rows unexpectedly have a product_id set. Aborted, nothing deleted.\n"; exit; }
        q("DELETE FROM orders WHERE id IN ($ph)", $ids);
        log_activity("Daraz sync cleanup: deleted ".count($ids)." orphaned-format order(s) for resync with SKU tracking", 'Daraz');
        echo "Deleted " . count($ids) . " order row(s). Now click Sync Orders Now to re-pull them with SKU tracking.\n";
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
