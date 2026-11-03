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
      if ($this->ch === null) $this->ch = curl_init();
      $ch = $this->ch;
      curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => $isGet,          /* resets any POST state from a previous reused-handle call */
        CURLOPT_POST           => !$isGet,
        CURLOPT_POSTFIELDS     => $isGet ? '' : http_build_query($all),
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

/* Pull recent Daraz orders into Sales (orders table, Courier = Daraz). Idempotent — an order
   whose daraz_order_id is already on a local row is skipped, so this is safe to run repeatedly
   (manual button or a future cron job) without creating duplicates. A line whose Daraz SKU
   doesn't match any local product.sku is still imported (so nothing silently gets lost) with
   product_id left null and a ⚠ flag in Remarks for staff to fix manually, same spirit as the
   existing "✎ set charge" flow for cancel/exchange charges. */
function daraz_sync_orders(int $days = 14): array {
  $daraz = new Daraz();
  if (!$daraz->connected()) throw new Exception('Daraz is not connected yet — authorize the app first.');

  try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS daraz_order_id VARCHAR(40) NULL"); } catch (Exception $e) {}
  try { q("ALTER TABLE orders ADD INDEX idx_daraz_order_id (daraz_order_id)"); } catch (Exception $e) {}

  $dzCourier = row("SELECT id FROM couriers WHERE LOWER(name)='daraz'");
  $dzId = (int)($dzCourier['id'] ?? 0);
  if (!$dzId) throw new Exception('Daraz courier not found.');

  $createdAfter = date('c', strtotime("-{$days} days"));

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

  /* 2) drop ones we've already imported */
  $allIds = array_map(fn($o) => (string)$o['order_id'], $orders);
  $existing = [];
  if ($allIds) {
    $ph = implode(',', array_fill(0, count($allIds), '?'));
    foreach (rows("SELECT DISTINCT daraz_order_id FROM orders WHERE daraz_order_id IN ($ph)", $allIds) as $r) $existing[$r['daraz_order_id']] = true;
  }
  $byId = [];
  foreach ($orders as $o) if (!isset($existing[(string)$o['order_id']])) $byId[(string)$o['order_id']] = $o;
  if (!$byId) return ['fetched'=>count($orders),'created'=>0,'skipped'=>count($orders),'unmatched'=>0];

  /* 3) line items, chunked (Daraz doesn't document an exact cap — 20 is conservative) */
  $items = [];
  foreach (array_chunk(array_keys($byId), 20) as $chunk) {
    foreach ($daraz->getOrderItems($chunk) as $r) $items[(string)$r['order_id']] = $r['order_items'] ?? [];
  }

  /* product lookup by SKU (case-insensitive) */
  $productsBySku = [];
  foreach (rows("SELECT id,sku,cost FROM products WHERE sku IS NOT NULL AND sku<>''") as $p) {
    $productsBySku[strtolower(trim($p['sku']))] = $p;
  }

  /* same smallest-unused-number code scheme sales.php uses */
  $pref = setting('order_prefix','ORD-'); $used = [];
  foreach (rows("SELECT code FROM orders") as $cr) {
    $c = (string)$cr['code']; if ($pref!=='' && strpos($c,$pref)===0) $c = substr($c, strlen($pref));
    $n = (int)preg_replace('/\D+/','', $c); if ($n>0) $used[$n] = 1;
  }
  $nextNum = function() use (&$used) { $n=1; while (isset($used[$n])) $n++; $used[$n]=1; return $n; };

  $created = 0; $unmatched = 0;
  foreach ($byId as $orderId => $o) {
    $lineItems = $items[$orderId] ?? [];
    if (!$lineItems) continue;

    /* group identical-sku line items into one qty'd Sales line (Daraz lists multi-qty as repeated items) */
    $groups = [];
    foreach ($lineItems as $it) {
      $sku    = trim((string)($it['sku'] ?? '')) ?: trim((string)($it['shop_sku'] ?? ''));
      $price  = (float)($it['item_price'] ?? $it['paid_price'] ?? 0);
      $status = daraz_map_status((string)($it['status'] ?? 'pending'));
      $key = $sku.'|'.$price.'|'.$status;
      if (!isset($groups[$key])) $groups[$key] = ['sku'=>$sku,'price'=>$price,'status'=>$status,'qty'=>0];
      $groups[$key]['qty']++;
    }

    $addr     = $o['address_shipping'] ?? [];
    $customer = trim(($o['customer_first_name'] ?? '').' '.($o['customer_last_name'] ?? ''));
    $phone    = trim((string)($addr['phone'] ?? ''));
    $address  = trim(implode(', ', array_filter([$addr['address1'] ?? '', $addr['address2'] ?? '', $addr['city'] ?? ''])));
    $orderDate = date('Y-m-d', strtotime($o['created_at'] ?? 'now'));
    $payType   = stripos((string)($o['payment_method'] ?? ''), 'cod') !== false ? 'cod' : 'prepaid';
    $delivery  = (float)($o['shipping_fee'] ?? 0);
    $group     = count($groups) > 1 ? ('DZ'.$orderId) : null;

    $first = true;
    foreach ($groups as $g) {
      $prod    = $productsBySku[strtolower($g['sku'])] ?? null;
      $remarks = 'Daraz #'.$orderId;
      if (!$prod) { $unmatched++; $remarks .= ' ⚠ SKU "'.$g['sku'].'" not in Products — set product/cost manually'; }

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

  log_activity("Daraz sync: $created line(s) imported from ".count($byId)." order(s)".($unmatched ? ", $unmatched unmatched SKU(s)" : ''), 'Daraz');
  return ['fetched'=>count($orders), 'created'=>$created, 'skipped'=>count($orders)-count($byId), 'unmatched'=>$unmatched];
}
