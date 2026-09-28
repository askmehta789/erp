<?php
/* ============================================================
   pickndrop_webhook.php — receives live order-status pushes from
   Pick & Drop (registered via Settings → Pick & Drop → Register Webhook).

   Pick & Drop's docs don't specify a request-signature scheme, so this
   endpoint is protected by a random token WE generate and embed in the
   URL we register with them (?token=...) — never trust the payload alone.

   Expected POST JSON body (per https://pickndrop.apidog.io/):
     {orderID, tracking_number, status, comments, epod, package_type, timestamp}
   ============================================================ */
require_once __DIR__.'/pickndrop_api.php';

header('Content-Type: application/json');

$want = trim((string)setting('pd_webhook_secret',''));
$given = trim((string)($_GET['token'] ?? ''));
if ($want === '' || $given === '' || !hash_equals($want, $given)) {
  http_response_code(403);
  echo json_encode(['status'=>'error','message'=>'invalid token']);
  exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>'invalid JSON body']);
  exit;
}

pd_ensure_cols();

$orderId = trim((string)($data['orderID'] ?? ''));
$tracking = trim((string)($data['tracking_number'] ?? ''));
$rawStatus = trim((string)($data['status'] ?? ''));

$o = null;
if ($orderId !== '') $o = row("SELECT * FROM orders WHERE pd_order_id=? LIMIT 1", [$orderId]);
if (!$o && $tracking !== '') $o = row("SELECT * FROM orders WHERE pd_order_id=? LIMIT 1", [$tracking]);

if (!$o) {
  http_response_code(200);   /* acknowledge anyway — nothing to match yet, don't make them retry forever */
  echo json_encode(['status'=>'ok','message'=>'order not found, ignored']);
  exit;
}

$mapped = $rawStatus !== '' ? pd_to_local_status($rawStatus) : null;
try { q("UPDATE orders SET pd_status=? WHERE id=?", [$rawStatus, $o['id']]); } catch (Exception $e) {}

if ($mapped && $mapped !== $o['status']) {
  fifo_status_change((int)$o['id'], (int)$o['product_id'], (int)$o['qty'], $o['status'], $mapped);
  if ($mapped==='delivered') q("UPDATE orders SET status='delivered', payment_status='paid' WHERE id=?", [$o['id']]);
  else q("UPDATE orders SET status=? WHERE id=?", [$mapped, $o['id']]);
  log_activity("Pick & Drop webhook: order {$o['code']} status {$o['status']}→$mapped ($rawStatus)", 'Pick & Drop');
  try {
    q("CREATE TABLE IF NOT EXISTS notifications(id INT AUTO_INCREMENT PRIMARY KEY,type VARCHAR(30) DEFAULT 'info',message VARCHAR(255) NOT NULL,link VARCHAR(120) DEFAULT '',is_read TINYINT(1) DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    if ($mapped==='delivered') q("INSERT INTO notifications(type,message,link) VALUES('delivered',?,?)", ["Pick & Drop delivered order {$o['code']} ✅", 'pickndrop.php']);
    elseif ($mapped==='returned') q("INSERT INTO notifications(type,message,link) VALUES('ncm',?,?)", ["Pick & Drop returned order {$o['code']} ↩️", 'pickndrop.php']);
  } catch (Exception $e) {}
}

echo json_encode(['status'=>'ok']);
