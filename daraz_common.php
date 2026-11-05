<?php
/* ============================================================
   daraz_common.php — shared by the Daraz Dashboard, Daraz Sales,
   Daraz Analytics pages and the daraz_link.php endpoint.

   Link-by-order-ID model: the ERP no longer bulk-imports every
   Daraz order. Staff check ONE Daraz order number at a time; the
   ERP fetches only that order, shows ERP-vs-Daraz side by side,
   and changes nothing until "Update ERP from Daraz" is pressed.
   ============================================================ */
require_once __DIR__.'/daraz_api.php';

function daraz_courier_id(): int {
  static $id = null;
  if ($id !== null) return $id;
  $r = row("SELECT id FROM couriers WHERE LOWER(name)='daraz' ORDER BY id LIMIT 1");
  if (!$r) {
    try { q("INSERT INTO couriers(name,status) VALUES('Daraz','active')"); }
    catch (Exception $e) { try { q("INSERT INTO couriers(name) VALUES('Daraz')"); } catch (Exception $e2) {} }
    $r = row("SELECT id FROM couriers WHERE LOWER(name)='daraz' ORDER BY id LIMIT 1");
  }
  return $id = (int)($r['id'] ?? 0);
}

function daraz_ensure_ads_table(): void {
  static $ok = false; if ($ok) return;
  try { q("CREATE TABLE IF NOT EXISTS daraz_ads(
    id INT AUTO_INCREMENT PRIMARY KEY,
    spend_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    note VARCHAR(160) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch (Exception $e) {}
  $ok = true;
}

/* product lookup by Daraz SKU (explicit mapping wins over the product's own SKU) */
function daraz_products_by_sku(): array {
  static $map = null;
  if ($map !== null) return $map;
  $map = [];
  foreach (rows("SELECT id,sku,cost FROM products WHERE sku IS NOT NULL AND sku<>''") as $p) $map[strtolower(trim($p['sku']))] = $p;
  foreach (rows("SELECT id,daraz_sku,cost FROM products WHERE daraz_sku IS NOT NULL AND daraz_sku<>''") as $p) $map[strtolower(trim($p['daraz_sku']))] = $p;
  return $map;
}

/* next free order code, same smallest-unused-number scheme sales.php uses */
function daraz_next_code(): string {
  $pref = (string)setting('order_prefix','ORD-');
  $used = [];
  foreach (rows("SELECT code FROM orders") as $cr) {
    $c = (string)$cr['code'];
    if ($pref !== '' && strpos($c, $pref) === 0) $c = substr($c, strlen($pref));
    if (ctype_digit($c)) $used[(int)$c] = 1;
  }
  $n = 1; while (isset($used[$n])) $n++;
  return $pref.str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/* ---------- 1) fetch ONE order from Daraz, normalised ---------- */
function daraz_fetch_order(string $orderId, bool $withFinance = true): array {
  $daraz = new Daraz();
  if (!$daraz->connected()) throw new Exception('Daraz is not connected yet — authorize it in Settings → Channel / Daraz API first.');
  $orderId = preg_replace('/\D+/', '', $orderId);
  if ($orderId === '') throw new Exception('Enter a Daraz order number.');

  $h = $daraz->getOrder($orderId);
  if (!$h) throw new Exception("Daraz has no order #$orderId on this seller account.");
  $items = $daraz->getOrderItemsOne($orderId);
  $txns = null; $finErr = null;
  if ($withFinance) {
    try { $txns = $daraz->getTransactions(date('Y-m-d', strtotime($h['created_at'] ?? 'now')), date('Y-m-d'), $orderId); }
    catch (Exception $e) {
      $finErr = stripos($e->getMessage(), 'permission') !== false || stripos($e->getMessage(), 'Insufficient') !== false
        ? 'Your Daraz app has no Finance permission yet — fees must be entered by hand.'
        : 'Fees unavailable: '.$e->getMessage();
    }
  }
  return daraz_normalize_order($orderId, $h, $items, $txns, $finErr);
}

/* turn Daraz's raw order + items (+ finance rows) into the ERP's view of the order.
   Separate from the API calls so it can be tested with a saved Daraz response. */
function daraz_normalize_order(string $orderId, array $h, array $items, ?array $txns, ?string $finErr = null): array {
  $lines = [];
  foreach ($items as $it) {
    $sku    = trim((string)($it['sku'] ?? '')) ?: trim((string)($it['shop_sku'] ?? ''));
    $raw    = (string)($it['status'] ?? '');
    $status = daraz_map_status($raw);
    $list   = dz_num($it['item_price'] ?? 0);
    $paid   = dz_num($it['paid_price'] ?? $list);
    $sellerV= dz_num($it['voucher_seller'] ?? 0);
    $platV  = dz_num($it['voucher_platform'] ?? 0);
    $ship   = dz_num($it['shipping_amount'] ?? 0);
    $unit   = round(max(0, $list - $sellerV), 2);
    $key = $sku.'|'.$unit.'|'.$status;
    if (!isset($lines[$key])) $lines[$key] = [
      'sku'=>$sku, 'name'=>trim((string)($it['name'] ?? '')), 'status'=>$status, 'status_raw'=>$raw,
      'qty'=>0, 'list_unit'=>$list, 'unit_revenue'=>$unit,
      'seller_discount'=>0.0, 'platform_voucher'=>0.0, 'paid'=>0.0, 'ship_customer'=>0.0,
    ];
    $lines[$key]['qty']++;
    $lines[$key]['seller_discount']  += $sellerV;
    $lines[$key]['platform_voucher'] += $platV;
    $lines[$key]['paid']             += $paid;
    $lines[$key]['ship_customer']    += $ship;
  }
  $lines = array_values($lines);
  /* some responses carry shipping only on the header — keep it for reference on line 1 */
  $shipHeader = dz_num($h['shipping_fee'] ?? 0);
  if ($lines && array_sum(array_column($lines, 'ship_customer')) <= 0 && $shipHeader > 0) $lines[0]['ship_customer'] = $shipHeader;

  $fin = ['ok'=>false, 'error'=>$finErr, 'fees'=>0.0, 'credits'=>0.0, 'payout'=>0.0, 'rows'=>[]];
  if ($txns !== null) {
    foreach ($txns as $t) {
      $on = preg_replace('/\D+/', '', (string)($t['order_no'] ?? $t['trade_order_id'] ?? $orderId));
      if ($on !== '' && $on !== $orderId) continue;          /* API may ignore the order filter */
      $amt = dz_num($t['amount'] ?? 0);
      $fin['rows'][] = ['name'=>(string)($t['fee_name'] ?? $t['transaction_type'] ?? 'Transaction'), 'amount'=>$amt,
                        'date'=>(string)($t['transaction_date'] ?? '')];
      if ($amt < 0) $fin['fees'] += -$amt; else $fin['credits'] += $amt;
    }
    $fin['payout'] = round($fin['credits'] - $fin['fees'], 2);
    $fin['ok'] = (bool)$fin['rows'];
    if (!$fin['rows']) $fin['error'] = 'No finance entries yet — Daraz posts fees after the order is delivered and settled.';
  }

  $addr = $h['address_shipping'] ?? [];
  return [
    'order_id'  => $orderId,
    'created'   => date('Y-m-d', strtotime($h['created_at'] ?? 'now')),
    'customer'  => trim(($h['customer_first_name'] ?? '').' '.($h['customer_last_name'] ?? '')),
    'phone'     => daraz_clean_phone((string)($addr['phone'] ?? '')),
    'address'   => trim(implode(', ', array_filter([$addr['address1'] ?? '', $addr['address2'] ?? '', $addr['city'] ?? '']))),
    'city'      => trim((string)($addr['city'] ?? '')),
    'payment'   => stripos((string)($h['payment_method'] ?? ''), 'cod') !== false ? 'cod' : 'prepaid',
    'lines'     => $lines,
    'ship_header' => $shipHeader,
    'finance'   => $fin,
  ];
}

/* ---------- 2) ERP rows that belong to a Daraz order ---------- */
function daraz_erp_rows(string $orderId): array {
  daraz_ensure_cols();
  $orderId = preg_replace('/\D+/', '', $orderId);
  return rows("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id
               WHERE o.daraz_order_id=?
                  OR (o.daraz_order_id IS NULL AND o.courier_id=? AND (TRIM(o.remarks)=? OR TRIM(o.remarks)=? OR o.remarks LIKE ?))
               ORDER BY o.id", [$orderId, daraz_courier_id(), $orderId, 'Daraz #'.$orderId, 'Daraz #'.$orderId.' %']);
}

/* pair each Daraz line with an ERP row: same SKU first, then whatever is left in order */
function daraz_pair_lines(array $lines, array $erp): array {
  $used = []; $pairs = [];
  foreach ($lines as $i => $L) {
    foreach ($erp as $j => $r) {
      if (isset($used[$j])) continue;
      if ($L['sku'] !== '' && strcasecmp((string)($r['daraz_sku'] ?? ''), $L['sku']) === 0) { $pairs[$i] = $j; $used[$j] = 1; break; }
    }
  }
  foreach ($lines as $i => $L) {
    if (isset($pairs[$i])) continue;
    foreach ($erp as $j => $r) { if (!isset($used[$j])) { $pairs[$i] = $j; $used[$j] = 1; break; } }
  }
  $extraErp = array_values(array_diff(array_keys($erp), array_keys($used)));
  return [$pairs, $extraErp];
}

/* ---------- 3) side-by-side comparison ---------- */
function daraz_compare(array $dz): array {
  $erp = daraz_erp_rows($dz['order_id']);
  [$pairs, $extraErp] = daraz_pair_lines($dz['lines'], $erp);
  $out = ['order_id'=>$dz['order_id'], 'linked'=>(bool)$erp, 'lines'=>[], 'extra_erp'=>[], 'diffs'=>0, 'totals'=>[]];
  $erpRev = 0; $dzRev = 0; $erpFee = 0;
  foreach ($erp as $r) { if ($r['status'] !== 'cancelled') $erpRev += (float)$r['sell_price'] * (int)$r['qty']; $erpFee += (float)$r['delivery_charge']; }
  foreach ($dz['lines'] as $i => $L) {
    if ($L['status'] !== 'cancelled') $dzRev += $L['unit_revenue'] * $L['qty'];
    $r = isset($pairs[$i]) ? $erp[$pairs[$i]] : null;
    /* an item cancelled on Daraz that the ERP never had is correct as-is — cancelled
       Daraz items are deliberately not imported, so it isn't a difference */
    if (!$r && $L['status'] === 'cancelled') {
      $out['lines'][] = ['sku'=>$L['sku'], 'name'=>$L['name'].' (cancelled on Daraz — not imported)', 'erp_id'=>null, 'erp_code'=>null,
        'erp_product'=>null, 'fields'=>[['label'=>'Status','erp'=>'—','daraz'=>'cancelled','money'=>false,'diff'=>false]],
        'info'=>['list_unit'=>$L['list_unit'], 'seller_discount'=>$L['seller_discount'], 'platform_voucher'=>$L['platform_voucher'],
                 'paid'=>$L['paid'], 'ship_customer'=>$L['ship_customer'], 'status_raw'=>$L['status_raw']]];
      continue;
    }
    $f = [];
    $add = function($label, $erpV, $dzV, $isMoney = false) use (&$f, &$out) {
      $differs = $isMoney ? abs((float)$erpV - (float)$dzV) > 0.009 : (string)$erpV !== (string)$dzV;
      if ($differs) $out['diffs']++;
      $f[] = ['label'=>$label, 'erp'=>$erpV, 'daraz'=>$dzV, 'money'=>$isMoney, 'diff'=>$differs];
    };
    $add('Qty', $r ? (int)$r['qty'] : '—', $L['qty']);
    $add('Your price per unit', $r ? (float)$r['sell_price'] : '—', $L['unit_revenue'], true);
    $add('Status', $r ? $r['status'] : '—', $L['status']);
    $out['lines'][] = [
      'sku'=>$L['sku'], 'name'=>$L['name'], 'erp_id'=>$r ? (int)$r['id'] : null, 'erp_code'=>$r['code'] ?? null,
      'erp_product'=>$r['product_name'] ?? null, 'fields'=>$f,
      'info'=>['list_unit'=>$L['list_unit'], 'seller_discount'=>$L['seller_discount'], 'platform_voucher'=>$L['platform_voucher'],
               'paid'=>$L['paid'], 'ship_customer'=>$L['ship_customer'], 'status_raw'=>$L['status_raw']],
    ];
  }
  foreach ($extraErp as $j) $out['extra_erp'][] = ['id'=>(int)$erp[$j]['id'], 'code'=>$erp[$j]['code'], 'product'=>$erp[$j]['product_name'] ?: $erp[$j]['daraz_item_name'], 'qty'=>(int)$erp[$j]['qty'], 'status'=>$erp[$j]['status']];
  if ($out['extra_erp']) $out['diffs'] += count($out['extra_erp']);
  if (!$erp) $out['diffs'] = max(1, $out['diffs']);
  $shipAsCost = $erp && !$dz['finance']['ok'] && $dz['ship_header'] > 0 && abs($erpFee - $dz['ship_header']) < 0.01;
  $out['totals'] = [
    'erp_revenue'=>round($erpRev,2), 'daraz_revenue'=>round($dzRev,2),
    'erp_fees'=>round($erpFee,2), 'daraz_fees'=>$dz['finance']['ok'] ? round($dz['finance']['fees'],2) : null,
    'payout'=>$dz['finance']['ok'] ? $dz['finance']['payout'] : null,
    'ship_customer'=>round(array_sum(array_column($dz['lines'],'ship_customer')),2),
    'ship_as_cost'=>$shipAsCost,
  ];
  if (abs($erpRev - $dzRev) > 0.009 && $erp) $out['diffs']++;
  if ($dz['finance']['ok'] && abs($erpFee - $dz['finance']['fees']) > 0.009) $out['diffs']++;
  if ($shipAsCost) $out['diffs']++;
  return $out;
}

/* ---------- 4) apply Daraz's values to the ERP ---------- */
function daraz_apply(array $dz): array {
  daraz_ensure_cols();
  $dzCid = daraz_courier_id();
  if (!$dzCid) throw new Exception('Daraz courier not found.');
  $erp = daraz_erp_rows($dz['order_id']);
  [$pairs, $extraErp] = daraz_pair_lines($dz['lines'], $erp);
  $bySku = daraz_products_by_sku();
  $fin = $dz['finance'];
  $group = count($dz['lines']) > 1 ? ('DZ'.$dz['order_id']) : null;
  $res = ['updated'=>0, 'created'=>0, 'skipped_cancelled'=>0, 'unmatched'=>0, 'stale'=>count($extraErp)];
  $now = date('Y-m-d H:i:s');
  $first = true;

  foreach ($dz['lines'] as $i => $L) {
    $prod = $L['sku'] !== '' ? ($bySku[strtolower($L['sku'])] ?? null) : null;
    $common = [
      'daraz_order_id'=>$dz['order_id'], 'daraz_sku'=>$L['sku'] ?: null, 'daraz_item_name'=>$L['name'] ?: null,
      'daraz_list_price'=>$L['list_unit'], 'daraz_seller_discount'=>round($L['seller_discount'],2),
      'daraz_platform_voucher'=>round($L['platform_voucher'],2), 'daraz_paid'=>round($L['paid'],2),
      'daraz_ship_customer'=>round($L['ship_customer'],2), 'daraz_status_raw'=>$L['status_raw'], 'daraz_checked_at'=>$now,
      'sell_price'=>$L['unit_revenue'], 'qty'=>max(1,$L['qty']), 'order_group'=>$group,
    ];

    if (isset($pairs[$i])) {
      $r = $erp[$pairs[$i]];
      $set = $common;
      $set['status'] = $L['status'];
      if ($L['status'] === 'delivered') $set['payment_status'] = 'paid';
      /* fees: real finance figure when we have it (on the order's first line only) */
      if ($fin['ok']) {
        $set['delivery_charge'] = $first ? round($fin['fees'],2) : 0;
        $set['daraz_payout']    = $first ? $fin['payout'] : null;
        $set['daraz_fee_source']= 'finance';
      } elseif (($r['daraz_fee_source'] ?? null) === null) {
        /* legacy import stored the CUSTOMER's shipping as your cost — undo exactly that case,
           keep anything else (that was typed by hand) */
        $wasShip = $dz['ship_header'] > 0 && abs((float)$r['delivery_charge'] - $dz['ship_header']) < 0.01;
        if ($wasShip) $set['delivery_charge'] = 0;
        $set['daraz_fee_source'] = ((float)($set['delivery_charge'] ?? $r['delivery_charge']) > 0) ? 'manual' : 'none';
      }
      foreach (['customer','phone','address'] as $k) if (trim((string)$r[$k]) === '' && $dz[$k] !== '') $set[$k] = $dz[$k];
      if (!$r['product_id'] && $prod) { $set['product_id'] = (int)$prod['id']; $set['cost_price'] = (float)$prod['cost']; }
      $pid = (int)($set['product_id'] ?? $r['product_id']);
      $cols = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($set)));
      q("UPDATE orders SET $cols WHERE id=?", array_merge(array_values($set), [(int)$r['id']]));
      /* stock follows the status / qty change (handles delivered→delivered qty edits too) */
      if ($pid) {
        $oldStatus = (!$r['product_id'] && $prod) ? '' : $r['status'];
        fifo_status_change((int)$r['id'], $pid, (int)$set['qty'], $oldStatus, $set['status']);
      }
      $res['updated']++;
    } else {
      if ($L['status'] === 'cancelled') { $res['skipped_cancelled']++; continue; }
      $remarks = 'Daraz #'.$dz['order_id'];
      if (!$prod) { $res['unmatched']++; $remarks .= ' ⚠ '.($L['name'] ?: 'item').' — SKU "'.$L['sku'].'" not mapped yet'; }
      $f = $common + [
        'code'=>daraz_next_code(), 'order_date'=>$dz['created'],
        'customer'=>$dz['customer'], 'phone'=>$dz['phone'], 'address'=>$dz['address'],
        'product_id'=>$prod['id'] ?? null, 'cost_price'=>$prod['cost'] ?? 0,
        'delivery_charge'=>($first && $fin['ok']) ? round($fin['fees'],2) : 0,
        'daraz_payout'=>($first && $fin['ok']) ? $fin['payout'] : null,
        'daraz_fee_source'=>$fin['ok'] ? 'finance' : 'none',
        'cancel_charge'=>0, 'exchange_charge'=>0, 'zone'=>'inside',
        'payment_type'=>$dz['payment'], 'payment_status'=>$L['status']==='delivered' ? 'paid' : 'unpaid',
        'status'=>$L['status'], 'courier_id'=>$dzCid, 'remarks'=>$remarks,
      ];
      $cols = implode('`,`', array_keys($f));
      q("INSERT INTO orders(`$cols`) VALUES(".implode(',', array_fill(0, count($f), '?')).")", array_values($f));
      $newId = (int)db()->lastInsertId();
      if ($prod) fifo_status_change($newId, (int)$prod['id'], (int)$f['qty'], '', $f['status']);
      $res['created']++;
    }
    $first = false;
  }
  log_activity("Daraz #{$dz['order_id']}: updated {$res['updated']}, added {$res['created']} line(s) from Daraz".($fin['ok'] ? ' (real fees)' : ''), 'Daraz');
  return $res;
}

/* ---------- shared date-range picker ---------- */
function daraz_range(array $src): array {
  $isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
  $today = date('Y-m-d');
  $presets = [
    'today'     => ['Today',        $today, $today],
    'yesterday' => ['Yesterday',    date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    '7d'        => ['Last 7 days',  date('Y-m-d', strtotime('-6 days')), $today],
    '30d'       => ['Last 30 days', date('Y-m-d', strtotime('-29 days')), $today],
    'month'     => ['This month',   date('Y-m-01'), date('Y-m-t')],
    'lastmonth' => ['Last month',   date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
  ];
  $range = (string)($src['range'] ?? '');
  if (isset($presets[$range])) { [$label, $start, $end] = $presets[$range]; }
  elseif ($isDate($src['from'] ?? '') && $isDate($src['to'] ?? '')) {
    $range = 'custom'; $start = $src['from']; $end = $src['to'];
    if ($start > $end) [$start, $end] = [$end, $start];
    $label = date('j M', strtotime($start)).' – '.date('j M Y', strtotime($end));
  } elseif (preg_match('/^\d{4}-\d{2}$/', $src['m'] ?? '')) {
    $range = 'custom'; $start = $src['m'].'-01'; $end = date('Y-m-t', strtotime($start)); $label = date('F Y', strtotime($start));
  } else { $range = 'month'; [$label, $start, $end] = $presets['month']; }
  $keep = $range === 'custom'
    ? '<input type="hidden" name="from" value="'.e($start).'"><input type="hidden" name="to" value="'.e($end).'">'
    : '<input type="hidden" name="range" value="'.e($range).'">';
  $qs = $range === 'custom' ? 'from='.urlencode($start).'&to='.urlencode($end) : 'range='.urlencode($range);
  return ['range'=>$range, 'start'=>$start, 'end'=>$end, 'label'=>$label, 'presets'=>$presets, 'keep'=>$keep, 'qs'=>$qs];
}

/* render the date bar (presets + custom from/to) — $page is the target script */
function daraz_range_bar(array $R, string $page, string $extra = ''): string {
  $h = '<div class="panel dz-range"><span class="dz-range-lbl">📅 Showing <b>'.e($R['label']).'</b></span><div class="dz-pills">';
  foreach ($R['presets'] as $k => [$l]) $h .= '<a class="dz-pill'.($R['range']===$k?' on':'').'" href="'.e($page).'?range='.$k.$extra.'">'.e($l).'</a>';
  $h .= '</div><form method="get" action="'.e($page).'" class="dz-range-form">'
      . '<input type="date" name="from" value="'.e($R['start']).'" aria-label="From date"><span class="muted">to</span>'
      . '<input type="date" name="to" value="'.e($R['end']).'" aria-label="To date"><button class="btn nh-ghost">Apply</button></form></div>';
  return $h;
}

/* ---------- shared metrics ---------- */
function daraz_orders_between(string $start, string $end): array {
  daraz_ensure_cols();
  return rows("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id
               WHERE o.courier_id=? AND o.order_date BETWEEN ? AND ?
               ORDER BY o.order_date DESC, o.id DESC", [daraz_courier_id(), $start, $end]);
}
function daraz_ads_between(string $start, string $end): float {
  daraz_ensure_ads_table();
  return (float)val("SELECT COALESCE(SUM(amount),0) FROM daraz_ads WHERE spend_date BETWEEN ? AND ?", [$start, $end]);
}
/* one consistent P&L for any set of Daraz order lines */
function daraz_metrics(array $orders, float $ads = 0.0): array {
  $m = ['orders'=>0, 'lines'=>count($orders), 'delivered'=>0, 'returned'=>0, 'open'=>0, 'units'=>0,
        'revenue'=>0.0, 'cost'=>0.0, 'fees'=>0.0, 'ret_loss'=>0.0, 'ads'=>$ads, 'gross'=>0.0, 'net'=>0.0,
        'missing_fees'=>0, 'unmatched'=>0, 'value'=>0.0];
  $ids = [];
  foreach ($orders as $o) {
    $ids[$o['daraz_order_id'] ?: ('erp'.$o['id'])] = 1;
    $q = (int)$o['qty']; $m['value'] += (float)$o['sell_price'] * $q;
    if ($o['status'] === 'delivered') {
      $m['delivered']++; $m['units'] += $q;
      $m['revenue'] += (float)$o['sell_price'] * $q; $m['cost'] += (float)$o['cost_price'] * $q; $m['fees'] += (float)$o['delivery_charge'];
      if ((float)$o['delivery_charge'] <= 0 && ($o['daraz_fee_source'] ?? '') !== 'finance') $m['missing_fees']++;
    } elseif (in_array($o['status'], ['returned','cancelled','exchanged'], true)) {
      $m['returned']++; $m['ret_loss'] += max(0, -order_profit($o));
    } else $m['open']++;
    if (!$o['product_id']) $m['unmatched']++;
    $m['gross'] += order_profit($o);
  }
  $m['orders'] = count($ids);
  $m['net']    = $m['gross'] - $ads;
  $m['margin'] = $m['revenue'] > 0 ? $m['net'] / $m['revenue'] * 100 : null;
  $m['roas']   = $ads > 0 ? $m['revenue'] / $ads : null;
  $m['ret_rate'] = ($m['delivered'] + $m['returned']) > 0 ? $m['returned'] / ($m['delivered'] + $m['returned']) * 100 : null;
  return $m;
}

/* shared look for the three Daraz pages (they sit inside the app's .ncmv3 glass theme) */
function daraz_shared_css(): string { return <<<'CSS'
<style>
.dz-nav{display:flex;gap:6px;flex-wrap:wrap;margin:14px 0 0}
.dz-nav a{padding:8px 14px;border-radius:999px;font-size:12.5px;font-weight:800;text-decoration:none;color:var(--ink);border:1px solid rgba(148,163,184,.35);background:rgba(255,255,255,.6)}
body.dark .dz-nav a{background:rgba(2,6,23,.4)}
.dz-nav a.on{background:#f85606;border-color:#f85606;color:#fff}
.dz-range{display:flex;align-items:center;gap:10px 14px;flex-wrap:wrap;margin-top:16px;padding:12px 16px}
.dz-range-lbl{font-size:13px}
.dz-pills{display:flex;gap:6px;flex-wrap:wrap}
.dz-pill{padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;text-decoration:none;color:var(--ink);border:1px solid rgba(148,163,184,.35);background:rgba(255,255,255,.6)}
body.dark .dz-pill{background:rgba(2,6,23,.4)}
.dz-pill:hover{border-color:#f85606}
.dz-pill.on{background:#f85606;border-color:#f85606;color:#fff}
.dz-range-form{display:inline-flex;gap:6px;align-items:center;margin-left:auto}
.dz-range-form input{border-radius:999px;padding:7px 12px;border:1px solid rgba(148,163,184,.35);font-size:12.5px}
.dz-status{display:inline-block;padding:4px 11px;border-radius:999px;font-size:11px;font-weight:800;border:none!important;appearance:none;cursor:pointer;color:#fff!important;box-shadow:none!important}
.dz-status.st-pending,.dz-status.st-processing{background:#64748b!important}
.dz-status.st-shipped{background:#e0851b!important}
.dz-status.st-delivered{background:#0f9d63!important}
.dz-status.st-returned,.dz-status.st-cancelled,.dz-status.st-exchanged{background:#dc4437!important}
.dz-sub{font-size:11.5px;color:var(--muted);margin-top:1px}
.dz-sub a{color:#0f9d63;text-decoration:none;font-weight:700}
.dz-oid{font-weight:800;display:flex;align-items:center;gap:6px}
.dz-oid .dz-dot{width:7px;height:7px;border-radius:50%;background:#f85606;flex:none}
.dz-warn{color:#b45309;font-size:11px;margin-top:2px;display:block;max-width:260px;white-space:normal}
.dz-badge{display:inline-block;font-size:10.5px;font-weight:800;padding:1px 7px;border-radius:999px}
.dz-b-ok{background:rgba(15,157,99,.14);color:#0f9d63}.dz-b-warn{background:rgba(245,158,11,.16);color:#b45309}.dz-b-fin{background:rgba(99,102,241,.14);color:#4f46e5}
.dz-up{color:var(--green);font-weight:800;font-size:11.5px}.dz-dn{color:var(--red);font-weight:800;font-size:11.5px}
/* link / check modal */
.dzl-tbl{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:8px}
.dzl-tbl th,.dzl-tbl td{padding:6px 8px;border-bottom:1px solid rgba(148,163,184,.2);text-align:left}
.dzl-tbl td.r,.dzl-tbl th.r{text-align:right}
.dzl-diff td{background:rgba(245,158,11,.12)}
.dzl-note{font-size:12px;padding:8px 12px;border-radius:10px;margin-top:8px}
.dzl-note.warn{background:rgba(245,158,11,.14);color:#92400e}.dzl-note.bad{background:rgba(239,68,68,.12);color:#b91c1c}.dzl-note.ok{background:rgba(15,157,99,.12);color:#047857}
body.dark .dzl-note.warn{color:#fcd34d}body.dark .dzl-note.bad{color:#fca5a5}body.dark .dzl-note.ok{color:#6ee7b7}
</style>
CSS; }

function daraz_tabs(string $current): string {
  $tabs = ['daraz.php'=>'📊 Dashboard', 'daraz_sales.php'=>'🧾 Daraz Sales', 'daraz_analytics.php'=>'📈 Analytics'];
  $h = '<nav class="dz-nav">';
  foreach ($tabs as $p => $l) if (can_see($p)) $h .= '<a href="'.$p.'"'.($p===$current?' class="on"':'').'>'.$l.'</a>';
  return $h.'</nav>';
}

require_once __DIR__.'/daraz_link_ui.php';
