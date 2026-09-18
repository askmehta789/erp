<?php
require_once __DIR__ . '/config.php';
/* keep the whole app on Nepal time regardless of server location */
date_default_timezone_set('Asia/Kathmandu');

function db() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE       => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                 PDO::ATTR_TIMEOUT       => 10]
            );
            try { $pdo->exec("SET time_zone = '+05:45'"); } catch (Exception $e) {}
        } catch (PDOException $e) {
            die('<h3>Database connection failed.</h3><p>Check your details in <b>config.php</b>.<br>'
                .htmlspecialchars($e->getMessage()).'</p>');
        }
    }
    return $pdo;
}

function q($sql, $p = []) {
    try {
        $st = db()->prepare($sql);
        $st->execute((array)$p);
        return $st;
    } catch (PDOException $e) {
        /* re-throw as generic Exception so page handlers can catch it */
        throw new Exception('DB error: '.$e->getMessage().' | SQL: '.substr($sql,0,120));
    }
}
function rows($sql, $p = []) { return q($sql, $p)->fetchAll(); }
function row($sql, $p = [])  { return q($sql, $p)->fetch(); }
function val($sql, $p = [])  { return q($sql, $p)->fetchColumn(); }

function setting($key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (rows("SELECT skey,svalue FROM settings") as $r)
                $cache[$r['skey']] = $r['svalue'];
        } catch (Exception $e) { /* settings table may not exist yet */ }
    }
    return $cache[$key] ?? $default;
}

/* ============================================================
   FIFO STOCK BATCHES
   Each purchase = a batch (qty + unit cost). Deliveries consume
   from the OLDEST batch first; order cost_price = actual FIFO cost.
   ============================================================ */
function ensure_stock_batches() {
  static $done=false; if($done) return; $done=true;
  try {
    q("CREATE TABLE IF NOT EXISTS stock_batches(
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        purchase_date DATE NOT NULL,
        qty_in INT NOT NULL DEFAULT 0,
        qty_left INT NOT NULL DEFAULT 0,
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        note VARCHAR(160) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(product_id))");
    q("CREATE TABLE IF NOT EXISTS batch_use(
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        channel VARCHAR(12) NOT NULL DEFAULT 'sales',
        batch_id INT NOT NULL,
        qty INT NOT NULL,
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        INDEX(order_id), INDEX(batch_id))");
    try { q("ALTER TABLE batch_use ADD COLUMN channel VARCHAR(12) NOT NULL DEFAULT 'sales'"); } catch (Exception $e) {}
  } catch (Exception $e) {}
}

/* products created before batches existed get an opening batch from their current stock */
function batches_migrate($pid) {
  ensure_stock_batches(); $pid=(int)$pid; if(!$pid) return;
  try {
    if ((int)val("SELECT COUNT(*) FROM stock_batches WHERE product_id=?",[$pid])) return;
    $p = row("SELECT stock,cost FROM products WHERE id=?",[$pid]);
    if ($p && (int)$p['stock']>0)
      q("INSERT INTO stock_batches(product_id,purchase_date,qty_in,qty_left,unit_cost,note)
         VALUES(?,?,?,?,?,'Opening stock')",[$pid,date('Y-m-d'),(int)$p['stock'],(int)$p['stock'],(float)$p['cost']]);
  } catch (Exception $e) {}
}

/* keep products.stock as a cached total of remaining batch units */
function stock_cache($pid) {
  try { q("UPDATE products SET stock=(SELECT COALESCE(SUM(qty_left),0) FROM stock_batches WHERE product_id=?) WHERE id=?",[(int)$pid,(int)$pid]); } catch (Exception $e) {}
}

/* pure FIFO planner (unit-testable): batches = [[id,qty_left,unit_cost],…] oldest first */
function fifo_plan(array $batches, $need) {
  $need=(int)$need; $plan=[]; 
  foreach ($batches as $b) {
    if ($need<=0) break;
    $take=min($need,(int)$b['qty_left']);
    if ($take>0) { $plan[]=['batch_id'=>(int)$b['id'],'qty'=>$take,'unit_cost'=>(float)$b['unit_cost']]; $need-=$take; }
  }
  return ['plan'=>$plan,'short'=>$need];   // short = units not covered by any batch
}

/* consume FIFO for a delivered order; returns actual per-unit cost used */
function fifo_consume($orderId,$pid,$qty,$channel='sales') {
  ensure_stock_batches(); $pid=(int)$pid; $qty=(int)$qty; $orderId=(int)$orderId;
  if(!$pid||$qty<=0) return null;
  batches_migrate($pid);
  $batches = rows("SELECT id,qty_left,unit_cost FROM stock_batches WHERE product_id=? AND qty_left>0 ORDER BY purchase_date,id",[$pid]);
  $r = fifo_plan($batches,$qty);
  $costTotal=0;
  foreach ($r['plan'] as $u) {
    q("UPDATE stock_batches SET qty_left=qty_left-? WHERE id=?",[$u['qty'],$u['batch_id']]);
    if ($orderId) q("INSERT INTO batch_use(order_id,channel,batch_id,qty,unit_cost) VALUES(?,?,?,?,?)",[$orderId,$channel,$u['batch_id'],$u['qty'],$u['unit_cost']]);
    $costTotal += $u['qty']*$u['unit_cost'];
  }
  if ($r['short']>0) {   // more sold than batches held — price the shortfall at the product's base cost
    $base=(float)val("SELECT cost FROM products WHERE id=?",[$pid]);
    $costTotal += $r['short']*$base;
  }
  stock_cache($pid);
  $unit = round($costTotal/max(1,$qty),2);
  if ($orderId) { try {
    if ($channel==='daraz') q("UPDATE daraz_orders SET cost_price=? WHERE id=?",[$unit,$orderId]);
    else q("UPDATE orders SET cost_price=? WHERE id=?",[$unit,$orderId]);
  } catch (Exception $e) {} }
  return $unit;
}

/* put units back into the exact batches an order took them from (undeliver/return/delete) */
function fifo_restore($orderId,$pid=0,$channel='sales') {
  ensure_stock_batches(); $orderId=(int)$orderId; if(!$orderId) return;
  $uses = rows("SELECT * FROM batch_use WHERE order_id=? AND channel=?",[$orderId,$channel]);
  foreach ($uses as $u) { q("UPDATE stock_batches SET qty_left=qty_left+? WHERE id=?",[(int)$u['qty'],(int)$u['batch_id']]); }
  q("DELETE FROM batch_use WHERE order_id=? AND channel=?",[$orderId,$channel]);
  if ($uses) {
    $pids = array_unique(array_map(fn($u)=>(int)val("SELECT product_id FROM stock_batches WHERE id=?",[(int)$u['batch_id']]),$uses));
    foreach($pids as $p) stock_cache($p);
  } elseif ($pid) { // order delivered before FIFO existed — restore into oldest batch
    batches_migrate($pid);
    $tbl = $channel==='daraz' ? 'daraz_orders' : 'orders';
    $b=row("SELECT id FROM stock_batches WHERE product_id=? ORDER BY purchase_date,id LIMIT 1",[$pid]);
    if($b){ $o=row("SELECT qty FROM $tbl WHERE id=?",[$orderId]); q("UPDATE stock_batches SET qty_left=qty_left+? WHERE id=?",[(int)($o['qty']??0),(int)$b['id']]); stock_cache($pid); }
  }
}

/* stock movement on any order status change (used by sales, NCM page and cron) */
function fifo_status_change($orderId,$pid,$qty,$oldStatus,$newStatus,$channel='sales') {
  /* consignment couriers: their orders draw from their own stock ledger
     (already FIFO-consumed at transfer time), not straight from main FIFO */
  if ($channel==='sales' && $orderId) {
    $cid = (int)val("SELECT COALESCE(courier_id,0) FROM orders WHERE id=?",[(int)$orderId]);
    $hh = hh_courier_id();
    if ($hh && $cid === $hh) { hh_on_status_change($orderId,$pid,$qty,$oldStatus,$newStatus); return; }
    $dx = dropex_courier_id();
    if ($dx && $cid === $dx) { dropex_on_status_change($orderId,$pid,$qty,$oldStatus,$newStatus); return; }
  }
  $was=($oldStatus==='delivered'); $is=($newStatus==='delivered');
  if(!$was&&$is)  fifo_consume($orderId,$pid,$qty,$channel);
  if($was&&!$is)  fifo_restore($orderId,$pid,$channel);
}

/* ============================================================
   BUSINESS ALERT CALCULATORS (shared by pages + cron)
   ============================================================ */
/* per-courier COD money position.
   Couriers release COD NET of their charges: payout = COD collected − delivery fees − cancel/return fees.
   held (what they still owe you) = net payable − releases recorded in cod_ledger. */
function cod_holdings() {
  $out = [];
  try {
    $agg = rows("SELECT o.courier_id AS cid, COALESCE(c.name,'(no courier)') AS name,
                        SUM(CASE WHEN o.status='delivered' AND o.payment_type='cod' THEN o.sell_price*o.qty ELSE 0 END) AS collected,
                        SUM(CASE WHEN o.status='delivered' THEN o.delivery_charge ELSE 0 END) AS dfees,
                        SUM(CASE WHEN o.status IN ('cancelled','returned') THEN o.cancel_charge ELSE 0 END) AS cfees
                 FROM orders o LEFT JOIN couriers c ON c.id=o.courier_id
                 WHERE o.courier_id IS NOT NULL
                 GROUP BY o.courier_id, c.name");
    $released = [];
    foreach (rows("SELECT courier_id, SUM(amount) AS amt FROM cod_ledger WHERE type='in' GROUP BY courier_id") as $r)
      $released[(int)$r['courier_id']] = (float)$r['amt'];
    foreach ($agg as $c) {
      $collected = (float)$c['collected'];
      if ($collected <= 0) continue;
      $charges = (float)$c['dfees'] + (float)$c['cfees'];
      $net  = $collected - $charges;
      $rel  = $released[(int)$c['cid']] ?? 0;
      $held = $net - $rel;
      $out[] = ['courier'=>$c['name'], 'cid'=>(int)$c['cid'], 'collected'=>$collected,
                'charges'=>$charges, 'net'=>$net, 'released'=>$rel, 'held'=>$held];
    }
    usort($out, fn($a,$b)=>$b['held']<=>$a['held']);
    $out = array_values(array_filter($out, fn($h)=>$h['held'] > 0.5 || $h['collected'] > 0));
  } catch (Exception $e) {}
  return $out;
}

/* FIFO-stock reorder suggestions from 28-day delivered velocity */
function reorder_suggestions() {
  $lead = max(1, (int)setting('reorder_lead_days', 7));
  $out = [];
  try {
    $vel = [];
    foreach (rows("SELECT product_id, SUM(qty) AS q FROM orders
                   WHERE status='delivered' AND order_date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
                   GROUP BY product_id") as $v)
      $vel[(int)$v['product_id']] = (float)$v['q'] / 28.0;   // units per day
    foreach (rows("SELECT id,name,stock FROM products") as $p) {
      $pd = $vel[(int)$p['id']] ?? 0;
      if ($pd <= 0) continue;                                   // nothing selling → no suggestion
      $cover = (int)floor(((int)$p['stock']) / $pd);            // days of stock left
      if ($cover <= $lead) {
        $suggest = (int)(ceil(max(0, $pd*28 - (int)$p['stock']) / 10) * 10);  // 4-week top-up, rounded to 10
        $out[] = ['id'=>(int)$p['id'], 'name'=>$p['name'], 'stock'=>(int)$p['stock'],
                  'per_week'=>round($pd*7,1), 'cover_days'=>$cover, 'suggest'=>max(10,$suggest)];
      }
    }
    usort($out, fn($a,$b)=>$a['cover_days']<=>$b['cover_days']);
  } catch (Exception $e) {}
  return $out;
}

/* cost of the NEXT unit to leave (oldest open batch) — used for order-time snapshots & projections */
function fifo_current_cost($pid) {
  $pid=(int)$pid; if(!$pid) return 0.0;
  ensure_stock_batches(); batches_migrate($pid);
  try {
    $c = val("SELECT unit_cost FROM stock_batches WHERE product_id=? AND qty_left>0 ORDER BY purchase_date,id LIMIT 1",[$pid]);
    if ($c !== null && $c !== false && $c !== '') return (float)$c;
  } catch (Exception $e) {}
  return (float)val("SELECT cost FROM products WHERE id=?",[$pid]);
}

/* tiny key-value cache (JSON payloads) */
function kv_ensure(){ static $ok=false; if($ok)return;
  try { q("CREATE TABLE IF NOT EXISTS kv_cache(k VARCHAR(64) PRIMARY KEY, v LONGTEXT, updated_at DATETIME)"); } catch (Exception $e) {}
  $ok=true;
}
function kv_get($k){ kv_ensure();
  try { $r=row("SELECT v,updated_at FROM kv_cache WHERE k=?",[$k]); return $r?['v'=>json_decode($r['v'],true),'at'=>$r['updated_at']]:null; }
  catch (Exception $e) { return null; }
}
function kv_set($k,$v){ kv_ensure();
  try { q("REPLACE INTO kv_cache(k,v,updated_at) VALUES(?,?,NOW())",[$k,json_encode($v)]); } catch (Exception $e) {}
}

/* ================= Hungry Hunter consignment stock =================
   Stock physically sits AT the courier. Transfers consume main FIFO batches
   (cost captured); HH-courier orders deduct HH stock on delivery, NOT main stock. */
function ensure_hh_stock() { static $ok=false; if($ok) return;
  try { q("CREATE TABLE IF NOT EXISTS hh_stock(
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    type VARCHAR(12) NOT NULL,           /* in | out | return | adjust */
    qty INT NOT NULL,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    ref_order_id INT NULL,
    note VARCHAR(160) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(product_id), INDEX(ref_order_id))"); } catch (Exception $e) {}
  $ok=true;
}
function hh_courier_id() {
  static $id=null; if($id!==null) return $id;
  $id=(int)val("SELECT id FROM couriers WHERE LOWER(name) LIKE '%hungry%' LIMIT 1");
  return $id;
}
/* per-product position at HH: [sent, delivered, returned, adj, left, avg_cost] */
function hh_position($pid=0) {
  ensure_hh_stock();
  $w = $pid ? " WHERE product_id=".(int)$pid : "";
  $out=[];
  foreach (rows("SELECT product_id,
          SUM(CASE WHEN type='in' THEN qty ELSE 0 END) sent,
          SUM(CASE WHEN type='in' THEN qty*unit_cost ELSE 0 END) sent_cost,
          SUM(CASE WHEN type='out' THEN qty ELSE 0 END) delivered,
          SUM(CASE WHEN type='return' THEN qty ELSE 0 END) returned,
          SUM(CASE WHEN type='adjust' THEN qty ELSE 0 END) adj
          FROM hh_stock $w GROUP BY product_id") as $r) {
    $r['left'] = (int)$r['sent'] - (int)$r['delivered'] - (int)$r['returned'] + (int)$r['adj'];
    $r['avg_cost'] = ((int)$r['sent'])>0 ? round((float)$r['sent_cost']/(int)$r['sent'],2) : 0;
    $out[(int)$r['product_id']]=$r;
  }
  return $pid ? ($out[(int)$pid] ?? ['sent'=>0,'delivered'=>0,'returned'=>0,'adj'=>0,'left'=>0,'avg_cost'=>0]) : $out;
}
/* delivery hook: HH orders move HH stock instead of main FIFO */
function hh_on_status_change($orderId,$pid,$qty,$oldStatus,$newStatus) {
  ensure_hh_stock(); $orderId=(int)$orderId; $pid=(int)$pid; $qty=(int)$qty;
  $was=($oldStatus==='delivered'); $is=($newStatus==='delivered');
  if(!$was && $is) {
    $pos=hh_position($pid);
    $unit=(float)$pos['avg_cost'];
    /* Fallback: if no priced stock was ever transferred to HH (avg_cost 0),
       use the product's real cost (oldest open FIFO batch, else products.cost)
       so profit still subtracts the product cost instead of showing 0. */
    if ($unit <= 0) {
      $unit = (float)fifo_current_cost($pid);
      if ($unit <= 0) $unit = (float)val("SELECT cost FROM products WHERE id=?",[$pid]);
    }
    q("INSERT INTO hh_stock(product_id,type,qty,unit_cost,ref_order_id,note) VALUES(?,?,?,?,?,?)",
      [$pid,'out',$qty,$unit,$orderId,'delivered by HH']);
    try { q("UPDATE orders SET cost_price=? WHERE id=?",[$unit,$orderId]); } catch (Exception $e) {}
  }
  if($was && !$is) {
    q("DELETE FROM hh_stock WHERE ref_order_id=? AND type='out'",[$orderId]);
  }
  if($was && $is) {
    /* status stayed 'delivered' — but the quantity itself may have just been
       edited (a typo fix, a correction). This is the exact bug that caused HH's
       "Delivered" count to silently drift away from the real order quantities:
       previously nothing here ever re-synced the ledger for a same-status save. */
    $existingRows = rows("SELECT id, qty FROM hh_stock WHERE ref_order_id=? AND type='out' ORDER BY id ASC",[$orderId]);
    if (count($existingRows) > 1) {
      /* legacy duplicate rows from before this sync existed — collapse them into
         one correct row instead of leaving the old ones silently double-counting */
      $keepId = (int)$existingRows[0]['id'];
      foreach (array_slice($existingRows,1) as $dupe) q("DELETE FROM hh_stock WHERE id=?",[(int)$dupe['id']]);
      q("UPDATE hh_stock SET qty=? WHERE id=?",[$qty,$keepId]);
    } elseif (count($existingRows) === 1) {
      if ((int)$existingRows[0]['qty'] !== $qty) q("UPDATE hh_stock SET qty=? WHERE id=?",[$qty,(int)$existingRows[0]['id']]);
    } elseif ($qty>0) {
      /* order was delivered before this hook existed / row was lost — create it now */
      $pos=hh_position($pid); $unit=(float)$pos['avg_cost'];
      if ($unit<=0) { $unit=(float)fifo_current_cost($pid); if ($unit<=0) $unit=(float)val("SELECT cost FROM products WHERE id=?",[$pid]); }
      q("INSERT INTO hh_stock(product_id,type,qty,unit_cost,ref_order_id,note) VALUES(?,?,?,?,?,?)",
        [$pid,'out',$qty,$unit,$orderId,'delivered by HH (synced late)']);
    }
  }
}

/* compares HH's stock ledger against the real, current quantities on delivered HH
   orders — the audit that would have caught the qty-edit bug automatically.
   Returns only products where they disagree. */
function hh_reconcile_check() {
  ensure_hh_stock();
  $hh = hh_courier_id(); if (!$hh) return [];
  $real = [];
  foreach (rows("SELECT product_id, COALESCE(SUM(qty),0) q FROM orders WHERE courier_id=? AND status='delivered' GROUP BY product_id",[$hh]) as $r) {
    $real[(int)$r['product_id']] = (int)$r['q'];
  }
  $ledger = hh_position();
  $pids = array_unique(array_merge(array_keys($real), array_keys($ledger)));
  $out = [];
  foreach ($pids as $pid) {
    $realQ = $real[$pid] ?? 0;
    $ledgerQ = (int)($ledger[$pid]['delivered'] ?? 0);
    if ($realQ !== $ledgerQ) $out[$pid] = ['real'=>$realQ,'ledger'=>$ledgerQ,'diff'=>$realQ-$ledgerQ];
  }
  return $out;
}

/* manually correct one product's ledger to match reality — used for mismatches
   that already existed before the fix above started preventing new ones */
function hh_reconcile_fix($pid) {
  $pid=(int)$pid; $issues = hh_reconcile_check();
  if (!isset($issues[$pid])) return false;
  $diff = (int)$issues[$pid]['diff'];
  if ($diff===0) return false;
  $pos = hh_position($pid); $unit=(float)$pos['avg_cost'];
  if ($unit<=0) { $unit=(float)fifo_current_cost($pid); if ($unit<=0) $unit=(float)val("SELECT cost FROM products WHERE id=?",[$pid]); }
  q("INSERT INTO hh_stock(product_id,type,qty,unit_cost,note) VALUES(?,?,?,?,?)",
    [$pid,'out',$diff,$unit,'Reconciliation — matched to real order quantities']);
  return true;
}

/* fix every mismatched product in one go */
function hh_reconcile_fix_all() {
  $n=0; foreach (array_keys(hh_reconcile_check()) as $pid) { if (hh_reconcile_fix($pid)) $n++; }
  return $n;
}

/* ============================================================
   DROPEX — a second consignment courier, same model as Hungry Hunter:
   stock physically lives with them, not deducted from main FIFO stock
   until they actually deliver. Built as a fully separate, parallel
   system (own table, own functions) rather than generalizing the HH
   functions — safer than risking the already-proven HH code path.
   ============================================================ */
function ensure_dropex_stock() { static $ok=false; if($ok) return;
  try { q("CREATE TABLE IF NOT EXISTS dropex_stock(
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    type VARCHAR(12) NOT NULL,           /* in | out | return | adjust */
    qty INT NOT NULL,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    ref_order_id INT NULL,
    note VARCHAR(160) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(product_id), INDEX(ref_order_id))"); } catch (Exception $e) {}
  $ok=true;
}
function dropex_courier_id() {
  static $id=null; if($id!==null) return $id;
  $id=(int)val("SELECT id FROM couriers WHERE LOWER(name) LIKE '%dropex%' LIMIT 1");
  return $id;
}
/* per-product position at Dropex: [sent, delivered, returned, adj, left, avg_cost] */
function dropex_position($pid=0) {
  ensure_dropex_stock();
  $w = $pid ? " WHERE product_id=".(int)$pid : "";
  $out=[];
  foreach (rows("SELECT product_id,
          SUM(CASE WHEN type='in' THEN qty ELSE 0 END) sent,
          SUM(CASE WHEN type='in' THEN qty*unit_cost ELSE 0 END) sent_cost,
          SUM(CASE WHEN type='out' THEN qty ELSE 0 END) delivered,
          SUM(CASE WHEN type='return' THEN qty ELSE 0 END) returned,
          SUM(CASE WHEN type='adjust' THEN qty ELSE 0 END) adj
          FROM dropex_stock $w GROUP BY product_id") as $r) {
    $r['left'] = (int)$r['sent'] - (int)$r['delivered'] - (int)$r['returned'] + (int)$r['adj'];
    $r['avg_cost'] = ((int)$r['sent'])>0 ? round((float)$r['sent_cost']/(int)$r['sent'],2) : 0;
    $out[(int)$r['product_id']]=$r;
  }
  return $pid ? ($out[(int)$pid] ?? ['sent'=>0,'delivered'=>0,'returned'=>0,'adj'=>0,'left'=>0,'avg_cost'=>0]) : $out;
}
/* delivery hook: Dropex orders move Dropex stock instead of main FIFO */
function dropex_on_status_change($orderId,$pid,$qty,$oldStatus,$newStatus) {
  ensure_dropex_stock(); $orderId=(int)$orderId; $pid=(int)$pid; $qty=(int)$qty;
  $was=($oldStatus==='delivered'); $is=($newStatus==='delivered');
  if(!$was && $is) {
    $pos=dropex_position($pid);
    $unit=(float)$pos['avg_cost'];
    if ($unit <= 0) {
      $unit = (float)fifo_current_cost($pid);
      if ($unit <= 0) $unit = (float)val("SELECT cost FROM products WHERE id=?",[$pid]);
    }
    q("INSERT INTO dropex_stock(product_id,type,qty,unit_cost,ref_order_id,note) VALUES(?,?,?,?,?,?)",
      [$pid,'out',$qty,$unit,$orderId,'delivered by Dropex']);
    try { q("UPDATE orders SET cost_price=? WHERE id=?",[$unit,$orderId]); } catch (Exception $e) {}
  }
  if($was && !$is) {
    q("DELETE FROM dropex_stock WHERE ref_order_id=? AND type='out'",[$orderId]);
  }
  if($was && $is) {
    /* same qty-edit-after-delivery sync + duplicate-collapse fix proven on HH */
    $existingRows = rows("SELECT id, qty FROM dropex_stock WHERE ref_order_id=? AND type='out' ORDER BY id ASC",[$orderId]);
    if (count($existingRows) > 1) {
      $keepId = (int)$existingRows[0]['id'];
      foreach (array_slice($existingRows,1) as $dupe) q("DELETE FROM dropex_stock WHERE id=?",[(int)$dupe['id']]);
      q("UPDATE dropex_stock SET qty=? WHERE id=?",[$qty,$keepId]);
    } elseif (count($existingRows) === 1) {
      if ((int)$existingRows[0]['qty'] !== $qty) q("UPDATE dropex_stock SET qty=? WHERE id=?",[$qty,(int)$existingRows[0]['id']]);
    } elseif ($qty>0) {
      $pos=dropex_position($pid); $unit=(float)$pos['avg_cost'];
      if ($unit<=0) { $unit=(float)fifo_current_cost($pid); if ($unit<=0) $unit=(float)val("SELECT cost FROM products WHERE id=?",[$pid]); }
      q("INSERT INTO dropex_stock(product_id,type,qty,unit_cost,ref_order_id,note) VALUES(?,?,?,?,?,?)",
        [$pid,'out',$qty,$unit,$orderId,'delivered by Dropex (synced late)']);
    }
  }
}

function dropex_reconcile_check() {
  ensure_dropex_stock();
  $dx = dropex_courier_id(); if (!$dx) return [];
  $real = [];
  foreach (rows("SELECT product_id, COALESCE(SUM(qty),0) q FROM orders WHERE courier_id=? AND status='delivered' GROUP BY product_id",[$dx]) as $r) {
    $real[(int)$r['product_id']] = (int)$r['q'];
  }
  $ledger = dropex_position();
  $pids = array_unique(array_merge(array_keys($real), array_keys($ledger)));
  $out = [];
  foreach ($pids as $pid) {
    $realQ = $real[$pid] ?? 0;
    $ledgerQ = (int)($ledger[$pid]['delivered'] ?? 0);
    if ($realQ !== $ledgerQ) $out[$pid] = ['real'=>$realQ,'ledger'=>$ledgerQ,'diff'=>$realQ-$ledgerQ];
  }
  return $out;
}
function dropex_reconcile_fix($pid) {
  $pid=(int)$pid; $issues = dropex_reconcile_check();
  if (!isset($issues[$pid])) return false;
  $diff = (int)$issues[$pid]['diff'];
  if ($diff===0) return false;
  $pos = dropex_position($pid); $unit=(float)$pos['avg_cost'];
  if ($unit<=0) { $unit=(float)fifo_current_cost($pid); if ($unit<=0) $unit=(float)val("SELECT cost FROM products WHERE id=?",[$pid]); }
  q("INSERT INTO dropex_stock(product_id,type,qty,unit_cost,note) VALUES(?,?,?,?,?)",
    [$pid,'out',$diff,$unit,'Reconciliation — matched to real order quantities']);
  return true;
}
function dropex_reconcile_fix_all() {
  $n=0; foreach (array_keys(dropex_reconcile_check()) as $pid) { if (dropex_reconcile_fix($pid)) $n++; }
  return $n;
}


/* ================= Bank & Payees shared ================= */
function ensure_payees() { static $ok=false; if($ok) return;
  try { q("CREATE TABLE IF NOT EXISTS payees(
    id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, note VARCHAR(160) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch (Exception $e) {}
  try { q("CREATE TABLE IF NOT EXISTS payee_ledger(
    id INT AUTO_INCREMENT PRIMARY KEY, payee_id INT NOT NULL,
    entry_date DATE NOT NULL, type VARCHAR(8) NOT NULL,
    amount DECIMAL(12,2) NOT NULL, label VARCHAR(140) DEFAULT '',
    ref_expense_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX(payee_id), INDEX(entry_date), INDEX(ref_expense_id))"); } catch (Exception $e) {}
  try { q("ALTER TABLE payee_ledger ADD COLUMN IF NOT EXISTS ref_expense_id INT NULL"); } catch (Exception $e) {}
  $ok=true;
}
function ensure_purchase_products() { static $ok=false; if($ok) return; $ok=true;
  try {
    $has=(int)val("SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchases' AND COLUMN_NAME='products'");
    if(!$has) q("ALTER TABLE purchases ADD COLUMN products VARCHAR(255) NULL");
  } catch (Exception $e) {}
}

/* ---- Follow-up & Return control (portable migrations) ---- *//* AUTO-REPAIR: any order that saved cost_price 0/* AUTO-REPAIR: any order that saved cost_price 0 gets the product's real cost.
   Runs automatically on profit pages; exits in one cheap COUNT when nothing to fix.
   Cost source order: HH avg (for Hungry Hunter rows) -> oldest open FIFO batch -> product base cost. */
function repair_zero_cost_profit() { static $done=false; if($done) return; $done=true;
  try {
    $n=(int)val("SELECT COUNT(*) FROM orders WHERE (cost_price IS NULL OR cost_price<=0)
                 AND product_id IS NOT NULL AND status NOT IN ('cancelled')");
    if(!$n) return;
    $hh=function_exists('hh_courier_id') ? hh_courier_id() : 0;
    $bad=rows("SELECT id,product_id,courier_id,status FROM orders
               WHERE (cost_price IS NULL OR cost_price<=0) AND product_id IS NOT NULL
                 AND status NOT IN ('cancelled') LIMIT 500");
    foreach($bad as $o){
      $pid=(int)$o['product_id']; $unit=0.0;
      if($hh && (int)$o['courier_id']===$hh && function_exists('hh_position')){
        $pos=hh_position($pid); $unit=(float)$pos['avg_cost'];
      }
      if($unit<=0 && function_exists('fifo_current_cost')) $unit=(float)fifo_current_cost($pid);
      if($unit<=0) $unit=(float)val("SELECT cost FROM products WHERE id=?",[$pid]);
      if($unit>0) q("UPDATE orders SET cost_price=? WHERE id=?",[$unit,$o['id']]);
    }
  } catch (Exception $e) {}
}
function ensure_order_group() { static $ok=false; if($ok) return;
  /* portable across MySQL and MariaDB: probe information_schema, then plain ALTER (no IF NOT EXISTS) */
  try {
    $has = (int)val("SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='order_group'");
    if (!$has) q("ALTER TABLE orders ADD COLUMN order_group VARCHAR(24) NULL");
  } catch (Exception $e) {}
  $ok=true;
}
function ensure_banks() { static $ok=false; if($ok) return;
  try { q("CREATE TABLE IF NOT EXISTS bank_accounts(
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    acct_no VARCHAR(60) DEFAULT '',
    kind VARCHAR(16) NOT NULL DEFAULT 'bank',   /* bank | wallet | cash */
    opening DECIMAL(14,2) NOT NULL DEFAULT 0,
    remarks VARCHAR(200) DEFAULT '',
    archived TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch (Exception $e) {}
  try { q("CREATE TABLE IF NOT EXISTS bank_txns(
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    txn_date DATE NOT NULL,
    direction VARCHAR(4) NOT NULL,              /* in | out */
    amount DECIMAL(14,2) NOT NULL,
    category VARCHAR(40) DEFAULT '',
    remarks VARCHAR(200) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(account_id), INDEX(txn_date))"); } catch (Exception $e) {}
  $ok=true;
}
/* current balance of one account: opening + ins - outs */
function bank_balance($accId) {
  ensure_banks();
  $open=(float)val("SELECT opening FROM bank_accounts WHERE id=?", [$accId]);
  $in =(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='in'",  [$accId]);
  $out=(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='out'", [$accId]);
  return $open + $in - $out;
}
function set_setting($k,$v){ try { q("INSERT INTO settings(skey,svalue) VALUES(?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)",[$k,$v]); } catch (Exception $e) {} }
/* auto-bill an Ads expense as a Due to the configured ads payee */
function ads_autobill($expenseId,$date,$amountRs,$product,$usd=null){
  /* $amountRs MUST already be in rupees. If a USD figure is supplied we note it in the label. */
  $pid=(int)setting('ads_payee_id',0); if(!$pid) return;
  ensure_payees();
  $label='Ads'.($product?' — '.$product:'').($usd? ' ($'.rtrim(rtrim(number_format((float)$usd,2),'0'),'.').')' : '');
  try { q("INSERT INTO payee_ledger(payee_id,entry_date,type,amount,label,ref_expense_id) VALUES(?,?,?,?,?,?)",
    [$pid,$date,'due',(float)$amountRs,$label,(int)$expenseId]); } catch (Exception $e) {}
}