<?php
/* ============================================================
   EXCHANGES — DEEP REPORT
   How many orders got exchanged (wrong/damaged item → replacement
   sent) and what that cost in resend charges. Same pattern as
   analytics_returns.php — a focused deep-dive, not an everyday page.
   ============================================================ */
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
repair_zero_cost_profit();
ensure_order_exchange_cols();
$PAGE_TITLE='Exchanges';

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

/* ================= computation ================= */
$ex=['exchanged'=>0,'charge'=>0,'ordersTotal'=>count($orders)];
$exByCourier=[]; $exByProduct=[]; $exByDay=[]; $exRemarks=[]; $exCustomers=[];
$totByCourier=[]; $totByProduct=[];
foreach($orders as $o){
  $cn=$o['courier_name']?:'Unassigned'; $totByCourier[$cn]=($totByCourier[$cn]??0)+1;
  $pn0=$o['product_name']?:'(none)';    $totByProduct[$pn0]=($totByProduct[$pn0]??0)+1;
  $st=$o['status']; $isEx=$st==='exchanged';
  if(!$isEx) continue;
  $chg=(float)$o['exchange_charge'];
  $ex['exchanged']++; $ex['charge']+=$chg;

  if(!isset($exByCourier[$cn])) $exByCourier[$cn]=['ex'=>0,'charge'=>0];
  $exByCourier[$cn]['ex']++; $exByCourier[$cn]['charge']+=$chg;

  $pn=$o['product_name']?:'(none)';
  if(!isset($exByProduct[$pn])) $exByProduct[$pn]=['ex'=>0,'charge'=>0];
  $exByProduct[$pn]['ex']++; $exByProduct[$pn]['charge']+=$chg;

  $dk=pkey($o['order_date'],$g);
  if(!isset($exByDay[$dk])) $exByDay[$dk]=['ex'=>0];
  $exByDay[$dk]['ex']++;

  $rm=trim((string)($o['remarks']??'')); if($rm!=='') { $rk=mb_strtolower($rm); $exRemarks[$rk]=($exRemarks[$rk]??0)+1; }

  $ph=trim((string)($o['phone']??''));
  if($ph!==''){
    if(!isset($exCustomers[$ph])) $exCustomers[$ph]=['name'=>$o['customer'],'phone'=>$ph,'ex'=>0,'charge'=>0];
    $exCustomers[$ph]['ex']++; $exCustomers[$ph]['charge']+=$chg;
  }
}
foreach($exByCourier as $cn=>&$row){ $tt=$totByCourier[$cn]??0; $row['orders']=$tt; $row['rate']=$tt?round($row['ex']/$tt*100,1):0; } unset($row);
uasort($exByCourier, fn($a,$b)=>$b['charge']<=>$a['charge']);
foreach($exByProduct as $pn=>&$row){ $tt=$totByProduct[$pn]??0; $row['orders']=$tt; $row['rate']=$tt?round($row['ex']/$tt*100,1):0; } unset($row);
uasort($exByProduct, fn($a,$b)=>$b['charge']<=>$a['charge']);

arsort($exRemarks); $exRemarks=array_slice($exRemarks,0,8,true);

$exRepeat = array_filter($exCustomers, fn($c)=>$c['ex']>=2);
uasort($exRepeat, fn($a,$b)=>$b['ex']<=>$a['ex']);
$exRepeat = array_slice($exRepeat,0,10);

$exRate = $ex['ordersTotal'] ? round($ex['exchanged']/$ex['ordersTotal']*100,1) : 0;
$exAvgCharge = $ex['exchanged'] ? round($ex['charge']/$ex['exchanged']) : 0;

$serEx=[];
foreach(array_keys($periods) as $k){ $serEx[]=$exByDay[$k]['ex']??0; }

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
  <h1 class="rc-title" style="font-size:19px;font-weight:900;margin:0">🔁 Exchanges — Deep Report</h1>
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
  <div class="metric blue"><span class="g">🔁</span><div class="mv"><?= $ex['exchanged'] ?></div><div class="ml">Exchanged</div></div>
  <div class="metric blue"><span class="g">%</span><div class="mv"><?= $exRate ?>%</div><div class="ml">Exchange Rate</div></div>
  <div class="metric red"><span class="g">💸</span><div class="mv" style="font-size:19px"><?= money($ex['charge']) ?></div><div class="ml">Total Spent on Exchanges</div></div>
  <div class="metric amber"><span class="g">≈</span><div class="mv" style="font-size:19px"><?= money($exAvgCharge) ?></div><div class="ml">Avg Cost / Exchange</div></div>
</div>

<div class="panel" style="margin-top:16px">
  <div class="panel-body">
    <div class="chart-box" style="height:230px;margin-bottom:20px"><canvas id="cExTrend"></canvas></div>

    <div class="grid cols-2" style="align-items:start">
      <div>
        <div class="dash-sec" style="margin-bottom:8px">🚚 By Courier</div>
        <div class="table-wrap"><table class="tbl num-tbl">
          <thead><tr><th>Courier</th><th class="right">Orders</th><th class="right">Exchanged</th><th class="right">Rate</th><th class="right">Cost</th></tr></thead><tbody>
          <?php foreach($exByCourier as $cn=>$row): ?>
          <tr><td><b><?= e($cn) ?></b></td><td class="right"><?= $row['orders'] ?></td>
            <td class="right" style="color:var(--blue,#2563eb)"><?= $row['ex'] ?></td>
            <td class="right"><span class="pill <?= $row['rate']>=15?'p-red':($row['rate']>=8?'p-yellow':'p-green') ?>"><?= $row['rate'] ?>%</span></td>
            <td class="right" style="font-weight:800"><?= money($row['charge']) ?></td></tr>
          <?php endforeach; if(!$exByCourier) echo '<tr><td colspan="5"><div class="empty">No exchanges in this range 🎉</div></td></tr>'; ?>
          </tbody>
        </table></div>
      </div>
      <div>
        <div class="dash-sec" style="margin-bottom:8px">🏷️ By Product</div>
        <div class="table-wrap"><table class="tbl num-tbl">
          <thead><tr><th>Product</th><th class="right">Orders</th><th class="right">Exchanged</th><th class="right">Rate</th><th class="right">Cost</th></tr></thead><tbody>
          <?php foreach($exByProduct as $pn=>$row): ?>
          <tr><td><b><?= e($pn) ?></b></td><td class="right"><?= $row['orders'] ?></td>
            <td class="right" style="color:var(--blue,#2563eb)"><?= $row['ex'] ?></td>
            <td class="right"><span class="pill <?= $row['rate']>=15?'p-red':($row['rate']>=8?'p-yellow':'p-green') ?>"><?= $row['rate'] ?>%</span></td>
            <td class="right" style="font-weight:800"><?= money($row['charge']) ?></td></tr>
          <?php endforeach; if(!$exByProduct) echo '<tr><td colspan="5"><div class="empty">No exchanges in this range 🎉</div></td></tr>'; ?>
          </tbody>
        </table></div>
      </div>
    </div>

    <div class="grid cols-2" style="align-items:start;margin-top:20px">
      <div>
        <div class="dash-sec" style="margin-bottom:8px">💬 Most Common Remarks</div>
        <?php if($exRemarks): ?>
        <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>Remark (as entered)</th><th class="right">Times</th></tr></thead><tbody>
          <?php foreach($exRemarks as $txt=>$cnt): ?><tr><td><?= e(mb_strimwidth($txt,0,60,'…')) ?></td><td class="right"><b><?= $cnt ?></b></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php else: ?><div class="empty">No remarks recorded on exchanged orders in this range.</div><?php endif; ?>
      </div>
      <div>
        <div class="dash-sec" style="margin-bottom:8px">⚠️ Repeat Exchange Customers <span class="muted" style="font-weight:600;font-size:11px">2+ in this range</span></div>
        <?php if($exRepeat): ?>
        <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>Customer</th><th>Phone</th><th class="right">Exchanges</th><th class="right">Cost</th></tr></thead><tbody>
          <?php foreach($exRepeat as $c): ?><tr><td><?= e($c['name']?:'—') ?></td><td class="muted"><?= e($c['phone']) ?></td>
            <td class="right"><b style="color:var(--blue,#2563eb)"><?= $c['ex'] ?></b></td><td class="right"><?= money($c['charge']) ?></td></tr><?php endforeach; ?>
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
new Chart(cExTrend,{type:'bar',data:{labels:LB,datasets:[
  {label:'Exchanged',data:<?= json_encode($serEx) ?>,backgroundColor:'#2563eb',borderRadius:4}
]},options:Object.assign({},base,{scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:CH_GRID},ticks:{precision:0}}}})});
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
