<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
repair_zero_cost_profit();   /* auto-fix orders saved with Rs.0 cost so profit is honest everywhere */
$PAGE_TITLE='Reports';

/* ---- date range filter ---- */
$from = $_GET['from'] ?? date('Y-m-01', strtotime('-2 months'));
$to   = $_GET['to']   ?? date('Y-m-d');
if ($from > $to) { $t=$from; $from=$to; $to=$t; }

/* quick "log ad spend" (stored as an Advertising expense) */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action']??'')==='log_ad') {
  check_csrf();
  try {
    $amt  = (float)($_POST['amount'] ?? 0);
    $date = ($_POST['date'] ?? '') ?: date('Y-m-d');
    $chan = trim($_POST['channel'] ?? 'Advertising');
    if ($amt > 0) q("INSERT INTO expenses(expense_date,description,amount,category,pay_from) VALUES(?,?,?,?,?)",
                    [$date, 'Ad spend'.($chan!==''?" — $chan":''), $amt, 'Advertising', 'bank']);
    log_activity('Logged ad spend Rs.'.$amt,'Reports'); flash('Ad spend logged.');
  } catch (Exception $e) { flash('Error: '.$e->getMessage()); }
  header("Location: reports.php?from=".urlencode($from)."&to=".urlencode($to)); exit;
}

$orders = rows("SELECT o.*, p.name AS product_name, c.name AS courier_name, cu.name AS cust_name
                FROM orders o
                LEFT JOIN products p  ON p.id=o.product_id
                LEFT JOIN couriers c  ON c.id=o.courier_id
                LEFT JOIN customers cu ON cu.phone=o.phone
                WHERE o.order_date BETWEEN ? AND ?
                ORDER BY o.order_date", [$from,$to]);

/* ---- aggregate ---- */
$revenue=0;$profit=0;$delivery=0;$cogs=0;$orderValue=0;
$delivered=0;$cancelled=0;$returned=0;$pending=0;$shipped=0;$processing=0;
$codPending=0;$codCollected=0;$prepaid=0;
$statusCount=[];$byProd=[];$byCust=[];$monthly=[];$byCourier=[];$byPay=[];
foreach($orders as $o){
  $line=(float)$o['sell_price']*(int)$o['qty'];
  $orderValue+=$line;
  $revenue+=order_revenue($o); $profit+=order_profit($o);
  $statusCount[$o['status']]=($statusCount[$o['status']]??0)+1;
  $pt=$o['payment_type']?:'cod'; $byPay[$pt]=($byPay[$pt]??0)+$line;
  $cn=$o['courier_name']?:'(none)';
  if(!isset($byCourier[$cn])) $byCourier[$cn]=['orders'=>0,'delivered'=>0,'units'=>0,'revenue'=>0,'profit'=>0,'fee'=>0];
  $byCourier[$cn]['orders']++;
  switch($o['status']){
    case 'delivered':
      $delivered++; $unitsDelivered=($unitsDelivered??0)+(int)$o['qty']; $delivery+=(float)$o['delivery_charge'];
      $cogs+=(float)$o['cost_price']*(int)$o['qty'];
      $k=$o['product_name']?:'(none)'; $byProd[$k]=($byProd[$k]??0)+$line;
      $cust=$o['cust_name']?:($o['customer']?:'(walk-in)'); $byCust[$cust]=($byCust[$cust]??0)+$line;
      $m=substr($o['order_date'],0,7); $monthly[$m]=($monthly[$m]??0)+$line;
      $byCourier[$cn]['delivered']++; $byCourier[$cn]['units']+=(int)$o['qty']; $byCourier[$cn]['revenue']+=$line;
      $byCourier[$cn]['profit']+=order_profit($o); $byCourier[$cn]['fee']+=(float)$o['delivery_charge'];
      if($pt==='cod') $codCollected+=$line; else $prepaid+=$line;
      break;
    case 'returned':  $returned++; break;
    case 'cancelled': $cancelled++; break;
    case 'shipped':   $shipped++;   if($pt==='cod'&&$o['payment_status']!=='paid')$codPending+=$line; break;
    case 'processing':$processing++; break;
    default:          $pending++;
  }
}
arsort($byProd); $topProd=array_slice($byProd,0,10,true);
arsort($byCust); $topCust=array_slice($byCust,0,8,true);
ksort($monthly);
$total=count($orders);
$margin=$revenue>0?round($profit/$revenue*100,1):0;
$aov=$delivered?round($revenue/$delivered):0;
$returnRate=$total?round($returned/$total*100,1):0;
$deliveryRate=$total?round($delivered/$total*100,1):0;

/* expenses in range, by category */
$expRows=rows("SELECT category, COALESCE(SUM(amount),0) AS amt FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY category ORDER BY amt DESC",[$from,$to]);
$expenses=array_sum(array_column($expRows,'amt'));
$net=$profit-$expenses;

/* ---- advertising / ad-spend metrics ---- */
$adKeywords=['advert','ad spend','ads','marketing','facebook','fb','insta','google','tiktok','boost','meta','ppc','campaign','promotion'];
$adSpend=0;
foreach($expRows as $er){ $cat=strtolower((string)$er['category']); foreach($adKeywords as $kw){ if(strpos($cat,$kw)!==false){ $adSpend+=(float)$er['amt']; break; } } }
$adsPerOrder     = $total ? $adSpend/$total : 0;          // ad cost to get one order
$adsPerDelivered = $delivered ? $adSpend/$delivered : 0;  // CPA (delivered)
$adPctRevenue    = $revenue>0 ? $adSpend/$revenue*100 : 0;
$adPctProfit     = $profit>0  ? $adSpend/$profit*100  : 0; // ads as % of gross profit
$profitAfterAds  = $profit - $adSpend;
$roas            = $adSpend>0 ? $revenue/$adSpend : 0;     // return on ad spend
$netAfterAds     = $net; // net already includes ads (they're expenses)
$allStatus=['pending','processing','shipped','delivered','cancelled','returned'];

require __DIR__.'/includes/header.php';
?>
<div class="page-head">
  <div><h1>📊 Reports</h1><p>Full business report · <?= e($from) ?> → <?= e($to) ?></p></div>
  <div style="display:flex;gap:10px">
    <button class="btn" onclick="window.print()">🖨 Print / PDF</button>
    <button class="btn btn-primary" onclick="exportCSV()">⬇ Export CSV</button>
  </div>
</div>

<div class="card" style="margin-bottom:18px"><form method="get" class="toolbar" style="align-items:flex-end">
  <div><label style="font-size:12px;color:var(--muted);font-weight:700">From</label><br><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label style="font-size:12px;color:var(--muted);font-weight:700">To</label><br><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-primary">Apply</button>
  <a class="btn" href="reports.php">Reset</a>
</form></div>

<!-- KPI cards -->
<div class="mgrid">
  <div class="metric blue"><div><div class="mv"><?= number_format($total) ?></div><div class="ml">Total Orders</div></div><div class="mi">📦</div></div>
  <div class="metric purple"><div><div class="mv"><?= number_format($unitsDelivered??0) ?> <span style="font-size:13px">pcs</span></div><div class="ml">Units Delivered</div></div><div class="mi">🔢</div></div>
  <div class="metric green"><div><div class="mv" style="font-size:21px"><?= money($revenue) ?></div><div class="ml">Revenue (delivered)</div><div class="ms">≈ <?= usd($revenue) ?> · <?= $margin ?>% margin</div></div><div class="mi">📈</div></div>
  <div class="metric teal"><div><div class="mv" style="font-size:21px"><?= money($profit) ?></div><div class="ml">Gross Profit</div><div class="ms">≈ <?= usd($profit) ?></div></div><div class="mi">💵</div></div>
  <div class="metric red"><div><div class="mv" style="font-size:21px"><?= money($expenses) ?></div><div class="ml">Expenses</div><div class="ms">≈ <?= usd($expenses) ?></div></div><div class="mi">💸</div></div>
  <div class="metric <?= $net>=0?'green':'red' ?>"><div><div class="mv" style="font-size:21px"><?= money($net) ?></div><div class="ml">Net Profit</div><div class="ms">≈ <?= usd($net) ?></div></div><div class="mi">🧾</div></div>
  <div class="metric amber"><div><div class="mv"><?= $deliveryRate ?>%</div><div class="ml">Delivery Rate</div><div class="ms"><?= $delivered ?> of <?= $total ?></div></div><div class="mi">✅</div></div>
  <div class="metric orange"><div><div class="mv"><?= $returnRate ?>%</div><div class="ml">Return Rate</div><div class="ms"><?= $returned ?> returned</div></div><div class="mi">↩️</div></div>
  <div class="metric indigo"><div><div class="mv" style="font-size:21px"><?= money($aov) ?></div><div class="ml">Avg Order Value</div></div><div class="mi">🎯</div></div>
</div>

<!-- ===== Advertising Performance ===== -->
<div class="panel" style="margin-top:20px">
  <div class="panel-head"><h2>📣 Advertising Performance</h2>
    <form method="post" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="log_ad">
      <input name="channel" placeholder="Channel (Facebook…)" style="width:150px;padding:7px 10px;border:1px solid var(--border);border-radius:8px">
      <input name="amount" type="number" step="any" placeholder="Amount Rs." required style="width:120px;padding:7px 10px;border:1px solid var(--border);border-radius:8px">
      <input name="date" type="date" value="<?= e($to) ?>" style="padding:7px 10px;border:1px solid var(--border);border-radius:8px">
      <button class="btn btn-sm btn-primary">＋ Log Ad Spend</button>
    </form>
  </div>
  <div class="panel-body">
    <?php if($adSpend<=0): ?>
      <div class="muted" style="margin-bottom:14px">No ad spend recorded in this period. Use <b>Log Ad Spend</b> above (or add an expense with category “Advertising”) to see ads-per-order and ROAS.</div>
    <?php endif; ?>
    <div class="mgrid">
      <div class="metric red"><div><div class="mv" style="font-size:20px"><?= money($adSpend) ?></div><div class="ml">Total Ad Spend</div><div class="ms">≈ <?= usd($adSpend) ?> · <?= round($adPctRevenue,1) ?>% of revenue</div></div><div class="mi">💰</div></div>
      <div class="metric blue"><div><div class="mv" style="font-size:20px"><?= money(round($adsPerOrder)) ?></div><div class="ml">Ads per Order</div><div class="ms">across <?= number_format($total) ?> orders</div></div><div class="mi">📦</div></div>
      <div class="metric amber"><div><div class="mv" style="font-size:20px"><?= money(round($adsPerDelivered)) ?></div><div class="ml">Ads per Delivered</div><div class="ms">cost per sale (CPA)</div></div><div class="mi">✅</div></div>
      <div class="metric purple"><div><div class="mv"><?= round($adPctProfit,1) ?>%</div><div class="ml">Ads per Profit</div><div class="ms">of gross profit</div></div><div class="mi">📊</div></div>
      <div class="metric teal"><div><div class="mv"><?= $roas>0?number_format($roas,2).'×':'—' ?></div><div class="ml">ROAS</div><div class="ms">revenue ÷ ad spend</div></div><div class="mi">🚀</div></div>
      <div class="metric <?= $profitAfterAds>=0?'green':'red' ?>"><div><div class="mv" style="font-size:20px"><?= money($profitAfterAds) ?></div><div class="ml">Profit after Ads</div><div class="ms">gross profit − ad spend</div></div><div class="mi">🧾</div></div>
    </div>
    <div class="info-row" style="margin-top:8px"><span class="it muted" style="font-size:12.5px">“Ads per Order” = total ad spend ÷ all orders. “Ads per Delivered” (CPA) = ad spend ÷ delivered orders. ROAS above 1× means ads bring back more revenue than they cost; “Profit after Ads” is your real gross earning once ads are paid.</span></div>
  </div>
</div>

<!-- P&L + charts -->
<div class="grid cols-2" style="margin-top:20px;align-items:start">
  <div class="panel"><div class="panel-head"><h2>💰 Profit & Loss</h2></div><div class="panel-body" style="padding-top:6px">
    <div class="info-row"><span class="it">Revenue (product sales, delivered)</span><span class="iv"><?= money($revenue) ?></span></div>
    <div class="info-row"><span class="it">− Cost of Goods Sold</span><span class="iv" style="color:var(--red)"><?= money($cogs) ?></span></div>
    <div class="info-row"><span class="it">= Gross Profit</span><span class="iv" style="color:var(--green)"><?= money($profit) ?></span></div>
    <div class="info-row"><span class="it">− Operating Expenses</span><span class="iv" style="color:var(--red)"><?= money($expenses) ?></span></div>
    <div class="info-row"><span class="it"><b>= Net Profit</b></span><span class="iv" style="color:<?= $net>=0?'var(--green)':'var(--red)' ?>"><b><?= money($net) ?></b></span></div>
    <div class="info-row"><span class="it muted">Delivery charges collected (separate)</span><span class="iv muted"><?= money($delivery) ?></span></div>
  </div></div>
  <div class="panel"><div class="panel-head"><h2>📈 Monthly Revenue</h2></div><div class="panel-body"><div class="chart-box" style="height:230px"><canvas id="rev"></canvas></div></div></div>
</div>

<!-- status + payment -->
<div class="grid cols-2" style="margin-top:20px;align-items:start">
  <div class="panel"><div class="panel-head"><h2>📦 Orders by Status</h2></div><div class="panel-body">
    <?php $mx=$statusCount?max($statusCount):1; foreach($allStatus as $s){ $v=$statusCount[$s]??0; ?>
      <div class="cat-row"><div class="cat-name"><?= pill($s) ?></div><div class="cat-track"><div class="cat-fill" style="width:<?= max(2,round($v/$mx*100)) ?>%;background:var(--brand)"></div></div><div class="cat-amt num"><?= $v ?></div></div>
    <?php } ?>
  </div></div>
  <div class="panel"><div class="panel-head"><h2>💳 Payment / COD</h2></div><div class="panel-body" style="padding-top:6px">
    <div class="info-row"><span class="ii g">💵</span><span class="it">COD Collected (delivered)</span><span class="iv"><?= money($codCollected) ?></span></div>
    <div class="info-row"><span class="ii b">💳</span><span class="it">Prepaid (delivered)</span><span class="iv"><?= money($prepaid) ?></span></div>
    <div class="info-row"><span class="ii a">⏳</span><span class="it">COD Pending (in delivery)</span><span class="iv"><?= money($codPending) ?></span></div>
    <div class="info-row"><span class="ii t">🧮</span><span class="it">Total Order Value (all)</span><span class="iv"><?= money($orderValue) ?></span></div>
  </div></div>
</div>

<!-- courier performance table -->
<div class="panel" style="margin-top:20px"><div class="panel-head"><h2>🚚 Courier Performance</h2></div>
  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>Courier</th><th class="right">Orders</th><th class="right">Delivered</th><th class="right">Units</th><th class="right">Revenue</th><th class="right">Profit</th><th class="right">Delivery Fees</th></tr></thead><tbody>
    <?php foreach($byCourier as $cn=>$d): ?>
      <tr><td><b><?= e($cn) ?></b></td><td class="num right"><?= $d['orders'] ?></td><td class="num right"><?= $d['delivered'] ?></td><td class="num right" style="font-weight:700;color:var(--green)"><?= number_format($d['units']) ?></td><td class="num right"><?= money($d['revenue']) ?></td><td class="num right" style="color:var(--green)"><?= money($d['profit']) ?></td><td class="num right muted"><?= money($d['fee']) ?></td></tr>
    <?php endforeach; if(!$byCourier) echo '<tr><td colspan="7"><div class="empty">No orders in this range.</div></td></tr>'; ?>
  </tbody></table></div>
</div>

<!-- top products + top customers -->
<div class="grid cols-2" style="margin-top:20px;align-items:start">
  <div class="panel"><div class="panel-head"><h2>🏆 Top Products</h2></div>
    <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>#</th><th>Product</th><th class="right">Revenue</th></tr></thead><tbody>
      <?php $i=1; foreach($topProd as $n=>$v): ?><tr><td><?= $i++ ?></td><td><b><?= e($n) ?></b></td><td class="num right"><?= money($v) ?></td></tr><?php endforeach; if(!$topProd) echo '<tr><td colspan="3"><div class="empty">No delivered sales.</div></td></tr>'; ?>
    </tbody></table></div>
  </div>
  <div class="panel"><div class="panel-head"><h2>👥 Top Customers</h2></div>
    <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>#</th><th>Customer</th><th class="right">Spent</th></tr></thead><tbody>
      <?php $i=1; foreach($topCust as $n=>$v): ?><tr><td><?= $i++ ?></td><td><b><?= e($n) ?></b></td><td class="num right"><?= money($v) ?></td></tr><?php endforeach; if(!$topCust) echo '<tr><td colspan="3"><div class="empty">No delivered sales.</div></td></tr>'; ?>
    </tbody></table></div>
  </div>
</div>

<!-- expenses by category -->
<div class="panel" style="margin-top:20px"><div class="panel-head"><h2>💸 Expenses by Category</h2><span class="muted" style="font-size:13px">Total: <?= money($expenses) ?></span></div>
  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr><th>Category</th><th class="right">Amount</th><th class="right">% of Total</th></tr></thead><tbody>
    <?php foreach($expRows as $er): $pct=$expenses>0?round($er['amt']/$expenses*100,1):0; ?>
      <tr><td><b><?= e($er['category']?:'(uncategorised)') ?></b></td><td class="num right"><?= money($er['amt']) ?></td><td class="num right muted"><?= $pct ?>%</td></tr>
    <?php endforeach; if(!$expRows) echo '<tr><td colspan="3"><div class="empty">No expenses in this range.</div></td></tr>'; ?>
  </tbody></table></div>
</div>

<script>
new Chart(document.getElementById('rev'),{type:'bar',
  data:{labels:<?= json_encode(array_keys($monthly)) ?>,datasets:[{data:<?= json_encode(array_values($monthly)) ?>,backgroundColor:'#3b82f6',borderRadius:6,maxBarThickness:48}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:function(v){return v>=1000?(v/1000)+'K':v;}}}}}});

var REPORT={
  range:'<?= e($from) ?> to <?= e($to) ?>',
  kpis:{orders:<?= $total ?>,revenue:<?= (int)$revenue ?>,profit:<?= (int)$profit ?>,expenses:<?= (int)$expenses ?>,net:<?= (int)$net ?>,delivered:<?= $delivered ?>,returned:<?= $returned ?>,aov:<?= (int)$aov ?>},
  products:<?= json_encode($topProd) ?>,customers:<?= json_encode($topCust) ?>,couriers:<?= json_encode($byCourier) ?>
};
function exportCSV(){
  var L=[];
  L.push('Business Report,'+REPORT.range);
  L.push('');
  L.push('SUMMARY');
  L.push('Total Orders,'+REPORT.kpis.orders);
  L.push('Revenue,'+REPORT.kpis.revenue);
  L.push('Gross Profit,'+REPORT.kpis.profit);
  L.push('Expenses,'+REPORT.kpis.expenses);
  L.push('Net Profit,'+REPORT.kpis.net);
  L.push('Delivered,'+REPORT.kpis.delivered);
  L.push('Returned,'+REPORT.kpis.returned);
  L.push('Avg Order Value,'+REPORT.kpis.aov);
  L.push('');
  L.push('TOP PRODUCTS,Revenue');
  Object.keys(REPORT.products).forEach(function(k){L.push('"'+k.replace(/"/g,'""')+'",'+REPORT.products[k]);});
  L.push('');
  L.push('TOP CUSTOMERS,Spent');
  Object.keys(REPORT.customers).forEach(function(k){L.push('"'+k.replace(/"/g,'""')+'",'+REPORT.customers[k]);});
  L.push('');
  L.push('COURIER,Orders,Delivered,Revenue,Profit,Fees');
  Object.keys(REPORT.couriers).forEach(function(k){var c=REPORT.couriers[k];L.push('"'+k.replace(/"/g,'""')+'",'+c.orders+','+c.delivered+','+c.revenue+','+c.profit+','+c.fee);});
  var blob=new Blob([L.join('\n')],{type:'text/csv'});
  var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='report_<?= e($from) ?>_to_<?= e($to) ?>.csv';a.click();
}
</script>
<?php require __DIR__.'/includes/footer.php'; ?>