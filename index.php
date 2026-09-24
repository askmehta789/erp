<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
repair_zero_cost_profit();   /* auto-fix orders saved with Rs.0 cost so profit is honest everywhere */
$PAGE_TITLE='Dashboard';

/* ---- pull all orders ---- */
$orders = rows("SELECT o.*, p.name AS product_name, c.name AS courier_name
                FROM orders o
                LEFT JOIN products p ON p.id=o.product_id
                LEFT JOIN couriers c ON c.id=o.courier_id
                ORDER BY o.order_date DESC, o.id DESC");

/* ---- period selector: 30d / 90d / this year / lifetime ---- */
$range = (string)($_GET['range'] ?? 'all');
$rangeDefs = [
  '30'   => ['Last 30 days',  date('Y-m-d', strtotime('-30 days'))],
  '90'   => ['Last 90 days',  date('Y-m-d', strtotime('-90 days'))],
  'year' => ['This year',     date('Y-01-01')],
  'all'  => ['Lifetime',      null],
];
if(!isset($rangeDefs[$range])) $range='all';
[$rangeLabel,$rangeCut]=$rangeDefs[$range];
if($rangeCut!==null){
  $orders=array_values(array_filter($orders,fn($o)=>substr((string)$o['order_date'],0,10)>=$rangeCut));
}

$today = date('Y-m-d');
$mAgo  = date('Y-m-d', strtotime('-30 days'));

/* ---- counters ---- */
$total=count($orders); $delivered=0; $returned=0; $cancelled=0; $shipped=0;
$processing=0; $pending=0; $hold=0;
$revenue=0; $profit=0; $delivery=0; $cogs=0; $returnLoss=0;
$orderValue=0; $deliveredValue=0; $returnedValue=0; $pendingValue=0;
$codPending=0; $codCollected=0; $unitsDelivered=0; $tUnits=0;
$tOrders=0; $tDelivered=0; $tCancelled=0; $tReturned=0;
$byProd=[]; $daily=[]; $last7=[];

/* last-7-day buckets */
for($i=6;$i>=0;$i--){ $d=date('Y-m-d',strtotime("-$i days")); $last7[$d]=0; }

foreach($orders as $o){
  $line = (float)$o['sell_price']*(int)$o['qty'];
  $revenue += order_revenue($o);
  $profit  += order_profit($o);
  $orderValue += $line;

  switch($o['status']){
    case 'delivered':  $delivered++;  $deliveredValue+=$line; $delivery+=(float)$o['delivery_charge']; $cogs+=(float)$o['cost_price']*(int)$o['qty']; $unitsDelivered+=(int)$o['qty'];
                       $k=$o['product_name']?:'(none)'; $byProd[$k]=($byProd[$k]??0)+$line;
                       $dd=$o['order_date']; $daily[$dd]=($daily[$dd]??0)+$line;
                       if($o['payment_type']==='cod') $codCollected+=$line;
                       break;
    case 'returned':   $returned++;   $returnedValue+=$line; $returnLoss+=(float)$o['cancel_charge']; break;
    case 'cancelled':  $cancelled++;  $returnLoss+=(float)$o['cancel_charge']; break;
    case 'shipped':    $shipped++;    $pendingValue+=$line; if($o['payment_type']==='cod'&&$o['payment_status']!=='paid') $codPending+=$line; break;
    case 'processing': $processing++; $pendingValue+=$line; if($o['payment_type']==='cod'&&$o['payment_status']!=='paid') $codPending+=$line; break;
    default:           $pending++;    $pendingValue+=$line; if($o['payment_type']==='cod'&&$o['payment_status']!=='paid') $codPending+=$line;
  }
  if(isset($last7[$o['order_date']])) $last7[$o['order_date']]++;
  if($o['order_date']===$today){ $tOrders++;
    if($o['status']==='delivered'){$tDelivered++; $tUnits+=(int)$o['qty'];}
    if($o['status']==='cancelled')$tCancelled++;
    if($o['status']==='returned') $tReturned++;
  }
}
$inDelivery  = $shipped + $processing;
$returnProc  = $returned;
$returnRate  = $total? round(($returned)/$total*100,1):0;
$cancelRate  = $total? round(($cancelled)/$total*100,1):0;
$combinedRate = $total? round(($returned+$cancelled)/$total*100,1):0;
$expWhere    = $rangeCut!==null ? "WHERE expense_date>=?" : "";
$expParams   = $rangeCut!==null ? [$rangeCut] : [];
$expenses    = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses $expWhere", $expParams);
$expenseByCat = rows("SELECT category, COALESCE(SUM(amount),0) AS total FROM expenses $expWhere GROUP BY category ORDER BY total DESC", $expParams);
$net         = $profit - $expenses;
/* stock summary for dashboard */
$prodRows    = rows("SELECT stock, low_stock, cost FROM products");
$stkProducts = count($prodRows);
$stkUnits    = array_sum(array_column($prodRows,'stock'));
try { ensure_stock_batches(); $stkValue = (float)val("SELECT COALESCE(SUM(qty_left*unit_cost),0) FROM stock_batches"); }
catch (Exception $e) { $stkValue = 0; }
if ($stkValue <= 0) $stkValue = array_sum(array_map(fn($p)=>(int)$p['stock']*(float)$p['cost'], $prodRows));
$stkLow      = count(array_filter($prodRows, fn($p)=>(int)$p['stock']>0 && (int)$p['stock']<=(int)$p['low_stock']));
$stkOut      = count(array_filter($prodRows, fn($p)=>(int)$p['stock']<=0));
$margin      = $revenue>0? round($profit/$revenue*100,1):0;
$netMargin   = $revenue>0? round($net/$revenue*100,1):0;

/* order-status chart buckets — delivered excluded (it dwarfs the rest;
   it's shown big in the cards + the doughnut). This keeps small bars visible. */
$statusChart = [
  'Pending'    => $pending,
  'Processing' => $processing,
  'Shipped'    => $shipped,
  'Cancelled'  => $cancelled,
  'Returned'   => $returned,
];
$normalVsReturn = [ 'Normal'=>max(0,$total-$returned), 'Returned/RTV'=>$returned ];

arsort($byProd); $topProd=array_slice($byProd,0,5,true);
/* self-clean: remove legacy blank orders (no customer, product, price or phone) */
try { q("DELETE FROM orders WHERE COALESCE(customer,'')='' AND (product_id IS NULL OR product_id=0)
        AND COALESCE(sell_price,0)=0 AND COALESCE(phone,'')=''"); } catch (Exception $e) {}

$recent = rows("SELECT o.*, p.name AS product_name, c.name AS courier_name
                FROM orders o
                LEFT JOIN products p ON p.id=o.product_id
                LEFT JOIN couriers c ON c.id=o.courier_id
                ORDER BY o.id DESC LIMIT 15");
/* keep only rows that have at least SOME content (skip fully-empty placeholders) */
$recent = array_values(array_filter($recent, function($o){
  return trim((string)($o['customer'] ?? '')) !== '' || !empty($o['product_id']) || (float)($o['sell_price'] ?? 0) > 0 || trim((string)($o['phone'] ?? '')) !== '';
}));
$recent = array_slice($recent, 0, 10);


require __DIR__.'/includes/header.php';
?>
<div class="page-head">
  <div><h1>📊 Dashboard</h1><p>Welcome back, <?= e(current_user()['name']) ?> — your store at a glance · <b><?= e($rangeLabel) ?></b></p>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:7px">
      <?php foreach($rangeDefs as $rk=>$rd): ?>
        <a href="index.php?range=<?= $rk ?>" style="text-decoration:none;font-size:11.5px;font-weight:800;border-radius:99px;padding:4px 12px;<?= $rk===$range?'background:#1f2740;color:#fff':'background:#fff;color:#3a4258;border:1px solid #e4e8f2' ?>"><?= e($rd[0]) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <a class="btn btn-primary" href="sales.php">+ New Order</a>
</div>

<form method="get" action="search.php" class="card" style="display:flex;gap:10px;padding:12px 14px;margin-bottom:20px">
  <input class="search-in" name="q" placeholder="🔍 Search anything — orders, customers, phone, product, tracking #, courier, users…">
  <button class="btn btn-primary">Search</button>
</form>

<?php
/* build a "needs attention" to-do list */
$todo=[];
$awaiting = $pending + $processing;
if ($awaiting>0)     $todo[] = ['🕒','var(--amber)', "$awaiting order".($awaiting>1?'s':'')." awaiting dispatch", 'sales.php'];
if ($shipped>0)      $todo[] = ['🚚','var(--blue)',  "$shipped order".($shipped>1?'s':'')." in transit", 'ncm.php'];
if ($stkOut>0)       $todo[] = ['🚫','var(--red)',   "$stkOut product".($stkOut>1?'s':'')." out of stock", 'products.php'];
/* COD threshold alert */
$codHeld = 0; foreach (cod_holdings() as $h) $codHeld += max(0,$h['held']);
$codThr = (float)setting('cod_alert_threshold', 100000);
if ($codThr > 0) {
  foreach (cod_holdings() as $h) {
    if ($h['held'] > $codThr)
      $todo[] = ['💰','var(--red)', $h['courier'].' holding '.money($h['held']).' COD — above your '.money($codThr).' limit', 'cod.php'];
  }
}
/* reorder suggestions */
$reo = reorder_suggestions();
if (count($reo))
  $todo[] = ['🛒','var(--amber)', count($reo).' product'.(count($reo)>1?'s':'').' need reorder soon ('.e($reo[0]['name']).' ~'.$reo[0]['cover_days'].'d left)', 'products.php'];
if ($stkLow>0)       $todo[] = ['⚠️','var(--amber)', "$stkLow product".($stkLow>1?'s':'')." low on stock", 'products.php'];
if ($codPending>0)   $todo[] = ['💰','var(--teal)',  money(round($codPending))." COD in transit (undelivered orders)", 'cod.php'];
if ($returned>0)     $todo[] = ['↩️','var(--red)',   "$returned returned/cancelled — review why", 'reports.php'];
?>
<!-- ===== metrics + compact attention side card ===== -->
<div class="dash-top<?= $todo?' has-att':'' ?>">
<div class="dash-main">
<div class="mgrid">
  <div class="metric blue"><div><div class="mv"><?= number_format($total) ?></div><div class="ml">Total Orders</div></div><div class="mi">📦</div></div>
  <div class="metric green"><div><div class="mv"><?= number_format($delivered) ?></div><div class="ml">Delivered</div></div><div class="mi">✅</div></div>
  <div class="metric purple"><div><div class="mv"><?= number_format($unitsDelivered) ?> <span style="font-size:13px">pcs</span></div><div class="ml">Units Delivered</div><div class="ms">total quantity sold</div></div><div class="mi">🔢</div></div>
  <div class="metric amber"><div><div class="mv"><?= number_format($returned) ?></div><div class="ml">Returns / RTV</div></div><div class="mi">↩️</div></div>
  <div class="metric red">
    <div>
      <div class="mv"><span class="rr-view rr-return"><?= $returnRate ?>%</span><span class="rr-view rr-cancel" style="display:none"><?= $cancelRate ?>%</span></div>
      <div class="ml"><span class="rr-view rr-return">Return Rate</span><span class="rr-view rr-cancel" style="display:none">Cancel Rate</span></div>
      <div class="ms">Combined <?= $combinedRate ?>% · <?= $returnRate ?>% return + <?= $cancelRate ?>% cancel</div>
    </div>
    <div class="mi">％</div>
  </div>
  <div class="metric orange"><div><div class="mv"><?= number_format($inDelivery) ?></div><div class="ml">In Delivery</div></div><div class="mi">🚚</div></div>
  <div class="metric indigo"><div><div class="mv"><?= number_format($pending) ?></div><div class="ml">Pending Orders</div></div><div class="mi">⏳</div></div>
  <div class="metric purple"><div><div class="mv"><?= number_format($cancelled) ?></div><div class="ml">Cancelled</div></div><div class="mi">✖️</div></div>
  <div class="metric teal mlink" onclick="location='cod.php'"><div><div class="mv" style="font-size:22px"><?= money($codPending) ?></div><div class="ml">COD In Transit</div><div class="ms">orders not yet delivered</div></div><div class="mi">💰</div></div>
  <div class="metric amber mlink" onclick="location='cod.php'"><div><div class="mv" style="font-size:22px"><?= money($codHeld) ?></div><div class="ml">COD With Couriers</div><div class="ms">delivered, release pending</div></div><div class="mi">🚚</div></div>
</div>

<!-- ===== money row ===== -->
<div class="mgrid" style="margin-top:16px">
  <div class="metric green"><div><div class="mv" style="font-size:22px"><?= money($revenue) ?></div><div class="ml">Revenue (delivered)</div><div class="ms"><?= $margin ?>% margin</div></div><div class="mi">📈</div></div>
  <div class="metric blue"><div><div class="mv" style="font-size:22px"><?= money($profit) ?></div><div class="ml">Gross Profit</div></div><div class="mi">💵</div></div>
  <div class="metric red"><div><div class="mv" style="font-size:22px"><?= money($expenses) ?></div><div class="ml">Expenses</div></div><div class="mi">💸</div></div>
  <div class="metric <?= $net>=0?'teal':'red' ?>"><div><div class="mv" style="font-size:22px"><?= money($net) ?></div><div class="ml">Net Profit</div><div class="ms">after expenses · <?= $netMargin ?>% margin</div></div><div class="mi">🧾</div></div>
</div>
</div><!-- /dash-main -->

<?php if($todo): ?>
<aside class="att-card panel noglow">
  <div class="att-head">⚡ Attention <span class="att-n"><?= count($todo) ?></span></div>
  <div class="att-list">
    <?php foreach($todo as $t): ?>
      <a class="att-item" href="<?= e($t[3]) ?>">
        <span class="att-i"><?= $t[0] ?></span>
        <span class="att-t"><?= e($t[2]) ?></span>
        <span class="att-go" style="color:<?= $t[1] ?>">→</span>
      </a>
    <?php endforeach; ?>
  </div>
</aside>
<?php endif; ?>
</div><!-- /dash-top -->

<!-- ===== analytics + side panels ===== -->
<div class="grid" style="grid-template-columns:2fr 1fr;margin-top:20px;align-items:start">
  <!-- charts -->
  <div class="panel">
    <div class="panel-head"><h2>📊 Sales Analytics</h2></div>
    <div class="panel-body">
      <div class="grid cols-2" style="gap:22px">
        <div><div class="dash-sec" style="margin:0 0 10px">Order Status <span style="text-transform:none;letter-spacing:0;font-weight:600">(excl. delivered)</span></div>
          <div class="chart-box" style="height:230px"><canvas id="statusChart"></canvas></div></div>
        <div><div class="dash-sec" style="margin:0 0 10px">Normal vs Returned</div>
          <div class="chart-box" style="height:230px"><canvas id="pieChart"></canvas></div></div>
      </div>
      <div class="dash-sec" style="margin:22px 0 10px">Orders — Last 7 Days</div>
      <div class="chart-box" style="height:200px"><canvas id="last7Chart"></canvas></div>
    </div>
  </div>

  <!-- today's details -->
  <div class="panel">
    <div class="panel-head"><h2>📅 Today's Details</h2><span class="muted" style="font-size:12px"><?= date('d M Y') ?></span></div>
    <div class="panel-body" style="padding-top:6px">
      <div class="info-row"><span class="ii g">🛒</span><span class="it">Today's Orders</span><span class="iv"><?= $tOrders ?></span></div>
      <div class="info-row"><span class="ii b">✅</span><span class="it">Today's Delivered</span><span class="iv"><?= $tDelivered ?> orders · <?= number_format($tUnits) ?> pcs</span></div>
      <div class="info-row"><span class="ii r">✖️</span><span class="it">Today's Cancelled</span><span class="iv"><?= $tCancelled ?></span></div>
      <div class="info-row"><span class="ii a">↩️</span><span class="it">Today's Returns</span><span class="iv"><?= $tReturned ?></span></div>
      <div class="info-row"><span class="ii p">📦</span><span class="it">In Delivery</span><span class="iv"><?= $inDelivery ?></span></div>
      <div class="info-row"><span class="ii t">⏳</span><span class="it">Pending</span><span class="iv"><?= $pending ?></span></div>
    </div>
  </div>
</div>

<!-- ===== COD + Order Values + Return Rate ===== -->
<div class="grid cols-3" style="margin-top:20px;align-items:start">
  <!-- COD info -->
  <div class="panel">
    <div class="panel-head"><h2>💰 COD Info</h2></div>
    <div class="panel-body" style="padding-top:6px">
      <div class="info-row"><span class="ii g">💵</span><span class="it">COD Collected</span><span class="iv"><?= money($codCollected) ?></span></div>
      <div class="info-row"><span class="ii r">⏳</span><span class="it">COD In Transit (undelivered)</span><span class="iv"><?= money($codPending) ?></span></div>
      <div class="info-row"><span class="ii r">🚚</span><span class="it">COD With Couriers (release pending)</span><span class="iv"><?= money($codHeld) ?></span></div>
      <div class="info-row"><span class="ii b">🚚</span><span class="it">Delivery Charges</span><span class="iv"><?= money($delivery) ?></span></div>
      <div class="info-row"><span class="ii a">🧮</span><span class="it">COGS (delivered)</span><span class="iv"><?= money($cogs) ?></span></div>
    </div>
  </div>

  <!-- order values -->
  <div class="panel">
    <div class="panel-head"><h2>₹ Order Values</h2></div>
    <div class="panel-body">
      <div class="vsplit">
        <div class="vcell tint-b"><div class="vl">Order Value</div><div class="vv" style="color:var(--blue)"><?= money($orderValue) ?></div></div>
        <div class="vcell tint-g"><div class="vl">Delivered Value</div><div class="vv" style="color:var(--green)"><?= money($deliveredValue) ?></div></div>
        <div class="vcell tint-r"><div class="vl">Returned Value</div><div class="vv" style="color:var(--red)"><?= money($returnedValue) ?></div></div>
        <div class="vcell tint-a"><div class="vl">Pending Value</div><div class="vv" style="color:var(--amber)"><?= money($pendingValue) ?></div></div>
      </div>
    </div>
  </div>

  <!-- return rate + top products -->
  <div class="panel">
    <div class="panel-head"><h2>↩️ Return Rate</h2>
      <label class="rr-toggle-label" title="Switch between Return Rate and Cancel Rate">
        <span id="rrLabelReturn" class="on">Return</span>
        <span class="mini-switch"><input type="checkbox" id="rrToggleInput" onchange="rrToggle(this.checked)"><span class="mini-slider"></span></span>
        <span id="rrLabelCancel">Cancel</span>
      </label>
    </div>
    <div class="panel-body" style="padding-top:6px">
      <div class="rate-row">
        <div class="rm">
          <b class="rr-view rr-return"><?= $returned ?> RTV</b><span class="rr-view rr-return">/ <?= $delivered ?> delivered</span>
          <b class="rr-view rr-cancel" style="display:none"><?= $cancelled ?> Cancelled</b><span class="rr-view rr-cancel" style="display:none">/ <?= $total ?> total orders</span>
        </div>
        <div class="rate-badge" style="background:var(--red-bg);color:var(--red)">
          <span class="rr-view rr-return"><?= $returnRate ?>%</span><span class="rr-view rr-cancel" style="display:none"><?= $cancelRate ?>%</span>
        </div>
      </div>
      <div class="muted" style="font-size:11.5px;margin-top:8px">Combined issue rate: <b><?= $combinedRate ?>%</b> — <?= $returnRate ?>% return + <?= $cancelRate ?>% cancel</div>
      <div class="dash-sec" style="margin:14px 0 6px">Top Products</div>
      <?php $mx=$topProd?max($topProd):1; foreach($topProd as $n=>$v): ?>
        <div class="cat-row"><div class="cat-name"><?= e($n) ?></div><div class="cat-track"><div class="cat-fill" style="width:<?= max(4,round($v/$mx*100)) ?>%;background:var(--brand)"></div></div><div class="cat-amt num"><?= money($v) ?></div></div>
      <?php endforeach; if(!$topProd) echo '<div class="muted">No delivered sales yet.</div>'; ?>
    </div>
  </div>
</div>

<!-- ===== stock details (near return rate) ===== -->
<div class="grid cols-2" style="margin-top:20px;align-items:start">
  <div class="panel"><div class="panel-head"><h2>📦 Stock Details</h2><a class="btn btn-sm" href="products.php">Open Inventory →</a></div>
    <div class="panel-body" style="padding-top:6px">
      <div class="info-row"><span class="ii b">🏷️</span><span class="it">Products</span><span class="iv"><?= number_format($stkProducts) ?></span></div>
      <div class="info-row"><span class="ii t">📦</span><span class="it">Units in Stock</span><span class="iv"><?= number_format($stkUnits) ?></span></div>
      <div class="info-row"><span class="ii g">💰</span><span class="it">Stock Value (cost)</span><span class="iv"><?= money($stkValue) ?></span></div>
      <div class="info-row"><span class="ii a">⚠️</span><span class="it">Low Stock Items</span><span class="iv" style="color:var(--amber)"><?= $stkLow ?></span></div>
      <div class="info-row"><span class="ii r">🚫</span><span class="it">Out of Stock</span><span class="iv" style="color:var(--red)"><?= $stkOut ?></span></div>
    </div>
  </div>
  <div class="panel"><div class="panel-head"><h2>💡 Profit Note</h2><a class="btn btn-sm" href="expenses.php">Open Expenses →</a></div>
    <div class="panel-body" style="padding-top:6px">
      <div class="info-row"><span class="it">Revenue (delivered)</span><span class="iv"><?= money($revenue) ?></span></div>
      <div class="info-row"><span class="it">− COGS</span><span class="iv" style="color:var(--red)"><?= money($cogs) ?></span></div>
      <div class="info-row"><span class="it">− Delivery Charges</span><span class="iv" style="color:var(--red)"><?= money($delivery) ?></span></div>
      <?php if($returnLoss>0): ?><div class="info-row"><span class="it">− Returns / Cancellation Charges</span><span class="iv" style="color:var(--red)"><?= money($returnLoss) ?></span></div><?php endif; ?>
      <div class="info-row"><span class="it">= Gross Profit</span><span class="iv" style="color:var(--green)"><?= money($profit) ?></span></div>
      <?php if($expenseByCat): ?>
      <div class="info-row" style="cursor:pointer" onclick="toggleExpBreakdown(this)">
        <span class="it">− Expenses <span id="expChevron" class="muted" style="font-size:10px">▸ tap to show</span></span>
        <span class="iv" style="color:var(--red)"><?= money($expenses) ?></span>
      </div>
      <div id="expBreakdown" style="display:none;margin:0 0 8px 15px;padding-left:9px;border-left:2px solid var(--surface-2)">
        <?php foreach($expenseByCat as $ec): $pct = $expenses>0? round($ec['total']/$expenses*100,1):0; ?>
        <div class="info-row" style="padding:6px 4px;font-size:12px;border-bottom:0">
          <span class="it" style="color:var(--muted-2);font-weight:500">↳ <?= htmlspecialchars($ec['category']) ?></span>
          <span class="iv" style="font-weight:600;color:var(--muted-2)"><?= money($ec['total']) ?> <span style="opacity:.65">(<?= $pct ?>%)</span></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="info-row"><span class="it">− Expenses</span><span class="iv" style="color:var(--red)"><?= money($expenses) ?></span></div>
      <?php endif; ?>
      <div class="info-row"><span class="it"><b>= Net Profit</b></span><span class="iv" style="color:<?= $net>=0?'var(--green)':'var(--red)' ?>"><b><?= money($net) ?></b></span></div>
      <div class="info-row"><span class="it muted">Net Margin</span><span class="iv" style="color:<?= $net>=0?'var(--green)':'var(--red)' ?>"><?= $netMargin ?>%</span></div>
    </div>
  </div>
</div>

<!-- ===== recent orders ===== -->
<div class="panel" style="margin-top:20px">
  <div class="panel-head"><h2>🧾 Recent Orders</h2><a class="btn btn-sm" href="sales.php">View all →</a></div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Phone</th><th>Product</th><th class="right">Amount</th><th>Courier</th><th>Payment</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach($recent as $o): $paid = strtolower((string)($o['payment_status'] ?? ''))==='paid'; ?>
      <tr>
        <td><b><?= e($o['code']?:('#'.$o['id'])) ?></b></td>
        <td class="num muted"><?= e($o['order_date']) ?></td>
        <td><?= e($o['customer']?:'—') ?></td>
        <td class="num muted"><?= e($o['phone']?:'—') ?></td>
        <td><?= e($o['product_name']?:'—') ?><?= (int)$o['qty']>1?' <span class="muted">×'.(int)$o['qty'].'</span>':'' ?></td>
        <td class="num right"><b><?= money($o['sell_price']*$o['qty']) ?></b></td>
        <td><?= e($o['courier_name']?:'—') ?></td>
        <td><span class="pill <?= $paid?'p-green':'p-red' ?>" style="font-size:11px"><?= $paid?'Received':'Not Yet' ?></span></td>
        <td><?= pill($o['status']) ?></td>
      </tr>
    <?php endforeach; if(!$recent) echo '<tr><td colspan="9"><div class="empty">No orders yet — click <b>Sales</b> then <b>＋ Add Order</b> to create your first.</div></td></tr>'; ?>
    </tbody>
  </table></div>
</div>

<script>
/* Profit Note: tap "Expenses" to reveal the per-category breakdown */
function toggleExpBreakdown(row){
  var b = document.getElementById('expBreakdown'), c = document.getElementById('expChevron');
  if (!b) return;
  var open = b.style.display !== 'none';
  b.style.display = open ? 'none' : '';
  if (c) c.textContent = open ? '▸ tap to show' : '▾ tap to hide';
}

/* Return Rate metric card + panel: toggle headline between return % and cancel % */
function rrToggle(showCancel){
  document.querySelectorAll('.rr-return').forEach(function(el){ el.style.display = showCancel ? 'none' : ''; });
  document.querySelectorAll('.rr-cancel').forEach(function(el){ el.style.display = showCancel ? '' : 'none'; });
  var lr = document.getElementById('rrLabelReturn'), lc = document.getElementById('rrLabelCancel');
  if (lr && lc) { lr.classList.toggle('on', !showCancel); lc.classList.toggle('on', showCancel); }
}

var CH_GRID = (getComputedStyle(document.body).getPropertyValue('--border')||'#e9edf3').trim();
var baseOpts = {responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}}};

/* order status bar (delivered excluded so every bar is visible) */
new Chart(document.getElementById('statusChart'),{
  type:'bar',
  data:{labels:<?= json_encode(array_keys($statusChart)) ?>,
    datasets:[{data:<?= json_encode(array_values($statusChart)) ?>,
      backgroundColor:['#94a3b8','#6366f1','#f59e0b','#dc2626','#f43f5e'],
      borderRadius:6,maxBarThickness:54}]},
  options:Object.assign({},baseOpts,{scales:{y:{beginAtZero:true,grid:{color:CH_GRID},ticks:{precision:0}},x:{grid:{display:false}}}})
});

/* normal vs return doughnut */
new Chart(document.getElementById('pieChart'),{
  type:'doughnut',
  data:{labels:<?= json_encode(array_keys($normalVsReturn)) ?>,
    datasets:[{data:<?= json_encode(array_values($normalVsReturn)) ?>,
      backgroundColor:['#3b82f6','#e11d2b'],borderWidth:0}]},
  options:Object.assign({},baseOpts,{cutout:'62%',plugins:{legend:{display:true,position:'bottom',labels:{padding:14,usePointStyle:true}}}})
});

/* last 7 days */
new Chart(document.getElementById('last7Chart'),{
  type:'bar',
  data:{labels:<?= json_encode(array_map(fn($d)=>date('M j',strtotime($d)),array_keys($last7))) ?>,
    datasets:[{data:<?= json_encode(array_values($last7)) ?>,backgroundColor:'#60a5fa',borderRadius:5,maxBarThickness:46}]},
  options:Object.assign({},baseOpts,{scales:{y:{beginAtZero:true,grid:{color:CH_GRID},ticks:{precision:0}},x:{grid:{display:false}}}})
});

</script>
<?php require __DIR__.'/includes/footer.php'; ?>