<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
repair_zero_cost_profit();   /* auto-fix orders saved with Rs.0 cost so profit is honest everywhere */

/* ---- stock actions (batches) ---- */
ensure_stock_batches();
if (function_exists('ensure_hh_stock')) ensure_hh_stock();
if (function_exists('ensure_dropex_stock')) ensure_dropex_stock();
try { q("CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(140) NOT NULL, contact VARCHAR(120),
  city VARCHAR(80), category VARCHAR(60), balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)"); } catch (Exception $e) {}
try { q("ALTER TABLE stock_batches ADD COLUMN IF NOT EXISTS supplier_id INT NULL"); } catch (Exception $e) {}
try { q("ALTER TABLE products ADD COLUMN IF NOT EXISTS image VARCHAR(255) NULL"); } catch (Exception $e) {}
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['_action'] ?? '', ['restock','setstock'], true)) {
  check_csrf();
  $pid=(int)($_POST['id'] ?? 0);
  batches_migrate($pid);
  if (($_POST['_action'])==='restock') {
    $qty=max(0,(int)($_POST['qty'] ?? 0));
    $ucost=(float)($_POST['unit_cost'] ?? 0);
    $supplierId=(int)($_POST['supplier_id'] ?? 0) ?: null;
    if($ucost<=0) $ucost=(float)val("SELECT cost FROM products WHERE id=?",[$pid]);
    if(!$supplierId){
      flash('Pick a vendor before adding a purchase batch — this keeps "quantity purchased per vendor" accurate.');
    } elseif($qty>0){
      q("INSERT INTO stock_batches(product_id,purchase_date,qty_in,qty_left,unit_cost,note,supplier_id) VALUES(?,?,?,?,?,?,?)",
        [$pid, ($_POST['pdate'] ?? '') ?: date('Y-m-d'), $qty, $qty, $ucost, trim($_POST['note'] ?? ''), $supplierId]);
      q("UPDATE products SET cost=? WHERE id=?",[$ucost,$pid]);   /* latest rate becomes the base cost */
      stock_cache($pid);
      log_activity("Restocked #$pid: +$qty @ ".$ucost,'Stock'); flash("Batch added: $qty pcs @ Rs.$ucost");
    }
  } else { /* set exact count */
    $target=max(0,(int)($_POST['qty'] ?? 0));
    $cur=(int)val("SELECT COALESCE(SUM(qty_left),0) FROM stock_batches WHERE product_id=?",[$pid]);
    if ($target>$cur) {
      $ucost=(float)val("SELECT cost FROM products WHERE id=?",[$pid]);
      q("INSERT INTO stock_batches(product_id,purchase_date,qty_in,qty_left,unit_cost,note) VALUES(?,?,?,?,?,'Count adjustment +')",
        [$pid,date('Y-m-d'),$target-$cur,$target-$cur,$ucost]);
    } elseif ($target<$cur) {
      $need=$cur-$target;   /* shrinkage: remove from OLDEST batches first */
      foreach(rows("SELECT id,qty_left FROM stock_batches WHERE product_id=? AND qty_left>0 ORDER BY purchase_date,id",[$pid]) as $b){
        if($need<=0)break; $take=min($need,(int)$b['qty_left']);
        q("UPDATE stock_batches SET qty_left=qty_left-? WHERE id=?",[$take,(int)$b['id']]); $need-=$take;
      }
    }
    stock_cache($pid); log_activity("Set stock #$pid = $target",'Stock'); flash("Stock set to $target pcs.");
  }
  $returnTo = trim((string)($_POST['return_to'] ?? ''));
  $returnTo = (str_starts_with($returnTo,'product_detail.php?') || str_starts_with($returnTo,'products.php?')) ? $returnTo : ('products.php?open='.$pid.'#batches-'.$pid);
  header('Location: '.$returnTo); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action'] ?? '')==='bulk_category') {
  check_csrf();
  $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
  $cat = trim((string)($_POST['category'] ?? ''));
  $n=0; foreach($ids as $pid){ q("UPDATE products SET category=? WHERE id=?",[$cat,$pid]); $n++; }
  flash($n>0 ? "✅ Updated category for {$n} product(s)." : 'Nothing selected.');
  log_activity("Bulk category change: $n products → $cat",'Products');
  header('Location: products.php'); exit;
}
handle_crud('products');
$PAGE_TITLE='Products & Stock';

$products = rows("SELECT * FROM products ORDER BY name");
foreach($products as $p) batches_migrate((int)$p['id']);
$products = rows("SELECT * FROM products ORDER BY name");   /* re-read after migration */
$vendorList = rows("SELECT id,name FROM suppliers ORDER BY name");
/* batches per product + weighted avg cost of remaining stock */
$reorder = reorder_suggestions();
$reoMap = []; foreach($reorder as $r) $reoMap[$r['id']] = $r;
/* a batch is a genuine VENDOR PURCHASE only if it wasn't created by an internal stock
   movement. HH returns and manual count-corrections are real, valid stock — they belong
   in Stock Value — but they are not new money spent buying from a vendor, so they must
   never inflate "lifetime purchased". This is the fix for the 1000/200-sent/50-returned
   case: without it, the 50 returned pieces were being counted as a brand-new purchase,
   showing 1,050 lifetime instead of the true 1,000. */
function is_vendor_purchase_batch($note){
  $n = trim((string)$note);
  return $n !== 'Count adjustment +' && $n !== 'returned from Hungry Hunter';
}
$allBatches = rows("SELECT * FROM stock_batches ORDER BY product_id, purchase_date, id");
$bat=[]; $batVal=[]; $batAvg=[]; $lifeQty=[]; $lifeAmt=[];
foreach($allBatches as $b){ $pid=(int)$b['product_id']; $bat[$pid][]=$b;
  $batVal[$pid]=($batVal[$pid]??0)+(int)$b['qty_left']*(float)$b['unit_cost'];
  if (is_vendor_purchase_batch($b['note'])) {
    $lifeQty[$pid]=($lifeQty[$pid]??0)+(int)$b['qty_in'];
    $lifeAmt[$pid]=($lifeAmt[$pid]??0)+(int)$b['qty_in']*(float)$b['unit_cost'];
  }
}
foreach($bat as $pid=>$list){ $units=array_sum(array_map(fn($b)=>(int)$b['qty_left'],$list));
  $batAvg[$pid]=$units>0?round(($batVal[$pid]??0)/$units,2):null; }
/* Hungry Hunter movement history per product, for the flow panel */
$hhMoves=[];
foreach(rows("SELECT * FROM hh_stock ORDER BY product_id, created_at, id") as $m){ $hhMoves[(int)$m['product_id']][]=$m; }
$orders   = rows("SELECT product_id, qty, sell_price, cost_price, delivery_charge, cancel_charge, status, COALESCE(courier_id,0) AS courier_id FROM orders");
$courierNames = []; foreach(rows("SELECT id,name FROM couriers") as $c) $courierNames[(int)$c['id']] = $c['name'];

/* ---- stock on hand -------------------------------------------------------
   FIFO only deducts stock when a parcel is DELIVERED, so products.stock still
   counts units that physically left the shelf and are riding in a courier van.
   out  = shipped, not delivered yet  → gone from the shelf, still in book stock
   res  = pending/processing          → still on the shelf, but promised
   hh   = consignment sitting AT Hungry Hunter (a separate pool, already out of
          main stock when it was transferred)
   ------------------------------------------------------------------------- */
$hhCid = function_exists('hh_courier_id') ? hh_courier_id() : 0;
$dxCid = function_exists('dropex_courier_id') ? dropex_courier_id() : 0;
$commit=[];
foreach(rows("SELECT product_id, status, COALESCE(courier_id,0) AS cid, SUM(qty) AS q
              FROM orders WHERE status IN ('pending','processing','shipped')
              GROUP BY product_id, status, cid") as $r){
  $pid=(int)$r['product_id']; if(!$pid) continue;
  if($hhCid && (int)$r['cid']===$hhCid) continue;         /* HH orders draw from HH stock */
  if($dxCid && (int)$r['cid']===$dxCid) continue;         /* Dropex orders draw from Dropex stock */
  if(!isset($commit[$pid])) $commit[$pid]=['out'=>0,'res'=>0];
  if($r['status']==='shipped') $commit[$pid]['out'] += (int)$r['q'];
  else                         $commit[$pid]['res'] += (int)$r['q'];
}
$hhPos = function_exists('hh_position') ? hh_position() : [];
$dxPos = function_exists('dropex_position') ? dropex_position() : [];

/* ---- ad spend per product (Expenses → category 'Ads', tagged with a product) ---- */
$adsByProd=[]; $adsUntagged=0.0; $adsTotal=0.0;
try {
  foreach(rows("SELECT COALESCE(product,'') AS product, SUM(amount) AS amt
                FROM expenses WHERE category='Ads' GROUP BY product") as $r){
    $amt=(float)$r['amt']; $adsTotal += $amt;
    $key=mb_strtolower(trim((string)$r['product']));
    if($key==='') { $adsUntagged += $amt; continue; }
    $adsByProd[$key] = ($adsByProd[$key] ?? 0) + $amt;
  }
} catch (Exception $e) {}
$adsFor = function($p) use($adsByProd){ return (float)($adsByProd[mb_strtolower(trim((string)$p['name']))] ?? 0); };
$stockPos = function($p) use($commit,$hhPos,$dxPos){
  $pid=(int)$p['id']; $sys=(int)$p['stock'];
  $out=(int)($commit[$pid]['out'] ?? 0);
  $res=(int)($commit[$pid]['res'] ?? 0);
  $hh =(int)($hhPos[$pid]['left'] ?? 0);
  $dx =(int)($dxPos[$pid]['left'] ?? 0);
  $onHand = max(0,$sys-$out);
  return ['sys'=>$sys,'out'=>$out,'res'=>$res,'hh'=>$hh,'dx'=>$dx,'hand'=>$onHand,'free'=>max(0,$onHand-$res)];
};

/* aggregate per-product performance (delivered = realised) */
$agg=[]; $deliveredByCourier=[];
foreach($orders as $o){
  $pid=(int)$o['product_id']; if(!$pid) continue;
  if(!isset($agg[$pid])) $agg[$pid]=['sold'=>0,'rev'=>0,'cogs'=>0,'del'=>0,'profit'=>0,'orders'=>0,'returned'=>0];
  $agg[$pid]['orders']++;
  $q=(int)$o['qty'];
  if($o['status']==='delivered'){
    $agg[$pid]['sold']   += $q;
    $agg[$pid]['rev']    += (float)$o['sell_price']*$q;
    $agg[$pid]['cogs']   += (float)$o['cost_price']*$q;
    $agg[$pid]['del']    += (float)$o['delivery_charge'];
    $agg[$pid]['profit'] += ((float)$o['sell_price']-(float)$o['cost_price'])*$q - (float)$o['delivery_charge'];
    $cname = $courierNames[(int)$o['courier_id']] ?? 'Self / Other';
    $deliveredByCourier[$pid][$cname] = ($deliveredByCourier[$pid][$cname] ?? 0) + $q;
  } elseif(in_array($o['status'],['returned','cancelled'],true)){
    $agg[$pid]['returned']++;
    $agg[$pid]['profit'] -= (float)$o['cancel_charge'];   /* match order_profit(): a return/cancel is a loss, not zero */
  }
}
/* totals */
$tProducts=count($products);
$tStockUnits=array_sum(array_column($products,'stock'));
$tOnHand=0;$tOut=0;$tRes=0;$tHH=0;$tDX=0;$tLow=0;
foreach($products as $__p){ $__s=$stockPos($__p);
  $tOnHand+=$__s['hand']; $tOut+=$__s['out']; $tRes+=$__s['res']; $tHH+=$__s['hh']; $tDX+=$__s['dx'];
  if($__s['hand']<=(int)$__p['low_stock']) $tLow++;
}
$tStockValue=array_sum($batVal);
$tLifeQty=array_sum($lifeQty); $tLifeAmt=array_sum($lifeAmt);
$tSold=array_sum(array_map(fn($p)=>$agg[$p['id']]['sold']??0,$products));
$tRevenue=array_sum(array_map(fn($p)=>$agg[$p['id']]['rev']??0,$products));
$tGross=array_sum(array_map(fn($p)=>$agg[$p['id']]['profit']??0,$products));
$tAdsTagged=array_sum(array_map(fn($p)=>$adsFor($p),$products));
$tProfit=$tGross-$tAdsTagged;                       /* net of ads linked to a product */

/* ---- sparkline: units sold per week, last 6 weeks, per product ---- */
$wkAgo = date('Y-m-d', strtotime('-42 days'));
$weeklyRows = rows("SELECT product_id, DATE(order_date) od, qty FROM orders WHERE status='delivered' AND order_date>=?",[$wkAgo]);
$spark=[];
foreach($weeklyRows as $r){
  $pid=(int)$r['product_id']; if(!$pid) continue;
  $wk = (int)floor((strtotime(date('Y-m-d')) - strtotime($r['od'])) / (7*86400));  // 0=this week ... 5=6 weeks ago
  if($wk<0||$wk>5) continue;
  if(!isset($spark[$pid])) $spark[$pid]=[0,0,0,0,0,0];
  $spark[$pid][5-$wk] += (int)$r['qty'];   // index 0 = oldest, 5 = most recent, for left-to-right chart order
}

/* ---- best margin / most-returned flags (only among products with real activity) ----
   "Margin / pc" is NET, not just price-cost — it also deducts a flat per-piece
   overhead (Ads + Delivery + Office + Returns, editable in Settings → Finance & Tax)
   so it reflects what a unit actually earns, not an optimistic sticker number. */
$overheadPerPc = (float)setting('product_overhead_per_pc', 650);
$bestMarginPid=null; $bestMarginVal=-1;
$worstReturnPid=null; $worstReturnVal=-1;
foreach($products as $__p){
  $__a=$agg[$__p['id']]??['sold'=>0,'orders'=>0,'returned'=>0];
  if((float)$__p['price']>0){
    $__m=round(((float)$__p['price']-(float)$__p['cost']-$overheadPerPc)/(float)$__p['price']*100);
    if($__a['sold']>0 && $__m>$bestMarginVal){ $bestMarginVal=$__m; $bestMarginPid=(int)$__p['id']; }
  }
  if($__a['orders']>=5){   // minimum sample size so one unlucky order doesn't look like a crisis
    $__rr=round($__a['returned']/$__a['orders']*100,1);
    if($__rr>$worstReturnVal){ $worstReturnVal=$__rr; $worstReturnPid=(int)$__p['id']; }
  }
}

/* ---- Ads Health: should you keep paying to advertise each product? ----
   🔴 Stop: real ad spend AND you're actually losing money after ads (unambiguous, no threshold to argue).
   🟡 Watch: ad spend exists and EITHER return rate is elevated OR ROAS is weak, but still net-positive for now.
   Thresholds are Settings (not hardcoded) since "elevated" differs by business. */
$adsMinSpend  = (float)setting('ads_min_spend',500);
$adsWatchRoas = (float)setting('ads_watch_roas',2);
$adsWatchRet  = (float)setting('ads_watch_return_rate',15);
$adsVerdicts=[]; $adsStopCount=0; $adsWatchCount=0;
foreach($products as $__p){
  $__a=$agg[$__p['id']]??['rev'=>0,'profit'=>0,'orders'=>0,'returned'=>0];
  $__adsP=$adsFor($__p); if($__adsP < $adsMinSpend) continue;
  $__prof=$__a['profit']-$__adsP;
  $__roas=$__adsP>0 ? round($__a['rev']/$__adsP,2) : null;
  $__rr=$__a['orders']>0 ? round($__a['returned']/$__a['orders']*100,1) : 0;
  $__v=null;
  if($__prof<0) $__v='stop';
  elseif(($__roas!==null && $__roas<$adsWatchRoas) || $__rr>=$adsWatchRet) $__v='watch';
  if($__v){ $adsVerdicts[$__p['id']]=['verdict'=>$__v,'returnRate'=>$__rr,'prof'=>$__prof];
    if($__v==='stop'){ $adsStopCount++; $why=$__prof<0?('losing '.money(abs($__prof)).' after ads'):''; notify("📣 Stop ads suggested: {$__p['name']} — $why",'alert','products.php',24); }
    else $adsWatchCount++;
  }
}

require __DIR__.'/includes/header.php';
?>
<style>
.pd3-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin-bottom:16px}
.pd3-tile{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:13px 15px;box-shadow:0 5px 14px rgba(30,41,80,.05);border-top:3px solid #ddd;transition:transform .15s ease}
.pd3-tile:hover{transform:translateY(-2px)}
.pd3-tile b{font-size:17px;font-weight:900;display:block;overflow-wrap:anywhere;color:var(--ink);font-variant-numeric:tabular-nums}
.pd3-tile span{font-size:9.5px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.02em}
.pd3-tile.b{border-top-color:#0369a1}.pd3-tile.g{border-top-color:#16a34a}.pd3-tile.a{border-top-color:#d97706}.pd3-tile.p{border-top-color:#7c3aed}.pd3-tile.t{border-top-color:#0d9488}.pd3-tile.r{border-top-color:#dc2626}

.pd3-reocard{background:var(--amber-bg,#fff7ed);border:1px solid #fed7aa;border-radius:14px;padding:14px 16px;margin-bottom:14px}
.pd3-reocard h3{font-size:12.5px;font-weight:900;color:#9a3412;margin-bottom:9px}
.pd3-reorow{display:flex;align-items:center;gap:10px;padding:5px 0;flex-wrap:wrap;font-size:12.5px}
.pd3-reorow b{flex:1;color:var(--ink);font-weight:700;min-width:160px}
.pd3-cp{background:#c2410c;color:#fff;border:0;border-radius:7px;padding:6px 12px;font-size:10.5px;font-weight:800;cursor:pointer;white-space:nowrap}

.pd3-flagrow{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.pd3-flag{border-radius:14px;padding:13px 16px;color:#fff}
.pd3-flag.best{background:linear-gradient(120deg,#16a34a,#15803d)}
.pd3-flag.worst{background:linear-gradient(120deg,#dc2626,#991b1b)}
.pd3-flag .l{font-size:10px;opacity:.85;font-weight:800;text-transform:uppercase}
.pd3-flag .v{font-size:14px;font-weight:900;margin-top:3px}

.pd3-toolbar{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:12px 16px;box-shadow:0 5px 14px rgba(30,41,80,.05);margin-bottom:10px;display:flex;gap:10px;align-items:center}
.pd3-toolbar input{flex:1;border:0;background:var(--surface-2);color:var(--ink);border-radius:9px;padding:9px 14px;font-size:13px;font:inherit}
.pd3-filterbar{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px}
.pd3-fchip{background:var(--surface);border:1px solid var(--border);color:var(--ink);border-radius:99px;padding:6px 13px;font-size:11.5px;font-weight:700;box-shadow:0 3px 8px rgba(30,41,80,.04);cursor:pointer}
.pd3-fchip.on{background:#0369a1;border-color:#0369a1;color:#fff}

.pd3-bulkbar{display:none;background:var(--green-bg,#eefdf4);border:1px solid #86efac;border-radius:11px;padding:10px 16px;font-size:12.5px;font-weight:700;color:#15803d;margin-bottom:14px;align-items:center;gap:10px;flex-wrap:wrap}
.pd3-bulkbar.show{display:flex}
.pd3-bulkbar button{background:#16a34a;color:#fff;border:0;border-radius:7px;padding:6px 13px;font-size:11px;font-weight:800;cursor:pointer}
.pd3-bulkbar button.clear{background:#64748b}

.pd3-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px}
.pd3-card{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:0 8px 22px rgba(30,41,80,.07);overflow:hidden;position:relative;transition:transform .15s ease,box-shadow .15s ease}
.pd3-card:hover{transform:translateY(-2px);box-shadow:0 14px 30px rgba(30,41,80,.12)}
.pd3-card.warn{box-shadow:0 8px 22px rgba(220,38,38,.14);border-color:#fca5a5}
.pd3-chk{position:absolute;top:10px;left:10px;z-index:2;width:17px;height:17px}
.pd3-imgband{height:130px;display:flex;align-items:center;justify-content:center;font-size:30px;position:relative;color:#fff;font-weight:900;overflow:hidden}
.pd3-imgband.has-photo{background:var(--surface-2) !important}
.pd3-imgband img{width:100%;height:100%;object-fit:contain;padding:10px;box-sizing:border-box}
.pd3-imgband .flags{position:absolute;bottom:9px;left:10px;display:flex;gap:4px;flex-wrap:wrap;z-index:1}
.pd3-fbadge{font-size:9px;font-weight:800;border-radius:99px;padding:3px 8px;background:rgba(255,255,255,.94)}
.pd3-body{padding:14px 16px}
.pd3-nm{font-weight:900;font-size:14.5px;color:var(--ink);overflow-wrap:anywhere}
.pd3-sku{font-size:10.5px;color:var(--muted);margin-bottom:10px;font-weight:600}

.pd3-stockbadge{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:800;border-radius:9px;padding:6px 11px;margin-bottom:11px;width:100%;box-sizing:border-box}
.pd3-stockbadge.ok{background:var(--green-bg,#dcfce7);color:#15803d}
.pd3-stockbadge.low{background:var(--amber-bg,#fef3c7);color:#b45309}
.pd3-stockbadge.out{background:var(--red-bg,#fee2e2);color:#c0392b}

.pd3-statrow{display:flex;justify-content:space-between;align-items:center;font-size:12px;padding:6px 0;border-top:1px solid var(--border)}
.pd3-statrow:first-of-type{border-top:0}
.pd3-statrow span{color:var(--muted);font-weight:600}
.pd3-statrow b{font-weight:800;color:var(--ink);font-variant-numeric:tabular-nums}
.pd3-spark{display:flex;align-items:flex-end;gap:2px;height:18px;width:64px}
.pd3-spark div{flex:1;background:#93c5fd;border-radius:1px;min-height:2px}
.pd3-spark.down div{background:#fca5a5}
.pd3-chipsrow{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0}
.pd3-chip{font-size:9.5px;background:var(--surface-2);color:var(--muted);border-radius:99px;padding:3px 9px;font-weight:700}
.pd3-chip.ok{background:var(--green-bg,#dcfce7);color:#16a34a}.pd3-chip.out{background:var(--red-bg,#fee2e2);color:#c0392b}

.pd3-stockbar{height:8px;border-radius:99px;background:var(--surface-2);overflow:hidden;display:flex;margin:4px 0 8px}
.pd3-stockbar i{display:block}
.pd3-legend2{display:flex;flex-wrap:wrap;gap:8px 12px;font-size:10.5px;color:var(--muted);font-weight:700;margin-bottom:2px}
.pd3-legend2 span{display:inline-flex;align-items:center;gap:4px}
.pd3-legend2 i{width:8px;height:8px;border-radius:2px;display:inline-block;flex:none}

.pd3-verdict{font-size:10.5px;font-weight:800;border-radius:8px;padding:6px 10px;margin-top:10px}
.pd3-verdict.stop{background:var(--red-bg,#fee2e2);color:#c0392b}
.pd3-verdict.watch{background:var(--amber-bg,#fef3c7);color:#b45309}
.pd3-foot{display:flex;gap:7px;margin-top:12px}
.pd3-foot button,.pd3-foot a{flex:1;background:var(--surface-2);border:0;border-radius:9px;padding:8px;font-size:10.5px;font-weight:800;color:var(--ink);cursor:pointer;text-align:center;text-decoration:none;display:block}
.pd3-foot a.view{background:#dbeafe;color:#0369a1}
body.dark .pd3-foot a.view{background:rgba(59,130,246,.18);color:#7dd3fc}
.pd3-empty{padding:30px 18px;text-align:center;color:var(--muted);font-size:13px;background:var(--surface);border:1px solid var(--border);border-radius:16px}
</style>

<div class="page-head"><div><h1>🏷️ Products &amp; Stock</h1><p>Catalogue, FIFO batches, profit/loss — one place</p></div>
  <button class="btn btn-primary" onclick="crudOpen('products')">+ Add Product</button></div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<?php if($reorder): ?>
<div class="pd3-reocard">
  <h3>🛒 Reorder Suggestions — based on last 28 days of delivered sales vs FIFO stock</h3>
  <?php foreach(array_slice($reorder,0,8) as $r): ?>
  <div class="pd3-reorow">
    <b><?= e($r['name']) ?> — <?= (int)$r['stock'] ?> left · ~<?= $r['per_week'] ?>/week · ≈<?= (int)$r['cover_days'] ?>d cover</b>
    <button type="button" class="pd3-cp" onclick="openStock(<?= (int)$r['id'] ?>,'<?= e(addslashes($r['name'])) ?>',0,<?= (int)$r['suggest'] ?>)">🛒 Create Purchase (~<?= (int)$r['suggest'] ?> pcs)</button>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if($bestMarginPid || $worstReturnPid): ?>
<div class="pd3-flagrow">
  <?php if($bestMarginPid): $bp=array_values(array_filter($products,fn($x)=>(int)$x['id']===$bestMarginPid)); $bp=$bp[0]??null; if($bp): ?>
  <div class="pd3-flag best"><div class="l">🏆 Best Margin</div><div class="v"><?= e($bp['name']) ?> — <?= $bestMarginVal ?>%</div></div>
  <?php endif; endif; ?>
  <?php if($worstReturnPid && $worstReturnVal>0): $wp=array_values(array_filter($products,fn($x)=>(int)$x['id']===$worstReturnPid)); $wp=$wp[0]??null; if($wp): ?>
  <div class="pd3-flag worst"><div class="l">⚠️ Most Returned</div><div class="v"><?= e($wp['name']) ?> — <?= $worstReturnVal ?>% return rate</div></div>
  <?php endif; endif; ?>
</div>
<?php endif; ?>

<div class="pd3-tiles">
  <div class="pd3-tile b"><b><?= number_format($tProducts) ?></b><span>Products</span></div>
  <div class="pd3-tile g"><b><?= number_format($tOnHand) ?></b><span>On Hand</span></div>
  <div class="pd3-tile a"><b><?= number_format($tOut) ?></b><span>With Courier</span></div>
  <div class="pd3-tile p"><b><?= number_format($tHH) ?></b><span>At Hungry Hunter</span></div>
  <div class="pd3-tile b"><b><?= number_format($tDX) ?></b><span>At Dropex</span></div>
  <div class="pd3-tile b"><b><?= number_format($tRes) ?></b><span>Reserved</span></div>
  <div class="pd3-tile t"><b><?= money($tStockValue) ?></b><span>Stock Value</span></div>
  <div class="pd3-tile b"><b><?= number_format($tLifeQty) ?></b><span>Lifetime Purchased</span></div>
  <div class="pd3-tile t"><b><?= money($tLifeAmt) ?></b><span>Purchase Amount</span></div>
  <div class="pd3-tile a"><b><?= number_format($tSold) ?></b><span>Units Sold</span></div>
  <div class="pd3-tile a"><b><?= money($adsTotal) ?></b><span>Ads Spent</span></div>
  <div class="pd3-tile <?= $tProfit>=0?'g':'r' ?>"><b><?= money($tProfit) ?></b><span>Net Product Profit</span></div>
</div>

<?php if($adsUntagged>0): ?><div class="flash" style="background:var(--amber-bg,#fef3c7);color:var(--amber)">📣 <?= money($adsUntagged) ?> of ad spend isn't tagged to any product, so it is <b>not</b> deducted below. Tag it in <a href="expenses.php"><b>Expenses</b></a> to see true per-product profit.</div><?php endif; ?>
<?php if($adsStopCount>0): ?><div class="flash" style="background:var(--red-bg,#fee2e2);color:var(--red)">🔴 <b><?= $adsStopCount ?></b> product<?= $adsStopCount>1?'s are':' is' ?> losing money on ads.<?= $adsWatchCount>0?' Plus '.$adsWatchCount.' more to watch.':'' ?></div>
<?php elseif($adsWatchCount>0): ?><div class="flash" style="background:var(--amber-bg,#fef3c7);color:var(--amber)">🟡 <b><?= $adsWatchCount ?></b> product<?= $adsWatchCount>1?'s':'' ?> worth watching — weak ROAS or a higher return rate while running ads.</div>
<?php endif; ?>

<div class="pd3-toolbar">🔍 <input id="pd3Search" placeholder="Search products…"></div>
<div class="pd3-filterbar">
  <button type="button" class="pd3-fchip on" data-f="all">All (<?= count($products) ?>)</button>
  <button type="button" class="pd3-fchip" data-f="out">🔴 Out of Stock</button>
  <button type="button" class="pd3-fchip" data-f="low">🟡 Low Stock</button>
  <button type="button" class="pd3-fchip" data-f="profit">📈 Profitable</button>
  <button type="button" class="pd3-fchip" data-f="loss">📉 Losing Money</button>
</div>

<form method="post" id="pd3BulkForm">
<input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="bulk_category"><input type="hidden" name="ids" id="pd3BulkIds">
<div class="pd3-bulkbar" id="pd3BulkBar">
  <span><b id="pd3BulkCount">0</b> selected</span>
  <input type="text" name="category" placeholder="New category…" style="border:1px solid var(--border);border-radius:7px;padding:5px 9px;font-size:11px">
  <button type="submit">Apply Category to Selected</button>
  <button type="button" class="clear" onclick="pd3ClearBulk()">✕ Clear</button>
</div>
</form>

<div class="pd3-grid" id="pd3Grid">
<?php foreach($products as $p): $a=$agg[$p['id']]??['sold'=>0,'rev'=>0,'profit'=>0,'orders'=>0,'returned'=>0];
  $sp=$stockPos($p); $st=$sp['hand'];
  $isOut=$st<=0; $isLow=!$isOut && $st<=(int)$p['low_stock'];
  $spTotal=max(1,$sp['hand']+$sp['out']+$sp['hh']+$sp['dx']);
  $marginRs=(float)$p['price']-(float)$p['cost']-$overheadPerPc;
  $margin=$p['price']>0?round($marginRs/$p['price']*100):0;
  $adsP=$adsFor($p); $gross=$a['profit']; $prof=$gross-$adsP;
  $roas=$adsP>0 ? round($a['rev']/$adsP,2) : null;
  $adsVerdict=$adsVerdicts[$p['id']]['verdict'] ?? null;
  $spk = $spark[$p['id']] ?? [0,0,0,0,0,0];
  $spkMax = max(1,max($spk));
  $spkTrend = (array_sum(array_slice($spk,3))) <=> (array_sum(array_slice($spk,0,3)));
  $profitFilter = $a['sold']>0 ? ($prof>=0?'profit':'loss') : '';
  $stockFilter = $isOut?'out':($isLow?'low':'');
  $cardCls = ($isOut||($prof<0&&$a['sold']>0)) ? 'warn' : '';
  $initial = mb_strtoupper(mb_substr($p['name'],0,2));
  $hueSeed = crc32($p['name']) % 5;
  $hueBg = [['#93c5fd','#0369a1'],['#c7d2fe','#4338ca'],['#fde68a','#d97706'],['#a7f3d0','#0d9488'],['#fbcfe8','#be185d']][$hueSeed];
?>
<div class="pd3-card <?= $cardCls ?>" data-name="<?= e(mb_strtolower($p['name'].' '.$p['sku'])) ?>" data-stock="<?= $stockFilter ?>" data-profit="<?= $profitFilter ?>">
  <input type="checkbox" class="pd3-chk pd3-sel" value="<?= (int)$p['id'] ?>">
  <div class="pd3-imgband<?= $p['image']?' has-photo':'' ?>" style="<?= $p['image']?'':'background:linear-gradient(135deg,'.$hueBg[0].','.$hueBg[1].')' ?>">
    <?php if($p['image']): ?><img src="<?= e($p['image']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
    <?php else: ?><?= e($initial) ?><?php endif; ?>
    <?php if($bestMarginPid===(int)$p['id'] || ($worstReturnPid===(int)$p['id'] && $worstReturnVal>0)): ?>
    <div class="flags">
      <?php if($bestMarginPid===(int)$p['id']): ?><span class="pd3-fbadge" style="color:#16a34a">🏆 Best Margin</span><?php endif; ?>
      <?php if($worstReturnPid===(int)$p['id'] && $worstReturnVal>0): ?><span class="pd3-fbadge" style="color:#dc2626">⚠️ Most Returned</span><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="pd3-body">
    <div class="pd3-nm"><?= e($p['name']) ?></div>
    <div class="pd3-sku"><?= e($p['sku']) ?><?= $p['category']?' · '.e($p['category']):'' ?></div>

    <div class="pd3-stockbadge <?= $isOut?'out':($isLow?'low':'ok') ?>">
      <?= $isOut ? '🔴 Out of Stock' : ($isLow ? '🟡 Low Stock — '.number_format($st).' left' : '🟢 In Stock — '.number_format($st)) ?>
    </div>

    <div class="pd3-stockbar" title="green = shelf · amber = courier · purple = Hungry Hunter · blue = Dropex">
      <?php if($sp['hand']+$sp['out']+$sp['hh']+$sp['dx']<=0): ?><i style="width:100%;background:#fecaca"></i>
      <?php else: ?><i style="width:<?= round($sp['hand']/$spTotal*100,1) ?>%;background:#16a34a"></i><i style="width:<?= round($sp['out']/$spTotal*100,1) ?>%;background:#d97706"></i><i style="width:<?= round($sp['hh']/$spTotal*100,1) ?>%;background:#7c3aed"></i><i style="width:<?= round($sp['dx']/$spTotal*100,1) ?>%;background:#0369a1"></i><?php endif; ?>
    </div>
    <div class="pd3-legend2">
      <span><i style="background:#16a34a"></i><?= $st ?> shelf</span>
      <?php if($sp['out']): ?><span><i style="background:#d97706"></i><?= $sp['out'] ?> courier</span><?php endif; ?>
      <?php if($sp['hh']): ?><span><i style="background:#7c3aed"></i><?= $sp['hh'] ?> HH</span><?php endif; ?>
      <?php if($sp['dx']): ?><span><i style="background:#0369a1"></i><?= $sp['dx'] ?> Dropex</span><?php endif; ?>
      <?php if($sp['res']): ?><span>· <?= $sp['res'] ?> reserved</span><?php endif; ?>
    </div>

    <div class="pd3-statrow"><span>Price</span><b><?= money($p['price']) ?></b></div>
    <div class="pd3-statrow"><span title="Price − Cost − Rs <?= $overheadPerPc ?> overhead (Ads + Delivery + Office + Returns) — edit in Settings → Finance &amp; Tax">Margin / pc</span><b style="color:<?= $marginRs>=0?'var(--green)':'var(--red)' ?>"><?= money($marginRs) ?> <span style="font-weight:600;color:var(--muted);font-size:10.5px">(<?= $margin ?>%)</span></b></div>
    <div class="pd3-statrow"><span>Profit</span><b style="color:<?= $prof>=0?'var(--green)':'var(--red)' ?>"><?= money($prof) ?></b></div>
    <?php if(array_sum($spk)>0): ?>
    <div class="pd3-statrow"><span>Trend</span><div class="pd3-spark<?= $spkTrend<0?' down':'' ?>" title="units sold, last 6 weeks"><?php foreach($spk as $wv): ?><div style="height:<?= max(8,round($wv/$spkMax*100)) ?>%"></div><?php endforeach; ?></div></div>
    <?php endif; ?>
    <div class="pd3-chipsrow">
      <span class="pd3-chip"><?= (int)$a['sold'] ?> sold</span>
      <?php if($roas!==null): ?><span class="pd3-chip <?= $roas>=2?'ok':'out' ?>"><?= $roas ?>× ROAS</span><?php endif; ?>
      <?php if(isset($reoMap[$p['id']])): ?><span class="pd3-chip out">🛒 ~<?= $reoMap[$p['id']]['cover_days'] ?>d cover</span><?php endif; ?>
    </div>
    <?php if($adsVerdict==='stop'): ?><div class="pd3-verdict stop">🔴 Stop ads suggested</div>
    <?php elseif($adsVerdict==='watch'): ?><div class="pd3-verdict watch">🟡 Watch ad spend</div><?php endif; ?>
    <div class="pd3-foot">
      <a class="view" href="product_detail.php?id=<?= (int)$p['id'] ?>">📄 Details</a>
      <button type="button" onclick="openStock(<?= (int)$p['id'] ?>,'<?= e(addslashes($p['name'])) ?>',<?= (float)$p['cost'] ?>)">📦+</button>
      <button type="button" data-rec="<?= e(json_encode($p)) ?>" onclick="crudEdit('products',this)">✏️</button>
    </div>
  </div>
</div>
<?php endforeach; if(!$products): ?><div class="pd3-empty">No products yet — click "Add Product".</div><?php endif; ?>
</div>

<?php render_crud_modal('products'); ?>

<!-- add stock batch modal -->
<div class="modal-bg" id="stockModal" style="z-index:99990">
  <div class="modal" style="width:440px;max-width:94vw">
    <div class="modal-head"><span>📦 Add Stock Batch — <span id="sm_name"></span></span><span class="mx" onclick="closeStock()">✕</span></div>
    <form method="post" style="padding:18px 22px" class="fgrid">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="restock"><input type="hidden" name="id" id="sm_id">
      <div class="full"><label>Vendor *</label><select name="supplier_id" id="sm_supplier" required>
        <option value="">— select vendor —</option>
        <?php foreach($vendorList as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?>
      </select>
      <a href="vendor_purchases.php#suppliers" target="_blank" style="font-size:10.5px;color:#0369a1;font-weight:700">+ New vendor not in the list? Add one →</a></div>
      <div><label>Purchase Date</label><input type="date" name="pdate" value="<?= date('Y-m-d') ?>"></div>
      <div><label>Quantity (pcs) *</label><input type="number" name="qty" id="sm_qty" min="1" required placeholder="1000"></div>
      <div><label>Rate per pc (Rs.) *</label><input type="number" step="any" name="unit_cost" id="sm_cost" required placeholder="190"></div>
      <div><label>Note</label><input name="note" placeholder="lot / batch reference…"></div>
      <div class="full" style="display:flex;justify-content:flex-end;gap:10px;margin-top:4px">
        <button type="button" class="btn" onclick="closeStock()">Cancel</button>
        <button class="btn btn-primary">💾 Add Batch</button>
      </div>
    </form>
  </div>
</div>
<script>
function openStock(id,name,cost,suggestQty){
  document.getElementById('sm_id').value=id;
  document.getElementById('sm_name').textContent=name;
  document.getElementById('sm_cost').value=cost||'';
  document.getElementById('sm_qty').value=suggestQty||'';
  document.getElementById('stockModal').classList.add('open');
}
function closeStock(){document.getElementById('stockModal').classList.remove('open');}
document.getElementById('stockModal').addEventListener('click',function(e){if(e.target===this)closeStock();});

document.getElementById('pd3Search').addEventListener('input', pd3ApplyFilters);
var pd3ActiveFilter = 'all';
document.querySelectorAll('.pd3-fchip').forEach(function(chip){
  chip.addEventListener('click', function(){
    document.querySelectorAll('.pd3-fchip').forEach(function(c){ c.classList.toggle('on', c===chip); });
    pd3ActiveFilter = chip.getAttribute('data-f');
    pd3ApplyFilters();
  });
});
function pd3ApplyFilters(){
  var q = document.getElementById('pd3Search').value.toLowerCase().trim();
  document.querySelectorAll('.pd3-card').forEach(function(card){
    var okSearch = !q || (card.getAttribute('data-name')||'').indexOf(q)!==-1;
    var okFilter = true;
    if(pd3ActiveFilter==='out') okFilter = card.getAttribute('data-stock')==='out';
    else if(pd3ActiveFilter==='low') okFilter = card.getAttribute('data-stock')==='low';
    else if(pd3ActiveFilter==='profit') okFilter = card.getAttribute('data-profit')==='profit';
    else if(pd3ActiveFilter==='loss') okFilter = card.getAttribute('data-profit')==='loss';
    card.style.display = (okSearch && okFilter) ? '' : 'none';
  });
}

function pd3RefreshBulk(){
  var sel = document.querySelectorAll('.pd3-sel:checked');
  var bar = document.getElementById('pd3BulkBar');
  bar.classList.toggle('show', sel.length>0);
  document.getElementById('pd3BulkCount').textContent = sel.length;
  document.getElementById('pd3BulkIds').value = Array.prototype.map.call(sel,function(c){return c.value;}).join(',');
}
document.querySelectorAll('.pd3-sel').forEach(function(cb){ cb.addEventListener('change', pd3RefreshBulk); });
function pd3ClearBulk(){
  document.querySelectorAll('.pd3-sel').forEach(function(cb){ cb.checked=false; });
  pd3RefreshBulk();
}
document.getElementById('pd3BulkForm').addEventListener('submit', function(e){
  if(!document.getElementById('pd3BulkIds').value){ e.preventDefault(); }
});
</script>
<?php require __DIR__.'/includes/footer.php'; ?>