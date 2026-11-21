<?php
/* ============================================================
   ncm_insights_lib.php — data behind the NCM Insights page
   (charge check · COD settlement · delivery speed & stuck alerts).

   NCM's API has no "settlement" or "analytics" endpoint, so this keeps a
   small local copy per NCM order (table ncm_meta), filled from two calls
   the ERP already uses:
     GET /v1/order?id=        → route, COD, billed delivery charge, payment status
     GET /v1/order/status?id= → every status step with its time
   ncm_insights_refresh() tops it up a batch at a time (cron + "Refresh" button),
   so the page itself never waits on NCM.
   ============================================================ */
require_once __DIR__.'/ncm_api.php';

function ncm_ins_ensure(): void {
  static $ok = false; if ($ok) return;
  ncm_ensure_cols();
  try { q("CREATE TABLE IF NOT EXISTS ncm_meta (
    ncm_order_id VARCHAR(40) NOT NULL PRIMARY KEY,
    fbranch VARCHAR(80) NULL, branch VARCHAR(80) NULL, dtype VARCHAR(40) NULL,
    cod DECIMAL(12,2) NULL, charge DECIMAL(12,2) NULL, pay_status VARCHAR(60) NULL,
    created_at DATETIME NULL, picked_at DATETIME NULL, delivered_at DATETIME NULL, returned_at DATETIME NULL,
    last_status VARCHAR(100) NULL, last_change_at DATETIME NULL, verdict VARCHAR(12) NULL, steps INT NOT NULL DEFAULT 0,
    detail_at DATETIME NULL, hist_at DATETIME NULL, detail_keys VARCHAR(500) NULL,
    settlement_id INT NULL, settled_amount DECIMAL(12,2) NULL,
    INDEX(settlement_id), INDEX(last_change_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
  try { q("CREATE TABLE IF NOT EXISTS ncm_settlements (
    id INT AUTO_INCREMENT PRIMARY KEY, ref VARCHAR(80) NULL, settle_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0, orders INT NOT NULL DEFAULT 0, source VARCHAR(20) NOT NULL DEFAULT 'manual',
    note VARCHAR(255) NULL, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
  $ok = true;
}

/* first value of $a under any of $keys (NCM's field names aren't documented — accept the likely ones) */
function ncm_ins_pick(array $a, array $keys) {
  foreach ($keys as $k) if (isset($a[$k]) && $a[$k] !== '' && !is_array($a[$k])) return $a[$k];
  return null;
}

/* remember the route when the ERP books an order (so the charge check works from day one) */
function ncm_ins_save_route($ncmId, string $fbranch, string $branch, string $dtype, $cod = null): void {
  $ncmId = trim((string)$ncmId); if ($ncmId === '') return;
  ncm_ins_ensure();
  try { q("INSERT INTO ncm_meta(ncm_order_id,fbranch,branch,dtype,cod) VALUES(?,?,?,?,?)
           ON DUPLICATE KEY UPDATE fbranch=VALUES(fbranch), branch=VALUES(branch), dtype=VALUES(dtype), cod=COALESCE(VALUES(cod),cod)",
          [$ncmId, $fbranch, $branch, $dtype, $cod === null ? null : (float)$cod]); } catch (Exception $e) {}
}

/* status steps → timestamps */
function ncm_ins_timeline(array $items): array {
  $out = ['created_at'=>null,'picked_at'=>null,'delivered_at'=>null,'returned_at'=>null,'last_status'=>null,'last_change_at'=>null,'verdict'=>'unknown','steps'=>count($items)];
  if (!$items) return $out;
  $withTs = [];
  foreach ($items as $x) { $t = strtotime($x['added_time'] ?: ''); $withTs[] = ['s'=>$x['status'], 't'=>$t ?: null]; }
  usort($withTs, fn($a, $b) => ($a['t'] ?? PHP_INT_MAX) <=> ($b['t'] ?? PHP_INT_MAX));
  $fmt = fn($t) => $t ? date('Y-m-d H:i:s', $t) : null;
  $out['created_at'] = $fmt($withTs[0]['t']);
  foreach ($withTs as $x) {
    $l = strtolower($x['s']);
    $isPicked = preg_match('/pickup complete|picked|dispatch|arrived|sent for delivery|out for delivery/', $l)
             && !preg_match('/sent for pickup|send for pickup|pickup order created|order created/', $l);
    if ($isPicked && !$out['picked_at']) $out['picked_at'] = $fmt($x['t']);
    if (!$out['returned_at'] && (ncm_vendor_bound($x['s']) || ncm_return_stage($x['s']) === 'final')) $out['returned_at'] = $fmt($x['t']);
  }
  $last = end($withTs);
  $out['last_status'] = mb_substr($last['s'], 0, 100);
  $out['last_change_at'] = $fmt($last['t']);
  $out['verdict'] = ncm_timeline_verdict($items);
  if ($out['verdict'] === 'customer') {
    foreach (array_reverse($withTs) as $x) {
      $l = strtolower($x['s']);
      if (strpos($l, 'deliver') !== false && !preg_match('/sent for|send for|out for|arrived|dispatch|vendor|return/', $l)) { $out['delivered_at'] = $fmt($x['t']); break; }
    }
  }
  return $out;
}

/* every NCM order the ERP knows, one row per NCM id (multi-product orders share one id) */
function ncm_ins_orders(): array {
  ncm_ins_ensure();
  return rows("SELECT o.ncm_order_id AS nid, MIN(o.id) AS id, MIN(o.code) AS code, MAX(o.customer) AS customer, MAX(o.phone) AS phone,
                      MIN(o.order_date) AS order_date, MAX(o.address) AS address,
                      SUM(CASE WHEN o.status NOT IN ('cancelled') THEN 1 ELSE 0 END) AS live_lines,
                      GROUP_CONCAT(DISTINCT o.status) AS statuses,
                      SUM(CASE WHEN LOWER(o.payment_type)='cod' THEN o.sell_price*o.qty ELSE 0 END) AS erp_cod,
                      SUM(o.delivery_charge) AS erp_charge, SUM(o.cancel_charge) AS erp_cancel,
                      MAX(o.ncm_status) AS ncm_status,
                      m.fbranch, m.branch, m.dtype, m.cod, m.charge, m.pay_status, m.created_at, m.picked_at, m.delivered_at,
                      m.returned_at, m.last_status, m.last_change_at, m.verdict, m.hist_at, m.detail_at, m.settlement_id, m.settled_amount
               FROM orders o LEFT JOIN ncm_meta m ON m.ncm_order_id=o.ncm_order_id
               WHERE COALESCE(o.ncm_order_id,'')<>''
               GROUP BY o.ncm_order_id
               ORDER BY MIN(o.order_date) DESC, MIN(o.id) DESC");
}

/* ERP-side final state for an NCM order */
function ncm_ins_state(array $r): string {
  $st = explode(',', (string)$r['statuses']);
  if (in_array('delivered', $st, true)) return 'delivered';
  if (array_intersect(['returned','exchanged'], $st)) return 'returned';
  if ($st === ['cancelled']) return 'cancelled';
  return 'active';
}

/* top up ncm_meta from NCM: active orders first, then finished ones never fetched.
   $cap = max NCM orders per run (2 API calls each, fired 8 orders at a time). */
function ncm_insights_refresh(int $cap = 60): array {
  ncm_ins_ensure();
  if (!ncm()->configured()) return ['orders'=>0, 'rates'=>0, 'errors'=>0];
  $now = time(); $pick = [];
  foreach (ncm_ins_orders() as $r) {
    $state = ncm_ins_state($r);
    $hist = $r['hist_at'] ? strtotime($r['hist_at']) : 0;
    $age = (int)floor(($now - strtotime($r['order_date'])) / 86400);
    if ($state === 'active') { if ($now - $hist > 3*3600) $pick[$r['nid']] = 100 - min($age, 60) + ($hist ? 0 : 50); }
    elseif (!$hist || !$r['detail_at']) $pick[$r['nid']] = 20 - min($age, 19);
    /* a just-finished order: one more read so the final charge (e.g. return fee) is captured */
    elseif ($age <= 45 && $hist < strtotime((string)$r['last_change_at'] ?: '1970-01-01') + 3600) $pick[$r['nid']] = 10;
  }
  arsort($pick);
  $ids = array_slice(array_keys($pick), 0, $cap);
  $done = 0; $errors = 0;
  foreach (array_chunk($ids, 8) as $chunk) {
    $req = [];
    foreach ($chunk as $nid) {
      $req['d'.$nid] = ['method'=>'GET', 'path'=>'/v1/order', 'query'=>['id'=>$nid]];
      $req['h'.$nid] = ['method'=>'GET', 'path'=>'/v1/order/status', 'query'=>['id'=>$nid]];
    }
    $res = ncm()->multiGet($req);
    foreach ($chunk as $nid) {
      $d = $res['d'.$nid] ?? null; $h = $res['h'.$nid] ?? null;
      $dOk = is_array($d) && empty($d['__error']); $hOk = is_array($h) && empty($h['__error']);
      if (!$dOk && !$hOk) { $errors++; continue; }
      $set = []; $p = [];
      if ($dOk) {
        if (isset($d['data']) && is_array($d['data']) && !isset($d['delivery_charge'])) $d = $d['data'];
        $from = ncm_ins_pick($d, ['fbranch','from_branch','pickup_branch','source_branch','source','sender_branch']);
        $to   = ncm_ins_pick($d, ['branch','destination','destination_branch','to_branch','delivery_branch','receiver_branch']);
        $type = ncm_ins_pick($d, ['delivery_type','type','service_type']);
        $cod  = ncm_ins_pick($d, ['cod_charge','cod','cod_amount','collection_amount']);
        $chg  = ncm_ins_pick($d, ['delivery_charge','charge','total_charge']);
        $pay  = ncm_ins_pick($d, ['payment_status','vendor_payment_status','cod_status','payment']);
        /* keep what the ERP saved at booking unless NCM says otherwise */
        if ($from !== null) { $set[] = 'fbranch=?'; $p[] = mb_substr((string)$from, 0, 80); }
        if ($to   !== null) { $set[] = 'branch=?';  $p[] = mb_substr((string)$to, 0, 80); }
        if ($type !== null) { $set[] = 'dtype=?';   $p[] = mb_substr((string)$type, 0, 40); }
        if (is_numeric($cod)) { $set[] = 'cod=?';    $p[] = (float)$cod; }
        if (is_numeric($chg)) { $set[] = 'charge=?'; $p[] = (float)$chg; }
        if ($pay !== null) { $set[] = 'pay_status=?'; $p[] = mb_substr((string)$pay, 0, 60); }
        $set[] = 'detail_at=NOW()'; $set[] = 'detail_keys=?'; $p[] = mb_substr(implode(',', array_keys($d)), 0, 500);
      }
      if ($hOk) {
        $t = ncm_ins_timeline(ncm_hist_items($h));
        foreach (['created_at','picked_at','delivered_at','returned_at','last_status','last_change_at','verdict'] as $k) { $set[] = "$k=?"; $p[] = $t[$k]; }
        $set[] = 'steps=?'; $p[] = $t['steps']; $set[] = 'hist_at=NOW()';
      }
      q("INSERT IGNORE INTO ncm_meta(ncm_order_id) VALUES(?)", [$nid]);
      $p[] = $nid;
      q("UPDATE ncm_meta SET ".implode(',', $set)." WHERE ncm_order_id=?", $p);
      $done++;
    }
  }
  $rates = ncm_ins_fetch_rates(15);
  kv_set('ncm_insights_last', ['at'=>date('Y-m-d H:i:s'), 'orders'=>$done, 'errors'=>$errors, 'queued'=>count($pick)]);
  return ['orders'=>$done, 'rates'=>$rates, 'errors'=>$errors, 'queued'=>max(0, count($pick) - $done)];
}

/* ---------- standard rates (cached per route for 7 days) ---------- */
function ncm_ins_rate_type(?string $dtype): string {
  $types = ncm_delivery_types(); $d = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$dtype));
  foreach ($types as $k => $t) if ($d === strtolower($k) || $d === strtolower(preg_replace('/[^a-z0-9]/i', '', $t['label'])) || $d === strtolower(preg_replace('/[^a-z0-9]/i', '', $t['rate']))) return $t['rate'];
  return $types['Door2Door']['rate'];
}
function ncm_ins_rate_key(string $from, string $to, string $rateType): string { return mb_strtoupper(trim($from)).'|'.mb_strtoupper(trim($to)).'|'.$rateType; }
function ncm_ins_rates(): array { $c = kv_get('ncm_rate_cache'); return is_array($c['v'] ?? null) ? $c['v'] : []; }
function ncm_ins_rate_value($rr): ?float {
  if (is_numeric($rr)) return (float)$rr;
  if (is_array($rr)) foreach (['charge','rate','delivery_charge','amount'] as $k) if (isset($rr[$k]) && is_numeric($rr[$k])) return (float)$rr[$k];
  if (is_array($rr) && isset($rr['data']) && is_array($rr['data'])) return ncm_ins_rate_value($rr['data']);
  return null;
}
/* look up routes used by delivered orders that have no fresh cached rate */
function ncm_ins_fetch_rates(int $cap = 15): int {
  $cache = ncm_ins_rates(); $need = [];
  foreach (rows("SELECT DISTINCT fbranch, branch, dtype FROM ncm_meta WHERE COALESCE(branch,'')<>''") as $m) {
    $from = $m['fbranch'] ?: (string)setting('ncm_from_branch', 'TINKUNE');
    $rt = ncm_ins_rate_type($m['dtype']);
    $k = ncm_ins_rate_key($from, $m['branch'], $rt);
    if (!isset($cache[$k]) || time() - (int)$cache[$k]['t'] > 7*86400) $need[$k] = [$from, $m['branch'], $rt];
  }
  $need = array_slice($need, 0, $cap, true); if (!$need) return 0;
  $req = []; foreach ($need as $k => [$f, $t, $rt]) $req[$k] = ['method'=>'GET', 'path'=>'/v1/shipping-rate', 'query'=>['creation'=>$f, 'destination'=>$t, 'type'=>$rt]];
  $n = 0;
  foreach (ncm()->multiGet($req) as $k => $rr) {
    if (is_array($rr) && !empty($rr['__error'])) continue;
    $v = ncm_ins_rate_value($rr);
    if ($v !== null) { $cache[$k] = ['r'=>$v, 't'=>time()]; $n++; }
  }
  kv_set('ncm_rate_cache', $cache);
  return $n;
}
function ncm_ins_expected(array $r, ?array $cache = null): ?float {
  if (empty($r['branch'])) return null;
  $cache ??= ncm_ins_rates();
  $k = ncm_ins_rate_key($r['fbranch'] ?: (string)setting('ncm_from_branch', 'TINKUNE'), $r['branch'], ncm_ins_rate_type($r['dtype']));
  return isset($cache[$k]) ? (float)$cache[$k]['r'] : null;
}

/* billed delivery charge for an NCM order: NCM's live figure, else what the ERP holds */
function ncm_ins_billed(array $r): float {
  if ($r['charge'] !== null) return (float)$r['charge'];
  return ncm_ins_state($r) === 'returned' ? (float)$r['erp_cancel'] : (float)$r['erp_charge'];
}
/* COD NCM collects for an order */
function ncm_ins_cod(array $r): float { return $r['cod'] !== null ? (float)$r['cod'] : (float)$r['erp_cod']; }
/* NCM's own payment status says it has paid this order out to you */
function ncm_ins_ncm_paid(?string $s): bool {
  $l = strtolower((string)$s);
  return $l !== '' && preg_match('/complete|paid|settled|transferred|cleared/', $l) && !preg_match('/unpaid|not paid|pending/', $l);
}

/* ---------- COD statement import ---------- */
function ncm_ins_parse_statement(string $file): array {
  $raw = (string)file_get_contents($file);
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
  $lines = preg_split('/\r\n|\r|\n/', trim($raw));
  if (count($lines) < 2) throw new Exception('The file has no data rows.');
  $delim = substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : (substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',');
  $head = array_map(fn($h) => strtolower(trim((string)$h)), str_getcsv($lines[0], $delim));
  $idCol = null; $amtCol = null; $amtRank = 99;
  /* amount column, best first: net/payable → paid/settled → amount/total → COD */
  $ranks = ['/net|payable/', '/paid|settle|transfer/', '/amount|total/', '/cod/'];
  foreach ($head as $i => $h) {
    if ($idCol === null && preg_match('/order.?id|orderid|ncm.?id|^id$|order no|order number|tracking/', $h)) $idCol = $i;
    if (preg_match('/charge|fee|date|\bid\b|order/', $h)) continue;
    foreach ($ranks as $rk => $re) if ($rk < $amtRank && preg_match($re, $h)) { $amtCol = $i; $amtRank = $rk; break; }
  }
  if ($idCol === null) throw new Exception('Could not find an order-ID column. Headers found: '.implode(', ', $head));
  $out = [];
  foreach (array_slice($lines, 1) as $ln) {
    if (trim($ln) === '') continue;
    $c = str_getcsv($ln, $delim);
    $id = preg_replace('/\D+/', '', (string)($c[$idCol] ?? ''));
    if ($id === '') continue;
    $amt = $amtCol !== null ? (float)preg_replace('/[^0-9.\-]/', '', (string)($c[$amtCol] ?? '')) : null;
    $out[$id] = ($out[$id] ?? 0) + ($amt ?? 0);
    if ($amtCol === null) $out[$id] = null;
  }
  return ['rows'=>$out, 'id_col'=>$head[$idCol], 'amt_col'=>$amtCol !== null ? $head[$amtCol] : null];
}

/* active parcels whose NCM status hasn't changed for $days+ days (newest data from ncm_meta) */
function ncm_ins_stuck(int $days): array {
  $out = []; $now = time();
  foreach (ncm_ins_orders() as $r) {
    if (ncm_ins_state($r) !== 'active' || !$r['last_change_at']) continue;
    $d = ($now - strtotime($r['last_change_at'])) / 86400;
    if ($d >= $days) $out[] = $r + ['stuck_d'=>$d];
  }
  usort($out, fn($a, $b) => $b['stuck_d'] <=> $a['stuck_d']);
  return $out;
}
