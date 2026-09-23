<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
repair_zero_cost_profit();
ensure_stock_batches();
if (function_exists('ensure_hh_stock')) ensure_hh_stock();
if (function_exists('ensure_dropex_stock')) ensure_dropex_stock();
$PAGE_TITLE='Product Detail';

$pid = (int)($_GET['id'] ?? 0);
$p = row("SELECT * FROM products WHERE id=?",[$pid]);
if (!$p) { header('Location: products.php'); exit; }

/* ---- Live Delivery Status — today's deliveries, what's out for delivery
   right now, and (when nothing's moved today) when it was last delivered.
   Kept cheap and separate from the heavier stock/FIFO computation below so
   the polled ?ajax=delivery endpoint stays fast. ---- */
function pd_live_delivery_data($pid) {
  $today = date('Y-m-d');
  $todayDelivered = rows("SELECT o.customer, o.qty, c.name AS courier
                          FROM orders o LEFT JOIN couriers c ON c.id=o.courier_id
                          WHERE o.product_id=? AND o.status='delivered' AND o.order_date=?
                          ORDER BY o.id DESC", [$pid,$today]);
  $outForDelivery = rows("SELECT o.customer, o.qty, c.name AS courier
                          FROM orders o LEFT JOIN couriers c ON c.id=o.courier_id
                          WHERE o.product_id=? AND o.status='shipped'
                          ORDER BY o.id DESC", [$pid]);
  $lastDelivered = (string)val("SELECT order_date FROM orders WHERE product_id=? AND status='delivered' ORDER BY order_date DESC, id DESC LIMIT 1", [$pid]);
  $recent = rows("SELECT o.customer, o.qty, o.status, o.order_date, c.name AS courier
                  FROM orders o LEFT JOIN couriers c ON c.id=o.courier_id
                  WHERE o.product_id=? AND o.status IN ('delivered','shipped')
                  ORDER BY o.order_date DESC, o.id DESC LIMIT 6", [$pid]);
  return [
    'todayDeliveredQty' => array_sum(array_column($todayDelivered,'qty')),
    'todayDeliveredN'   => count($todayDelivered),
    'outQty'            => array_sum(array_column($outForDelivery,'qty')),
    'outN'              => count($outForDelivery),
    'lastDelivered'     => $lastDelivered ?: null,
    'recent'            => $recent,
  ];
}
if (($_GET['ajax'] ?? '') === 'delivery') {
  header('Content-Type: application/json');
  echo json_encode(pd_live_delivery_data($pid));
  exit;
}
$liveDelivery = pd_live_delivery_data($pid);

batches_migrate($pid);
$p = row("SELECT * FROM products WHERE id=?",[$pid]);   /* re-read after migration */
try { q("ALTER TABLE stock_batches ADD COLUMN IF NOT EXISTS supplier_id INT NULL"); } catch (Exception $e) {}
$vendorList = rows("SELECT id,name FROM suppliers ORDER BY name");

/* ---- batches for this product only ---- */
function is_vendor_purchase_batch_pd($note){
  $n = trim((string)$note);
  return $n !== 'Count adjustment +' && $n !== 'returned from Hungry Hunter';
}
$bat = rows("SELECT * FROM stock_batches WHERE product_id=? ORDER BY purchase_date, id",[$pid]);
$batVal=0; $lifeQty=0; $lifeAmt=0;
foreach($bat as $b){
  $batVal += (int)$b['qty_left']*(float)$b['unit_cost'];
  if (is_vendor_purchase_batch_pd($b['note'])) { $lifeQty += (int)$b['qty_in']; $lifeAmt += (int)$b['qty_in']*(float)$b['unit_cost']; }
}
$batUnits = array_sum(array_map(fn($b)=>(int)$b['qty_left'],$bat));
$batAvg = $batUnits>0 ? round($batVal/$batUnits,2) : null;

/* ---- stock position (same logic as products.php, scoped to one product) ---- */
$hhCid = function_exists('hh_courier_id') ? hh_courier_id() : 0;
$dxCid = function_exists('dropex_courier_id') ? dropex_courier_id() : 0;
$commit=['out'=>0,'res'=>0];
foreach(rows("SELECT status, COALESCE(courier_id,0) AS cid, SUM(qty) AS q FROM orders WHERE product_id=? AND status IN ('pending','processing','shipped') GROUP BY status, cid",[$pid]) as $r){
  if($hhCid && (int)$r['cid']===$hhCid) continue;
  if($dxCid && (int)$r['cid']===$dxCid) continue;
  if($r['status']==='shipped') $commit['out'] += (int)$r['q']; else $commit['res'] += (int)$r['q'];
}
$hp = function_exists('hh_position') ? hh_position($pid) : ['sent'=>0,'delivered'=>0,'returned'=>0,'adj'=>0,'left'=>0];
$dp = function_exists('dropex_position') ? dropex_position($pid) : ['sent'=>0,'delivered'=>0,'returned'=>0,'adj'=>0,'left'=>0];
$sys=(int)$p['stock']; $out=$commit['out']; $res=$commit['res']; $hh=(int)($hp['left']??0); $dx=(int)($dp['left']??0);
$onHand = max(0,$sys-$out); $st=$onHand; $free=max(0,$onHand-$res);
$spTotal = max(1,$onHand+$out+$hh+$dx);

/* ---- orders for this product: aggregate + delivered-by-channel ---- */
$orders = rows("SELECT qty, sell_price, cost_price, delivery_charge, cancel_charge, status, COALESCE(courier_id,0) AS courier_id FROM orders WHERE product_id=?",[$pid]);
$courierNames = []; foreach(rows("SELECT id,name FROM couriers") as $c) $courierNames[(int)$c['id']] = $c['name'];
$sold=0; $rev=0; $gross=0; $ordersN=0; $returnedN=0; $deliveredByCourier=[];
$totalDeliveryCharge=0; $totalCancelCharge=0;
foreach($orders as $o){
  $ordersN++;
  $q=(int)$o['qty'];
  if($o['status']==='delivered'){
    $sold+=$q; $rev+=(float)$o['sell_price']*$q;
    $gross += ((float)$o['sell_price']-(float)$o['cost_price'])*$q - (float)$o['delivery_charge'];
    $totalDeliveryCharge += (float)$o['delivery_charge'];
    $cname = $courierNames[(int)$o['courier_id']] ?? 'Self / Other';
    $deliveredByCourier[$cname] = ($deliveredByCourier[$cname] ?? 0) + $q;
  } elseif(in_array($o['status'],['returned','cancelled'],true)){
    $returnedN++; $gross -= (float)$o['cancel_charge']; $totalCancelCharge += (float)$o['cancel_charge'];
  }
}
$returnRate = $ordersN>0 ? round($returnedN/$ordersN*100,1) : 0;

/* ---- per-unit economics (based on units actually SOLD/delivered) ---- */
$marginPerPc  = (float)$p['price'] - (float)$p['cost'];
$deliveryPerPc = $sold>0 ? $totalDeliveryCharge/$sold : 0;

/* ---- ads for this product ---- */
$adsP=0.0;
try { $adsP = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE category='Ads' AND LOWER(TRIM(product))=?",[mb_strtolower(trim($p['name']))]); } catch (Exception $e) {}
$prof = $gross - $adsP;
$roas = $adsP>0 ? round($rev/$adsP,2) : null;
$margin = $p['price']>0 ? round(($p['price']-$p['cost'])/$p['price']*100) : 0;

/* per-unit economics, finished now that ad spend is known */
$adsPerPc = $sold>0 ? $adsP/$sold : 0;
$netPerPc = $marginPerPc - $deliveryPerPc - $adsPerPc;

$adsMinSpend  = (float)setting('ads_min_spend',500);
$adsWatchRoas = (float)setting('ads_watch_roas',2);
$adsWatchRet  = (float)setting('ads_watch_return_rate',15);
$adsVerdict=null;
if ($adsP >= $adsMinSpend) {
  if ($prof<0) $adsVerdict='stop';
  elseif (($roas!==null && $roas<$adsWatchRoas) || $returnRate>=$adsWatchRet) $adsVerdict='watch';
}

/* ---- HH movement history for this product ---- */
$hhMoves = rows("SELECT * FROM hh_stock WHERE product_id=? ORDER BY created_at, id",[$pid]);
$dxMoves = rows("SELECT * FROM dropex_stock WHERE product_id=? ORDER BY created_at, id",[$pid]);

/* ---- sales trend, last 6 weeks ---- */
$wkAgo = date('Y-m-d', strtotime('-42 days'));
$spk=[0,0,0,0,0,0];
foreach(rows("SELECT DATE(order_date) od, qty FROM orders WHERE product_id=? AND status='delivered' AND order_date>=?",[$pid,$wkAgo]) as $r){
  $wk=(int)floor((strtotime(date('Y-m-d'))-strtotime($r['od']))/(7*86400));
  if($wk<0||$wk>5) continue;
  $spk[5-$wk] += (int)$r['qty'];
}
$spkMax = max(1,max($spk));

require __DIR__.'/includes/header.php';
?>
<style>
.pdd-back{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:#0369a1;text-decoration:none;margin-bottom:12px}
.pdd-hero{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:20px 24px;box-shadow:0 8px 24px rgba(30,41,80,.07);margin-bottom:16px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}
.pdd-av{width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,#93c5fd,#0369a1);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:22px;flex:none;overflow:hidden}
.pdd-av img{width:100%;height:100%;object-fit:contain;background:var(--surface-2);padding:6px;box-sizing:border-box}
.pdd-nm{font-size:21px;font-weight:900;color:var(--ink)}
.pdd-sub{font-size:12.5px;color:var(--muted);margin-top:2px}
.pdd-badges{display:flex;gap:6px;margin-top:7px;flex-wrap:wrap}
.pdd-badge{font-size:10px;font-weight:800;border-radius:99px;padding:4px 11px}
.pdd-b-stop{background:var(--red-bg,#fee2e2);color:#c0392b}.pdd-b-watch{background:var(--amber-bg,#fef3c7);color:#b45309}
.pdd-acts{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}

.pdd-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:11px;margin-bottom:16px}
.pdd-stat{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:14px;box-shadow:0 6px 16px rgba(30,41,80,.06);border-top:3px solid #ddd}
.pdd-stat.b{border-top-color:#0369a1}.pdd-stat.g{border-top-color:#16a34a}.pdd-stat.r{border-top-color:#dc2626}.pdd-stat.p{border-top-color:#7c3aed}
.pdd-stat b{font-size:18px;font-weight:900;display:block;color:var(--ink);font-variant-numeric:tabular-nums}
.pdd-stat span{font-size:9.5px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.02em}

.pdd-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:0 6px 18px rgba(30,41,80,.06);padding:17px 19px;margin-bottom:15px}
.pdd-card h3{font-size:13.5px;font-weight:900;margin-bottom:11px;color:var(--ink);display:flex;align-items:center;gap:8px}
.pdd-stockbar{height:9px;border-radius:99px;background:var(--surface-2);overflow:hidden;display:flex;max-width:360px;margin-bottom:8px}
.pdd-stockbar i{display:block}
.pdd-deliverline{background:var(--green-bg,#eefdf4);border:1px solid #86efac;border-radius:10px;padding:11px 15px;font-size:13px;margin-bottom:14px;color:var(--ink)}
.pdd-spark{display:flex;align-items:flex-end;gap:3px;height:42px;max-width:260px}
.pdd-spark div{flex:1;background:#93c5fd;border-radius:2px;min-height:3px}
.pdd-hh-recon{background:var(--surface-2);border-radius:10px;padding:11px 15px;font-size:12.5px;margin-bottom:11px;color:var(--ink)}
.pdd-hh-recon b{color:var(--ink)}
.pdd-showmore{display:block;width:100%;margin-top:9px;padding:9px;border:1px dashed var(--border);border-radius:10px;background:var(--surface-2);color:var(--muted);font-size:12px;font-weight:700;cursor:pointer;text-align:center}
.pdd-showmore:hover{background:var(--surface);color:var(--ink);border-color:var(--brand)}
.pdd-form-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:10px}

.pdd-econ-grid{display:flex;flex-direction:column}
.pdd-econ-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;font-size:13px;border-bottom:1px solid var(--border)}
.pdd-econ-row:last-child{border-bottom:0}
.pdd-econ-row span{color:var(--muted);font-weight:600}
.pdd-econ-row b{font-weight:800;color:var(--ink);font-variant-numeric:tabular-nums}
.pdd-econ-row.total{background:var(--surface-2);margin:3px 0;padding:10px 10px;border-radius:9px;border-bottom:none}

.pdd-live-dot{display:inline-block;width:8px;height:8px;border-radius:99px;background:#16a34a;margin-left:2px}
.pdd-live-dot.pulse{animation:pddpulse 1s ease}
@keyframes pddpulse{0%{transform:scale(1.8);opacity:.4}100%{transform:scale(1);opacity:1}}
.pdd-live-badge{display:flex;align-items:center;gap:8px;border-radius:10px;padding:10px 14px;font-size:13px;font-weight:700;margin-bottom:8px}
.pdd-live-badge.ok{background:var(--green-bg,#dcfce7);color:#15803d}
.pdd-live-badge.out{background:var(--blue-bg,#dbeafe);color:#0369a1}
.pdd-live-badge.idle{background:var(--surface-2);color:var(--muted);font-weight:600}
.pdd-live-list{margin-top:10px;border-top:1px solid var(--border);padding-top:10px}
.pdd-live-row{display:flex;justify-content:space-between;gap:10px;font-size:12px;padding:5px 0;color:var(--ink)}
.pdd-live-empty{font-size:12px;color:var(--muted)}
</style>

<a href="products.php" class="pdd-back">← Back to Products</a>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="pdd-hero">
  <div class="pdd-av"><?php if($p['image']): ?><img src="<?= e($p['image']) ?>" alt="<?= e($p['name']) ?>"><?php else: ?><?= e(mb_strtoupper(mb_substr($p['name'],0,2))) ?><?php endif; ?></div>
  <div>
    <div class="pdd-nm"><?= e($p['name']) ?></div>
    <div class="pdd-sub"><?= e($p['sku']) ?><?= $p['category']?' · '.e($p['category']):'' ?></div>
    <?php if($adsVerdict==='stop'): ?><div class="pdd-badges"><span class="pdd-badge pdd-b-stop">🔴 Stop ads suggested</span></div>
    <?php elseif($adsVerdict==='watch'): ?><div class="pdd-badges"><span class="pdd-badge pdd-b-watch">🟡 Watch ad spend</span></div><?php endif; ?>
  </div>
  <div class="pdd-acts">
    <button class="btn btn-sm" onclick="openStock(<?= (int)$p['id'] ?>,'<?= e(addslashes($p['name'])) ?>',<?= (float)$p['cost'] ?>)">📦+ Add Stock</button>
    <button class="btn btn-sm" data-rec="<?= e(json_encode($p)) ?>" onclick="crudEdit('products',this)">✏️ Edit</button>
    <form method="post" action="products.php" style="display:inline" onsubmit="return confirm('Delete this product?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm" style="color:var(--red)">🗑 Delete</button></form>
  </div>
</div>

<div class="pdd-stats">
  <div class="pdd-stat b"><b><?= money($p['price']) ?></b><span>Price</span></div>
  <div class="pdd-stat b"><b><?= money($marginPerPc) ?></b><span>Margin / pc (<?= $margin ?>%)</span></div>
  <div class="pdd-stat <?= $prof>=0?'g':'r' ?>"><b><?= money($prof) ?></b><span>Profit<?= $adsP>0?' (net of ads)':'' ?></span></div>
  <div class="pdd-stat b"><b><?= number_format($sold) ?></b><span>Units Sold</span></div>
  <div class="pdd-stat p"><b><?= money($batVal) ?></b><span>Stock Value</span></div>
  <?php if($roas!==null): ?><div class="pdd-stat <?= $roas>=2?'g':'r' ?>"><b><?= $roas ?>×</b><span>ROAS</span></div><?php endif; ?>
</div>

<div class="pdd-card">
  <h3>🚚 Live Delivery Status <span id="liveDot" class="pdd-live-dot"></span></h3>
  <div id="liveDeliveryBody"></div>
</div>

<div class="pdd-card">
  <h3>📦 Stock Position</h3>
  <div class="pdd-stockbar">
    <?php if($onHand+$out+$hh+$dx<=0): ?><i style="width:100%;background:#fecaca"></i>
    <?php else: ?><i style="width:<?= round($onHand/$spTotal*100,1) ?>%;background:#16a34a"></i><i style="width:<?= round($out/$spTotal*100,1) ?>%;background:#d97706"></i><i style="width:<?= round($hh/$spTotal*100,1) ?>%;background:#7c3aed"></i><i style="width:<?= round($dx/$spTotal*100,1) ?>%;background:#0369a1"></i><?php endif; ?>
  </div>
  <div class="muted" style="font-size:12px">
    <b style="color:var(--ink)"><?= $st ?></b> on shelf ·
    <b style="color:var(--ink)"><?= $out ?></b> with courier ·
    <b style="color:var(--ink)"><?= $hh ?></b> at Hungry Hunter ·
    <b style="color:var(--ink)"><?= $dx ?></b> at Dropex ·
    <b style="color:var(--ink)"><?= $res ?></b> reserved
    <?= ($res && $free!==$st) ? ' · <b style="color:var(--ink)">'.$free.'</b> free to sell' : '' ?>
  </div>
  <?php if(array_sum($spk)>0): ?>
  <h3 style="margin-top:16px">📈 Sales Trend — last 6 weeks</h3>
  <div class="pdd-spark"><?php foreach($spk as $wv): ?><div style="height:<?= max(6,round($wv/$spkMax*100)) ?>%" title="<?= $wv ?> sold"></div><?php endforeach; ?></div>
  <?php endif; ?>
</div>

<div class="pdd-card">
  <h3>✅ Delivered — All Channels</h3>
  <div class="pdd-deliverline">
    <b><?= number_format($sold) ?> delivered in total</b>
    <?php if($deliveredByCourier): $parts=[]; foreach($deliveredByCourier as $cn=>$cq) $parts[]="{$cq} via ".e($cn); echo ' — '.implode(' · ',$parts); endif; ?>
  </div>
  <div class="muted" style="font-size:12px"><?= $returnedN ?> returned/cancelled of <?= $ordersN ?> total orders (<?= $returnRate ?>% rate)<?= $adsP>0?' · Rs. '.number_format($adsP).' spent on ads for this product':'' ?></div>
</div>

<div class="pdd-card">
  <h3>💹 Per-Unit Economics <span class="muted" style="font-size:11px;font-weight:600">— per piece actually delivered</span></h3>
  <div class="pdd-econ-grid">
    <div class="pdd-econ-row"><span>Sell Price</span><b><?= money($p['price']) ?></b></div>
    <div class="pdd-econ-row"><span>Cost Price</span><b>− <?= money($p['cost']) ?></b></div>
    <div class="pdd-econ-row total"><span>Gross Margin / pc</span><b style="color:<?= $marginPerPc>=0?'var(--green)':'var(--red)' ?>"><?= money($marginPerPc) ?> (<?= $margin ?>%)</b></div>
    <div class="pdd-econ-row"><span>Delivery Cost / pc</span><b>− <?= money($deliveryPerPc) ?></b></div>
    <div class="pdd-econ-row"><span>Ads Spend / pc</span><b>− <?= money($adsPerPc) ?></b></div>
    <div class="pdd-econ-row total"><span>Net Profit / pc</span><b style="color:<?= $netPerPc>=0?'var(--green)':'var(--red)' ?>"><?= money($netPerPc) ?></b></div>
  </div>
  <?php if($sold>0): ?>
  <div class="muted" style="font-size:11.5px;margin-top:10px">Based on <b style="color:var(--ink)"><?= number_format($sold) ?></b> delivered pcs · Rs. <?= number_format($totalDeliveryCharge) ?> total delivery charges · Rs. <?= number_format($adsP) ?> total ad spend<?= $totalCancelCharge>0?' · Rs. '.number_format($totalCancelCharge).' lost to returns/cancellations (kept separate — those units were never sold, so spreading them per piece would understate this product\'s real margin)':'' ?>.</div>
  <?php else: ?>
  <div class="muted" style="font-size:11.5px;margin-top:10px">No delivered units yet — delivery and ads cost per piece will appear once this product has at least one delivered order.</div>
  <?php endif; ?>
</div>

<div class="pdd-card">
  <h3>🧾 FIFO Batches — oldest sells first</h3>
  <?php if($bat): ?>
  <div class="muted" style="font-size:11.5px;margin-bottom:8px">Lifetime: <b style="color:var(--ink)"><?= number_format($lifeQty) ?> pcs</b> purchased across <?= count($bat) ?> batch<?= count($bat)===1?'':'es' ?> for <b style="color:var(--ink)"><?= money($lifeAmt) ?></b> total<?= $batAvg?' · avg cost of remaining stock: <b style="color:var(--ink)">'.money($batAvg).'</b>/pc':'' ?></div>
  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>Date</th><th>Bought</th><th>Left</th><th>Rate</th><th>Value Left</th><th style="text-align:left">Note</th></tr></thead><tbody>
    <?php foreach($bat as $b): $done=(int)$b['qty_left']<=0; ?>
    <tr style="<?= $done?'opacity:.45':'' ?>">
      <td><?= e($b['purchase_date']) ?></td><td><?= (int)$b['qty_in'] ?></td>
      <td style="font-weight:800"><?= (int)$b['qty_left'] ?><?= $done?' ✓':'' ?></td>
      <td><?= money($b['unit_cost']) ?></td><td><?= money((int)$b['qty_left']*(float)$b['unit_cost']) ?></td>
      <td style="text-align:left" class="muted"><?= e($b['note']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><span class="muted" style="font-size:12px">No batches yet — click "📦+ Add Stock" above.</span><?php endif; ?>

  <form method="post" action="products.php" class="pdd-form-row">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="setstock"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <input type="hidden" name="return_to" value="product_detail.php?id=<?= (int)$p['id'] ?>">
    <div><label style="font-size:11px;color:var(--muted);font-weight:700">Physical recount — set exact</label><br><input type="number" name="qty" min="0" value="<?= $st ?>" style="width:110px"></div>
    <button class="btn btn-sm">Set</button>
  </form>
</div>

<?php if($hhMoves): ?>
<div class="pdd-card">
  <h3>🐝 Hungry Hunter — movement history</h3>
  <div class="pdd-hh-recon">
    <b><?= number_format((int)$hp['sent']) ?></b> sent
    <span class="muted">−</span> <b><?= number_format((int)$hp['delivered']) ?></b> delivered by HH
    <span class="muted">−</span> <b><?= number_format((int)$hp['returned']) ?></b> returned
    <?= (int)$hp['adj']!==0 ? '<span class="muted">'.((int)$hp['adj']>0?'+':'').'</span> <b>'.number_format((int)$hp['adj']).'</b> adj' : '' ?>
    <span class="muted">=</span> <b style="color:#7c5cf7"><?= number_format((int)$hp['left']) ?></b> at Hungry Hunter now
  </div>
  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>Date</th><th>Movement</th><th>Qty</th><th>Rate</th><th style="text-align:left">Note</th></tr></thead><tbody>
    <?php $hhRows=array_reverse($hhMoves); foreach($hhRows as $i=>$mv): $ic=['in'=>'📦 Sent to HH','out'=>'✅ Delivered by HH','return'=>'↩ Returned to you','adjust'=>'± Adjustment'][$mv['type']]??$mv['type']; ?>
    <tr<?= $i>=10?' class="pdd-more-row" style="display:none"':'' ?>>
      <td><?= e(date('d M Y',strtotime($mv['created_at']))) ?></td><td><?= e($ic) ?></td>
      <td style="font-weight:800"><?= (int)$mv['qty'] ?></td><td><?= (float)$mv['unit_cost']>0?money($mv['unit_cost']):'—' ?></td>
      <td style="text-align:left" class="muted"><?= e($mv['note']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php if(count($hhRows)>10): ?>
  <button type="button" class="pdd-showmore" onclick="pddShowMore(this)">▼ Show <?= count($hhRows)-10 ?> more</button>
  <?php endif; ?>
  <div class="muted" style="font-size:11px;margin-top:8px">✓ Those <?= (int)$hp['returned'] ?> returned pieces are already counted in the <b><?= $st ?> on-shelf</b> figure above — not counted twice.</div>
</div>
<?php endif; ?>

<?php if($dxMoves): ?>
<div class="pdd-card">
  <h3>🚴 Dropex — movement history</h3>
  <div class="pdd-hh-recon">
    <b><?= number_format((int)$dp['sent']) ?></b> sent
    <span class="muted">−</span> <b><?= number_format((int)$dp['delivered']) ?></b> delivered by Dropex
    <span class="muted">−</span> <b><?= number_format((int)$dp['returned']) ?></b> returned
    <?= (int)$dp['adj']!==0 ? '<span class="muted">'.((int)$dp['adj']>0?'+':'').'</span> <b>'.number_format((int)$dp['adj']).'</b> adj' : '' ?>
    <span class="muted">=</span> <b style="color:#0369a1"><?= number_format((int)$dp['left']) ?></b> at Dropex now
  </div>
  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>Date</th><th>Movement</th><th>Qty</th><th>Rate</th><th style="text-align:left">Note</th></tr></thead><tbody>
    <?php $dxRows=array_reverse($dxMoves); foreach($dxRows as $i=>$mv): $ic=['in'=>'📦 Sent to Dropex','out'=>'✅ Delivered by Dropex','return'=>'↩ Returned to you','adjust'=>'± Adjustment'][$mv['type']]??$mv['type']; ?>
    <tr<?= $i>=10?' class="pdd-more-row" style="display:none"':'' ?>>
      <td><?= e(date('d M Y',strtotime($mv['created_at']))) ?></td><td><?= e($ic) ?></td>
      <td style="font-weight:800"><?= (int)$mv['qty'] ?></td><td><?= (float)$mv['unit_cost']>0?money($mv['unit_cost']):'—' ?></td>
      <td style="text-align:left" class="muted"><?= e($mv['note']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php if(count($dxRows)>10): ?>
  <button type="button" class="pdd-showmore" onclick="pddShowMore(this)">▼ Show <?= count($dxRows)-10 ?> more</button>
  <?php endif; ?>
  <div class="muted" style="font-size:11px;margin-top:8px">✓ Those <?= (int)$dp['returned'] ?> returned pieces are already counted in the <b><?= $st ?> on-shelf</b> figure above — not counted twice.</div>
</div>
<?php endif; ?>

<?php render_crud_modal('products'); ?>

<!-- add stock batch modal (POSTs to products.php, returns here) -->
<div class="modal-bg" id="stockModal" style="z-index:99990">
  <div class="modal" style="width:440px;max-width:94vw">
    <div class="modal-head"><span>📦 Add Stock Batch — <span id="sm_name"></span></span><span class="mx" onclick="closeStock()">✕</span></div>
    <form method="post" action="products.php" style="padding:18px 22px" class="fgrid">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="restock"><input type="hidden" name="id" id="sm_id">
      <input type="hidden" name="return_to" value="product_detail.php?id=<?= (int)$p['id'] ?>">
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

function pddShowMore(btn){
  var tbl=btn.previousElementSibling;   // .table-wrap right before the button
  tbl.querySelectorAll('.pdd-more-row').forEach(function(tr){tr.style.display='';});
  btn.remove();
}

/* ---- Live Delivery Status: render + poll ---- */
var LIVE_PID = <?= (int)$pid ?>;
var INITIAL_LIVE = <?= json_encode($liveDelivery) ?>;
function pddEsc(s){var d=document.createElement('div');d.textContent=String(s==null?'':s);return d.innerHTML;}
function renderLiveDelivery(d){
  var html='';
  if(d.todayDeliveredQty>0){
    html+='<div class="pdd-live-badge ok">✅ '+d.todayDeliveredQty+' unit'+(d.todayDeliveredQty===1?'':'s')+' delivered today ('+d.todayDeliveredN+' order'+(d.todayDeliveredN===1?'':'s')+')</div>';
  }
  if(d.outQty>0){
    html+='<div class="pdd-live-badge out">🚴 '+d.outQty+' unit'+(d.outQty===1?'':'s')+' out for delivery right now ('+d.outN+' order'+(d.outN===1?'':'s')+')</div>';
  }
  if(!d.todayDeliveredQty && !d.outQty){
    html+='<div class="pdd-live-badge idle">'+(d.lastDelivered?('⏸ Nothing delivered today — last delivered on '+pddEsc(d.lastDelivered)):'— No deliveries recorded yet')+'</div>';
  }
  if(d.recent && d.recent.length){
    html+='<div class="pdd-live-list">';
    d.recent.forEach(function(r){
      var icon = r.status==='delivered' ? '✅' : '🚴';
      html+='<div class="pdd-live-row"><span>'+icon+' '+pddEsc(r.customer||'—')+' × '+r.qty+'</span><span class="muted">'+pddEsc(r.courier||'Self / Other')+' · '+pddEsc(r.order_date)+'</span></div>';
    });
    html+='</div>';
  } else if(!d.todayDeliveredQty && !d.outQty && !d.lastDelivered){
    html+='<div class="pdd-live-empty">No delivered or in-transit orders for this product yet.</div>';
  }
  document.getElementById('liveDeliveryBody').innerHTML = html;
}
renderLiveDelivery(INITIAL_LIVE);
function pddPollLive(){
  fetch('product_detail.php?id='+LIVE_PID+'&ajax=delivery',{credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){
      renderLiveDelivery(d);
      var dot=document.getElementById('liveDot');
      if(dot){ dot.classList.remove('pulse'); void dot.offsetWidth; dot.classList.add('pulse'); }
    }).catch(function(){});
}
setInterval(pddPollLive, 25000);
</script>
<?php require __DIR__.'/includes/footer.php'; ?>