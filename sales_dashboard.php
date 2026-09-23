<?php
/* =====================================================================
   sales_dashboard.php — Sales Team performance & leaderboard
   Ranks salespeople (employees, department='Sales') by delivered revenue,
   with quantity, orders, returns/cancellations and bonus paid.
   ===================================================================== */
require_once __DIR__.'/functions.php';
require_login(); require_page_access();
$PAGE_TITLE = 'Sales Team';

try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS sales_person_id INT NULL AFTER code"); } catch (Exception $e) {}
try { q("ALTER TABLE orders ADD INDEX idx_sales_person_id (sales_person_id)"); } catch (Exception $e) {}
ensure_company_salesperson();

/* ---- date range: This Month / Last Month / This Year / All Time / Custom ---- */
$range = in_array($_GET['range'] ?? '', ['month','last_month','year','all','custom'], true) ? $_GET['range'] : 'month';
if ($range === 'custom' && !empty($_GET['from']) && !empty($_GET['to'])) {
    $from = $_GET['from']; $to = $_GET['to'];
} elseif ($range === 'last_month') {
    $from = date('Y-m-01', strtotime('first day of last month'));
    $to   = date('Y-m-t',  strtotime('last day of last month'));
} elseif ($range === 'year') {
    $from = date('Y-01-01'); $to = date('Y-12-31');
} elseif ($range === 'all') {
    $from = null; $to = null;
} else {
    $range = 'month';
    $from = date('Y-m-01'); $to = date('Y-m-t');
}
if ($from && $to && $from > $to) { [$from,$to] = [$to,$from]; }
$dateWhere  = ($from && $to) ? "AND o.order_date BETWEEN ? AND ?" : "";
$dateParams = ($from && $to) ? [$from,$to] : [];
$rangeLabel = [
    'month'=>'This Month ('.date('F Y').')', 'last_month'=>'Last Month ('.date('F Y', strtotime('first day of last month')).')',
    'year'=>'This Year ('.date('Y').')', 'all'=>'All Time',
    'custom'=>($from && $to ? e($from).' → '.e($to) : 'Custom range'),
][$range];

/* Luprah is deliberately excluded — it's the bucket for orders that
   belong to the company itself, not to a real salesperson, so it never ranks. */
$people = rows("SELECT id,name,phone,status FROM employees WHERE department='Sales' AND is_company=0 ORDER BY (status='active') DESC, name");
$companyStat = row("SELECT COUNT(*) n,
    SUM(o.status='delivered') del,
    SUM(CASE WHEN o.status='delivered' THEN o.sell_price*o.qty ELSE 0 END) rev
    FROM orders o JOIN employees sp ON sp.id=o.sales_person_id AND sp.is_company=1 $dateWhere", $dateParams);

$stat = [];
foreach (rows("
    SELECT o.sales_person_id spid,
           COUNT(*) n,
           SUM(o.status='delivered') del,
           SUM(o.status='cancelled') cancd,
           SUM(o.status='returned') retd,
           SUM(o.status='shipped') onway,
           SUM(o.status IN ('pending','processing')) confirmed,
           SUM(CASE WHEN o.status='delivered' THEN o.qty ELSE 0 END) units,
           SUM(CASE WHEN o.status='delivered' THEN o.sell_price*o.qty ELSE 0 END) rev,
           SUM(CASE WHEN o.status='delivered' THEN (o.sell_price-o.cost_price)*o.qty-o.delivery_charge
                    WHEN o.status IN ('cancelled','returned') THEN -o.cancel_charge ELSE 0 END) prof,
           SUM(CASE WHEN o.status IN ('cancelled','returned') THEN o.cancel_charge ELSE 0 END) cancf
    FROM orders o WHERE o.sales_person_id IS NOT NULL $dateWhere GROUP BY o.sales_person_id", $dateParams) as $r) {
    $stat[(int)$r['spid']] = $r;
}

$bonus = [];
try {
    $bonusSql = "SELECT employee_id eid, SUM(amount) amt FROM salary_entries WHERE type='bonus'".
        ($from && $to ? " AND entry_date BETWEEN ? AND ?" : "")." GROUP BY employee_id";
    foreach (rows($bonusSql, $dateParams) as $r) $bonus[(int)$r['eid']] = (float)$r['amt'];
} catch (Exception $e) {}

$G = function($id,$k) use ($stat) { return isset($stat[$id]) ? (float)$stat[$id][$k] : 0; };

$ranked = [];
foreach ($people as $p) {
    $id = (int)$p['id'];
    $n  = $G($id,'n');
    $canc = $G($id,'cancd') + $G($id,'retd');
    $ranked[] = [
        'id'=>$id, 'name'=>$p['name'], 'phone'=>$p['phone'], 'status'=>$p['status'],
        'n'=>$n, 'del'=>$G($id,'del'), 'cancd'=>$G($id,'cancd'), 'retd'=>$G($id,'retd'), 'canc'=>$canc, 'onway'=>$G($id,'onway'),
        'confirmed'=>$G($id,'confirmed'), 'units'=>$G($id,'units'), 'rev'=>$G($id,'rev'),
        'prof'=>$G($id,'prof'), 'cancf'=>$G($id,'cancf'),
        'rate'=>$n ? round($G($id,'del')/$n*100) : 0,
        'bonus'=>$bonus[$id] ?? 0,
    ];
}
/* Combined performance score — revenue, order volume and return/cancel rate all
   count, not revenue alone. Each factor is normalised to 0-100 against the best
   performer in the range so no single metric (e.g. a big-ticket order) dominates
   just from scale, then blended: 50% revenue, 30% order volume, 20% low returns. */
$maxRev = 0; $maxN = 0;
foreach ($ranked as $r) { if ($r['n']<1) continue; $maxRev = max($maxRev, $r['rev']); $maxN = max($maxN, $r['n']); }
foreach ($ranked as &$r) {
    if ($r['n'] < 1) { $r['score'] = 0; continue; }
    $revScore = $maxRev > 0 ? ($r['rev'] / $maxRev * 100) : 0;
    $volScore = $maxN  > 0 ? ($r['n']   / $maxN  * 100) : 0;
    $returnRate = $r['n'] ? ($r['canc'] / $r['n'] * 100) : 0;
    $qualScore = 100 - $returnRate;
    $r['score'] = round(0.5*$revScore + 0.3*$volScore + 0.2*$qualScore, 1);
}
unset($r);
usort($ranked, function($a,$b){ return $b['score'] <=> $a['score'] ?: $b['rev'] <=> $a['rev']; });
$podium = array_slice(array_filter($ranked, fn($r)=>$r['n']>0), 0, 3);

$unassigned = row("SELECT COUNT(*) n,
    SUM(CASE WHEN o.status='delivered' THEN o.qty ELSE 0 END) units,
    SUM(CASE WHEN o.status='delivered' THEN o.sell_price*o.qty ELSE 0 END) rev
    FROM orders o WHERE o.sales_person_id IS NULL $dateWhere", $dateParams);

$tOrders=0;$tUnits=0;$tRev=0;$tCanc=0;
foreach ($ranked as $r) { $tOrders+=$r['n']; $tUnits+=$r['units']; $tRev+=$r['rev']; $tCanc+=$r['canc']; }

$medal = ['🥇','🥈','🥉'];
$chartLabels=[]; $chartDel=[]; $chartCancd=[]; $chartRetd=[];
foreach ($ranked as $i=>$r) { if ($r['n']<1) continue; $chartLabels[]=$r['name']; $chartDel[]=(int)$r['del']; $chartCancd[]=(int)$r['cancd']; $chartRetd[]=(int)$r['retd']; }

require __DIR__.'/includes/header.php';
?>
<style>
.podium{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:6px 0 4px}
.pod-card{position:relative;border-radius:16px;padding:18px 20px;background:rgba(255,255,255,.6);border:1px solid rgba(255,255,255,.9);box-shadow:var(--shadow);overflow:hidden}
body.dark .pod-card{background:rgba(17,25,44,.55);border-color:rgba(148,163,184,.16)}
.pod-card.r1{border-top:4px solid #f5b301}
.pod-card.r2{border-top:4px solid #9aa5b1}
.pod-card.r3{border-top:4px solid #c9793d}
.pod-medal{font-size:30px;line-height:1}
.pod-name{font-size:16px;font-weight:800;margin-top:4px}
.pod-sub{font-size:11.5px;color:var(--muted-2);margin-bottom:10px}
.pod-stats{display:flex;gap:16px;flex-wrap:wrap}
.pod-stats div .l{font-size:9.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted-2);font-weight:800}
.pod-stats div .v{font-size:15px;font-weight:800}
.rank-badge{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:999px;background:rgba(100,116,139,.15);font-size:11px;font-weight:800;color:var(--muted)}
.range-chips{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
.range-chips a.btn.on{background:var(--brand);border-color:var(--brand);color:#fff}
</style>

<div class="page-head">
  <div><h1>🏆 Sales Team</h1><p>Leaderboard for each salesperson — quantity sold, revenue, orders &amp; returns/cancellations · <b><?= $rangeLabel ?></b></p></div>
  <a class="btn" href="salary.php">👥 Manage Sales Staff</a>
</div>

<div class="card" style="margin-bottom:18px;padding:14px 16px">
  <form method="get" class="toolbar range-chips">
    <a class="btn btn-sm<?= $range==='month'?' on':'' ?>" href="?range=month">This Month</a>
    <a class="btn btn-sm<?= $range==='last_month'?' on':'' ?>" href="?range=last_month">Last Month</a>
    <a class="btn btn-sm<?= $range==='year'?' on':'' ?>" href="?range=year">This Year</a>
    <a class="btn btn-sm<?= $range==='all'?' on':'' ?>" href="?range=all">All Time</a>
    <span style="width:1px;height:26px;background:var(--border);margin:0 2px"></span>
    <input type="hidden" name="range" value="custom">
    <div><label style="font-size:12px;color:var(--muted);font-weight:700">From</label><br><input type="date" name="from" value="<?= e($from ?: '') ?>"></div>
    <div><label style="font-size:12px;color:var(--muted);font-weight:700">To</label><br><input type="date" name="to" value="<?= e($to ?: '') ?>"></div>
    <button class="btn btn-sm btn-primary">Apply</button>
  </form>
</div>

<?php if ((int)($unassigned['n'] ?? 0) > 0): ?>
<div class="flash" style="background:var(--amber-bg,#fef3c7);color:#8a5a00;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
  <span>⚠️ <b><?= number_format((int)$unassigned['n']) ?> order(s)</b> (<?= money($unassigned['rev'] ?? 0) ?> delivered revenue) have no sales person assigned yet.
  Set one on the <a href="sales.php" style="color:#8a5a00;font-weight:800;text-decoration:underline">Sales sheet</a>, or bulk-tag them all as company sales:</span>
  <button class="btn btn-sm btn-primary" style="margin-left:auto" id="assignLuprahBtn" onclick="assignUnassignedToLuprah()">🏢 Assign all to Luprah</button>
</div>
<?php endif; ?>

<?php if ((int)($companyStat['n'] ?? 0) > 0): ?>
<div class="flash" style="background:rgba(99,102,241,.1);color:#4338ca">
  🏢 <b><?= number_format((int)$companyStat['n']) ?> order(s)</b> (<?= money($companyStat['rev'] ?? 0) ?> delivered revenue) are tagged
  <b>Luprah</b> — company sales with no individual dealer. Excluded from the leaderboard below on purpose.
</div>
<?php endif; ?>

<div class="mgrid">
  <div class="metric blue"><div><div class="mv"><?= count($people) ?></div><div class="ml">Sales Staff</div><div class="ms">active + inactive</div></div><div class="mi">👤</div></div>
  <div class="metric indigo"><div><div class="mv"><?= number_format($tOrders) ?></div><div class="ml">Attributed Orders</div><div class="ms">has a sales person</div></div><div class="mi">📦</div></div>
  <div class="metric green"><div><div class="mv" style="font-size:20px"><?= money($tRev) ?></div><div class="ml">Delivered Revenue</div><div class="ms">by sales team</div></div><div class="mi">💰</div></div>
  <div class="metric teal"><div><div class="mv"><?= number_format($tUnits) ?></div><div class="ml">Units Delivered</div><div class="ms">total quantity</div></div><div class="mi">🏷️</div></div>
  <div class="metric red"><div><div class="mv"><?= number_format($tCanc) ?></div><div class="ml">Returned / Cancelled</div><div class="ms">across sales team</div></div><div class="mi">↩️</div></div>
</div>

<?php if ($podium): ?>
<div class="dash-sec" style="margin:20px 0 10px">Top Performers</div>
<div class="podium">
  <?php foreach ($podium as $i=>$r): ?>
  <div class="pod-card r<?= $i+1 ?>">
    <div class="pod-medal"><?= $medal[$i] ?? '' ?></div>
    <div class="pod-name"><?= e($r['name']) ?></div>
    <div class="pod-sub">#<?= $i+1 ?> · <?= (int)$r['n'] ?> orders · <?= $r['rate'] ?>% delivery rate</div>
    <div class="pod-stats">
      <div><div class="l">Score</div><div class="v" style="color:var(--brand)"><?= $r['score'] ?></div></div>
      <div><div class="l">Revenue</div><div class="v" style="color:var(--green)"><?= money($r['rev']) ?></div></div>
      <div><div class="l">Units</div><div class="v"><?= number_format($r['units']) ?></div></div>
      <div><div class="l">Returns</div><div class="v" style="color:var(--red)"><?= (int)$r['canc'] ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($chartLabels): ?>
<div class="dash-sec" style="margin:20px 0 10px">Delivered vs Cancelled vs Returned — by Salesperson</div>
<div class="panel"><div class="panel-body" style="height:320px"><canvas id="chSpRev"></canvas></div></div>
<?php endif; ?>

<div class="dash-sec" style="margin:20px 0 10px">Full Leaderboard <span style="text-transform:none;font-weight:600;color:var(--muted-2)">— ranked by combined performance score (revenue, order volume &amp; return rate)</span></div>
<?php if (!$people): ?>
<div class="panel"><div class="panel-body">
  No sales staff found yet. Add an employee with department <b>Sales</b> in
  <a href="salary.php">Staff &amp; Salary → Staff Directory</a> to start tracking them here.
</div></div>
<?php else: ?>
<div class="cour-cards">
<?php foreach ($ranked as $i=>$r):
  $dp=$r['rate']; $cp=$r['n']?round($r['canc']/$r['n']*100):0;
  $avg=$r['del']?round($r['rev']/$r['del']):0;
?>
  <div class="ccard" style="--ccol:<?= $i<3?['#f5b301','#9aa5b1','#c9793d'][$i]:'#6366f1' ?>">
    <div class="ccard-top">
      <div>
        <div class="ccard-name"><span class="rank-badge">#<?= $i+1 ?></span> <?= e($r['name']) ?>
          <?= $r['status']!=='active'?'<span class="pill p-red" style="font-size:9px">inactive</span>':'' ?></div>
        <div class="ccard-sub"><?= (int)$r['n'] ?> total orders · <?= number_format($r['units']) ?> pcs delivered</div>
      </div>
      <div class="ccard-rates">
        <span style="color:var(--brand);font-size:13px">⭐ <?= $r['score'] ?> score</span>
        <span style="color:<?= $dp>=85?'var(--green)':($dp>=60?'var(--amber)':'var(--red)') ?>"><?= $dp ?>% delivery</span>
        <span style="color:<?= $cp>=15?'var(--red)':'var(--muted-2)' ?>"><?= $cp ?>% returned/cancelled</span>
      </div>
    </div>
    <div class="ccard-bar"><i style="width:<?= $dp ?>%"></i></div>
    <div class="ccard-chips">
      <?php if($r['phone']): ?><span>📞 <?= e($r['phone']) ?></span><?php endif; ?>
      <span title="all-time bonus paid via Staff Salary">🎁 Bonus paid: <?= money($r['bonus']) ?></span>
    </div>
    <div class="ccard-grid">
      <div class="cb cb-g"><div class="cbl">✅ Delivered</div><div class="cbv"><?= (int)$r['del'] ?></div><div class="cbs"><?= money($r['rev']) ?></div></div>
      <div class="cb cb-r"><div class="cbl">✕ Returned/Cancelled</div><div class="cbv"><?= (int)$r['canc'] ?></div><div class="cbs">Charge: <?= money($r['cancf']) ?></div></div>
      <div class="cb cb-y"><div class="cbl">🚚 On the way</div><div class="cbv"><?= (int)$r['onway'] ?></div></div>
      <div class="cb cb-b"><div class="cbl">📋 Confirmed</div><div class="cbv"><?= (int)$r['confirmed'] ?></div></div>
    </div>
    <div class="ccard-foot">
      <div><div class="fl">Avg Order</div><div class="fv"><?= money($avg) ?></div></div>
      <div><div class="fl">Net Profit</div><div class="fv" style="color:<?= $r['prof']>=0?'var(--green)':'var(--red)' ?>"><?= money($r['prof']) ?></div></div>
      <div style="margin-left:auto"><a class="btn btn-sm" href="sales.php?q=<?= urlencode($r['name']) ?>">View orders →</a></div>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($chartLabels): ?>
<script>
var isDark=document.body.classList.contains('dark');
var gcol=isDark?'rgba(148,163,184,.15)':'rgba(100,116,139,.12)';
var tcol=isDark?'#94a3b8':'#475569';
new Chart(document.getElementById('chSpRev'),{type:'bar',
  data:{labels:<?= json_encode($chartLabels) ?>,datasets:[
    {label:'Delivered',data:<?= json_encode($chartDel) ?>,backgroundColor:'#10b981',borderRadius:4,maxBarThickness:56,categoryPercentage:0.6,barPercentage:0.9},
    {label:'Cancelled',data:<?= json_encode($chartCancd) ?>,backgroundColor:'#ef4444',borderRadius:4,maxBarThickness:56,categoryPercentage:0.6,barPercentage:0.9},
    {label:'Returned',data:<?= json_encode($chartRetd) ?>,backgroundColor:'#f59e0b',borderRadius:4,maxBarThickness:56,categoryPercentage:0.6,barPercentage:0.9}
  ]},
  options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
    plugins:{legend:{position:'bottom',labels:{color:tcol,font:{size:11},boxWidth:12}},
      tooltip:{callbacks:{footer:function(items){var t=0;items.forEach(function(i){t+=i.parsed.y;});return 'Total: '+t+' orders';}}}},
    scales:{x:{stacked:true,grid:{display:false},ticks:{color:tcol,font:{size:10}}},
            y:{stacked:true,beginAtZero:true,grid:{color:gcol},ticks:{color:tcol,font:{size:10},precision:0}}}}});
</script>
<?php endif; ?>

<script>
var CSRF=<?= json_encode(csrf()) ?>, RANGE_FROM=<?= json_encode($from) ?>, RANGE_TO=<?= json_encode($to) ?>;
function assignUnassignedToLuprah(){
  var n=<?= (int)($unassigned['n'] ?? 0) ?>;
  var scope=(RANGE_FROM&&RANGE_TO)?' in the selected date range':' (all time)';
  if(!confirm('Assign all '+n+' unassigned order(s)'+scope+' to Luprah (company sales)?'))return;
  var btn=document.getElementById('assignLuprahBtn'); if(btn){btn.disabled=true;btn.textContent='Assigning…';}
  var body='op=assign_unassigned_to_company&csrf='+encodeURIComponent(CSRF)
    +'&from='+encodeURIComponent(RANGE_FROM||'')+'&to='+encodeURIComponent(RANGE_TO||'');
  fetch('sales.php?ajax=1',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body:body})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.ok){ alert('Assigned '+d.updated+' order(s) to Luprah.'); window.location.reload(); }
      else { alert('Could not assign: '+(d.error||'unknown error')); if(btn){btn.disabled=false;btn.textContent='🏢 Assign all to Luprah';} }
    })
    .catch(function(e){ alert('Network error: '+e.message); if(btn){btn.disabled=false;btn.textContent='🏢 Assign all to Luprah';} });
}
</script>

<?php require __DIR__.'/includes/footer.php'; ?>
