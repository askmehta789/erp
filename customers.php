<?php
/* ============================================================
   CUSTOMER INSIGHTS — single merged page (previously split across
   customers.php "Customer Analytics" and analytics_customers.php
   "Customer Insights"). All-time by design: a repeat-rate or LTV
   number computed over just a week is noise, not insight.
   ============================================================ */
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
repair_zero_cost_profit();
$PAGE_TITLE='Customer Insights';

$pid = (int)($_GET['pid'] ?? 0);
$pw  = $pid ? " AND o.product_id = ".$pid : "";
$allProducts = rows("SELECT id,name FROM products ORDER BY name");
$pName = $pid ? (string)val("SELECT name FROM products WHERE id=?",[$pid]) : '';

$orders = rows("SELECT o.phone, o.customer, o.sell_price, o.cost_price, o.delivery_charge, o.cancel_charge, o.qty, o.status, o.order_date, o.product_id
                FROM orders o WHERE (COALESCE(o.customer,'')<>'' OR COALESCE(o.phone,'')<>'') $pw ORDER BY o.order_date");

/* ---- per-customer roll-up — grouped by phone, falling back to name so
   walk-in / no-phone customers are still counted (not just skipped) ---- */
$cust=[];
foreach($orders as $o){
  $phone = trim((string)$o['phone']);
  $key = $phone !== '' ? $phone : 'name:'.strtolower(trim((string)$o['customer']));
  if(!isset($cust[$key])) $cust[$key]=['name'=>$o['customer'],'phone'=>$phone,'orders'=>0,'delivered'=>0,'bad'=>0,'revenue'=>0,'profit'=>0,'first'=>$o['order_date'],'last'=>$o['order_date']];
  $c=&$cust[$key];
  $c['orders']++;
  if($o['customer'] && !$c['name']) $c['name']=$o['customer'];
  if($o['order_date']<$c['first']) $c['first']=$o['order_date'];
  if($o['order_date']>$c['last'])  $c['last']=$o['order_date'];
  if($o['status']==='delivered'){
    $c['delivered']++;
    $c['revenue']+=(float)$o['sell_price']*(int)$o['qty'];
    $c['profit']+=((float)$o['sell_price']-(float)$o['cost_price'])*(int)$o['qty']-(float)$o['delivery_charge'];
  } elseif(in_array($o['status'],['returned','cancelled'],true)) {
    $c['bad']++;
    $c['profit']-=(float)$o['cancel_charge'];
  }
  unset($c);
}
foreach($cust as &$c){
  $c['retRate'] = $c['orders'] ? round($c['bad']/$c['orders']*100) : 0;
  $c['risky']   = ($c['bad']>=2) || ($c['orders']>=2 && $c['retRate']>=50);
  $c['repeat']  = $c['delivered']>=2;
  $c['oneTime'] = $c['delivered']===1;
} unset($c);

$totalCustomers = count($cust);
$repeatCust  = array_filter($cust, fn($c)=>$c['repeat']);
$riskyCust   = array_filter($cust, fn($c)=>$c['risky']);
$repeatCount = count($repeatCust);
$repeatRate  = $totalCustomers ? round($repeatCount/$totalCustomers*100,1) : 0;
$totalOrders = count($orders);
$avgOrdersPerCust = $totalCustomers ? round($totalOrders/$totalCustomers,2) : 0;
$totalRevenue   = array_sum(array_column($cust,'revenue'));
$avgLTV         = $totalCustomers ? round($totalRevenue/$totalCustomers) : 0;
$repeatRevenue  = array_sum(array_map(fn($c)=>$c['revenue'], $repeatCust));
$oneTimeRevenue = $totalRevenue - $repeatRevenue;

/* avg days between 1st and 2nd order, for customers who repeated */
$gaps=[];
foreach($cust as $c){ if($c['repeat']){ $g=(strtotime($c['last'])-strtotime($c['first']))/86400; if($g>0) $gaps[]=$g; } }
$avgGapDays = $gaps ? round(array_sum($gaps)/count($gaps)) : null;

/* value tiers (every customer lands in exactly one) */
$tiers=['No Completed Order'=>0,'One-time'=>0,'Repeat (2-4)'=>0,'Loyal (5+)'=>0];
foreach($cust as $c){
  if($c['delivered']>=5) $tiers['Loyal (5+)']++;
  elseif($c['delivered']>=2) $tiers['Repeat (2-4)']++;
  elseif($c['delivered']===1) $tiers['One-time']++;
  else $tiers['No Completed Order']++;
}

/* top 10 by lifetime value */
$byLtv = $cust; uasort($byLtv, fn($a,$b)=>$b['revenue']<=>$a['revenue']);
$top10 = array_slice($byLtv,0,10,true);

/* monthly new-vs-repeat DELIVERED orders (a customer's first-ever order
   month makes that month's order "new"; every later month is "repeat") */
$firstSeen=[];
foreach($orders as $o){
  $phone=trim((string)$o['phone']); $key=$phone!==''?$phone:'name:'.strtolower(trim((string)$o['customer']));
  if(!isset($firstSeen[$key])) $firstSeen[$key]=$o['order_date'];
}
$monthly=[];
foreach($orders as $o){
  if($o['status']!=='delivered') continue;
  $phone=trim((string)$o['phone']); $key=$phone!==''?$phone:'name:'.strtolower(trim((string)$o['customer']));
  $mk=date('Y-m',strtotime($o['order_date']));
  if(!isset($monthly[$mk])) $monthly[$mk]=['new'=>0,'repeat'=>0];
  if(date('Y-m',strtotime($firstSeen[$key]))===$mk) $monthly[$mk]['new']++; else $monthly[$mk]['repeat']++;
}
ksort($monthly);
$monthly = array_slice($monthly, -12, null, true);
$mLabels = array_map(fn($k)=>date('M Y',strtotime($k.'-01')), array_keys($monthly));
$mNew    = array_map(fn($v)=>$v['new'], array_values($monthly));
$mRepeat = array_map(fn($v)=>$v['repeat'], array_values($monthly));

/* table filter tabs */
$f = $_GET['f'] ?? 'all';
$list = $cust;
if($f==='repeat')      $list = $repeatCust;
elseif($f==='risky')   $list = $riskyCust;
elseif($f==='new')     $list = array_filter($cust, fn($c)=>$c['oneTime']);

require __DIR__.'/includes/header.php';
?>
<style>
.ci-hero{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:linear-gradient(120deg,#7c3aed,#4338ca);border-radius:18px;padding:22px 26px;color:#fff;margin-bottom:18px;box-shadow:0 14px 34px rgba(76,29,149,.25)}
.ci-hero h1{font-size:22px;font-weight:900;margin:0}
.ci-hero p{opacity:.85;font-size:12.5px;margin:2px 0 0}
.ci-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
.ci-tile{background:rgba(255,255,255,.72);backdrop-filter:blur(18px) saturate(1.6);border:1px solid rgba(255,255,255,.7);border-radius:16px;padding:15px 17px;box-shadow:0 8px 22px rgba(30,41,80,.08)}
.ci-tile .v{font-size:22px;font-weight:900;color:#1e2334;line-height:1.15}
.ci-tile .l{font-size:11.5px;color:#5a6580;font-weight:700;margin-top:3px}
.ci-tile .s{font-size:10.5px;color:#8a93a8;margin-top:1px}
.ci-tile.risk{background:rgba(254,226,226,.85)}
.ci-tile.risk .v{color:#b91c1c}
.ci-grid{display:grid;grid-template-columns:1.3fr 1fr;gap:16px;align-items:start;margin-bottom:16px}
.ci-panel{background:rgba(255,255,255,.72);backdrop-filter:blur(18px) saturate(1.6);border:1px solid rgba(255,255,255,.7);border-radius:16px;box-shadow:0 8px 22px rgba(30,41,80,.08);overflow:hidden}
.ci-panel-h{padding:14px 18px;font-weight:800;font-size:13.5px;border-bottom:1px solid rgba(200,210,230,.3);display:flex;align-items:center;justify-content:space-between}
.ci-panel-b{padding:16px 18px}
.lb-row{display:flex;align-items:center;gap:11px;padding:9px 0;border-bottom:1px solid rgba(210,218,235,.35)}
.lb-row:last-child{border-bottom:0}
.lb-rank{width:22px;height:22px;border-radius:99px;background:#f1f0ff;color:#7c3aed;font-weight:900;font-size:11px;display:flex;align-items:center;justify-content:center;flex:none}
.lb-rank.g1{background:#fef3c7;color:#b45309}.lb-rank.g2{background:#e5e7eb;color:#4b5563}.lb-rank.g3{background:#fed7aa;color:#c2410c}
.lb-name{font-weight:800;font-size:12.5px}
.lb-sub{font-size:10.5px;color:var(--muted)}
.lb-bar-wrap{flex:1;background:#eef0f8;border-radius:99px;height:7px;overflow:hidden;min-width:60px}
.lb-bar{height:100%;background:linear-gradient(90deg,#a78bfa,#7c3aed);border-radius:99px}
.lb-amt{font-weight:900;font-size:12.5px;white-space:nowrap;color:#1e2334}
@media(max-width:900px){.ci-grid{grid-template-columns:1fr !important}}
@media(max-width:640px){
  .ci-hero{padding:16px 18px}
  .ci-hero h1{font-size:18px}
  .ci-hero form select{width:100%}
  .ci-tile{padding:12px 13px}
  .ci-tile .v{font-size:18px}
  .lb-row{flex-wrap:wrap;gap:6px 10px}
  .lb-name,.lb-sub{min-width:0}
  .lb-bar-wrap{order:3;flex:1 1 100%;min-width:100%;margin:2px 0 0 33px;width:calc(100% - 33px)}
  .ncm-search{width:100%;max-width:100%}
}
</style>

<div class="ci-hero">
  <span style="font-size:32px">👥</span>
  <div style="flex:1;min-width:220px">
    <h1>Customer Insights</h1>
    <p>All-time view<?= $pName?' — '.e($pName).' only':'' ?> — who keeps coming back, what they're worth, and who's a return risk</p>
  </div>
  <form method="get" style="display:inline">
    <select name="pid" onchange="this.form.submit()" style="border-radius:99px;border:1px solid rgba(255,255,255,.5);background:rgba(255,255,255,.15);color:#fff;padding:8px 14px;font:inherit;font-size:12.5px">
      <option value="0" style="color:#1e2334">All products</option>
      <?php foreach($allProducts as $pp): ?><option value="<?= (int)$pp['id'] ?>" style="color:#1e2334" <?= $pid===(int)$pp['id']?'selected':'' ?>><?= e($pp['name']) ?></option><?php endforeach; ?>
    </select>
  </form>
</div>

<div class="ci-tiles">
  <div class="ci-tile"><div class="v"><?= number_format($totalCustomers) ?></div><div class="l">Unique Customers</div></div>
  <div class="ci-tile"><div class="v" style="color:#7c3aed"><?= $repeatRate ?>%</div><div class="l">Repeat Rate</div><div class="s"><?= $repeatCount ?> of <?= $totalCustomers ?></div></div>
  <div class="ci-tile"><div class="v"><?= $avgOrdersPerCust ?></div><div class="l">Avg Orders / Customer</div></div>
  <div class="ci-tile"><div class="v" style="font-size:18px"><?= money($avgLTV) ?></div><div class="l">Avg Lifetime Value</div></div>
  <div class="ci-tile"><div class="v"><?= $avgGapDays!==null ? $avgGapDays.'d' : '—' ?></div><div class="l">Avg Time to 2nd Order</div></div>
  <a class="ci-tile risk" style="text-decoration:none;display:block" href="customers.php?f=risky<?= $pid?'&pid='.$pid:'' ?>#allcust"><div class="v"><?= count($riskyCust) ?></div><div class="l">Return-Risk</div><div class="s">2+ returns or ≥50%</div></a>
</div>

<?php if($riskyCust): ?>
<div class="panel" style="margin-bottom:16px;border:1px solid var(--red-bg)">
  <div class="panel-head"><h2 style="color:var(--red)">🚩 Return-Risk Customers — confirm carefully before dispatch</h2></div>
  <div class="table-wrap"><table class="tbl"><thead><tr><th>Customer</th><th>Phone</th><th class="right">Orders</th><th class="right">Returned</th><th class="right">Return %</th><th>Last Order</th></tr></thead><tbody>
  <?php foreach($riskyCust as $c): ?>
    <tr style="background:var(--red-bg)"><td><b><?= e($c['name']?:'—') ?></b></td><td class="num"><?= e($c['phone']?:'—') ?></td>
    <td class="num right"><?= $c['orders'] ?></td><td class="num right"><?= $c['bad'] ?></td>
    <td class="num right"><b><?= $c['retRate'] ?>%</b></td><td class="num muted"><?= e(date('d M Y',strtotime($c['last']))) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<div class="ci-grid">
  <div class="ci-panel">
    <div class="ci-panel-h">📊 New vs Repeat Orders — Monthly</div>
    <div class="ci-panel-b"><div class="chart-box" style="height:230px"><canvas id="cMonthly"></canvas></div></div>
  </div>
  <div class="ci-panel">
    <div class="ci-panel-h">🍩 Customer Value Tiers</div>
    <div class="ci-panel-b"><div class="chart-box" style="height:230px"><canvas id="cTiers"></canvas></div></div>
  </div>
</div>

<div class="ci-grid" style="grid-template-columns:1fr 1fr">
  <div class="ci-panel">
    <div class="ci-panel-h">💰 Revenue: New vs Repeat Customers</div>
    <div class="ci-panel-b"><div class="chart-box" style="height:210px"><canvas id="cRevSplit"></canvas></div></div>
  </div>
  <div class="ci-panel">
    <div class="ci-panel-h">🏆 Top 10 Customers by Lifetime Value</div>
    <div class="ci-panel-b">
      <?php $i=0; $maxRev = $top10 ? max(array_column($top10,'revenue')) : 1; foreach($top10 as $key=>$c): $i++;
        $rankCls = $i===1?'g1':($i===2?'g2':($i===3?'g3':'')); $pct = $maxRev>0 ? round($c['revenue']/$maxRev*100) : 0; ?>
      <div class="lb-row">
        <span class="lb-rank <?= $rankCls ?>"><?= $i ?></span>
        <div style="min-width:110px">
          <div class="lb-name"><?= e($c['name']?:'—') ?></div>
          <div class="lb-sub"><?= e($c['phone']?:'no phone') ?> · <?= $c['delivered'] ?> orders</div>
        </div>
        <div class="lb-bar-wrap"><div class="lb-bar" style="width:<?= $pct ?>%"></div></div>
        <span class="lb-amt"><?= money($c['revenue']) ?></span>
      </div>
      <?php endforeach; if(!$top10): ?><div class="empty">No delivered orders yet.</div><?php endif; ?>
    </div>
  </div>
</div>

<div class="panel" style="margin-top:0" id="allcust">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px"><h2>📋 All Customers</h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <?php foreach(['all'=>'All','repeat'=>'Repeat','new'=>'One-time','risky'=>'Risky'] as $k=>$lbl): ?>
        <a class="btn btn-sm <?= $f===$k?'btn-primary':'' ?>" href="customers.php?f=<?= $k ?><?= $pid?'&pid='.$pid:'' ?>#allcust"><?= $lbl ?></a>
      <?php endforeach; ?>
      <input id="custSearch" class="ncm-search" placeholder="🔍 name or phone…" oninput="custFilter()">
      <button class="btn btn-sm" onclick="custCSV()">⬇ CSV</button>
    </div>
  </div>
  <div class="table-wrap"><table class="tbl num-tbl" id="custTbl">
    <thead><tr><th>Customer</th><th>Phone</th><th class="right">Orders</th><th class="right">Delivered</th><th class="right">Returned/Cancelled</th><th class="right">Return %</th><th class="right">Revenue</th><th class="right">Profit</th><th>Type</th><th>First Order</th><th>Last Order</th></tr></thead><tbody>
    <?php foreach($list as $key=>$c): ?>
    <tr data-s="<?= e(mb_strtolower(($c['name']?:'').' '.$c['phone'])) ?>"<?= $c['risky']?' style="background:var(--red-bg)"':'' ?>>
      <td><b><?= e($c['name']?:'—') ?></b></td>
      <td class="muted"><?= e($c['phone']?:'—') ?></td>
      <td class="right"><?= $c['orders'] ?></td>
      <td class="right" style="color:var(--green)"><?= $c['delivered'] ?></td>
      <td class="right" style="color:<?= $c['bad']>0?'var(--red)':'var(--muted)' ?>"><?= $c['bad'] ?></td>
      <td class="right"><?= $c['retRate'] ?>%</td>
      <td class="right" style="font-weight:800"><?= money($c['revenue']) ?></td>
      <td class="right" style="color:<?= $c['profit']>=0?'var(--green)':'var(--red)' ?>"><?= money($c['profit']) ?></td>
      <td><?= $c['risky']?'<span class="pill p-red">Risky</span>':($c['repeat']?'<span class="pill p-green">Repeat</span>':'<span class="pill p-grey">New</span>') ?></td>
      <td class="muted"><?= e(date('d M Y',strtotime($c['first']))) ?></td>
      <td class="muted"><?= e(date('d M Y',strtotime($c['last']))) ?></td>
    </tr>
    <?php endforeach; if(!$list): ?><tr><td colspan="11"><div class="empty">No customers in this view yet.</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<script>
var CH_GRID = getComputedStyle(document.body).getPropertyValue('--border') || '#e5e7eb';
var base = {responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}}};

new Chart(cMonthly,{type:'bar',data:{labels:<?= json_encode($mLabels) ?>,datasets:[
  {label:'New customers',data:<?= json_encode($mNew) ?>,backgroundColor:'#7c3aed',borderRadius:4},
  {label:'Repeat customers',data:<?= json_encode($mRepeat) ?>,backgroundColor:'#c4b5fd',borderRadius:4}
]},options:Object.assign({},base,{plugins:{legend:{display:true,position:'bottom',labels:{boxWidth:10,font:{size:10}}}},scales:{x:{stacked:true,grid:{display:false}},y:{stacked:true,beginAtZero:true,grid:{color:CH_GRID},ticks:{precision:0}}}})});

new Chart(cTiers,{type:'doughnut',data:{labels:<?= json_encode(array_keys($tiers)) ?>,datasets:[{data:<?= json_encode(array_values($tiers)) ?>,backgroundColor:['#e5e7eb','#c4b5fd','#8b5cf6','#5b21b6'],borderWidth:0}]},
  options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{display:true,position:'bottom',labels:{boxWidth:10,font:{size:10.5},padding:14}}}}});

new Chart(cRevSplit,{type:'bar',data:{labels:['Revenue Source'],datasets:[
  {label:'One-time customers',data:[<?= (float)$oneTimeRevenue ?>],backgroundColor:'#c4b5fd',borderRadius:6},
  {label:'Repeat customers',data:[<?= (float)$repeatRevenue ?>],backgroundColor:'#7c3aed',borderRadius:6}
]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:true,position:'bottom',labels:{boxWidth:10,font:{size:10.5}}},tooltip:{callbacks:{label:function(c){return c.dataset.label+': Rs. '+c.raw.toLocaleString('en-IN');}}}},scales:{x:{stacked:true,beginAtZero:true,grid:{color:CH_GRID},ticks:{callback:v=>v>=1000?(v/1000)+'K':v}},y:{stacked:true,grid:{display:false}}}}});

function custFilter(){
  var q=(document.getElementById('custSearch').value||'').toLowerCase().trim();
  document.querySelectorAll('#custTbl tbody tr[data-s]').forEach(function(tr){
    tr.style.display=(!q||(tr.getAttribute('data-s')||'').indexOf(q)!==-1)?'':'none';
  });
}
function custCSV(){
  var lines=[['Name','Phone','Orders','Delivered','Returned/Cancelled','Return %','Revenue','Profit','Type','First','Last'].join(',')];
  document.querySelectorAll('#custTbl tbody tr[data-s]').forEach(function(tr){
    if(tr.style.display==='none')return;
    var c=tr.querySelectorAll('td');
    var vals=[]; for(var i=0;i<c.length;i++) vals.push(c[i].innerText);
    lines.push(vals.map(function(v){return '"'+String(v).replace(/"/g,'""')+'"';}).join(','));
  });
  var blob=new Blob([lines.join('\n')],{type:'text/csv'});
  var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='customer_insights.csv';a.click();
}
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
