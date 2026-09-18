<?php
/* ============================================================
   RETURNS & CANCELLATIONS — DEEP REPORT
   Reached only via a link from analytics.php (top-details card +
   "Open Full Report"). Deliberately NOT in the main nav — same
   pattern as ncm_action.php: a focused deep-dive page, not a
   everyday destination on its own.
   ============================================================ */
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
repair_zero_cost_profit();
$PAGE_TITLE='Returns & Cancellations';

if (!function_exists('pkey')) {
function pkey($date,$g){ $ts=strtotime($date); if($g==='month')return date('Y-m',$ts); if($g==='week')return date('o-\W',$ts).sprintf('%02d',(int)date('W',$ts)); return date('Y-m-d',$ts); }
function plabel($k,$g){ if($g==='month')return date('M Y',strtotime($k.'-01')); if($g==='week')return str_replace('-W',' W',$k); return date('d M',strtotime($k)); }
}

$g    = in_array($_GET['g'] ?? '', ['day','week','month'], true) ? $_GET['g'] : 'day';
$to   = $_GET['to']   ?? date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-d', strtotime($g==='month' ? '-6 months' : ($g==='week' ? '-8 weeks' : '-30 days')));
if ($from > $to) { $t=$from; $from=$to; $to=$t; }
$pid = (int)($_GET['pid'] ?? 0);
$pw  = $pid ? " AND o.product_id = ".$pid : "";
$orders = rows("SELECT o.*, p.name AS product_name, c.name AS courier_name
                FROM orders o LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id
                WHERE o.order_date BETWEEN ? AND ? $pw ORDER BY o.order_date", [$from,$to]);
$allProducts = rows("SELECT id,name FROM products ORDER BY name");
$pName = $pid ? (string)val("SELECT name FROM products WHERE id=?",[$pid]) : '';

$periods=[];
foreach($orders as $o){ $k=pkey($o['order_date'],$g); if(!isset($periods[$k])) $periods[$k]=true; }
$labels=array_map(fn($k)=>plabel($k,$g), array_keys($periods));

/* ================= computation (unchanged from the Analytics version) ================= */
$rc=['returned'=>0,'cancelled'=>0,'chargeR'=>0,'chargeC'=>0,'ordersTotal'=>count($orders)];
$rcByCourier=[]; $rcByProduct=[]; $rcByDay=[]; $rcRemarks=[]; $rcCustomers=[];
$totByCourier=[]; $totByProduct=[];
foreach($orders as $o){
  $cn=$o['courier_name']?:'Unassigned'; $totByCourier[$cn]=($totByCourier[$cn]??0)+1;
  $pn0=$o['product_name']?:'(none)';    $totByProduct[$pn0]=($totByProduct[$pn0]??0)+1;
  $st=$o['status']; $isR=$st==='returned'; $isC=$st==='cancelled';
  if(!$isR && !$isC) continue;
  $chg=(float)$o['cancel_charge'];
  if($isR){ $rc['returned']++; $rc['chargeR']+=$chg; } else { $rc['cancelled']++; $rc['chargeC']+=$chg; }

  if(!isset($rcByCourier[$cn])) $rcByCourier[$cn]=['ret'=>0,'canc'=>0,'charge'=>0];
  if($isR) $rcByCourier[$cn]['ret']++; else $rcByCourier[$cn]['canc']++;
  $rcByCourier[$cn]['charge']+=$chg;

  $pn=$o['product_name']?:'(none)';
  if(!isset($rcByProduct[$pn])) $rcByProduct[$pn]=['ret'=>0,'canc'=>0,'charge'=>0,'revLost'=>0];
  if($isR) $rcByProduct[$pn]['ret']++; else $rcByProduct[$pn]['canc']++;
  $rcByProduct[$pn]['charge']+=$chg;
  $rcByProduct[$pn]['revLost']+=(float)$o['sell_price']*(int)$o['qty'];

  $dk=pkey($o['order_date'],$g);
  if(!isset($rcByDay[$dk])) $rcByDay[$dk]=['ret'=>0,'canc'=>0];
  if($isR) $rcByDay[$dk]['ret']++; else $rcByDay[$dk]['canc']++;

  $rm=trim((string)($o['remarks']??'')); if($rm!=='') { $rk=mb_strtolower($rm); $rcRemarks[$rk]=($rcRemarks[$rk]??0)+1; }

  $ph=trim((string)($o['phone']??''));
  if($ph!==''){
    if(!isset($rcCustomers[$ph])) $rcCustomers[$ph]=['name'=>$o['customer'],'phone'=>$ph,'ret'=>0,'canc'=>0,'charge'=>0];
    if($isR) $rcCustomers[$ph]['ret']++; else $rcCustomers[$ph]['canc']++;
    $rcCustomers[$ph]['charge']+=$chg;
  }
}
foreach($rcByCourier as $cn=>&$row){ $tt=$totByCourier[$cn]??0; $row['orders']=$tt; $row['rate']=$tt?round(($row['ret']+$row['canc'])/$tt*100,1):0; } unset($row);
uasort($rcByCourier, fn($a,$b)=>$b['charge']<=>$a['charge']);
foreach($rcByProduct as $pn=>&$row){ $tt=$totByProduct[$pn]??0; $row['orders']=$tt; $row['rate']=$tt?round(($row['ret']+$row['canc'])/$tt*100,1):0; } unset($row);
uasort($rcByProduct, fn($a,$b)=>$b['charge']<=>$a['charge']);

arsort($rcRemarks); $rcRemarks=array_slice($rcRemarks,0,8,true);

$rcRepeat = array_filter($rcCustomers, fn($c)=>($c['ret']+$c['canc'])>=2);
uasort($rcRepeat, fn($a,$b)=>($b['ret']+$b['canc'])<=>($a['ret']+$a['canc']));
$rcRepeat = array_slice($rcRepeat,0,10);

$rcRate = $rc['ordersTotal'] ? round(($rc['returned']+$rc['cancelled'])/$rc['ordersTotal']*100,1) : 0;
$rcChargeTotal = $rc['chargeR']+$rc['chargeC'];
$rcAvgCharge = ($rc['returned']+$rc['cancelled']) ? round($rcChargeTotal/($rc['returned']+$rc['cancelled'])) : 0;

$serRet=[]; $serCanc=[];
foreach(array_keys($periods) as $k){ $serRet[]=$rcByDay[$k]['ret']??0; $serCanc[]=$rcByDay[$k]['canc']??0; }

require __DIR__.'/includes/header.php';
?>
<style>.rc-controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.rc-controls select,.rc-controls input{border:1px solid var(--border);border-radius:9px;padding:7px 10px;font:inherit;font-size:12.5px}
@media(max-width:640px){
  .rc-controls{width:100%}
  .rc-controls select,.rc-controls input,.rc-controls label{width:100%}
  .rc-title{font-size:16.5px!important}
}
</style>

<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
  <a class="btn btn-sm" href="analytics.php">← Analytics</a>
  <h1 class="rc-title" style="font-size:19px;font-weight:900;margin:0">↩️ Returns &amp; Cancellations — Deep Report</h1>
</div>

<form method="get" class="rc-controls">
  <select name="g" onchange="this.form.submit()">
    <option value="day" <?= $g==='day'?'selected':'' ?>>Daily</option>
    <option value="week" <?= $g==='week'?'selected':'' ?>>Weekly</option>
    <option value="month" <?= $g==='month'?'selected':'' ?>>Monthly</option>
  </select>
  <label class="muted" style="font-size:12px">From <input type="date" name="from" value="<?= e($from) ?>" onchange="this.form.submit()"></label>
  <label class="muted" style="font-size:12px">To <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
  <select name="pid" onchange="this.form.submit()">
    <option value="0">All products</option>
    <?php foreach($allProducts as $pp): ?><option value="<?= (int)$pp['id'] ?>" <?= $pid===(int)$pp['id']?'selected':'' ?>><?= e($pp['name']) ?></option><?php endforeach; ?>
  </select>
</form>

<div class="mgrid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
  <div class="metric red"><span class="g">↩️</span><div class="mv"><?= $rc['returned'] ?></div><div class="ml">Returned</div></div>
  <div class="metric orange"><span class="g">✕</span><div class="mv"><?= $rc['cancelled'] ?></div><div class="ml">Cancelled</div></div>
  <div class="metric red"><span class="g">%</span><div class="mv"><?= $rcRate ?>%</div><div class="ml">Return + Cancel Rate</div></div>
  <div class="metric red"><span class="g">💸</span><div class="mv" style="font-size:19px"><?= money($rcChargeTotal) ?></div><div class="ml">Total Charges Lost</div></div>
  <div class="metric amber"><span class="g">≈</span><div class="mv" style="font-size:19px"><?= money($rcAvgCharge) ?></div><div class="ml">Avg Charge / Incident</div></div>
</div>

<div class="panel" style="margin-top:16px">
  <div class="panel-body">
    <div class="chart-box" style="height:230px;margin-bottom:20px"><canvas id="cRetTrend"></canvas></div>

    <div class="grid cols-2" style="align-items:start">
      <div>
        <div class="dash-sec" style="margin-bottom:8px">🚚 By Courier</div>
        <div class="table-wrap"><table class="tbl num-tbl">
          <thead><tr><th>Courier</th><th class="right">Orders</th><th class="right">Returned</th><th class="right">Cancelled</th><th class="right">Rate</th><th class="right">Charge Lost</th></tr></thead><tbody>
          <?php foreach($rcByCourier as $cn=>$row): ?>
          <tr><td><b><?= e($cn) ?></b></td><td class="right"><?= $row['orders'] ?></td>
            <td class="right" style="color:var(--red)"><?= $row['ret'] ?></td>
            <td class="right" style="color:var(--orange)"><?= $row['canc'] ?></td>
            <td class="right"><span class="pill <?= $row['rate']>=15?'p-red':($row['rate']>=8?'p-yellow':'p-green') ?>"><?= $row['rate'] ?>%</span></td>
            <td class="right" style="font-weight:800"><?= money($row['charge']) ?></td></tr>
          <?php endforeach; if(!$rcByCourier) echo '<tr><td colspan="6"><div class="empty">No returns or cancellations in this range 🎉</div></td></tr>'; ?>
          </tbody>
        </table></div>
      </div>
      <div>
        <div class="dash-sec" style="margin-bottom:8px">🏷️ By Product</div>
        <div class="table-wrap"><table class="tbl num-tbl">
          <thead><tr><th>Product</th><th class="right">Orders</th><th class="right">Ret+Canc</th><th class="right">Rate</th><th class="right">Charge Lost</th></tr></thead><tbody>
          <?php foreach($rcByProduct as $pn=>$row): ?>
          <tr><td><b><?= e($pn) ?></b></td><td class="right"><?= $row['orders'] ?></td>
            <td class="right"><?= $row['ret']+$row['canc'] ?><span class="muted" style="font-size:10px"> (<?= $row['ret'] ?>↩ <?= $row['canc'] ?>✕)</span></td>
            <td class="right"><span class="pill <?= $row['rate']>=15?'p-red':($row['rate']>=8?'p-yellow':'p-green') ?>"><?= $row['rate'] ?>%</span></td>
            <td class="right" style="font-weight:800"><?= money($row['charge']) ?></td></tr>
          <?php endforeach; if(!$rcByProduct) echo '<tr><td colspan="5"><div class="empty">No returns or cancellations in this range 🎉</div></td></tr>'; ?>
          </tbody>
        </table></div>
      </div>
    </div>

    <div class="grid cols-2" style="align-items:start;margin-top:20px">
      <div>
        <div class="dash-sec" style="margin-bottom:8px">💬 Most Common Remarks</div>
        <?php if($rcRemarks): ?>
        <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>Remark (as entered)</th><th class="right">Times</th></tr></thead><tbody>
          <?php foreach($rcRemarks as $txt=>$cnt): ?><tr><td><?= e(mb_strimwidth($txt,0,60,'…')) ?></td><td class="right"><b><?= $cnt ?></b></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php else: ?><div class="empty">No remarks recorded on returned/cancelled orders in this range.</div><?php endif; ?>
      </div>
      <div>
        <div class="dash-sec" style="margin-bottom:8px">⚠️ Repeat Return/Cancel Customers <span class="muted" style="font-weight:600;font-size:11px">2+ in this range</span></div>
        <?php if($rcRepeat): ?>
        <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>Customer</th><th>Phone</th><th class="right">Incidents</th><th class="right">Charge Lost</th></tr></thead><tbody>
          <?php foreach($rcRepeat as $c): ?><tr><td><?= e($c['name']?:'—') ?></td><td class="muted"><?= e($c['phone']) ?></td>
            <td class="right"><b style="color:var(--red)"><?= $c['ret']+$c['canc'] ?></b></td><td class="right"><?= money($c['charge']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php else: ?><div class="empty">No repeat offenders in this range — good sign.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
var CH_GRID = getComputedStyle(document.body).getPropertyValue('--border') || '#e5e7eb';
var base = {responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}}};
var LB = <?= json_encode($labels) ?>;
new Chart(cRetTrend,{type:'bar',data:{labels:LB,datasets:[
  {label:'Returned',data:<?= json_encode($serRet) ?>,backgroundColor:'#ef4444',borderRadius:4},
  {label:'Cancelled',data:<?= json_encode($serCanc) ?>,backgroundColor:'#f59e0b',borderRadius:4}
]},options:Object.assign({},base,{plugins:{legend:{display:true,position:'bottom',labels:{boxWidth:10,font:{size:10}}}},scales:{x:{stacked:true,grid:{display:false}},y:{stacked:true,beginAtZero:true,grid:{color:CH_GRID},ticks:{precision:0}}}})});
</script>
<?php require __DIR__.'/includes/footer.php'; ?>