<?php
/* ============================================================
   SALES PERSON — ANALYTICS (trend report)
   Separate from sales_dashboard.php's "Sales Team" leaderboard, which
   is a snapshot for one date range. This page tracks each salesperson
   OVER TIME (day/week/month), and can drill into one person for their
   own trend + product mix + returns/exchanges detail.
   ============================================================ */
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
repair_zero_cost_profit();
ensure_order_exchange_cols();
ensure_company_salesperson();
$PAGE_TITLE='Sales Person Analytics';

if (!function_exists('pkey')) {
function pkey($date,$g){ $ts=strtotime($date); if($g==='month')return date('Y-m',$ts); if($g==='week')return date('o-\W',$ts).sprintf('%02d',(int)date('W',$ts)); return date('Y-m-d',$ts); }
function plabel($k,$g){ if($g==='month')return date('M Y',strtotime($k.'-01')); if($g==='week')return str_replace('-W',' W',$k); return date('d M',strtotime($k)); }
}

$g    = in_array($_GET['g'] ?? '', ['day','week','month'], true) ? $_GET['g'] : 'week';
$to   = $_GET['to']   ?? date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-d', strtotime($g==='month' ? '-6 months' : ($g==='week' ? '-8 weeks' : '-30 days')));
if ($from > $to) { $t=$from; $from=$to; $to=$t; }
$spid = (int)($_GET['sp'] ?? 0);

/* Luprah (company bucket) excluded from the people list — same rule as sales_dashboard.php */
$people = rows("SELECT id,name,phone,status FROM employees WHERE department='Sales' AND is_company=0 ORDER BY (status='active') DESC, name");
$peopleById = []; foreach($people as $p) $peopleById[(int)$p['id']] = $p;
$selPerson = $spid ? ($peopleById[$spid] ?? null) : null;
if ($spid && !$selPerson) { $spid = 0; }   // unknown id — fall back to team view

$spw = $spid ? " AND o.sales_person_id = ".$spid : " AND o.sales_person_id IS NOT NULL";
$orders = rows("SELECT o.*, p.name AS product_name, sp.name AS sp_name
                FROM orders o LEFT JOIN products p ON p.id=o.product_id LEFT JOIN employees sp ON sp.id=o.sales_person_id
                WHERE o.order_date BETWEEN ? AND ? $spw ORDER BY o.order_date", [$from,$to]);

$periods=[];
foreach($orders as $o){ $k=pkey($o['order_date'],$g); if(!isset($periods[$k])) $periods[$k]=true; }
$labels=array_map(fn($k)=>plabel($k,$g), array_keys($periods));

/* ---- per-period, per-person aggregation ---- */
$ensureP = function(&$a,$k){ if(!isset($a[$k])) $a[$k]=['orders'=>0,'delivered'=>0,'units'=>0,'rev'=>0,'profit'=>0,'returned'=>0,'exchanged'=>0,'retCharge'=>0,'exCharge'=>0]; };
$P=[]; $byPerson=[]; $prodMix=[]; $remarks=[]; $recentIssues=[];
foreach(array_keys($periods) as $k) $ensureP($P,$k);
foreach($orders as $o){
  $k=pkey($o['order_date'],$g); $ensureP($P,$k);
  $pid2=(int)($o['sales_person_id'] ?? 0);
  if(!isset($byPerson[$pid2])) $byPerson[$pid2]=['name'=>$o['sp_name']?:'—','periods'=>[]];
  if(!isset($byPerson[$pid2]['periods'][$k])) $ensureP($byPerson[$pid2]['periods'],$k);
  $q=(int)$o['qty']; $st=$o['status'];
  $P[$k]['orders']++; $byPerson[$pid2]['periods'][$k]['orders']++;
  if($st==='delivered'){
    $line=(float)$o['sell_price']*$q;
    $prof=((float)$o['sell_price']-(float)$o['cost_price'])*$q-(float)$o['delivery_charge'];
    $P[$k]['delivered']++; $P[$k]['units']+=$q; $P[$k]['rev']+=$line; $P[$k]['profit']+=$prof;
    $bp=&$byPerson[$pid2]['periods'][$k]; $bp['delivered']++; $bp['units']+=$q; $bp['rev']+=$line; $bp['profit']+=$prof; unset($bp);
    $pn=$o['product_name']?:'(none)';
    if(!isset($prodMix[$pid2][$pn])) $prodMix[$pid2][$pn]=['q'=>0,'rev'=>0];
    $prodMix[$pid2][$pn]['q']+=$q; $prodMix[$pid2][$pn]['rev']+=$line;
  } elseif(in_array($st,['returned','cancelled'],true)){
    $chg=(float)$o['cancel_charge'];
    $P[$k]['returned']++; $P[$k]['retCharge']+=$chg; $P[$k]['profit']-=$chg;
    $bp=&$byPerson[$pid2]['periods'][$k]; $bp['returned']++; $bp['retCharge']+=$chg; $bp['profit']-=$chg; unset($bp);
    $recentIssues[]=['type'=>'return','date'=>$o['order_date'],'customer'=>$o['customer'],'product'=>$o['product_name'],'charge'=>$chg,'sp'=>$byPerson[$pid2]['name']];
  } elseif($st==='exchanged'){
    $chg=(float)$o['exchange_charge'];
    $P[$k]['exchanged']++; $P[$k]['exCharge']+=$chg; $P[$k]['profit']-=$chg;
    $bp=&$byPerson[$pid2]['periods'][$k]; $bp['exchanged']++; $bp['exCharge']+=$chg; $bp['profit']-=$chg; unset($bp);
    $recentIssues[]=['type'=>'exchange','date'=>$o['order_date'],'customer'=>$o['customer'],'product'=>$o['product_name'],'charge'=>$chg,'sp'=>$byPerson[$pid2]['name']];
  }
  $rm=trim((string)($o['remarks']??'')); if($rm!==''){ $rk=mb_strtolower($rm); $remarks[$rk]=($remarks[$rk]??0)+1; }
}
ksort($P);
usort($recentIssues, fn($a,$b)=>strcmp($b['date'],$a['date']));
$recentIssues = array_slice($recentIssues,0,12);
arsort($remarks); $remarks=array_slice($remarks,0,8,true);

/* ---- range totals ---- */
$T=['orders'=>0,'delivered'=>0,'units'=>0,'rev'=>0,'profit'=>0,'returned'=>0,'exchanged'=>0,'retCharge'=>0,'exCharge'=>0];
foreach($P as $p) foreach($T as $k2=>$v) $T[$k2]+=$p[$k2];
$avgOrder = $T['delivered'] ? round($T['rev']/$T['delivered']) : 0;
$returnRate = $T['orders'] ? round($T['returned']/$T['orders']*100,1) : 0;
$exchangeRate = $T['orders'] ? round($T['exchanged']/$T['orders']*100,1) : 0;

/* ---- totals per person, for the leaderboard + top-5 trend comparison ---- */
$personTotals=[];
foreach($byPerson as $pid2=>$bd){
  $tt=['name'=>$bd['name'],'orders'=>0,'delivered'=>0,'units'=>0,'rev'=>0,'profit'=>0,'returned'=>0,'exchanged'=>0,'retCharge'=>0,'exCharge'=>0];
  foreach($bd['periods'] as $pp) foreach($tt as $k2=>$v) if($k2!=='name') $tt[$k2]+=$pp[$k2];
  $tt['id']=$pid2;
  $tt['rate']=$tt['orders']?round($tt['delivered']/$tt['orders']*100):0;
  $tt['issueRate']=$tt['orders']?round(($tt['returned']+$tt['exchanged'])/$tt['orders']*100,1):0;
  $personTotals[$pid2]=$tt;
}
uasort($personTotals, fn($a,$b)=>$b['rev']<=>$a['rev']);
$top5Ids = array_slice(array_keys($personTotals),0,5);

/* ---- chart series ---- */
$serOrders=array_map(fn($p)=>$p['orders'], array_values($P));
$serRev=array_map(fn($p)=>round($p['rev']), array_values($P));
$serProfit=array_map(fn($p)=>round($p['profit']), array_values($P));
$serIssues=array_map(fn($p)=>$p['returned']+$p['exchanged'], array_values($P));
$palette=['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ef4444'];
$cmpDatasets=[]; $ci=0;
foreach($top5Ids as $pid2){
  $data=[]; foreach(array_keys($P) as $k) $data[]=round($byPerson[$pid2]['periods'][$k]['rev']??0);
  $cmpDatasets[]=['label'=>$personTotals[$pid2]['name'],'data'=>$data,'borderColor'=>$palette[$ci%count($palette)],'backgroundColor'=>$palette[$ci%count($palette)],'tension'=>.3,'fill'=>false];
  $ci++;
}
$topProducts = $spid ? ($prodMix[$spid] ?? []) : [];
arsort($topProducts);

function sp_link($field,$v){ global $g,$from,$to,$spid; $q=['g'=>$g,'from'=>$from,'to'=>$to]; if($spid)$q['sp']=$spid; $q[$field]=$v; return '?'.http_build_query($q); }

require __DIR__.'/includes/header.php';
?>
<style>
.spx-controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.spx-controls select,.spx-controls input{border:1px solid var(--border);border-radius:9px;padding:7px 10px;font:inherit;font-size:12.5px}
.spx-title{font-size:19px;font-weight:900;margin:0}
.spx-lead{width:100%;border-collapse:collapse}
.spx-lead th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding:6px 10px}
.spx-lead td{padding:8px 10px;font-size:12.5px;border-top:1px solid var(--border)}
.spx-lead tr.me{background:rgba(59,130,246,.08)}
@media(max-width:640px){ .spx-controls{width:100%} .spx-controls select,.spx-controls input,.spx-controls label{width:100%} .spx-title{font-size:16.5px!important} }
</style>

<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
  <a class="btn btn-sm" href="analytics.php">← Analytics</a>
  <h1 class="spx-title">🧑‍💼 Sales Person Analytics<?= $selPerson ? ' — '.e($selPerson['name']) : '' ?></h1>
  <a class="btn btn-sm" style="margin-left:auto" href="sales_dashboard.php">🏆 Leaderboard view</a>
</div>

<form method="get" class="spx-controls">
  <select name="sp" onchange="this.form.submit()">
    <option value="0">👥 All — Top 5 comparison</option>
    <?php foreach($people as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $spid===(int)$p['id']?'selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
  </select>
  <select name="g" onchange="this.form.submit()">
    <option value="day" <?= $g==='day'?'selected':'' ?>>Daily</option>
    <option value="week" <?= $g==='week'?'selected':'' ?>>Weekly</option>
    <option value="month" <?= $g==='month'?'selected':'' ?>>Monthly</option>
  </select>
  <label class="muted" style="font-size:12px">From <input type="date" name="from" value="<?= e($from) ?>" onchange="this.form.submit()"></label>
  <label class="muted" style="font-size:12px">To <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
</form>

<div class="mgrid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
  <div class="metric blue"><div><div class="mv"><?= number_format($T['orders']) ?></div><div class="ml"><?= $selPerson?'Their Orders':'Team Orders' ?></div></div><div class="mi">📦</div></div>
  <div class="metric green"><div><div class="mv" style="font-size:19px"><?= money($T['rev']) ?></div><div class="ml">Revenue</div></div><div class="mi">💰</div></div>
  <div class="metric teal"><div><div class="mv" style="font-size:19px"><?= money($T['profit']) ?></div><div class="ml">Net Profit</div></div><div class="mi">💵</div></div>
  <div class="metric amber"><div><div class="mv" style="font-size:19px"><?= money($avgOrder) ?></div><div class="ml">Avg Order Value</div></div><div class="mi">🎯</div></div>
  <div class="metric red"><div><div class="mv"><?= $returnRate ?>%</div><div class="ml">Return Rate</div></div><div class="mi">↩️</div></div>
  <div class="metric indigo"><div><div class="mv"><?= $exchangeRate ?>%</div><div class="ml">Exchange Rate</div></div><div class="mi">🔁</div></div>
</div>

<div class="panel" style="margin-top:16px">
  <div class="panel-body">
    <div class="dash-sec" style="margin-bottom:8px"><?= $spid ? '📈 Their Revenue &amp; Profit Trend' : '📈 Top 5 Salespeople — Revenue Trend' ?></div>
    <div class="chart-box" style="height:260px"><canvas id="cSpTrend"></canvas></div>
  </div>
</div>

<?php if(!$spid): ?>
<div class="panel" style="margin-top:16px">
  <div class="panel-body">
    <div class="dash-sec" style="margin-bottom:8px">🏆 Leaderboard — this range</div>
    <div class="table-wrap"><table class="tbl num-tbl spx-lead">
      <thead><tr><th>Salesperson</th><th class="right">Orders</th><th class="right">Delivered</th><th class="right">Revenue</th><th class="right">Profit</th><th class="right">Returned</th><th class="right">Exchanged</th><th class="right">Issue Rate</th><th></th></tr></thead>
      <tbody>
      <?php foreach($personTotals as $pid2=>$r): if($r['orders']<1) continue; ?>
        <tr>
          <td><b><?= e($r['name']) ?></b></td>
          <td class="right"><?= number_format($r['orders']) ?></td>
          <td class="right"><?= number_format($r['delivered']) ?> <span class="muted" style="font-size:10.5px">(<?= $r['rate'] ?>%)</span></td>
          <td class="right" style="font-weight:800"><?= money($r['rev']) ?></td>
          <td class="right" style="color:<?= $r['profit']>=0?'var(--green)':'var(--red)' ?>"><?= money($r['profit']) ?></td>
          <td class="right" style="color:var(--red)"><?= (int)$r['returned'] ?></td>
          <td class="right" style="color:var(--blue,#2563eb)"><?= (int)$r['exchanged'] ?></td>
          <td class="right"><span class="pill <?= $r['issueRate']>=15?'p-red':($r['issueRate']>=8?'p-yellow':'p-green') ?>"><?= $r['issueRate'] ?>%</span></td>
          <td class="right"><a class="btn btn-sm" href="<?= e(sp_link('sp',$pid2)) ?>">View →</a></td>
        </tr>
      <?php endforeach; if(!array_filter($personTotals,fn($r)=>$r['orders']>0)): ?>
        <tr><td colspan="9"><div class="empty">No orders with a sales person in this range.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php else: ?>
<div class="grid cols-2" style="align-items:start;margin-top:16px">
  <div class="panel">
    <div class="panel-body">
      <div class="dash-sec" style="margin-bottom:8px">🏷️ Top Products Sold</div>
      <?php if($topProducts): ?>
      <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>Product</th><th class="right">Units</th><th class="right">Revenue</th></tr></thead><tbody>
        <?php foreach(array_slice($topProducts,0,10,true) as $pn=>$row): ?>
        <tr><td><b><?= e($pn) ?></b></td><td class="right"><?= number_format($row['q']) ?></td><td class="right"><?= money($row['rev']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php else: ?><div class="empty">No delivered orders in this range yet.</div><?php endif; ?>
    </div>
  </div>
  <div class="panel">
    <div class="panel-body">
      <div class="dash-sec" style="margin-bottom:8px">💬 Most Common Remarks</div>
      <?php if($remarks): ?>
      <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>Remark</th><th class="right">Times</th></tr></thead><tbody>
        <?php foreach($remarks as $txt=>$cnt): ?><tr><td><?= e(mb_strimwidth($txt,0,60,'…')) ?></td><td class="right"><b><?= $cnt ?></b></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php else: ?><div class="empty">No remarks recorded in this range.</div><?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:16px">
  <div class="panel-body">
    <div class="dash-sec" style="margin-bottom:8px">⚠️ Recent Returns &amp; Exchanges<?= $spid ? '' : ' — all salespeople' ?></div>
    <?php if($recentIssues): ?>
    <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>Date</th><th>Type</th><?= $spid?'':'<th>Salesperson</th>' ?><th>Customer</th><th>Product</th><th class="right">Charge</th></tr></thead><tbody>
      <?php foreach($recentIssues as $r): ?>
      <tr>
        <td><?= e(date('d M',strtotime($r['date']))) ?></td>
        <td><span class="pill <?= $r['type']==='return'?'p-red':'p-blue' ?>"><?= $r['type']==='return'?'↩️ Return':'🔁 Exchange' ?></span></td>
        <?php if(!$spid): ?><td><?= e($r['sp']) ?></td><?php endif; ?>
        <td><?= e($r['customer']?:'—') ?></td>
        <td class="muted"><?= e($r['product']?:'—') ?></td>
        <td class="right"><?= money($r['charge']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php else: ?><div class="empty">No returns or exchanges in this range 🎉</div><?php endif; ?>
  </div>
</div>

<script>
var CH_GRID = getComputedStyle(document.body).getPropertyValue('--border') || '#e5e7eb';
var base = {responsive:true,maintainAspectRatio:false};
var LB = <?= json_encode($labels) ?>;
<?php if($spid): ?>
new Chart(cSpTrend,{type:'line',data:{labels:LB,datasets:[
  {label:'Revenue',data:<?= json_encode($serRev) ?>,borderColor:'#10b981',backgroundColor:'#10b981',tension:.3,fill:false},
  {label:'Profit',data:<?= json_encode($serProfit) ?>,borderColor:'#3b82f6',backgroundColor:'#3b82f6',tension:.3,fill:false}
]},options:Object.assign({},base,{plugins:{legend:{display:true,position:'bottom',labels:{boxWidth:10,font:{size:10}}}},scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:CH_GRID},ticks:{precision:0}}}})});
<?php else: ?>
new Chart(cSpTrend,{type:'line',data:{labels:LB,datasets:<?= json_encode($cmpDatasets) ?>},
  options:Object.assign({},base,{plugins:{legend:{display:true,position:'bottom',labels:{boxWidth:10,font:{size:10}}}},scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:CH_GRID},ticks:{precision:0}}}})});
<?php endif; ?>
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
