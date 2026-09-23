<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
repair_zero_cost_profit();   /* auto-fix orders saved with Rs.0 cost so profit is honest everywhere */
$PAGE_TITLE='Hungry Hunter';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;
ensure_hh_stock();

/* courier must exist */
$hhc = row("SELECT * FROM couriers WHERE LOWER(name) LIKE '%hungry%' LIMIT 1");
if (!$hhc) { try { q("INSERT INTO couriers(name,status) VALUES('Hungry Hunter','active')"); } catch (Exception $e) { try { q("INSERT INTO couriers(name) VALUES('Hungry Hunter')"); } catch (Exception $e2) {} }
  $hhc = row("SELECT * FROM couriers WHERE LOWER(name) LIKE '%hungry%' LIMIT 1"); }
$hhId = (int)($hhc['id'] ?? 0);

$products = rows("SELECT id,name,stock FROM products ORDER BY name");

/* ---- actions ---- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act = $_POST['_action'] ?? '';

  if ($act==='repair_cost') {                    /* fix ANY delivered order that saved cost_price 0 (all channels) */
    $hh = hh_courier_id(); $fixed=0; $noCost=[];
    $bad = rows("SELECT id,product_id,qty,courier_id FROM orders WHERE status='delivered' AND (cost_price IS NULL OR cost_price<=0)");
    foreach ($bad as $o) {
      $pid=(int)$o['product_id']; if(!$pid) continue;
      $unit=0.0;
      if ($hh && (int)$o['courier_id']===$hh) {           /* HH: HH avg cost first */
        $pos=hh_position($pid); $unit=(float)$pos['avg_cost'];
      }
      if ($unit<=0) $unit=(float)fifo_current_cost($pid);   /* then oldest open FIFO batch */
      if ($unit<=0) $unit=(float)val("SELECT cost FROM products WHERE id=?",[$pid]);  /* then product base cost */
      if ($unit>0) { q("UPDATE orders SET cost_price=? WHERE id=?",[$unit,$o['id']]); $fixed++; }
      else { $nm=val("SELECT name FROM products WHERE id=?",[$pid]); if($nm) $noCost[$nm]=1; }
    }
    $msg = $fixed>0 ? "✅ Fixed cost on {$fixed} delivered order(s). Profit is now correct." : "No orders needed fixing — all delivered costs look good.";
    if ($noCost) $msg .= " ⚠️ Couldn't fix ".count($noCost)." product(s) with no cost set: ".implode(', ',array_keys($noCost)).". Set their cost on the Products page, then run this again.";
    flash($msg);
    log_activity("Repaired cost on {$fixed} orders",'HungryHunter');
    header('Location: hungryhunter.php'); exit;
  }

  if ($act==='transfer') {                       /* send stock TO Hungry Hunter */
    $pid=(int)($_POST['product_id'] ?? 0); $qty=max(1,(int)($_POST['qty'] ?? 0));
    $p = row("SELECT * FROM products WHERE id=?",[$pid]);
    if (!$p) flash('Pick a product.');
    elseif ((int)$p['stock'] < $qty) flash('Not enough main stock — only '.(int)$p['stock'].' pcs of '.$p['name'].' in store.');
    else {
      $unit = fifo_consume(0,$pid,$qty,'hh');    /* main stock down, FIFO cost captured */
      q("INSERT INTO hh_stock(product_id,type,qty,unit_cost,note) VALUES(?,?,?,?,?)",
        [$pid,'in',$qty,(float)$unit,trim($_POST['note'] ?? '')]);
      log_activity("Sent {$qty} pcs of {$p['name']} to Hungry Hunter @ ".money($unit),'HungryHunter');
      flash("Sent $qty pcs of {$p['name']} to Hungry Hunter · FIFO cost ".money($unit)."/pc");
    }
    header('Location: hungryhunter.php'); exit;
  }

  if ($act==='return') {                          /* HH sends stock back to store */
    $pid=(int)($_POST['product_id'] ?? 0); $qty=max(1,(int)($_POST['qty'] ?? 0));
    $pos=hh_position($pid);
    if ($qty > (int)$pos['left']) flash('Hungry Hunter only holds '.(int)$pos['left'].' pcs of this product.');
    else {
      q("INSERT INTO hh_stock(product_id,type,qty,unit_cost,note) VALUES(?,?,?,?,?)",
        [$pid,'return',$qty,(float)$pos['avg_cost'],trim($_POST['note'] ?? '')]);
      /* back into main stock as a batch at the same cost */
      q("INSERT INTO stock_batches(product_id,purchase_date,qty_in,qty_left,unit_cost,note) VALUES(?,?,?,?,?,?)",
        [$pid,date('Y-m-d'),$qty,$qty,(float)$pos['avg_cost'],'returned from Hungry Hunter']);
      stock_cache($pid);
      log_activity("Hungry Hunter returned {$qty} pcs (product #$pid)",'HungryHunter');
      flash("Returned $qty pcs back to main stock.");
    }
    header('Location: hungryhunter.php'); exit;
  }

  if ($act==='adjust' && $isAdmin) {              /* correction ± */
    $pid=(int)($_POST['product_id'] ?? 0); $qty=(int)($_POST['qty'] ?? 0);
    if ($pid && $qty!==0) {
      q("INSERT INTO hh_stock(product_id,type,qty,unit_cost,note) VALUES(?,?,?,?,?)",
        [$pid,'adjust',$qty,0,trim($_POST['note'] ?? '') ?: 'manual adjustment']);
      flash('Adjustment recorded ('.($qty>0?'+':'').$qty.' pcs).');
    }
    header('Location: hungryhunter.php'); exit;
  }

  if ($act==='reconcile') {                        /* fix a product whose ledger drifted from real order quantities */
    $pid=(int)($_POST['product_id'] ?? 0);
    $ok = hh_reconcile_fix($pid);
    $nm = (string)val("SELECT name FROM products WHERE id=?",[$pid]);
    flash($ok ? "✅ Reconciled {$nm} — the ledger now matches real order quantities." : "Nothing to reconcile for {$nm}.");
    log_activity("Reconciled HH stock for product #$pid",'HungryHunter');
    header('Location: hungryhunter.php'); exit;
  }

  if ($act==='reconcile_all') {
    $n = hh_reconcile_fix_all();
    flash($n>0 ? "✅ Reconciled {$n} product(s) — the ledger now matches real order quantities across the board." : "Nothing needed reconciling.");
    log_activity("Reconciled $n HH product(s) in bulk",'HungryHunter');
    header('Location: hungryhunter.php'); exit;
  }
}

/* ---- month filter for orders ---- */
$m = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$mStart=$m.'-01'; $mEnd=date('Y-m-t',strtotime($mStart));
$orders = rows("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id
                WHERE o.courier_id=? AND o.order_date BETWEEN ? AND ?
                ORDER BY o.order_date DESC, o.id DESC LIMIT 400",[$hhId,$mStart,$mEnd]);

$mOrders=count($orders); $mDel=0; $mUnits=0; $mRev=0; $mProf=0; $mRet=0;
foreach($orders as $o){
  if($o['status']==='delivered'){ $mDel++; $mUnits+=(int)$o['qty']; $mRev+=(float)$o['sell_price']*(int)$o['qty']; $mProf+=order_profit($o); }
  elseif(in_array($o['status'],['returned','cancelled'],true)){ $mRet++; $mProf+=order_profit($o); }
}

/* ---- COD money (all-time, from the shared engine) ---- */
$codRow=['collected'=>0,'charges'=>0,'net'=>0,'released'=>0,'held'=>0];
foreach (cod_holdings() as $h) if ((int)$h['cid']===$hhId) { $codRow=$h; break; }

/* ---- stock at HH ---- */
$pos = hh_position();
$hhValue=0; $hhPcs=0;
foreach($pos as $pd){ $hhValue += max(0,(int)$pd['left'])*(float)$pd['avg_cost']; $hhPcs += max(0,(int)$pd['left']); }
$movements = rows("SELECT h.*, p.name AS product_name FROM hh_stock h LEFT JOIN products p ON p.id=h.product_id ORDER BY h.id DESC LIMIT 100");
$prodName=[]; foreach($products as $p) $prodName[(int)$p['id']]=$p['name'];

/* days since this product's most recent transfer INTO HH — a simple, honest aging signal */
$lastIn=[];
foreach (rows("SELECT product_id, MAX(created_at) la FROM hh_stock WHERE type='in' GROUP BY product_id") as $r) {
  $lastIn[(int)$r['product_id']] = $r['la'];
}

/* the reconciliation audit that would have caught the quantity-mismatch bug automatically */
$mismatches = hh_reconcile_check();

/* ---- customers via Hungry Hunter, scoped to the same month as the orders table above ---- */
$custRows = rows("SELECT customer, phone, status, sell_price, qty, order_date FROM orders WHERE courier_id=? AND order_date BETWEEN ? AND ?",[$hhId,$mStart,$mEnd]);
$custAgg = [];
foreach ($custRows as $c) {
  $key = trim((string)$c['phone']) ?: ('name:'.trim((string)$c['customer']));
  if (!isset($custAgg[$key])) $custAgg[$key]=['name'=>$c['customer'],'phone'=>$c['phone'],'orders'=>0,'value'=>0,'last'=>$c['order_date']];
  if ($c['status']==='delivered') { $custAgg[$key]['orders']++; $custAgg[$key]['value']+=(float)$c['sell_price']*(int)$c['qty']; }
  if ($c['order_date'] > $custAgg[$key]['last']) $custAgg[$key]['last']=$c['order_date'];
}
usort($custAgg, fn($a,$b)=>$b['value']<=>$a['value']);
$repeatRate = 0;
if (count($custAgg)) { $repeatN=0; foreach($custAgg as $c) if($c['orders']>=2) $repeatN++; $repeatRate = round($repeatN/count($custAgg)*100); }

$initial = function($name){ $name=trim((string)$name); return $name!=='' ? mb_strtoupper(mb_substr($name,0,1)) : '?'; };

require __DIR__.'/includes/header.php';
echo delivery_disabled_banner('hungryhunter.php');
?>
<style>
.hh2-hero{background:linear-gradient(120deg,#c2410c,#9a3412);border-radius:22px;padding:22px 26px;color:#fff;margin-bottom:14px;box-shadow:0 18px 40px rgba(154,52,18,.28)}
.hh2-hero-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.hh2-hero h1{font-size:21px;font-weight:900;margin:0}
.hh2-hero p{opacity:.85;font-size:12px;margin:2px 0 0}
.hh2-hero-links{display:flex;gap:8px;margin-left:auto;flex-wrap:wrap}
.hh2-hlink{background:rgba(255,255,255,.9);color:#9a3412;border-radius:10px;padding:8px 13px;font-size:11.5px;font-weight:800;text-decoration:none;border:0;cursor:pointer}
.hh2-hlink.ghost{background:rgba(255,255,255,.18);color:#fff}
.hh2-recon{margin-top:14px;display:inline-flex;align-items:center;gap:8px;background:rgba(251,191,36,.18);border:1px solid rgba(251,191,36,.45);color:#fde68a;border-radius:99px;padding:7px 16px;font-size:11.5px;font-weight:800;flex-wrap:wrap}
.hh2-recon.ok{background:rgba(74,222,128,.15);border-color:rgba(74,222,128,.4);color:#4ade80}

.hh2-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:14px 0}
.hh2-tile{background:rgba(255,255,255,.85);backdrop-filter:blur(16px);border-radius:15px;padding:13px 15px;box-shadow:0 6px 18px rgba(30,41,80,.07);border-top:3px solid #ddd}
.hh2-tile.o{border-top-color:#c2410c}.hh2-tile.g{border-top-color:#16a34a}.hh2-tile.b{border-top-color:#0369a1}.hh2-tile.p{border-top-color:#7c3aed}.hh2-tile.a{border-top-color:#d97706}
.hh2-tile b{font-size:18px;display:block;font-weight:900}
.hh2-tile span{font-size:10.5px;color:#5a6580;font-weight:700}

.hh2-flow{display:grid;grid-template-columns:1.4fr 1fr;gap:14px;margin-bottom:16px}
.hh2-card{background:#fff;border-radius:18px;box-shadow:0 8px 24px rgba(30,41,80,.07);overflow:hidden}
.hh2-card-h{padding:14px 18px;font-weight:900;font-size:13.5px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #f1f4f9;flex-wrap:wrap}
.hh2-tag{font-size:10px;font-weight:800;border-radius:99px;padding:2px 9px;background:#f1f4fa;color:#5a6580;margin-left:auto}
.hh2-search{margin:12px 18px;display:flex;align-items:center;gap:8px;background:#f8fafc;border-radius:10px;padding:8px 12px}
.hh2-search input{border:0;background:transparent;outline:0;font-size:12px;flex:1;font:inherit}
.hh2-row{padding:13px 18px;border-bottom:1px solid #f5f7fb}
.hh2-row:last-child{border-bottom:0}
.hh2-row-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:9px}
.hh2-row .nm{font-weight:800;color:#334;font-size:12.5px}
.hh2-row-acts{margin-left:auto;display:flex;gap:5px}
.hh2-stats{display:grid;grid-template-columns:repeat(5,auto);gap:14px;align-items:end}
.hh2-stat{text-align:left}
.hh2-stat b{display:block;font-size:13.5px;font-weight:900;color:#334}
.hh2-stat span{font-size:9px;color:#8a93a8;font-weight:700;text-transform:uppercase;letter-spacing:.03em}
.hh2-stat.left b{font-size:16px}
.hh2-stat.neg b{color:#dc2626}
.hh2-stat.low b{color:#d97706}
@media(max-width:520px){ .hh2-stats{grid-template-columns:repeat(3,auto);row-gap:8px} }

.hh2-mv{display:flex;gap:10px;padding:11px 18px;border-bottom:1px solid #f5f7fb;font-size:11.5px}
.hh2-mv:last-child{border-bottom:0}
.hh2-mv .ic{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;flex:none}
.hh2-mv .ic.in{background:#dbeafe}.hh2-mv .ic.out{background:#dcfce7}.hh2-mv .ic.return{background:#fef3c7}.hh2-mv .ic.adjust{background:#ede7fe}
.hh2-mv-body{flex:1;min-width:0}
.hh2-mv-top{display:flex;justify-content:space-between;gap:8px}
.hh2-mv-time{font-size:9.5px;color:#8a93a8;white-space:nowrap;flex:none}
.hh2-mv-note{font-size:10.5px;color:#8a93a8;margin-top:2px;font-style:italic}
.hh2-showall{width:100%;text-align:center;background:#f8fafc;border:0;padding:11px;font-size:11.5px;font-weight:800;color:#c2410c;cursor:pointer}

.hh2-codcard{background:#fff;border-radius:18px;box-shadow:0 8px 24px rgba(30,41,80,.07);padding:16px 20px;margin-bottom:16px;display:flex;gap:22px;flex-wrap:wrap;align-items:center}
.hh2-codstat{text-align:left}
.hh2-codstat b{display:block;font-size:15px;font-weight:900}
.hh2-codstat span{font-size:9.5px;color:#8a93a8;font-weight:700;text-transform:uppercase;letter-spacing:.03em}
.hh2-mismatch{background:#fee2e2;color:#c0392b;font-size:9.5px;font-weight:800;border-radius:99px;padding:2px 8px;margin-left:6px;border:0;cursor:pointer}
.hh2-aging{font-size:9.5px;color:#8a93a8;font-weight:700}
.hh2-aging.stale{color:#c2410c;background:#ffedd5;border-radius:99px;padding:2px 8px}
.hh2-acts{display:flex;gap:5px}
.hh2-iact{width:28px;height:28px;border-radius:8px;background:#f1f4fa;border:0;font-size:12px;cursor:pointer}
.hh2-empty{padding:22px 18px;text-align:center;color:#8a93a8;font-size:12px}


.hh2-custcard{background:#fff;border-radius:18px;box-shadow:0 8px 24px rgba(30,41,80,.07);overflow:hidden;margin-bottom:16px}
.hh2-avatar{width:34px;height:34px;border-radius:99px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12.5px;color:#fff;flex:none}
.hh2-crow{display:flex;align-items:center;gap:12px;padding:11px 18px;border-bottom:1px solid #f5f7fb}
.hh2-crow:last-child{border-bottom:0}
.hh2-cinfo{flex:1;min-width:0}
.hh2-cname{font-weight:800;font-size:12.5px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.hh2-csub{font-size:10.5px;color:#8a93a8;margin-top:1px}
.hh2-cval{text-align:right;font-weight:900;font-size:12.5px;flex:none}
.hh2-cval .l{font-size:9px;color:#8a93a8;font-weight:700;display:block}
.hh2-repeat{background:#ede7fe;color:#7c3aed;font-size:9px;font-weight:800;border-radius:99px;padding:2px 8px}
@media(max-width:900px){ .hh2-flow{grid-template-columns:1fr} }
</style>

<div class="hh2-hero">
  <div class="hh2-hero-top">
    <span style="font-size:28px">🛵</span>
    <div><h1>Hungry Hunter</h1><p>Inside-valley consignment courier — stock lives there, separate from your store</p></div>
    <div class="hh2-hero-links">
      <a class="hh2-hlink" href="sales.php?new=hh">＋ New Order</a>
      <button class="hh2-hlink ghost" onclick="openTr()">📦 Send Stock</button>
    </div>
  </div>
  <?php if($mismatches): ?>
    <div class="hh2-recon">⚠ <?= count($mismatches) ?> product(s) show a quantity mismatch between the stock ledger and real order quantities — fix one below, or all at once
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="reconcile_all"><button style="background:rgba(255,255,255,.9);color:#9a3412;border:0;border-radius:99px;padding:4px 12px;font-weight:800;font-size:10.5px;cursor:pointer">🔧 Reconcile All</button></form>
    </div>
  <?php else: ?>
    <div class="hh2-recon ok">✓ Stock ledger matches real order quantities — no mismatches found</div>
  <?php endif; ?>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="hh2-tiles">
  <div class="hh2-tile o"><b><?= money($hhValue) ?></b><span>📦 Stock Value at HH · <?= number_format($hhPcs) ?> pcs</span></div>
  <div class="hh2-tile g"><b><?= money($mRev) ?></b><span>💰 This Month Revenue</span></div>
  <div class="hh2-tile <?= $mProf>=0?'b':'a' ?>"><b><?= money($mProf) ?></b><span>📈 This Month Profit</span></div>
  <div class="hh2-tile p"><b><?= $repeatRate ?>%</b><span>👥 Repeat Customer Rate</span></div>
  <div class="hh2-tile a"><b><?= money(max(0,$codRow['held'])) ?></b><span>🚚 Remaining COD With HH</span></div>
</div>

<div class="hh2-codcard">
  <div class="hh2-codstat"><b><?= money($codRow['collected']) ?></b><span>💰 COD Collected</span></div>
  <div class="hh2-codstat"><b style="color:var(--red)">− <?= money($codRow['charges']) ?></b><span>✂️ Their Charges</span></div>
  <div class="hh2-codstat"><b><?= money($codRow['net']) ?></b><span>🧮 Net Payable</span></div>
  <div class="hh2-codstat"><b style="color:var(--green)"><?= money($codRow['released']) ?></b><span>✅ Released</span></div>
  <a href="cod.php" style="font-weight:800;margin-left:auto;font-size:12px">COD Ledger →</a>
</div>

<div class="hh2-flow">
  <div class="hh2-card">
    <div class="hh2-card-h">📦 Stock at Hungry Hunter <span class="hh2-tag"><?= count($pos) ?> products</span></div>
    <div class="hh2-search">🔍 <input id="hhStockSearch" placeholder="Search product…"></div>
    <?php foreach($pos as $pid2=>$pd): $left=(int)$pd['left']; $mm=$mismatches[$pid2] ?? null; $la=$lastIn[$pid2] ?? null; $days=$la?(int)((time()-strtotime($la))/86400):null; $val=max(0,$left)*(float)$pd['avg_cost']; ?>
    <div class="hh2-row" data-s="<?= e(mb_strtolower($prodName[$pid2] ?? '')) ?>">
      <div class="hh2-row-top">
        <span class="nm"><?= e($prodName[$pid2] ?? ('#'.$pid2)) ?></span>
        <?php if($mm): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="reconcile"><input type="hidden" name="product_id" value="<?= (int)$pid2 ?>"><button class="hh2-mismatch" title="Ledger says <?= $mm['ledger'] ?> delivered, real orders say <?= $mm['real'] ?> — click to fix">⚠ mismatch, click to fix</button></form><?php endif; ?>
        <?php if($days!==null && $days>=10): ?><span class="hh2-aging stale"><?= $days ?> days here</span><?php endif; ?>
        <div class="hh2-row-acts">
          <button class="hh2-iact" onclick="openRet(<?= (int)$pid2 ?>,<?= $left ?>)" title="return to store">↩︎</button>
          <?php if($isAdmin): ?><button class="hh2-iact" onclick="openAdj(<?= (int)$pid2 ?>)" title="adjust">±</button><?php endif; ?>
        </div>
      </div>
      <div class="hh2-stats">
        <div class="hh2-stat"><b><?= number_format($pd['sent']) ?></b><span>Sent</span></div>
        <div class="hh2-stat"><b style="color:#16a34a"><?= number_format($pd['delivered']) ?></b><span>Delivered</span></div>
        <div class="hh2-stat"><b class="muted"><?= number_format($pd['returned']) ?></b><span>Returned</span></div>
        <div class="hh2-stat left <?= $left<0?'neg':($left<=5?'low':'') ?>"><b><?= number_format($left) ?><?= $left<0?' ⚠':'' ?></b><span>In Hand</span></div>
        <div class="hh2-stat"><b><?= money($val) ?></b><span>Value · <?= money($pd['avg_cost']) ?>/pc</span></div>
      </div>
    </div>
    <?php endforeach; if(!$pos): ?><div class="hh2-empty">No stock at Hungry Hunter yet — click "📦 Send Stock".</div><?php endif; ?>
  </div>

  <div class="hh2-card">
    <div class="hh2-card-h">📜 Recent Movements <span class="hh2-tag" id="hhMvCount"><?= min(10,count($movements)) ?> of <?= count($movements) ?></span></div>
    <?php foreach($movements as $i=>$mv):
      $ic=['in'=>'📦','out'=>'✅','return'=>'↩︎','adjust'=>'±'][$mv['type']]??'•';
      $lbl=['in'=>'Sent to HH','out'=>'Delivered','return'=>'Returned to store','adjust'=>'Adjustment'][$mv['type']]??$mv['type'];
      $qtySign = ($mv['type']==='adjust' && (int)$mv['qty']>0) ? '+' : '';
      $hideStyle = $i>=10 ? ' style="display:none"' : '';
    ?>
    <div class="hh2-mv hh2-mv-extra"<?= $hideStyle ?>>
      <span class="ic <?= e($mv['type']) ?>"><?= $ic ?></span>
      <div class="hh2-mv-body">
        <div class="hh2-mv-top">
          <span><b><?= e($mv['product_name'] ?: ('#'.$mv['product_id'])) ?></b> · <?= e($lbl) ?> <b><?= $qtySign ?><?= (int)$mv['qty'] ?> pcs</b><?= $mv['ref_order_id']?' · order #'.(int)$mv['ref_order_id']:'' ?></span>
          <span class="hh2-mv-time"><?= e(date('d M, H:i',strtotime($mv['created_at']))) ?></span>
        </div>
        <?php if(in_array($mv['type'],['in','out','return'],true) && (float)$mv['unit_cost']>0): ?>
        <div class="hh2-mv-note" style="font-style:normal;color:#9a3412;font-weight:700">FIFO rate: <?= money($mv['unit_cost']) ?>/pc · total <?= money((float)$mv['unit_cost']*(int)$mv['qty']) ?></div>
        <?php endif; ?>
        <?php if($mv['note']): ?><div class="hh2-mv-note"><?= e($mv['note']) ?></div><?php endif; ?>
      </div>
    </div>
    <?php endforeach; if(!$movements): ?><div class="hh2-empty">No movements yet.</div><?php endif; ?>
    <?php if(count($movements)>10): ?><button type="button" class="hh2-showall" id="hhMvShowAll" data-total="<?= count($movements) ?>">⌄ See all <?= count($movements) ?> movements</button><?php endif; ?>
  </div>
</div>

<div class="hh2-custcard">
  <div class="hh2-card-h">👥 Customers via Hungry Hunter <span class="hh2-tag"><?= e(date('F Y',strtotime($mStart))) ?> · ranked by value</span></div>
  <div class="hh2-search">🔍 <input id="hhCustSearch" placeholder="Search name or phone…"></div>
  <?php foreach(array_slice($custAgg,0,15) as $c): ?>
  <div class="hh2-crow" data-s="<?= e(mb_strtolower(($c['name']?:'').' '.($c['phone']?:''))) ?>">
    <span class="hh2-avatar" style="background:linear-gradient(135deg,#fca5a5,#c2410c)"><?= e($initial($c['name'])) ?></span>
    <div class="hh2-cinfo">
      <div class="hh2-cname"><?= e($c['name']?:'—') ?><?php if($c['orders']>=2): ?><span class="hh2-repeat">🔁 <?= $c['orders'] ?> orders</span><?php endif; ?></div>
      <div class="hh2-csub"><?= e($c['phone']?:'—') ?> · last order <?= e(date('d M',strtotime($c['last']))) ?></div>
    </div>
    <div class="hh2-cval"><span class="l">This month</span><?= money($c['value']) ?></div>
  </div>
  <?php endforeach; if(!$custAgg): ?><div class="hh2-empty">No Hungry Hunter customers this month yet.</div><?php endif; ?>
</div>

<div class="hh2-card" style="margin-bottom:16px">
  <div class="hh2-card-h">🛵 Orders via Hungry Hunter — <?= e(date('F Y',strtotime($mStart))) ?>
    <form method="get" style="display:flex;gap:6px;align-items:center;margin-left:auto"><input type="month" name="m" value="<?= e($m) ?>" style="border:1px solid var(--border);border-radius:8px;padding:5px 9px;font-size:11.5px"><button class="btn btn-sm">Go</button></form>
  </div>
  <div class="hh2-search">🔍 <input id="hhOrderSearch" placeholder="Search order code, customer, or product…"></div>
  <div class="table-wrap"><table class="tbl led-tbl"><thead><tr>
    <th>Order</th><th>Date</th><th>Customer</th><th>Product</th><th>Qty</th><th>COD</th><th>Profit</th><th>Status</th>
  </tr></thead><tbody>
  <?php foreach($orders as $o):
    $isDel=$o['status']==='delivered'; $prof=order_profit($o);
    $pot=((float)$o['sell_price']-(float)$o['cost_price'])*(int)$o['qty']-(float)$o['delivery_charge'];
    $cod=strtolower((string)$o['payment_type'])==='cod' ? (float)$o['sell_price']*(int)$o['qty'] : 0;
  ?>
    <tr data-s="<?= e(mb_strtolower($o['code'].' '.$o['customer'].' '.$o['product_name'])) ?>">
      <td><b><?= e($o['code']) ?></b></td>
      <td><?= e($o['order_date']) ?></td>
      <td><b><?= e($o['customer'] ?: '—') ?></b></td>
      <td><b><?= e($o['product_name'] ?: '—') ?></b></td>
      <td style="text-align:right"><?= (int)$o['qty'] ?></td>
      <td style="text-align:right"><?= $cod>0?money($cod):'<span class="muted">prepaid</span>' ?></td>
      <td style="text-align:right;font-weight:800;color:<?= ($isDel?$prof:$pot)>=0?'var(--green)':'var(--red)' ?>"><?= $isDel?money($prof):(in_array($o['status'],['returned','cancelled'],true)?money($prof):'≈ '.money($pot)) ?></td>
      <td><?= pill($o['status']) ?></td>
    </tr>
  <?php endforeach; if(!$orders): ?><tr><td colspan="8"><div class="hh2-empty">No Hungry Hunter orders this month.</div></td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<!-- transfer modal -->
<div class="modal-bg" id="trModal" style="z-index:99990"><form class="modal" method="post" style="width:480px;max-width:94vw">
  <div class="modal-head"><span>📦 Send Stock to Hungry Hunter</span><span class="mx" onclick="closeM('trModal')">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="transfer">
  <div class="modal-body">
    <div class="full"><label>Product</label><select name="product_id" required><option value="">—</option>
      <?php foreach($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (store: <?= (int)$p['stock'] ?>)</option><?php endforeach; ?></select></div>
    <div><label>Quantity (pcs)</label><input type="number" name="qty" min="1" required></div>
    <div><label>Note</label><input name="note" placeholder="optional"></div>
    <div class="full muted" style="font-size:11.5px">Main store stock decreases (FIFO cost captured); the pieces then live at Hungry Hunter until delivered or returned.</div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="closeM('trModal')">Cancel</button><button class="btn btn-primary">📦 Send</button></div>
</form></div>

<!-- return modal -->
<div class="modal-bg" id="retModal" style="z-index:99990"><form class="modal" method="post" style="width:440px;max-width:94vw">
  <div class="modal-head"><span>↩︎ Return Stock to Store</span><span class="mx" onclick="closeM('retModal')">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="return"><input type="hidden" name="product_id" id="ret_pid">
  <div class="modal-body">
    <div><label>Quantity (pcs, max <span id="ret_max">0</span>)</label><input type="number" name="qty" id="ret_qty" min="1" required></div>
    <div><label>Note</label><input name="note" placeholder="optional"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="closeM('retModal')">Cancel</button><button class="btn btn-primary">↩︎ Return</button></div>
</form></div>

<!-- adjust modal -->
<div class="modal-bg" id="adjModal" style="z-index:99990"><form class="modal" method="post" style="width:440px;max-width:94vw">
  <div class="modal-head"><span>± Adjust HH Stock</span><span class="mx" onclick="closeM('adjModal')">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="adjust"><input type="hidden" name="product_id" id="adj_pid">
  <div class="modal-body">
    <div><label>Quantity (+ adds, − removes)</label><input type="number" name="qty" required placeholder="e.g. -2"></div>
    <div><label>Reason</label><input name="note" placeholder="e.g. damaged piece"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="closeM('adjModal')">Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div>

<script>
function openTr(){document.getElementById('trModal').classList.add('open');document.body.classList.add('modal-open');}
function openRet(pid,left){document.getElementById('ret_pid').value=pid;document.getElementById('ret_max').textContent=Math.max(0,left);document.getElementById('ret_qty').max=Math.max(0,left);document.getElementById('retModal').classList.add('open');document.body.classList.add('modal-open');}
function openAdj(pid){document.getElementById('adj_pid').value=pid;document.getElementById('adjModal').classList.add('open');document.body.classList.add('modal-open');}
function closeM(id){document.getElementById(id).classList.remove('open');document.body.classList.remove('modal-open');}
['trModal','retModal','adjModal'].forEach(function(id){var mm=document.getElementById(id);mm.addEventListener('click',function(e){if(e.target===mm)closeM(id);});});

function wireSearch(inputId, rowSelector){
  var el=document.getElementById(inputId); if(!el) return;
  el.addEventListener('input', function(){
    var q=this.value.toLowerCase().trim();
    document.querySelectorAll(rowSelector).forEach(function(r){
      var ok = !q || (r.getAttribute('data-s')||'').indexOf(q)!==-1;
      r.style.display = ok ? '' : 'none';
    });
  });
}
wireSearch('hhStockSearch','.hh2-row[data-s]');
wireSearch('hhCustSearch','.hh2-crow[data-s]');
wireSearch('hhOrderSearch','tr[data-s]');

var mvShowAll = document.getElementById('hhMvShowAll');
if (mvShowAll) {
  mvShowAll.addEventListener('click', function(){
    document.querySelectorAll('.hh2-mv-extra').forEach(function(row){
      if (row.style.display === 'none') row.style.display = '';
    });
    var total = mvShowAll.getAttribute('data-total');
    var countTag = document.getElementById('hhMvCount');
    if (countTag) countTag.textContent = total + ' of ' + total;
    mvShowAll.remove();
  });
}
</script>
<?php require __DIR__.'/includes/footer.php'; ?>