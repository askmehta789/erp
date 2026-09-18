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
foreach($orders as $o){
  $ordersN++;
  $q=(int)$o['qty'];
  if($o['status']==='delivered'){
    $sold+=$q; $rev+=(float)$o['sell_price']*$q;
    $gross += ((float)$o['sell_price']-(float)$o['cost_price'])*$q - (float)$o['delivery_charge'];
    $cname = $courierNames[(int)$o['courier_id']] ?? 'Self / Other';
    $deliveredByCourier[$cname] = ($deliveredByCourier[$cname] ?? 0) + $q;
  } elseif(in_array($o['status'],['returned','cancelled'],true)){
    $returnedN++; $gross -= (float)$o['cancel_charge'];
  }
}
$returnRate = $ordersN>0 ? round($returnedN/$ordersN*100,1) : 0;

/* ---- ads for this product ---- */
$adsP=0.0;
try { $adsP = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE category='Ads' AND LOWER(TRIM(product))=?",[mb_strtolower(trim($p['name']))]); } catch (Exception $e) {}
$prof = $gross - $adsP;
$roas = $adsP>0 ? round($rev/$adsP,2) : null;
$margin = $p['price']>0 ? round(($p['price']-$p['cost'])/$p['price']*100) : 0;

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
.pdd-back{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#0369a1;text-decoration:none;margin-bottom:12px}
.pdd-hero{background:#fff;border-radius:18px;padding:20px 24px;box-shadow:0 8px 24px rgba(30,41,80,.08);margin-bottom:14px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}
.pdd-av{width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,#93c5fd,#0369a1);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:20px;flex:none}
.pdd-nm{font-size:20px;font-weight:900}
.pdd-sub{font-size:12px;color:#8a93a8;margin-top:2px}
.pdd-badges{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}
.pdd-badge{font-size:9.5px;font-weight:800;border-radius:99px;padding:3px 10px}
.pdd-b-stop{background:#fee2e2;color:#c0392b}.pdd-b-watch{background:#fef3c7;color:#b45309}
.pdd-acts{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}

.pdd-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:14px}
.pdd-stat{background:#fff;border-radius:14px;padding:13px;box-shadow:0 6px 16px rgba(30,41,80,.07);border-top:3px solid #ddd}
.pdd-stat.b{border-top-color:#0369a1}.pdd-stat.g{border-top-color:#16a34a}.pdd-stat.r{border-top-color:#dc2626}.pdd-stat.p{border-top-color:#7c3aed}
.pdd-stat b{font-size:17px;font-weight:900;display:block}
.pdd-stat span{font-size:9px;color:#8a93a8;font-weight:700;text-transform:uppercase}

.pdd-card{background:#fff;border-radius:16px;box-shadow:0 6px 18px rgba(30,41,80,.07);padding:16px 18px;margin-bottom:14px}
.pdd-card h3{font-size:13px;font-weight:900;margin-bottom:10px}
.pdd-stockbar{height:8px;border-radius:99px;background:#f1f4f9;overflow:hidden;display:flex;max-width:340px;margin-bottom:6px}
.pdd-stockbar i{display:block}
.pdd-deliverline{background:#eefdf4;border:1px solid #86efac;border-radius:10px;padding:10px 14px;font-size:12.5px;margin-bottom:14px}
.pdd-spark{display:flex;align-items:flex-end;gap:3px;height:40px;max-width:260px}
.pdd-spark div{flex:1;background:#93c5fd;border-radius:2px;min-height:3px}
.pdd-hh-recon{background:#f8fafc;border-radius:10px;padding:10px 14px;font-size:12px;margin-bottom:10px}
.pdd-hh-recon b{color:#334}
.pdd-form-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:10px}
</style>

<a href="products.php" class="pdd-back">← Back to Products</a>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="pdd-hero">
  <div class="pdd-av"><?= e(mb_strtoupper(mb_substr($p['name'],0,2))) ?></div>
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
  <div class="pdd-stat b"><b><?= $margin ?>%</b><span>Margin</span></div>
  <div class="pdd-stat <?= $prof>=0?'g':'r' ?>"><b><?= money($prof) ?></b><span>Profit<?= $adsP>0?' (net of ads)':'' ?></span></div>
  <div class="pdd-stat b"><b><?= number_format($sold) ?></b><span>Units Sold</span></div>
  <div class="pdd-stat p"><b><?= money($batVal) ?></b><span>Stock Value</span></div>
  <?php if($roas!==null): ?><div class="pdd-stat <?= $roas>=2?'g':'r' ?>"><b><?= $roas ?>×</b><span>ROAS</span></div><?php endif; ?>
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
    <?php foreach($hhMoves as $mv): $ic=['in'=>'📦 Sent to HH','out'=>'✅ Delivered by HH','return'=>'↩ Returned to you','adjust'=>'± Adjustment'][$mv['type']]??$mv['type']; ?>
    <tr>
      <td><?= e(date('d M Y',strtotime($mv['created_at']))) ?></td><td><?= e($ic) ?></td>
      <td style="font-weight:800"><?= (int)$mv['qty'] ?></td><td><?= (float)$mv['unit_cost']>0?money($mv['unit_cost']):'—' ?></td>
      <td style="text-align:left" class="muted"><?= e($mv['note']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody></table></div>
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
    <?php foreach($dxMoves as $mv): $ic=['in'=>'📦 Sent to Dropex','out'=>'✅ Delivered by Dropex','return'=>'↩ Returned to you','adjust'=>'± Adjustment'][$mv['type']]??$mv['type']; ?>
    <tr>
      <td><?= e(date('d M Y',strtotime($mv['created_at']))) ?></td><td><?= e($ic) ?></td>
      <td style="font-weight:800"><?= (int)$mv['qty'] ?></td><td><?= (float)$mv['unit_cost']>0?money($mv['unit_cost']):'—' ?></td>
      <td style="text-align:left" class="muted"><?= e($mv['note']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody></table></div>
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
      <a href="purchases.php" target="_blank" style="font-size:10.5px;color:#0369a1;font-weight:700">+ New vendor not in the list? Add one →</a></div>
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
</script>
<?php require __DIR__.'/includes/footer.php'; ?>