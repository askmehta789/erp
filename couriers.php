<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
$PAGE_TITLE='Courier Center';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;

/* ---- extend couriers table (safe re-run) ---- */
foreach ([
  "ALTER TABLE couriers ADD COLUMN color VARCHAR(9) DEFAULT '#6366f1'",
  "ALTER TABLE couriers ADD COLUMN phone VARCHAR(40) DEFAULT ''",
  "ALTER TABLE couriers ADD COLUMN contact_person VARCHAR(120) DEFAULT ''",
  "ALTER TABLE couriers ADD COLUMN default_delivery DECIMAL(10,2) DEFAULT 0",
  "ALTER TABLE couriers ADD COLUMN cancel_charge DECIMAL(10,2) DEFAULT 100",
  "ALTER TABLE couriers ADD COLUMN note VARCHAR(255) DEFAULT ''",
  "ALTER TABLE couriers ADD COLUMN status VARCHAR(20) DEFAULT 'active'",
] as $ddl) { try { q($ddl); } catch (Exception $e) {} }

/* ---- actions ---- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act = $_POST['_action'] ?? '';
  if ($act==='save') {
    $id=(int)($_POST['id'] ?? 0);
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#6366f1';
    $f=[trim($_POST['name'] ?? ''), $color,
        in_array($_POST['status'] ?? '', ['active','inactive'], true)?$_POST['status']:'active',
        trim($_POST['phone'] ?? ''), trim($_POST['contact_person'] ?? ''),
        (float)($_POST['default_delivery'] ?? 0), (float)($_POST['cancel_charge'] ?? 100),
        trim($_POST['note'] ?? '')];
    if ($f[0]==='') { flash('Name is required.'); header('Location: couriers.php'); exit; }
    if ($id) {
      q("UPDATE couriers SET name=?,color=?,status=?,phone=?,contact_person=?,default_delivery=?,cancel_charge=?,note=? WHERE id=?", array_merge($f,[$id]));
      log_activity('Courier updated: '.$f[0],'Couriers'); flash('Courier updated.');
    } else {
      q("INSERT INTO couriers(name,color,status,phone,contact_person,default_delivery,cancel_charge,note) VALUES(?,?,?,?,?,?,?,?)",$f);
      log_activity('Courier added: '.$f[0],'Couriers'); flash('Courier added.');
    }
    header('Location: couriers.php'); exit;
  }
  if ($act==='delete' && $isAdmin) {
    $id=(int)($_POST['id'] ?? 0);
    $n=(int)val("SELECT COUNT(*) FROM orders WHERE courier_id=?",[$id]);
    if ($n>0) flash("Cannot delete — $n orders use this courier. Set it Inactive instead.");
    else { q("DELETE FROM couriers WHERE id=?",[$id]); flash('Courier deleted.'); }
    header('Location: couriers.php'); exit;
  }
}

$couriers = rows("SELECT * FROM couriers ORDER BY id");
$agingDays = max(1,(int)setting('sales_aging_days',3));

/* per-courier aggregates (all orders) */
$stat=[];
foreach (rows("SELECT o.courier_id cid,
        COUNT(*) n,
        SUM(o.status='delivered') del,
        SUM(o.status IN ('cancelled','returned')) canc,
        SUM(o.status IN ('shipped')) onway,
        SUM(o.status IN ('pending','processing')) confirmed,
        SUM(CASE WHEN o.status='delivered' THEN o.qty ELSE 0 END) units,
        SUM(CASE WHEN o.status='delivered' THEN o.sell_price*o.qty ELSE 0 END) rev,
        SUM(CASE WHEN o.status IN ('shipped') THEN o.sell_price*o.qty ELSE 0 END) onwayRev,
        SUM(CASE WHEN o.status IN ('pending','processing') THEN o.sell_price*o.qty ELSE 0 END) confRev,
        SUM(CASE WHEN o.status='delivered' THEN (o.sell_price-o.cost_price)*o.qty-o.delivery_charge
                 WHEN o.status IN ('cancelled','returned') THEN -o.cancel_charge ELSE 0 END) prof,
        SUM(CASE WHEN o.status='delivered' THEN o.delivery_charge ELSE 0 END) fees,
        SUM(CASE WHEN o.status IN ('cancelled','returned') THEN o.cancel_charge ELSE 0 END) cancf
        FROM orders o WHERE o.courier_id IS NOT NULL GROUP BY o.courier_id") as $r)
  $stat[(int)$r['cid']]=$r;

/* COD money per courier (all-time balances) */
$codC=[]; $codR=[];
foreach (rows("SELECT courier_id cid, SUM(sell_price*qty) amt FROM orders WHERE status='delivered' AND payment_type='cod' AND courier_id IS NOT NULL GROUP BY courier_id") as $r)
  $codC[(int)$r['cid']]=(float)$r['amt'];
try { foreach (rows("SELECT courier_id cid, SUM(amount) amt FROM cod_ledger WHERE type='in' GROUP BY courier_id") as $r)
  $codR[(int)$r['cid']]=(float)$r['amt']; } catch (Exception $e) {}

$G=function($id,$k)use($stat){ return isset($stat[$id])?(float)$stat[$id][$k]:0; };

/* page totals */
$tOrders=0;$tRev=0;$tProf=0;$tCharges=0;
foreach($couriers as $c){$id=(int)$c['id'];$tOrders+=$G($id,'n');$tRev+=$G($id,'rev');$tProf+=$G($id,'prof');$tCharges+=$G($id,'fees')+$G($id,'cancf');}

/* chart data */
$chartLabels=[];$chartRev=[];$chartVol=[];$chartRate=[];$chartColors=[];
foreach($couriers as $c){$id=(int)$c['id'];
  $chartLabels[]=$c['name']; $chartColors[]=$c['color']?:'#6366f1';
  $chartRev[]=round($G($id,'rev')); $chartVol[]=(int)$G($id,'n');
  $n=$G($id,'n'); $chartRate[]=$n?round($G($id,'del')/$n*100):0;}

require __DIR__.'/includes/header.php';
?>
<div class="page-head">
  <div><h1>🚚 Courier Center</h1><p>Delivery partner analytics, charges &amp; COD money</p></div>
  <button class="btn btn-primary" onclick="openCour()">＋ Add Courier</button>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="mgrid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
  <div class="metric blue"><div><div class="mv"><?= number_format($tOrders) ?></div><div class="ml">Total Orders</div><div class="ms">all couriers</div></div><div class="mi">📦</div></div>
  <div class="metric green"><div><div class="mv" style="font-size:20px"><?= money($tRev) ?></div><div class="ml">Total Revenue</div><div class="ms">delivered, product only</div></div><div class="mi">💰</div></div>
  <div class="metric teal"><div><div class="mv" style="font-size:20px"><?= money($tProf) ?></div><div class="ml">Net Profit</div><div class="ms">after cost &amp; charges</div></div><div class="mi">📈</div></div>
  <div class="metric red"><div><div class="mv" style="font-size:20px"><?= money($tCharges) ?></div><div class="ml">Total Courier Charges</div><div class="ms">delivery + cancel fees</div></div><div class="mi">💸</div></div>
</div>

<div class="dash-sec" style="margin:20px 0 10px">Analytics</div>
<div class="cour-charts">
  <div class="panel"><div class="panel-head"><h2>Revenue by Courier</h2></div><div class="panel-body"><canvas id="chRev" height="190"></canvas></div></div>
  <div class="panel"><div class="panel-head"><h2>Order Volume</h2></div><div class="panel-body"><canvas id="chVol" height="190"></canvas></div></div>
  <div class="panel"><div class="panel-head"><h2>Delivery Rate %</h2></div><div class="panel-body"><canvas id="chRate" height="190"></canvas></div></div>
</div>

<div class="dash-sec" style="margin:20px 0 10px">Courier Performance</div>
<div class="cour-cards">
<?php foreach($couriers as $c): $id=(int)$c['id']; $col=$c['color']?:'#6366f1';
  $n=$G($id,'n'); $del=$G($id,'del'); $canc=$G($id,'canc');
  $dp=$n?round($del/$n*100):0; $cp=$n?round($canc/$n*100):0;
  $hold=max(0,($codC[$id]??0)-$G($id,'fees')-$G($id,'cancf')-($codR[$id]??0));   /* net of courier charges */
  $avg=$del?round($G($id,'rev')/$del):0;
?>
  <div class="ccard" style="--ccol:<?= e($col) ?>">
    <div class="ccard-top">
      <div>
        <div class="ccard-name"><?= e($c['name']) ?> <?= ($c['status']??'active')!=='active'?'<span class="pill p-red" style="font-size:9px">inactive</span>':'' ?></div>
        <div class="ccard-sub"><?= (int)$n ?> total orders · <?= number_format($G($id,'units')) ?> pcs</div>
      </div>
      <div class="ccard-rates">
        <span style="color:<?= $dp>=85?'var(--green)':($dp>=60?'var(--amber)':'var(--red)') ?>"><?= $dp ?>% delivery</span>
        <span style="color:<?= $cp>=15?'var(--red)':'var(--muted-2)' ?>"><?= $cp ?>% cancelled</span>
      </div>
    </div>
    <div class="ccard-bar"><i style="width:<?= $dp ?>%"></i></div>
    <div class="ccard-chips">
      <span title="auto-fills Delivery on orders">🚚 <?= money($c['default_delivery'] ?? 0) ?></span>
      <span title="auto-fills Cancel Charge">↩️ <?= money($c['cancel_charge'] ?? 100) ?></span>
      <?php if($c['phone']): ?><span>📞 <?= e($c['phone']) ?></span><?php endif; ?>
      <?php if($c['contact_person']): ?><span>👤 <?= e($c['contact_person']) ?></span><?php endif; ?>
    </div>
    <div class="ccard-grid">
      <div class="cb cb-g"><div class="cbl">✅ Delivered</div><div class="cbv"><?= (int)$del ?></div><div class="cbs"><?= money($G($id,'rev')) ?></div></div>
      <div class="cb cb-r"><div class="cbl">✕ Cancelled</div><div class="cbv"><?= (int)$canc ?></div><div class="cbs">Fee: <?= money($G($id,'cancf')) ?></div></div>
      <div class="cb cb-y"><div class="cbl">🚚 On the way</div><div class="cbv"><?= (int)$G($id,'onway') ?></div><div class="cbs"><?= money($G($id,'onwayRev')) ?></div></div>
      <div class="cb cb-b"><div class="cbl">📋 Confirmed</div><div class="cbv"><?= (int)$G($id,'confirmed') ?></div><div class="cbs"><?= money($G($id,'confRev')) ?></div></div>
    </div>
    <?php if(($codC[$id]??0)>0): ?>
    <div class="ccard-cod<?= $hold>0.5?' hot':'' ?>">💰 COD: collected <b><?= money($codC[$id]??0) ?></b> · their charges <b style="color:var(--red)">− <?= money($G($id,'fees')+$G($id,'cancf')) ?></b> · released <b><?= money($codR[$id]??0) ?></b> · holding <b><?= money($hold) ?></b> <a href="cod.php">Ledger →</a></div>
    <?php endif; ?>
    <div class="ccard-foot">
      <div><div class="fl">Courier Fee</div><div class="fv" style="color:var(--red)"><?= money($G($id,'fees')+$G($id,'cancf')) ?></div></div>
      <div><div class="fl">Avg Order</div><div class="fv"><?= money($avg) ?></div></div>
      <div><div class="fl">Net Profit</div><div class="fv" style="color:<?= $G($id,'prof')>=0?'var(--green)':'var(--red)' ?>"><?= money($G($id,'prof')) ?></div></div>
      <div style="margin-left:auto;display:flex;gap:6px">
        <?php if(strtolower($c['name'])==='ncm'): ?><a class="btn btn-sm" href="ncm.php">📮</a><?php endif; ?>
        <?php if(strtolower($c['name'])==='daraz'): ?><a class="btn btn-sm" href="daraz.php">🛍</a><?php endif; ?>
        <button class="btn btn-sm" onclick='editCour(<?= json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✏️</button>
        <?php if($isAdmin): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete <?= e(addslashes($c['name'])) ?>?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-sm">🗑</button></form><?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- add/edit modal -->
<div class="modal-bg" id="courModal" style="z-index:99990">
  <div class="modal" style="width:560px;max-width:94vw">
    <div class="modal-head"><span id="courTitle">＋ Add Courier</span><span class="mx" onclick="closeCour()">✕</span></div>
    <form method="post" style="padding:18px 22px" class="fgrid">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="save"><input type="hidden" name="id" id="c_id">
      <div><label>Name *</label><input name="name" id="c_name" required></div>
      <div><label>Card Color</label><input type="color" name="color" id="c_color" value="#6366f1" style="height:38px;padding:3px"></div>
      <div><label>Status</label><select name="status" id="c_status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      <div><label>Phone</label><input name="phone" id="c_phone"></div>
      <div><label>Contact Person</label><input name="contact_person" id="c_contact"></div>
      <div><label>Default Delivery Charge (Rs.)</label><input type="number" step="any" min="0" name="default_delivery" id="c_deldef" value="0"></div>
      <div><label>Cancel/Return Charge (Rs.)</label><input type="number" step="any" min="0" name="cancel_charge" id="c_cancel" value="100"></div>
      <div class="full"><label>Note</label><input name="note" id="c_note" placeholder="e.g. weekly COD release on Sundays"></div>
      <div class="full" style="display:flex;justify-content:flex-end;gap:10px">
        <button type="button" class="btn" onclick="closeCour()">Cancel</button>
        <button class="btn btn-primary">💾 Save</button>
      </div>
    </form>
  </div>
</div>

<script>
var CL=<?= json_encode($chartLabels) ?>, CC=<?= json_encode($chartColors) ?>;
var isDark=document.body.classList.contains('dark');
var gcol=isDark?'rgba(148,163,184,.15)':'rgba(100,116,139,.12)';
var tcol=isDark?'#94a3b8':'#475569';
var base={responsive:true,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},ticks:{color:tcol,font:{size:10}}},y:{grid:{color:gcol},ticks:{color:tcol,font:{size:10}}}}};
new Chart(document.getElementById('chRev'),{type:'bar',data:{labels:CL,datasets:[{data:<?= json_encode($chartRev) ?>,backgroundColor:CC,borderRadius:6}]},options:base});
new Chart(document.getElementById('chVol'),{type:'doughnut',data:{labels:CL,datasets:[{data:<?= json_encode($chartVol) ?>,backgroundColor:CC,borderWidth:0}]},
  options:{responsive:true,cutout:'62%',plugins:{legend:{position:'bottom',labels:{color:tcol,font:{size:10},boxWidth:10}}}}});
new Chart(document.getElementById('chRate'),{type:'bar',data:{labels:CL,datasets:[{data:<?= json_encode($chartRate) ?>,backgroundColor:CC,borderRadius:6}]},
  options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},ticks:{color:tcol,font:{size:10}}},y:{min:0,max:100,grid:{color:gcol},ticks:{color:tcol,font:{size:10},callback:function(v){return v+'%';}}}}}});

function openCour(){
  document.getElementById('courTitle').textContent='＋ Add Courier';
  ['c_id','c_name','c_phone','c_contact','c_note'].forEach(function(i){document.getElementById(i).value='';});
  document.getElementById('c_status').value='active';
  document.getElementById('c_color').value='#6366f1';
  document.getElementById('c_deldef').value=0;
  document.getElementById('c_cancel').value=100;
  document.getElementById('courModal').classList.add('open');document.body.classList.add('modal-open');
  setTimeout(function(){document.getElementById('c_name').focus();},60);
}
function editCour(c){
  openCour();
  document.getElementById('courTitle').textContent='✏️ Edit '+c.name;
  document.getElementById('c_id').value=c.id;
  document.getElementById('c_name').value=c.name||'';
  document.getElementById('c_status').value=c.status||'active';
  document.getElementById('c_color').value=(c.color&&/^#[0-9a-fA-F]{6}$/.test(c.color))?c.color:'#6366f1';
  document.getElementById('c_phone').value=c.phone||'';
  document.getElementById('c_contact').value=c.contact_person||'';
  document.getElementById('c_deldef').value=c.default_delivery||0;
  document.getElementById('c_cancel').value=c.cancel_charge||100;
  document.getElementById('c_note').value=c.note||'';
}
function closeCour(){document.getElementById('courModal').classList.remove('open');document.body.classList.remove('modal-open');}
(function(){var mm=document.getElementById('courModal');mm.addEventListener('click',function(e){if(e.target===mm)closeCour();});})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
