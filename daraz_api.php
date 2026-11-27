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

  /* Sends a signed request to $apiPath with the given business params. Daraz enforces an exact
     HTTP method per endpoint (not interchangeable): /auth/token/create|refresh need POST,
     but GetOrders/GetMultipleOrderItems reject POST with "UnsupportedHTTPMethod" and need GET
     — confirmed against the live API, not assumed. */
  private function call(string $apiPath, array $params = [], string $method = 'POST'): array {
    if (!$this->configured()) throw new Exception('Daraz App Key/Secret not set. Add them in Settings → Channel / Daraz API.');

    $common = [
      'app_key'     => $this->appKey,
      'timestamp'   => (string)round(microtime(true) * 1000),
      'sign_method' => 'sha256',
    ];
    $all = array_merge($params, $common);
    $all['sign'] = $this->sign($apiPath, $all);

    $url = $this->base . $apiPath;
    $isGet = (strtoupper($method) === 'GET');
    if ($isGet) $url .= '?' . http_build_query($all);

    if (function_exists('curl_init')) {
      /* a fresh handle per call (not reused) — cURL's internal request-type state is sticky in
         ways that fight CURLOPT_HTTPGET on a handle that previously did a POST (see below), and
         a reused handle isn't worth the risk for calls this infrequent */
      $ch = curl_init();
      $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
      ];
      if ($isGet) {
        $opts[CURLOPT_HTTPGET] = true;   /* must NOT also set CURLOPT_POSTFIELDS (even '') — doing
                                             so silently re-forces POST regardless of this flag,
                                             confirmed empirically against a live echo endpoint */
      } else {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($all);
      }
      curl_setopt_array($ch, $opts);
      $body = curl_exec($ch);
      $err  = curl_error($ch);
      curl_close($ch);
      if ($body === false) throw new Exception('Could not reach Daraz: ' . $err);
    } else {
      $opts = ['http' => [
        'method'  => $isGet ? 'GET' : 'POST',
        'header'  => $isGet ? '' : "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $isGet ? '' : http_build_query($all),
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

  /* authenticated call — adds the current access token (auto-refreshing first if needed) */
  private function authedCall(string $apiPath, array $params = [], string $method = 'GET'): array {
    $params['access_token'] = $this->getValidAccessToken();
    return $this->call($apiPath, $params, $method);
  }

  /* GetOrders (/orders/get) — order headers only, no line items. $opts: created_after/
     created_before/update_after/update_before (ISO8601), status, offset, limit (max 100). */
  public function getOrders(array $opts = []): array {
    $params = [];
    foreach (['created_after','created_before','update_after','update_before','status','sort_by','sort_direction'] as $k) {
      if (isset($opts[$k]) && $opts[$k] !== '') $params[$k] = (string)$opts[$k];
    }
    $params['offset'] = (string)($opts['offset'] ?? 0);
    $params['limt']   = (string)($opts['limit'] ?? 100);   /* "limt" — Daraz's own documented (misspelled) param name */
    $data = $this->authedCall('/orders/get', $params, 'GET');
    return $data['data'] ?? ['orders' => [], 'count' => 0, 'countTotal' => 0];
  }

  /* GetMultipleOrderItems (/orders/items/get) — line items (sku, qty-as-separate-rows, price,
     status) for up to a batch of order ids. Daraz caps how many ids one call accepts, so callers
     should chunk (this class doesn't chunk internally since the cap isn't documented precisely). */
  public function getOrderItems(array $orderIds): array {
    if (!$orderIds) return [];
    $data = $this->authedCall('/orders/items/get', ['order_ids' => '[' . implode(',', array_map('intval', $orderIds)) . ']']);
    return $data['data'] ?? [];
  }

  /* GetOrder (/order/get) — ONE order header, by its Daraz order number */
  public function getOrder(string $orderId): array {
    $data = $this->authedCall('/order/get', ['order_id' => preg_replace('/\D+/', '', $orderId)]);
    return $data['data'] ?? [];
  }

  /* GetOrderItems (/order/items/get) — line items of ONE order (multi-qty = repeated rows) */
  public function getOrderItemsOne(string $orderId): array {
    $data = $this->authedCall('/order/items/get', ['order_id' => preg_replace('/\D+/', '', $orderId)]);
    return $data['data'] ?? [];
  }

  /* QueryTransactionDetails (/finance/transaction/details/get) — the real money Daraz
     credited/charged (item price credit, commission, payment fee, shipping fee, vouchers,
     penalties). Needs the app's Finance permission; callers must treat failure as
     "fees not available" rather than an error. Dates are Y-m-d. */
  public function getTransactions(string $start, string $end, string $orderId = '', int $offset = 0, int $limit = 500): array {
    $p = ['start_time' => $start, 'end_time' => $end, 'offset' => (string)$offset, 'limit' => (string)$limit];
    if ($orderId !== '') $p['trade_order_id'] = preg_replace('/\D+/', '', $orderId);
    $data = $this->authedCall('/finance/transaction/details/get', $p);
    $d = $data['data'] ?? [];
    return isset($d[0]) || $d === [] ? $d : ($d['list'] ?? $d['transactions'] ?? []);
  }
}

/* Daraz sometimes sends money as text ("1,450.00") — a plain (float) cast reads that as 1.
   Strip everything but digits, dot and minus before converting. */
function dz_num($v): float {
  if (is_int($v) || is_float($v)) return (float)$v;
  $s = preg_replace('/[^0-9.\-]/', '', (string)$v);
  return ($s === '' || $s === '-' || $s === '.') ? 0.0 : (float)$s;
}

/* Daraz order/item status -> this ERP's internal order status */
function daraz_map_status(string $s): string {
  $s = strtolower(trim($s));
  $map = [
    'unpaid' => 'pending', 'pending' => 'pending',
    'topack' => 'processing', 'toship' => 'processing', 'ready_to_ship' => 'processing',
    'shipped' => 'shipped',
    'delivered' => 'delivered',
    'returned' => 'returned',
    'canceled' => 'cancelled', 'cancelled' => 'cancelled', 'failed' => 'cancelled',
  ];
  return $map[$s] ?? 'pending';
}

function daraz_ensure_cols(): void {
  static $done = false; if ($done) return;
  try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS daraz_order_id VARCHAR(40) NULL"); } catch (Exception $e) {}
  try { q("ALTER TABLE orders ADD INDEX idx_daraz_order_id (daraz_order_id)"); } catch (Exception $e) {}
  try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS daraz_sku VARCHAR(80) NULL"); } catch (Exception $e) {}
  try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS daraz_item_name VARCHAR(160) NULL"); } catch (Exception $e) {}
  try { q("ALTER TABLE products ADD COLUMN IF NOT EXISTS daraz_sku VARCHAR(80) NULL"); } catch (Exception $e) {}
  /* money breakdown straight from Daraz, per order line, so the ERP never has to squeeze
     several different amounts into sell_price / delivery_charge again:
       list = Daraz item price (per unit) · seller_discount = YOUR voucher/discount (line total)
       platform_voucher = Daraz-funded voucher (not your cost) · paid = what the customer paid
       ship_customer = shipping the CUSTOMER paid (not your cost) · payout = finance net
     delivery_charge keeps meaning "Daraz fees you pay" (that's what order_profit subtracts). */
  foreach ([
    "daraz_list_price DECIMAL(12,2) NULL", "daraz_seller_discount DECIMAL(12,2) NULL",
    "daraz_platform_voucher DECIMAL(12,2) NULL", "daraz_paid DECIMAL(12,2) NULL",
    "daraz_ship_customer DECIMAL(12,2) NULL", "daraz_payout DECIMAL(12,2) NULL",
    "daraz_fee_source VARCHAR(12) NULL", "daraz_status_raw VARCHAR(40) NULL",
    "daraz_checked_at DATETIME NULL",
  ] as $col) { try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS $col"); } catch (Exception $e) {} }
  daraz_pack_ensure();
  $done = true;
}

/* Distinct Daraz SKUs sitting on imported orders with no product matched yet, newest first,
   with a sample item name and how many order lines are waiting on it — feeds the "🔗 Map
   Unmatched Daraz Products" panel so mapping one SKU is a single click, not per-order editing. */
function daraz_unmatched_skus(): array {
  daraz_ensure_cols();
  return rows("SELECT daraz_sku, MAX(daraz_item_name) AS sample_name, COUNT(*) AS line_count, MAX(order_date) AS last_seen
               FROM orders
               WHERE daraz_sku IS NOT NULL AND daraz_sku<>'' AND product_id IS NULL
                 AND courier_id = (SELECT id FROM couriers WHERE LOWER(name)='daraz' LIMIT 1)
               GROUP BY daraz_sku ORDER BY line_count DESC, last_seen DESC");
}

/* One click = map a Daraz SKU to a local product AND retroactively fix every already-imported
   order line that was waiting on it (sets product_id/cost_price, deducts stock the same way a
   fresh import would, clears the ⚠ remarks flag). Future syncs pick up the mapping automatically
   via products.daraz_sku (see daraz_sync_orders). */
function daraz_map_sku_to_product(string $sku, int $productId): array {
  daraz_ensure_cols();
  $sku = trim($sku);
  if ($sku === '' || !$productId) throw new Exception('Missing SKU or product.');
  $prod = row("SELECT id,cost FROM products WHERE id=?", [$productId]);
  if (!$prod) throw new Exception('Product not found.');

  q("UPDATE products SET daraz_sku=? WHERE id=?", [$sku, $productId]);

  $dzId = (int)val("SELECT id FROM couriers WHERE LOWER(name)='daraz' LIMIT 1");
  $pending = rows("SELECT id,qty,status,remarks FROM orders WHERE daraz_sku=? AND product_id IS NULL AND courier_id=?", [$sku, $dzId]);
  $fixed = 0;
  foreach ($pending as $o) {
    $cleanRemarks = trim(preg_replace('/\s*⚠.*$/u', '', (string)$o['remarks']));
    q("UPDATE orders SET product_id=?, cost_price=?, remarks=? WHERE id=?", [$productId, (float)$prod['cost'], $cleanRemarks, $o['id']]);
    stock_on_status_change($productId, (int)$o['qty'], '', $o['status'], (int)$o['id']);
    $fixed++;
  }
  daraz_pack_normalize();
  log_activity("Mapped Daraz SKU \"$sku\" to product #$productId — fixed $fixed existing order line(s)", 'Daraz');
  return ['fixed' => $fixed];
}

/* ===== Daraz pack listings =====
   One Daraz listing can be a multi-pack ("2 pack heel guard"): 1 Daraz unit = N real pieces.
   Pack size is set per listing — keyed by the Daraz SKU, or by the item name when a line has
   no SKU — so a 1 pc listing and a 2-pack listing of the same product each count correctly.
   orders.daraz_pack remembers the multiplier already applied to that line, which makes
   daraz_pack_normalize() idempotent and reversible: qty is stored in real PIECES and
   sell_price per piece, so stock, FIFO cost, profit and analytics all just work. */
function daraz_pack_ensure(): void {
  static $done = false; if ($done) return; $done = true;
  try { q("CREATE TABLE IF NOT EXISTS daraz_packs(
    lkey VARCHAR(190) NOT NULL PRIMARY KEY,
    pack_qty INT NOT NULL DEFAULT 1,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)"); } catch (Exception $e) {}
  try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS daraz_pack INT NOT NULL DEFAULT 1"); } catch (Exception $e) {}
}
function daraz_pack_key($sku, $name): string {
  $k = trim((string)$sku) !== '' ? trim((string)$sku) : trim((string)$name);
  return mb_strtolower(mb_substr($k, 0, 190));
}
function daraz_pack_map(): array {
  daraz_pack_ensure();
  $m = [];
  foreach (rows("SELECT lkey,pack_qty FROM daraz_packs WHERE pack_qty>1") as $r) $m[$r['lkey']] = (int)$r['pack_qty'];
  return $m;
}
/* bring every Daraz order line in line with its listing's pack size; returns lines changed */
function daraz_pack_normalize(): int {
  daraz_ensure_cols(); daraz_pack_ensure();
  $map = daraz_pack_map();
  $dzId = (int)val("SELECT id FROM couriers WHERE LOWER(name)='daraz' LIMIT 1");
  if (!$dzId) return 0;
  /* only lines that could need a change: already multiplied, or on a pack listing */
  $cand = rows("SELECT id,product_id,qty,sell_price,cost_price,status,daraz_sku,daraz_item_name,daraz_pack FROM orders
                WHERE courier_id=? AND (daraz_pack<>1".($map ? " OR (daraz_sku IS NOT NULL AND daraz_sku<>'') OR (daraz_item_name IS NOT NULL AND daraz_item_name<>'')" : '').")", [$dzId]);
  $n = 0;
  foreach ($cand as $o) {
    $want = $map[daraz_pack_key($o['daraz_sku'], $o['daraz_item_name'])] ?? 1;
    $have = max(1, (int)$o['daraz_pack']);
    if ($want === $have) continue;
    $units = max(1, (int)round((int)$o['qty'] / $have));        /* Daraz units (packs) on this line */
    $qty   = $units * $want;
    $price = round((float)$o['sell_price'] * $have / $want, 2);  /* per-piece price; line total unchanged */
    /* cost_price is already per piece (product cost / FIFO batches), so it stays as is */
    q("UPDATE orders SET qty=?, sell_price=?, daraz_pack=? WHERE id=?", [$qty, $price, $want, (int)$o['id']]);
    /* delivered lines: re-take the right number of pieces out of stock (restore + consume) */
    if ((int)$o['product_id'] && $o['status'] === 'delivered') fifo_status_change((int)$o['id'], (int)$o['product_id'], $qty, 'delivered', 'delivered');
    $n++;
  }
  if ($n) log_activity("Daraz pack sizes applied to $n order line(s)", 'Daraz');
  return $n;
}
/* every Daraz listing seen on orders, for the pack-size panel */
function daraz_pack_listings(): array {
  daraz_ensure_cols(); daraz_pack_ensure();
  $map = daraz_pack_map(); $out = [];
  $dzId = (int)val("SELECT id FROM couriers WHERE LOWER(name)='daraz' LIMIT 1");
  foreach (rows("SELECT o.daraz_sku, o.daraz_item_name, p.name AS product_name, COUNT(*) AS line_count, MAX(o.order_date) AS last_seen
                 FROM orders o LEFT JOIN products p ON p.id=o.product_id
                 WHERE o.courier_id=? AND ((o.daraz_sku IS NOT NULL AND o.daraz_sku<>'') OR (o.daraz_item_name IS NOT NULL AND o.daraz_item_name<>''))
                 GROUP BY o.daraz_sku, o.daraz_item_name, p.name ORDER BY last_seen DESC", [$dzId]) as $r) {
    $k = daraz_pack_key($r['daraz_sku'], $r['daraz_item_name']);
    if (!isset($out[$k])) $out[$k] = ['key'=>$k, 'sku'=>(string)$r['daraz_sku'], 'name'=>(string)$r['daraz_item_name'], 'product'=>(string)$r['product_name'], 'lines'=>0, 'last'=>$r['last_seen'], 'pack'=>$map[$k] ?? 1];
    $out[$k]['lines'] += (int)$r['line_count'];
    if ($out[$k]['name'] === '' && $r['daraz_item_name']) $out[$k]['name'] = $r['daraz_item_name'];
    if ($out[$k]['product'] === '' && $r['product_name']) $out[$k]['product'] = $r['product_name'];
  }
  return array_values($out);
}
function daraz_pack_set(string $key, int $pack): void {
  daraz_pack_ensure();
  $key = mb_strtolower(trim($key)); $pack = max(1, min(50, $pack));
  if ($key === '') return;
  if ($pack <= 1) q("DELETE FROM daraz_packs WHERE lkey=?", [$key]);
  else q("INSERT INTO daraz_packs(lkey,pack_qty) VALUES(?,?) ON DUPLICATE KEY UPDATE pack_qty=VALUES(pack_qty)", [$key, $pack]);
}

/* Pull recent Daraz orders into Sales (orders table, Courier = Daraz). Idempotent — an order
   whose daraz_order_id is already on a local row is skipped, so this is safe to run repeatedly
   (manual button or a future cron job) without creating duplicates. A line whose Daraz SKU
   doesn't match any local product.sku is still imported (so nothing silently gets lost) with
   product_id left null and a ⚠ flag in Remarks for staff to fix manually, same spirit as the
   existing "✎ set charge" flow for cancel/exchange charges. */
/* Daraz sends phones as "9779812345678" / "+977 98..." — staff want the plain 10-digit Nepali
   mobile. Strip non-digits, drop the 977 country code (and a leading 0), keep the last 10 digits.
   Anything that doesn't look like a mobile (e.g. a short landline) is returned digits-only. */
function daraz_clean_phone(string $raw): string {
  $d = preg_replace('/\D+/', '', $raw);
  if ($d === '') return trim($raw);
  if (strlen($d) > 10 && strpos($d, '977') === 0) $d = substr($d, 3);
  if (strlen($d) > 10 && $d[0] === '0') $d = ltrim($d, '0');
  if (strlen($d) > 10) $d = substr($d, -10);
  return $d;
}

/* one-off-but-idempotent repair for Daraz-courier orders already saved with a 977 prefix;
   cheap (only touches rows whose phone still contains 977 or non-digits), so safe to call on
   every Daraz page load / sync */
function daraz_fix_existing_phones(int $dzId): int {
  if ($dzId <= 0) return 0;
  $fixed = 0;
  foreach (rows("SELECT id, phone FROM orders WHERE courier_id=? AND phone IS NOT NULL AND phone<>''
                 AND (phone LIKE '977%' OR phone LIKE '+%' OR phone REGEXP '[^0-9]')", [$dzId]) as $r) {
    $clean = daraz_clean_phone((string)$r['phone']);
    if ($clean !== $r['phone']) { q("UPDATE orders SET phone=? WHERE id=?", [$clean, (int)$r['id']]); $fixed++; }
  }
  if ($fixed) log_activity("Daraz: cleaned 977 prefix from $fixed order phone number(s)", 'Daraz');
  return $fixed;
}

function daraz_sync_orders(int $days = 14, ?string $notBefore = null): array {
  $daraz = new Daraz();
  if (!$daraz->connected()) throw new Exception('Daraz is not connected yet — authorize the app first.');

  daraz_ensure_cols();

  $dzCourier = row("SELECT id FROM couriers WHERE LOWER(name)='daraz'");
  $dzId = (int)($dzCourier['id'] ?? 0);
  if (!$dzId) throw new Exception('Daraz courier not found.');
  daraz_fix_existing_phones($dzId);

  /* $notBefore: never reach back before this moment (auto sync starts from when it was switched on) */
  $createdAfter = date('c', max(strtotime("-{$days} days"), $notBefore ? (int)strtotime($notBefore) : 0));

  /* 1) collect order headers, paginated (hard cap 1000 to bound one run) */
  $orders = []; $offset = 0; $limit = 100; $total = 0;
  do {
    $page  = $daraz->getOrders(['created_after'=>$createdAfter, 'sort_by'=>'created_at', 'sort_direction'=>'ASC', 'offset'=>$offset, 'limit'=>$limit]);
    $batch = $page['orders'] ?? [];
    $orders = array_merge($orders, $batch);
    $total  = (int)($page['countTotal'] ?? count($orders));
    $offset += $limit;
  } while (count($batch) === $limit && $offset < $total && $offset < 1000);

  if (!$orders) return ['fetched'=>0,'created'=>0,'skipped'=>0,'unmatched'=>0];

  /* 2) drop ones we've already imported — either through this sync before (daraz_order_id set)
     OR, same as this account's pre-existing convention, typed by hand into Remarks (bare id,
     no daraz_order_id set). Missing the second check once already re-imported ~100 orders
     staff had already entered manually; both are checked now. */
  $allIds = array_map(fn($o) => (string)$o['order_id'], $orders);
  $existing = [];
  if ($allIds) {
    $ph = implode(',', array_fill(0, count($allIds), '?'));
    foreach (rows("SELECT DISTINCT daraz_order_id FROM orders WHERE daraz_order_id IN ($ph)", $allIds) as $r) $existing[$r['daraz_order_id']] = true;
    foreach (rows("SELECT DISTINCT TRIM(remarks) AS rid FROM orders WHERE daraz_order_id IS NULL AND TRIM(remarks) IN ($ph)", $allIds) as $r) $existing[$r['rid']] = true;
  }
  $byId = [];
  foreach ($orders as $o) if (!isset($existing[(string)$o['order_id']])) $byId[(string)$o['order_id']] = $o;
  if (!$byId) return ['fetched'=>count($orders),'created'=>0,'skipped'=>count($orders),'unmatched'=>0];

  /* 3) line items, chunked (Daraz doesn't document an exact cap — 20 is conservative) */
  $items = [];
  foreach (array_chunk(array_keys($byId), 20) as $chunk) {
    foreach ($daraz->getOrderItems($chunk) as $r) $items[(string)$r['order_id']] = $r['order_items'] ?? [];
  }

  /* product lookup by SKU (case-insensitive) — prefer the explicit Daraz-SKU mapping
     (Settings-free, set via the "🔗 Map Unmatched Daraz Products" panel on this page),
     falling back to the local product's own SKU in case it already happens to match */
  $productsBySku = [];
  foreach (rows("SELECT id,sku,cost FROM products WHERE sku IS NOT NULL AND sku<>''") as $p) {
    $productsBySku[strtolower(trim($p['sku']))] = $p;
  }
  foreach (rows("SELECT id,daraz_sku,cost FROM products WHERE daraz_sku IS NOT NULL AND daraz_sku<>''") as $p) {
    $productsBySku[strtolower(trim($p['daraz_sku']))] = $p;   /* takes priority — set after, overwrites a sku-only hit */
  }

  /* same smallest-unused-number code scheme sales.php uses */
  $pref = setting('order_prefix','ORD-'); $used = [];
  foreach (rows("SELECT code FROM orders") as $cr) {
    $c = (string)$cr['code']; if ($pref!=='' && strpos($c,$pref)===0) $c = substr($c, strlen($pref));
    $n = (int)preg_replace('/\D+/','', $c); if ($n>0) $used[$n] = 1;
  }
  $nextNum = function() use (&$used) { $n=1; while (isset($used[$n])) $n++; $used[$n]=1; return $n; };

  $created = 0; $unmatched = 0; $cancelledSkipped = 0; $linked = 0;
  foreach ($byId as $orderId => $o) {
    $lineItems = $items[$orderId] ?? [];
    if (!$lineItems) continue;

    /* group identical-sku line items into one qty'd Sales line (Daraz lists multi-qty as repeated items);
       a cancelled line is dropped here, not imported at all — cancelled Daraz orders carry no real
       inventory/revenue impact, so they're noise in Sales rather than a record worth keeping (unlike
       "returned", which has real cost and stays) */
    $groups = [];
    foreach ($lineItems as $it) {
      $status = daraz_map_status((string)($it['status'] ?? 'pending'));
      if ($status === 'cancelled') { $cancelledSkipped++; continue; }
      $sku    = trim((string)($it['sku'] ?? '')) ?: trim((string)($it['shop_sku'] ?? ''));
      /* your real per-unit revenue: Daraz item price minus YOUR (seller-funded) voucher —
         Daraz-funded vouchers don't reduce what you earn */
      $price  = round(max(0, dz_num($it['item_price'] ?? $it['paid_price'] ?? 0) - dz_num($it['voucher_seller'] ?? 0)), 2);
      $name   = trim((string)($it['name'] ?? ''));
      $key = $sku.'|'.$price.'|'.$status;
      if (!isset($groups[$key])) $groups[$key] = ['sku'=>$sku,'name'=>$name,'price'=>$price,'status'=>$status,'qty'=>0];
      $groups[$key]['qty']++;
    }
    if (!$groups) continue;   /* every line in this order was cancelled — nothing to import */

    $addr     = $o['address_shipping'] ?? [];
    $customer = trim(($o['customer_first_name'] ?? '').' '.($o['customer_last_name'] ?? ''));
    $phone    = daraz_clean_phone((string)($addr['phone'] ?? ''));
    $address  = trim(implode(', ', array_filter([$addr['address1'] ?? '', $addr['address2'] ?? '', $addr['city'] ?? ''])));
    $orderDate = date('Y-m-d', strtotime($o['created_at'] ?? 'now'));
    /* booked by hand in Sales already (same phone, around the same day, no Daraz # yet)?
       link that order instead of importing a duplicate — the re-check then syncs it */
    if ($phone !== '') {
      $hand = rows("SELECT id FROM orders WHERE courier_id=? AND (daraz_order_id IS NULL OR daraz_order_id='')
                    AND RIGHT(phone,10)=RIGHT(?,10) AND order_date BETWEEN ? AND ? AND status<>'cancelled'",
                   [$dzId, $phone, date('Y-m-d', strtotime($orderDate.' -1 day')), date('Y-m-d', strtotime($orderDate.' +3 days'))]);
      if ($hand) {
        q("UPDATE orders SET daraz_order_id=? WHERE id IN (".implode(',', array_map(fn($h) => (int)$h['id'], $hand)).")", [$orderId]);
        $linked++;
        continue;
      }
    }
    $payType   = stripos((string)($o['payment_method'] ?? ''), 'cod') !== false ? 'cod' : 'prepaid';
    /* shipping_fee is what the CUSTOMER paid for delivery — it is not a cost to you, so it
       must never land in delivery_charge (which order_profit subtracts as your cost). Daraz
       fees are filled in later from the finance API or by hand. */
    $delivery  = 0.0;
    $group     = count($groups) > 1 ? ('DZ'.$orderId) : null;

    $first = true;
    foreach ($groups as $g) {
      $prod    = $productsBySku[strtolower($g['sku'])] ?? null;
      $remarks = 'Daraz #'.$orderId;
      if (!$prod) { $unmatched++; $remarks .= ' ⚠ '.($g['name'] ?: 'item').' — SKU "'.$g['sku'].'" not mapped yet'; }

      $f = [
        'code'            => $pref.str_pad($nextNum(),4,'0',STR_PAD_LEFT),
        'order_date'      => $orderDate,
        'customer'        => $customer, 'phone' => $phone, 'address' => $address,
        'product_id'      => $prod['id'] ?? null,
        'qty'             => max(1, $g['qty']),
        'sell_price'      => $g['price'],
        'cost_price'      => $prod['cost'] ?? 0,
        'delivery_charge' => $first ? $delivery : 0,
        'cancel_charge'   => 0, 'exchange_charge' => 0,
        'zone'            => 'inside',
        'payment_type'    => $payType,
        'payment_status'  => $g['status']==='delivered' ? 'paid' : 'unpaid',
        'status'          => $g['status'],
        'courier_id'      => $dzId,
        'remarks'         => $remarks,
        'order_group'     => $group,
        'daraz_order_id'  => $orderId,
        'daraz_sku'       => $g['sku'] !== '' ? $g['sku'] : null,
        'daraz_item_name' => $g['name'] !== '' ? $g['name'] : null,
      ];
      $cols = implode('`,`', array_keys($f));
      $qs   = implode(',', array_fill(0, count($f), '?'));
      q("INSERT INTO orders(`$cols`) VALUES($qs)", array_values($f));
      $newId = (int)db()->lastInsertId();
      if ($prod) stock_on_status_change($prod['id'], $f['qty'], '', $f['status'], $newId);
      $created++;
      $first = false;
    }
  }

  log_activity("Daraz sync: $created line(s) imported from ".count($byId)." order(s)".($unmatched ? ", $unmatched unmatched SKU(s)" : '').($cancelledSkipped ? ", $cancelledSkipped cancelled line(s) skipped" : ''), 'Daraz');
  daraz_pack_normalize();   /* 2-pack listings → real pieces */
  return ['fetched'=>count($orders), 'created'=>$created, 'linked'=>$linked, 'skipped'=>count($orders)-count($byId), 'unmatched'=>$unmatched, 'cancelled_skipped'=>$cancelledSkipped];
}
