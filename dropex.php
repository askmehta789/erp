<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
repair_zero_cost_profit();   /* auto-fix orders saved with Rs.0 cost so profit is honest everywhere */
$PAGE_TITLE='Dropex';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;
ensure_dropex_stock();

/* courier must exist */
$dxc = row("SELECT * FROM couriers WHERE LOWER(name) LIKE '%dropex%' LIMIT 1");
if (!$dxc) { try { q("INSERT INTO couriers(name,status) VALUES('Dropex','active')"); } catch (Exception $e) { try { q("INSERT INTO couriers(name) VALUES('Dropex')"); } catch (Exception $e2) {} }
  $dxc = row("SELECT * FROM couriers WHERE LOWER(name) LIKE '%dropex%' LIMIT 1"); }
$dxId = (int)($dxc['id'] ?? 0);

$products = rows("SELECT id,name,stock FROM products ORDER BY name");

/* ---- actions ---- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act = $_POST['_action'] ?? '';

  if ($act==='repair_cost') {                    /* fix ANY delivered order that saved cost_price 0 (all channels) */
    $dx = dropex_courier_id(); $fixed=0; $noCost=[];
    $bad = rows("SELECT id,product_id,qty,courier_id FROM orders WHERE status='delivered' AND (cost_price IS NULL OR cost_price<=0)");
    foreach ($bad as $o) {
      $pid=(int)$o['product_id']; if(!$pid) continue;
      $unit=0.0;
      if ($dx && (int)$o['courier_id']===$dx) {           /* Dropex: Dropex avg cost first */
        $pos=dropex_position($pid); $unit=(float)$pos['avg_cost'];
      }
      if ($unit<=0) $unit=(float)fifo_current_cost($pid);   /* then oldest open FIFO batch */
      if ($unit<=0) $unit=(float)val("SELECT cost FROM products WHERE id=?",[$pid]);  /* then product base cost */
      if ($unit>0) { q("UPDATE orders SET cost_price=? WHERE id=?",[$unit,$o['id']]); $fixed++; }
      else { $nm=val("SELECT name FROM products WHERE id=?",[$pid]); if($nm) $noCost[$nm]=1; }
    }
    $msg = $fixed>0 ? "✅ Fixed cost on {$fixed} delivered order(s). Profit is now correct." : "No orders needed fixing — all delivered costs look good.";
    if ($noCost) $msg .= " ⚠️ Couldn't fix ".count($noCost)." product(s) with no cost set: ".implode(', ',array_keys($noCost)).". Set their cost on the Products page, then run this again.";
    flash($msg);
    log_activity("Repaired cost on {$fixed} orders",'Dropex');
    header('Location: dropex.php'); exit;
  }

  if ($act==='transfer') {                       /* send stock TO Dropex */
    $pid=(int)($_POST['product_id'] ?? 0); $qty=max(1,(int)($_POST['qty'] ?? 0));
    $p = row("SELECT * FROM products WHERE id=?",[$pid]);
    if (!$p) flash('Pick a product.');
    elseif ((int)$p['stock'] < $qty) flash('Not enough main stock — only '.(int)$p['stock'].' pcs of '.$p['name'].' in store.');
    else {
      $unit = fifo_consume(0,$pid,$qty,'dropex');    /* main stock down, FIFO cost captured */
      q("INSERT INTO dropex_stock(product_id,type,qty,unit_cost,note) VALUES(?,?,?,?,?)",
        [$pid,'in',$qty,(float)$unit,trim($_POST['note'] ?? '')]);
      log_activity("Sent {$qty} pcs of {$p['name']} to Dropex @ ".money($unit),'Dropex');
      flash("Sent $qty pcs of {$p['name']} to Dropex · FIFO cost ".money($unit)."/pc");
    }
    header('Location: dropex.php'); exit;
  }

  if ($act==='return') {                          /* Dropex sends stock back to store */
    $pid=(int)($_POST['product_id'] ?? 0); $qty=max(1,(int)($_POST['qty'] ?? 0));
    $pos=dropex_position($pid);
    if ($qty > (int)$pos['left']) flash('Dropex only holds '.(int)$pos['left'].' pcs of this product.');
    else {
      q("INSERT INTO dropex_stock(product_id,type,qty,unit_cost,note) VALUES(?,?,?,?,?)",
        [$pid,'return',$qty,(float)$pos['avg_cost'],trim($_POST['note'] ?? '')]);
      /* back into main stock as a batch at the same cost */
      q("INSERT INTO stock_batches(product_id,purchase_date,qty_in,qty_left,unit_cost,note) VALUES(?,?,?,?,?,?)",
        [$pid,date('Y-m-d'),$qty,$qty,(float)$pos['avg_cost'],'returned from Dropex']);
      stock_cache($pid);
      log_activity("Dropex returned {$qty} pcs (product #$pid)",'Dropex');
      flash("Returned $qty pcs back to main stock.");
    }
    header('Location: dropex.php'); exit;
  }

  if ($act==='adjust' && $isAdmin) {              /* correction ± */
    $pid=(int)($_POST['product_id'] ?? 0); $qty=(int)($_POST['qty'] ?? 0);
    if ($pid && $qty!==0) {
      q("INSERT INTO dropex_stock(product_id,type,qty,unit_cost,note) VALUES(?,?,?,?,?)",
        [$pid,'adjust',$qty,0,trim($_POST['note'] ?? '') ?: 'manual adjustment']);
      flash('Adjustment recorded ('.($qty>0?'+':'').$qty.' pcs).');
    }
    header('Location: dropex.php'); exit;
  }

  if ($act==='reconcile') {                        /* fix a product whose ledger drifted from real order quantities */
    $pid=(int)($_POST['product_id'] ?? 0);
    $ok = dropex_reconcile_fix($pid);
    $nm = (string)val("SELECT name FROM products WHERE id=?",[$pid]);
    flash($ok ? "✅ Reconciled {$nm} — the ledger now matches real order quantities." : "Nothing to reconcile for {$nm}.");
    log_activity("Reconciled Dropex stock for product #$pid",'Dropex');
    header('Location: dropex.php'); exit;
  }

  if ($act==='reconcile_all') {
    $n = dropex_reconcile_fix_all();
    flash($n>0 ? "✅ Reconciled {$n} product(s) — the ledger now matches real order quantities across the board." : "Nothing needed reconciling.");
    log_activity("Reconciled $n Dropex product(s) in bulk",'Dropex');
    header('Location: dropex.php'); exit;
  }
}

/* ---- month filter for orders ---- */
$m = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$mStart=$m.'-01'; $mEnd=date('Y-m-t',strtotime($mStart));
$orders = rows("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id
                WHERE o.courier_id=? AND o.order_date BETWEEN ? AND ?
                ORDER BY o.order_date DESC, o.id DESC LIMIT 400",[$dxId,$mStart,$mEnd]);

$mOrders=count($orders); $mDel=0; $mUnits=0; $mRev=0; $mProf=0; $mRet=0;
foreach($orders as $o){
  if($o['status']==='delivered'){ $mDel++; $mUnits+=(int)$o['qty']; $mRev+=(float)$o['sell_price']*(int)$o['qty']; $mProf+=order_profit($o); }
  elseif(in_array($o['status'],['returned','cancelled'],true)){ $mRet++; $mProf+=order_profit($o); }
}

/* ---- COD money (all-time, from the shared engine) ---- */
$codRow=['collected'=>0,'charges'=>0,'net'=>0,'released'=>0,'held'=>0];
foreach (cod_holdings() as $h) if ((int)$h['cid']===$dxId) { $codRow=$h; break; }

/* ---- stock at Dropex ---- */
$pos = dropex_position();
$dxValue=0; $dxPcs=0;
foreach($pos as $pd){ $dxValue += max(0,(int)$pd['left'])*(float)$pd['avg_cost']; $dxPcs += max(0,(int)$pd['left']); }
$movements = rows("SELECT h.*, p.name AS product_name FROM dropex_stock h LEFT JOIN products p ON p.id=h.product_id ORDER BY h.id DESC LIMIT 100");
$prodName=[]; foreach($products as $p) $prodName[(int)$p['id']]=$p['name'];

/* days since this product's most recent transfer INTO Dropex — a simple, honest aging signal */
$lastIn=[];
foreach (rows("SELECT product_id, MAX(created_at) la FROM dropex_stock WHERE type='in' GROUP BY product_id") as $r) {
  $lastIn[(int)$r['product_id']] = $r['la'];
}

/* the reconciliation audit that would have caught the quantity-mismatch bug automatically */
$mismatches = dropex_reconcile_check();

/* ---- customers via Dropex, scoped to the same month as the orders table above ---- */
$custRows = rows("SELECT customer, phone, status, sell_price, qty, order_date FROM orders WHERE courier_id=? AND order_date BETWEEN ? AND ?",[$dxId,$mStart,$mEnd]);
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
echo delivery_disabled_banner('dropex.php');
?>
<style>
.dx-hero{background:linear-gradient(120deg,#0369a1,#0c4a6e);border-radius:22px;padding:22px 26px;color:#fff;margin-bottom:14px;box-shadow:0 18px 40px rgba(3,74,110,.28)}
.dx-hero-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.dx-hero h1{font-size:21px;font-weight:900;margin:0}
.dx-hero p{opacity:.85;font-size:12px;margin:2px 0 0}
.dx-hero-links{display:flex;gap:8px;margin-left:auto;flex-wrap:wrap}
.dx-hlink{background:rgba(255,255,255,.9);color:#0c4a6e;border-radius:10px;padding:8px 13px;font-size:11.5px;font-weight:800;text-decoration:none;border:0;cursor:pointer}
.dx-hlink.ghost{background:rgba(255,255,255,.18);color:#fff}
.dx-recon{margin-top:14px;display:inline-flex;align-items:center;gap:8px;background:rgba(251,191,36,.18);border:1px solid rgba(251,191,36,.45);color:#fde68a;border-radius:99px;padding:7px 16px;font-size:11.5px;font-weight:800;flex-wrap:wrap}
.dx-recon.ok{background:rgba(74,222,128,.15);border-color:rgba(74,222,128,.4);color:#4ade80}

.dx-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:14px 0}
.dx-tile{background:rgba(255,255,255,.85);backdrop-filter:blur(16px);border-radius:15px;padding:13px 15px;box-shadow:0 6px 18px rgba(30,41,80,.07);border-top:3px solid #ddd}
.dx-tile.o{border-top-color:#0369a1}.dx-tile.g{border-top-color:#16a34a}.dx-tile.b{border-top-color:#0369a1}.dx-tile.p{border-top-color:#7c3aed}.dx-tile.a{border-top-color:#d97706}
.dx-tile b{font-size:18px;display:block;font-weight:900}
.dx-tile span{font-size:10.5px;color:#5a6580;font-weight:700}

.dx-flow{display:grid;grid-template-columns:1.4fr 1fr;gap:14px;margin-bottom:16px}
.dx-card{background:#fff;border-radius:18px;box-shadow:0 8px 24px rgba(30,41,80,.07);overflow:hidden}
.dx-card-h{padding:14px 18px;font-weight:900;font-size:13.5px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #f1f4f9;flex-wrap:wrap}
.dx-tag{font-size:10px;font-weight:800;border-radius:99px;padding:2px 9px;background:#f1f4fa;color:#5a6580;margin-left:auto}
.dx-search{margin:12px 18px;display:flex;align-items:center;gap:8px;background:#f8fafc;border-radius:10px;padding:8px 12px}
.dx-search input{border:0;background:transparent;outline:0;font-size:12px;flex:1;font:inherit}
.dx-row{padding:13px 18px;border-bottom:1px solid #f5f7fb}
.dx-row:last-child{border-bottom:0}
.dx-row-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:9px}
.dx-row .nm{font-weight:800;color:#334;font-size:12.5px}
.dx-row-acts{margin-left:auto;display:flex;gap:5px}
.dx-stats{display:grid;grid-template-columns:repeat(5,auto);gap:14px;align-items:end}
.dx-stat{text-align:left}
.dx-stat b{display:block;font-size:13.5px;font-weight:900;color:#334}
.dx-stat span{font-size:9px;color:#8a93a8;font-weight:700;text-transform:uppercase;letter-spacing:.03em}
.dx-stat.left b{font-size:16px}
.dx-stat.neg b{color:#dc2626}
.dx-stat.low b{color:#d97706}
@media(max-width:520px){ .dx-stats{grid-template-columns:repeat(3,auto);row-gap:8px} }

.dx-mv{display:flex;gap:10px;padding:11px 18px;border-bottom:1px solid #f5f7fb;font-size:11.5px}
.dx-mv:last-child{border-bottom:0}
.dx-mv .ic{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;flex:none}
.dx-mv .ic.in{background:#dbeafe}.dx-mv .ic.out{background:#dcfce7}.dx-mv .ic.return{background:#fef3c7}.dx-mv .ic.adjust{background:#ede7fe}
.dx-mv-body{flex:1;min-width:0}
.dx-mv-top{display:flex;justify-content:space-between;gap:8px}
.dx-mv-time{font-size:9.5px;color:#8a93a8;white-space:nowrap;flex:none}
.dx-mv-note{font-size:10.5px;color:#8a93a8;margin-top:2px;font-style:italic}
.dx-showall{width:100%;text-align:center;background:#f8fafc;border:0;padding:11px;font-size:11.5px;font-weight:800;color:#0369a1;cursor:pointer}

.dx-codcard{background:#fff;border-radius:18px;box-shadow:0 8px 24px rgba(30,41,80,.07);padding:16px 20px;margin-bottom:16px;display:flex;gap:22px;flex-wrap:wrap;align-items:center}
.dx-codstat{text-align:left}
.dx-codstat b{display:block;font-size:15px;font-weight:900}
.dx-codstat span{font-size:9.5px;color:#8a93a8;font-weight:700;text-transform:uppercase;letter-spacing:.03em}
.dx-mismatch{background:#fee2e2;color:#c0392b;font-size:9.5px;font-weight:800;border-radius:99px;padding:2px 8px;margin-left:6px;border:0;cursor:pointer}
.dx-aging{font-size:9.5px;color:#8a93a8;font-weight:700}
.dx-aging.stale{color:#0369a1;background:#e0f2fe;border-radius:99px;padding:2px 8px}
.dx-acts{display:flex;gap:5px}
.dx-iact{width:28px;height:28px;border-radius:8px;background:#f1f4fa;border:0;font-size:12px;cursor:pointer}
.dx-empty{padding:22px 18px;text-align:center;color:#8a93a8;font-size:12px}

.dx-custcard{background:#fff;border-radius:18px;box-shadow:0 8px 24px rgba(30,41,80,.07);overflow:hidden;margin-bottom:16px}
.dx-avatar{width:34px;height:34px;border-radius:99px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12.5px;color:#fff;flex:none}
.dx-crow{display:flex;align-items:center;gap:12px;padding:11px 18px;border-bottom:1px solid #f5f7fb}
.dx-crow:last-child{border-bottom:0}
.dx-cinfo{flex:1;min-width:0}
.dx-cname{font-weight:800;font-size:12.5px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.dx-csub{font-size:10.5px;color:#8a93a8;margin-top:1px}
.dx-cval{text-align:right;font-weight:900;font-size:12.5px;flex:none}
.dx-cval .l{font-size:9px;color:#8a93a8;font-weight:700;display:block}
.dx-repeat{background:#ede7fe;color:#7c3aed;font-size:9px;font-weight:800;border-radius:99px;padding:2px 8px}
@media(max-width:900px){ .dx-flow{grid-template-columns:1fr} }
</style>

<div class="dx-hero">
  <div class="dx-hero-top">
    <span style="font-size:28px">🚴</span>
    <div><h1>Dropex</h1><p>Consignment courier — stock lives there, separate from your store</p></div>
    <div class="dx-hero-links">
      <a class="dx-hlink" href="sales.php?new=dropex">＋ New Order</a>
      <button class="dx-hlink ghost" onclick="openTr()">📦 Send Stock</button>
    </div>
  </div>
  <?php if($mismatches): ?>
    <div class="dx-recon">⚠ <?= count($mismatches) ?> product(s) show a quantity mismatch between the stock ledger and real order quantities — fix one below, or all at once
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="reconcile_all"><button style="background:rgba(255,255,255,.9);color:#0c4a6e;border:0;border-radius:99px;padding:4px 12px;font-weight:800;font-size:10.5px;cursor:pointer">🔧 Reconcile All</button></form>
    </div>
  <?php else: ?>
    <div class="dx-recon ok">✓ Stock ledger matches real order quantities — no mismatches found</div>
  <?php endif; ?>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="dx-tiles">
  <div class="dx-tile o"><b><?= money($dxValue) ?></b><span>📦 Stock Value at Dropex · <?= number_format($dxPcs) ?> pcs</span></div>
  <div class="dx-tile g"><b><?= money($mRev) ?></b><span>💰 This Month Revenue</span></div>
  <div class="dx-tile <?= $mProf>=0?'b':'a' ?>"><b><?= money($mProf) ?></b><span>📈 This Month Profit</span></div>
  <div class="dx-tile p"><b><?= $repeatRate ?>%</b><span>👥 Repeat Customer Rate</span></div>
  <div class="dx-tile a"><b><?= money(max(0,$codRow['held'])) ?></b><span>🚚 Remaining COD With Dropex</span></div>
</div>

<div class="dx-codcard">
  <div class="dx-codstat"><b><?= money($codRow['collected']) ?></b><span>💰 COD Collected</span></div>
  <div class="dx-codstat"><b style="color:var(--red)">− <?= money($codRow['charges']) ?></b><span>✂️ Their Charges</span></div>
  <div class="dx-codstat"><b><?= money($codRow['net']) ?></b><span>🧮 Net Payable</span></div>
  <div class="dx-codstat"><b style="color:var(--green)"><?= money($codRow['released']) ?></b><span>✅ Released</span></div>
  <a href="cod.php" style="font-weight:800;margin-left:auto;font-size:12px">COD Ledger →</a>
</div>

<div class="dx-flow">
  <div class="dx-card">
    <div class="dx-card-h">📦 Stock at Dropex <span class="dx-tag"><?= count($pos) ?> products</span></div>
    <div class="dx-search">🔍 <input id="dxStockSearch" placeholder="Search product…"></div>
    <?php foreach($pos as $pid2=>$pd): $left=(int)$pd['left']; $mm=$mismatches[$pid2] ?? null; $la=$lastIn[$pid2] ?? null; $days=$la?(int)((time()-strtotime($la))/86400):null; $val=max(0,$left)*(float)$pd['avg_cost']; ?>
    <div class="dx-row" data-s="<?= e(mb_strtolower($prodName[$pid2] ?? '')) ?>">
      <div class="dx-row-top">
        <span class="nm"><?= e($prodName[$pid2] ?? ('#'.$pid2)) ?></span>
        <?php if($mm): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="reconcile"><input type="hidden" name="product_id" value="<?= (int)$pid2 ?>"><button class="dx-mismatch" title="Ledger says <?= $mm['ledger'] ?> delivered, real orders say <?= $mm['real'] ?> — click to fix">⚠ mismatch, click to fix</button></form><?php endif; ?>
        <?php if($days!==null && $days>=10): ?><span class="dx-aging stale"><?= $days ?> days here</span><?php endif; ?>
        <div class="dx-row-acts">
          <button class="dx-iact" onclick="openRet(<?= (int)$pid2 ?>,<?= $left ?>)" title="return to store">↩︎</button>
          <?php if($isAdmin): ?><button class="dx-iact" onclick="openAdj(<?= (int)$pid2 ?>)" title="adjust">±</button><?php endif; ?>
        </div>
      </div>
      <div class="dx-stats">
        <div class="dx-stat"><b><?= number_format($pd['sent']) ?></b><span>Sent</span></div>
        <div class="dx-stat"><b style="color:#16a34a"><?= number_format($pd['delivered']) ?></b><span>Delivered</span></div>
        <div class="dx-stat"><b class="muted"><?= number_format($pd['returned']) ?></b><span>Returned</span></div>
        <div class="dx-stat left <?= $left<0?'neg':($left<=5?'low':'') ?>"><b><?= number_format($left) ?><?= $left<0?' ⚠':'' ?></b><span>In Hand</span></div>
        <div class="dx-stat"><b><?= money($val) ?></b><span>Value · <?= money($pd['avg_cost']) ?>/pc</span></div>
      </div>
    </div>
    <?php endforeach; if(!$pos): ?><div class="dx-empty">No stock at Dropex yet — click "📦 Send Stock".</div><?php endif; ?>
  </div>

  <div class="dx-card">
    <div class="dx-card-h">📜 Recent Movements <span class="dx-tag" id="dxMvCount"><?= min(10,count($movements)) ?> of <?= count($movements) ?></span></div>
    <?php foreach($movements as $i=>$mv):
      $ic=['in'=>'📦','out'=>'✅','return'=>'↩︎','adjust'=>'±'][$mv['type']]??'•';
      $lbl=['in'=>'Sent to Dropex','out'=>'Delivered','return'=>'Returned to store','adjust'=>'Adjustment'][$mv['type']]??$mv['type'];
      $qtySign = ($mv['type']==='adjust' && (int)$mv['qty']>0) ? '+' : '';
      $hideStyle = $i>=10 ? ' style="display:none"' : '';
    ?>
    <div class="dx-mv dx-mv-extra"<?= $hideStyle ?>>
      <span class="ic <?= e($mv['type']) ?>"><?= $ic ?></span>
      <div class="dx-mv-body">
        <div class="dx-mv-top">
          <span><b><?= e($mv['product_name'] ?: ('#'.$mv['product_id'])) ?></b> · <?= e($lbl) ?> <b><?= $qtySign ?><?= (int)$mv['qty'] ?> pcs</b><?= $mv['ref_order_id']?' · order #'.(int)$mv['ref_order_id']:'' ?></span>
          <span class="dx-mv-time"><?= e(date('d M, H:i',strtotime($mv['created_at']))) ?></span>
        </div>
        <?php if(in_array($mv['type'],['in','out','return'],true) && (float)$mv['unit_cost']>0): ?>
        <div class="dx-mv-note" style="font-style:normal;color:#0c4a6e;font-weight:700">FIFO rate: <?= money($mv['unit_cost']) ?>/pc · total <?= money((float)$mv['unit_cost']*(int)$mv['qty']) ?></div>
        <?php endif; ?>
        <?php if($mv['note']): ?><div class="dx-mv-note"><?= e($mv['note']) ?></div><?php endif; ?>
      </div>
    </div>
    <?php endforeach; if(!$movements): ?><div class="dx-empty">No movements yet.</div><?php endif; ?>
    <?php if(count($movements)>10): ?><button type="button" class="dx-showall" id="dxMvShowAll" data-total="<?= count($movements) ?>">⌄ See all <?= count($movements) ?> movements</button><?php endif; ?>
  </div>
</div>

<div class="dx-custcard">
  <div class="dx-card-h">👥 Customers via Dropex <span class="dx-tag"><?= e(date('F Y',strtotime($mStart))) ?> · ranked by value</span></div>
  <div class="dx-search">🔍 <input id="dxCustSearch" placeholder="Search name or phone…"></div>
  <?php foreach(array_slice($custAgg,0,15) as $c): ?>
  <div class="dx-crow" data-s="<?= e(mb_strtolower(($c['name']?:'').' '.($c['phone']?:''))) ?>">
    <span class="dx-avatar" style="background:linear-gradient(135deg,#93c5fd,#0369a1)"><?= e($initial($c['name'])) ?></span>
    <div class="dx-cinfo">
      <div class="dx-cname"><?= e($c['name']?:'—') ?><?php if($c['orders']>=2): ?><span class="dx-repeat">🔁 <?= $c['orders'] ?> orders</span><?php endif; ?></div>
      <div class="dx-csub"><?= e($c['phone']?:'—') ?> · last order <?= e(date('d M',strtotime($c['last']))) ?></div>
    </div>
    <div class="dx-cval"><span class="l">This month</span><?= money($c['value']) ?></div>
  </div>
  <?php endforeach; if(!$custAgg): ?><div class="dx-empty">No Dropex customers this month yet.</div><?php endif; ?>
</div>

<div class="dx-card" style="margin-bottom:16px">
  <div class="dx-card-h">🚴 Orders via Dropex — <?= e(date('F Y',strtotime($mStart))) ?>
    <form method="get" style="display:flex;gap:6px;align-items:center;margin-left:auto"><input type="month" name="m" value="<?= e($m) ?>" style="border:1px solid var(--border);border-radius:8px;padding:5px 9px;font-size:11.5px"><button class="btn btn-sm">Go</button></form>
  </div>
  <div class="dx-search">🔍 <input id="dxOrderSearch" placeholder="Search order code, customer, or product…"></div>
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
  <?php endforeach; if(!$orders): ?><tr><td colspan="8"><div class="dx-empty">No Dropex orders this month.</div></td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<!-- transfer modal -->
<div class="modal-bg" id="trModal" style="z-index:99990"><form class="modal" method="post" style="width:480px;max-width:94vw">
  <div class="modal-head"><span>📦 Send Stock to Dropex</span><span class="mx" onclick="closeM('trModal')">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="transfer">
  <div class="modal-body">
    <div class="full"><label>Product</label><select name="product_id" required><option value="">—</option>
      <?php foreach($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (store: <?= (int)$p['stock'] ?>)</option><?php endforeach; ?></select></div>
    <div><label>Quantity (pcs)</label><input type="number" name="qty" min="1" required></div>
    <div><label>Note</label><input name="note" placeholder="optional"></div>
    <div class="full muted" style="font-size:11.5px">Main store stock decreases (FIFO cost captured); the pieces then live at Dropex until delivered or returned.</div>
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
  <div class="modal-head"><span>± Adjust Dropex Stock</span><span class="mx" onclick="closeM('adjModal')">✕</span></div>
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
wireSearch('dxStockSearch','.dx-row[data-s]');
wireSearch('dxCustSearch','.dx-crow[data-s]');
wireSearch('dxOrderSearch','tr[data-s]');

var mvShowAll = document.getElementById('dxMvShowAll');
if (mvShowAll) {
  mvShowAll.addEventListener('click', function(){
    document.querySelectorAll('.dx-mv-extra').forEach(function(row){
      if (row.style.display === 'none') row.style.display = '';
    });
    var total = mvShowAll.getAttribute('data-total');
    var countTag = document.getElementById('dxMvCount');
    if (countTag) countTag.textContent = total + ' of ' + total;
    mvShowAll.remove();
  });
}
</script>
<?php require __DIR__.'/includes/footer.php'; ?>