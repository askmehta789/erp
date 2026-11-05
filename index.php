<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
repair_zero_cost_profit();   /* auto-fix orders saved with Rs.0 cost so profit is honest everywhere */
$PAGE_TITLE='Dashboard';

/* ---- pull all orders ---- */
$allOrders = rows("SELECT o.*, p.name AS product_name, c.name AS courier_name
                FROM orders o
                LEFT JOIN products p ON p.id=o.product_id
                LEFT JOIN couriers c ON c.id=o.courier_id
                ORDER BY o.order_date DESC, o.id DESC");

/* ---- period: quick presets or a custom from–to range (default: lifetime) ---- */
$today = date('Y-m-d');
$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
$firstOrder = $allOrders ? min(array_map(fn($o) => substr((string)$o['order_date'], 0, 10), $allOrders)) : $today;
$rangeDefs = [
  'today'     => ['Today',        $today, $today],
  'yesterday' => ['Yesterday',    date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
  '7d'        => ['Last 7 days',  date('Y-m-d', strtotime('-6 days')), $today],
  '30d'       => ['Last 30 days', date('Y-m-d', strtotime('-29 days')), $today],
  'month'     => ['This month',   date('Y-m-01'), date('Y-m-t')],
  'lastmonth' => ['Last month',   date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
  'year'      => ['This year',    date('Y-01-01'), date('Y-12-31')],
  'all'       => ['Lifetime',     $firstOrder, $today],
];
$range = (string)($_GET['range'] ?? '');
if (isset($rangeDefs[$range])) {
  [$rangeLabel, $start, $end] = $rangeDefs[$range];
} elseif ($isDate($_GET['from'] ?? '') && $isDate($_GET['to'] ?? '')) {
  $range = 'custom'; $start = $_GET['from']; $end = $_GET['to'];
  if ($start > $end) [$start, $end] = [$end, $start];
  $rangeLabel = date('j M', strtotime($start)).' – '.date('j M Y', strtotime($end));
} else {
  $range = 'all'; [$rangeLabel, $start, $end] = $rangeDefs['all'];   /* opening the ERP shows Lifetime */
}
$isLifetime = $range === 'all';
$inRange = fn($o, $a, $b) => ($d = substr((string)$o['order_date'], 0, 10)) >= $a && $d <= $b;
$orders = $isLifetime ? $allOrders : array_values(array_filter($allOrders, fn($o) => $inRange($o, $start, $end)));

/* previous period of the same length, to show ▲▼ change (calendar months compare to the previous month) */
$days = (int)round((strtotime($end) - strtotime($start)) / 86400) + 1;
if ($start === date('Y-m-01', strtotime($start)) && $end === date('Y-m-t', strtotime($start))) {
  $pStart = date('Y-m-01', strtotime($start.' -1 month')); $pEnd = date('Y-m-t', strtotime($pStart));
} else {
  $pEnd = date('Y-m-d', strtotime($start.' -1 day')); $pStart = date('Y-m-d', strtotime($pEnd.' -'.($days-1).' days'));
}
$prevLabel = date('j M', strtotime($pStart)).' – '.date('j M Y', strtotime($pEnd));

$mAgo  = date('Y-m-d', strtotime('-30 days'));

/* ---- counters ---- */
$total=count($orders); $delivered=0; $returned=0; $cancelled=0; $shipped=0;
$processing=0; $pending=0; $hold=0; $exchanged=0;
$revenue=0; $profit=0; $delivery=0; $cogs=0; $returnLoss=0; $exchangeLoss=0;
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
    case 'exchanged':  $exchanged++;  $exchangeLoss+=(float)$o['exchange_charge']; break;
    case 'shipped':    $shipped++;    $pendingValue+=$line; if($o['payment_type']==='cod'&&$o['payment_status']!=='paid') $codPending+=$line; break;
    case 'processing': $processing++; $pendingValue+=$line; if($o['payment_type']==='cod'&&$o['payment_status']!=='paid') $codPending+=$line; break;
    default:           $pending++;    $pendingValue+=$line; if($o['payment_type']==='cod'&&$o['payment_status']!=='paid') $codPending+=$line;
  }
}
/* today's details and the 7-day chart always describe the real "today", whatever period is picked */
foreach($allOrders as $o){
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
$expWhere    = $isLifetime ? "" : "WHERE expense_date BETWEEN ? AND ?";
$expParams   = $isLifetime ? [] : [$start, $end];
$expenses    = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses $expWhere", $expParams);
$expenseByCat = rows("SELECT category, COALESCE(SUM(amount),0) AS total FROM expenses $expWhere GROUP BY category ORDER BY total DESC", $expParams);
$net         = $profit - $expenses;

/* ---- previous period, same rules ---- */
$P = ['orders'=>0,'delivered'=>0,'revenue'=>0.0,'profit'=>0.0,'cogs'=>0.0,'delivery'=>0.0,'returnLoss'=>0.0,'exchangeLoss'=>0.0,'returned'=>0];
if (!$isLifetime) {
  foreach ($allOrders as $o) {
    if (!$inRange($o, $pStart, $pEnd)) continue;
    $P['orders']++; $P['revenue'] += order_revenue($o); $P['profit'] += order_profit($o);
    if ($o['status']==='delivered') { $P['delivered']++; $P['cogs'] += (float)$o['cost_price']*(int)$o['qty']; $P['delivery'] += (float)$o['delivery_charge']; }
    elseif (in_array($o['status'],['returned','cancelled'],true)) { $P['returnLoss'] += (float)$o['cancel_charge']; if ($o['status']==='returned') $P['returned']++; }
    elseif ($o['status']==='exchanged') $P['exchangeLoss'] += (float)$o['exchange_charge'];
  }
  $P['expenses'] = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?", [$pStart, $pEnd]);
  $P['net'] = $P['profit'] - $P['expenses'];
  $P['returnRate'] = $P['orders'] ? round($P['returned']/$P['orders']*100,1) : 0;
}
/* ▲▼ change text + colour; $goodUp=false for costs, $pts for percentages */
function dash_delta($now, $before, bool $goodUp = true, bool $pts = false): array {
  if ($before === null) return ['', ''];
  if ($pts) { $d = $now - $before; if (abs($d) < 0.05) return ['no change','muted'];
    return [($d>0?'▲ ':'▼ ').round(abs($d),1).' pts', (($d>0)===$goodUp)?'dd-up':'dd-dn']; }
  if (abs($before) < 0.01) return [abs($now) < 0.01 ? '—' : 'new', 'muted'];
  $pc = ($now - $before) / abs($before) * 100;
  if (abs($pc) < 0.5) return ['no change','muted'];
  return [($pc>0?'▲ ':'▼ ').round(abs($pc)).'%', (($pc>0)===$goodUp)?'dd-up':'dd-dn'];
}
$cmp = fn($k) => $isLifetime ? null : $P[$k];

/* ---- trend: by day for short periods, by month for long ones ---- */
$byMonth = $days > 62;
$bk = fn($d) => $byMonth ? substr($d, 0, 7) : $d;
$trend = [];
if ($byMonth) { for ($t = strtotime(substr($start,0,7).'-01'); $t <= strtotime($end); $t = strtotime('+1 month', $t)) $trend[date('Y-m', $t)] = ['rev'=>0.0,'profit'=>0.0,'exp'=>0.0]; }
else { for ($t = strtotime($start); $t <= strtotime($end); $t += 86400) $trend[date('Y-m-d', $t)] = ['rev'=>0.0,'profit'=>0.0,'exp'=>0.0]; }
foreach ($orders as $o) { $k = $bk(substr((string)$o['order_date'],0,10)); if (!isset($trend[$k])) continue; $trend[$k]['rev'] += order_revenue($o); $trend[$k]['profit'] += order_profit($o); }
foreach (rows("SELECT expense_date d, SUM(amount) a FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY expense_date", [$start, $end]) as $x) {
  $k = $bk($x['d']); if (isset($trend[$k])) { $trend[$k]['exp'] += (float)$x['a']; $trend[$k]['profit'] -= (float)$x['a']; }
}

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
  'Exchanged'  => $exchanged,
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

/* build a "needs attention" to-do list */
$todo=[];
$awaiting = $pending + $processing;
if ($awaiting>0)     $todo[] = ['🕒','var(--amber)', "$awaiting order".($awaiting>1?'s':'')." awaiting dispatch", 'sales.php?status=pending,processing'];
if ($shipped>0)      $todo[] = ['🚚','var(--blue)',  "$shipped order".($shipped>1?'s':'')." in transit", 'sales.php?status=shipped'];
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

/* Sales links carry the selected period, so a click shows exactly these orders */
$sq = $isLifetime ? '' : '&from='.urlencode($start).'&to='.urlencode($end);

require __DIR__.'/includes/header.php';
$tile = function($label, $value, $d, $icon, $cls, $href = null) {
  $tag = $href ? 'a href="'.e($href).'"' : 'div';
  return '<'.$tag.' class="metric '.$cls.($href?' mlink':'').'" style="text-decoration:none;color:inherit"><span class="g">'.$icon.'</span>'
    .'<div class="mv">'.$value.'</div><div class="ml">'.e($label).($d[0] !== '' ? ' <span class="'.$d[1].'">'.e($d[0]).'</span>' : '').'</div></'.($href?'a':'div').'>';
};
?>
<style>
/* dashboard layout: one spacing scale (16px between cards, 18px inside) */
.db{display:flex;flex-direction:column;gap:16px}
.db .panel{margin:0}
.db-row{display:grid;gap:16px;align-items:stretch}
.db-r2{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
.db-r3{grid-template-columns:minmax(0,1.15fr) minmax(0,1fr) minmax(0,.95fr)}
.db-r21{grid-template-columns:minmax(0,2fr) minmax(0,1fr)}
@media(max-width:1180px){.db-r3{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.db-r3>:last-child{grid-column:1/-1}}
@media(max-width:860px){.db-r2,.db-r3,.db-r21{grid-template-columns:1fr}}
.db .mgrid{margin:0;grid-template-columns:repeat(6,minmax(0,1fr));gap:16px}
@media(max-width:1280px){.db .mgrid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:620px){.db .mgrid{grid-template-columns:repeat(2,minmax(0,1fr))}}
.db .metric .mv{font-size:22px}
.db .metric .ml{line-height:1.5}
.dd-up{color:var(--green);font-weight:800}.dd-dn{color:var(--red);font-weight:800}
.db .panel-body{padding:16px 18px}
.db .panel-head h2 small{font-size:12px;color:var(--muted);font-weight:600;text-transform:none;letter-spacing:0}
/* date bar */
.db-range{display:flex;align-items:center;gap:10px 14px;flex-wrap:wrap;padding:12px 16px}
.db-range .lbl{font-size:13px}
.db-pills{display:flex;gap:6px;flex-wrap:wrap}
.db-pill{padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;text-decoration:none;color:var(--ink);border:1px solid rgba(148,163,184,.35);background:rgba(255,255,255,.6)}
body.dark .db-pill{background:rgba(2,6,23,.4)}
.db-pill:hover{border-color:#3b82f6}
.db-pill.on{background:#3b82f6;border-color:#3b82f6;color:#fff}
.db-range form{display:inline-flex;gap:6px;align-items:center;margin-left:auto}
.db-range input{border-radius:999px;padding:7px 12px;border:1px solid rgba(148,163,184,.35);font-size:12.5px}
/* profit & loss */
.db-pl{width:100%;border-collapse:collapse}
.db-pl td{padding:8px 4px;font-size:13px;border-bottom:1px solid rgba(148,163,184,.12)}
.db-pl td.r{text-align:right;white-space:nowrap}
.db-pl tr.h td{font-size:12px;color:var(--muted);border-bottom:none}
.db-pl tr.sub td{font-weight:800}
.db-pl tr.tot td{border-top:2px solid rgba(148,163,184,.4);border-bottom:none;font-weight:800}
.db-split{display:flex;height:26px;border-radius:10px;overflow:hidden;margin-top:14px}
.db-split i{display:flex;align-items:center;justify-content:center;color:#fff;font-style:normal;font-size:10.5px;font-weight:800;overflow:hidden;white-space:nowrap}
/* orders grid */
.db .vsplit .vcell{padding:14px 16px;display:block;text-decoration:none;color:inherit}
.db .vsplit a.vcell:hover{filter:brightness(.97)}
.db .vcell .vv{margin-top:4px}
.db .vcell .vs{font-size:11.5px;color:var(--muted);font-weight:600;margin-top:2px}
.db .info-row a{color:inherit;text-decoration:none}
/* attention inside the row */
.db .att-card{position:static;height:100%}
/* ---------- phones ---------- */
@media(max-width:640px){
  .page-head{gap:10px}
  .page-head p{font-size:12.5px}
  .ncmv3.db{padding:0;margin:0;background:none;box-shadow:none;border:0;gap:12px}
  .db-row{gap:12px}
  .db .panel-head{padding:12px 14px}
  .db .panel-head h2{font-size:13px}
  .db .panel-body{padding:12px 14px}
  /* search: input takes the row, small button */
  .db > form.panel{padding:8px 10px;gap:8px}
  .db > form.panel .btn{padding:8px 12px}
  /* date bar: one swipeable row of periods, dates + Apply on their own row */
  .db-range{padding:12px;gap:10px}
  .db-range .lbl{width:100%}
  .db-pills{flex-wrap:nowrap;overflow-x:auto;width:100%;margin:0 -2px;padding:2px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
  .db-pills::-webkit-scrollbar{display:none}
  .db-pill{flex:none}
  .db-range form{margin-left:0;width:100%;display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);gap:6px}
  .db-range form input{width:100%;min-width:0;padding:7px 8px;font-size:12px}
  .db-range form .btn{grid-column:1/-1;width:100%}
  /* KPI tiles: two per row, smaller type, quieter icon */
  .db .mgrid{gap:10px}
  .db .metric{padding:12px 13px;min-width:0}
  .db .metric .mv{font-size:17px;word-break:break-word}
  .db .metric .ml{font-size:10px;letter-spacing:.03em;line-height:1.45}
  .db .metric .g{font-size:34px;right:-6px;top:-8px;opacity:.08}
  /* P&L: hide the Previous column, keep Change */
  .db-pl td{font-size:12.5px;padding:7px 2px}
  .db-pl .pv{display:none}
  .db-split{height:22px;margin-top:10px}
  .db .chart-box{height:220px!important}
  /* boxes stay two per row instead of one long column */
  .db .vsplit{grid-template-columns:1fr 1fr}
  .db .vsplit .vcell{padding:11px 12px}
  .db .vcell .vl{font-size:10.5px}
  .db .vcell .vv{font-size:17px}
  .db .info-row{font-size:12.5px}
  .db .grid.cols-2{grid-template-columns:1fr}
}
</style>

<div class="page-head">
  <div><h1>📊 Dashboard</h1><p>Welcome back, <?= e(current_user()['name']) ?> — your store at a glance · <b><?= e($rangeLabel) ?></b><?= $isLifetime ? '' : ' <span class="muted">vs '.e($prevLabel).'</span>' ?></p></div>
  <a class="btn btn-primary" href="sales.php">+ New Order</a>
</div>

<div class="ncmv3 db">

<form method="get" action="search.php" class="panel" style="display:flex;gap:10px;padding:12px 14px">
  <input class="search-in" name="q" placeholder="🔍 Search anything — orders, customers, phone, product, tracking #, courier, users…">
  <button class="btn btn-primary">Search</button>
</form>

<!-- 1) period -->
<div class="panel db-range">
  <span class="lbl">📅 Showing <b><?= e($rangeLabel) ?></b></span>
  <div class="db-pills">
    <?php foreach ($rangeDefs as $rk => $rd): ?><a class="db-pill<?= $range===$rk?' on':'' ?>" href="index.php?range=<?= $rk ?>"><?= e($rd[0]) ?></a><?php endforeach; ?>
  </div>
  <form method="get" action="index.php">
    <input type="date" name="from" value="<?= e($start) ?>" aria-label="From date"><span class="muted">to</span>
    <input type="date" name="to" value="<?= e($end) ?>" aria-label="To date">
    <button class="btn nh-ghost">Apply</button>
  </form>
</div>

<!-- 2) key numbers with change vs previous period -->
<div class="mgrid">
  <?= $tile('Orders', number_format($total), dash_delta($total, $cmp('orders')), '🛍', 'blue', 'sales.php?'.ltrim($sq,'&')) ?>
  <?= $tile('Delivered revenue', money($revenue), dash_delta($revenue, $cmp('revenue')), '💰', 'green') ?>
  <?= $tile('Gross profit', money($profit), dash_delta($profit, $cmp('profit')), '💵', 'indigo') ?>
  <?= $tile('Expenses', money($expenses), dash_delta($expenses, $cmp('expenses'), false), '💸', 'orange', 'expenses.php') ?>
  <?= $tile('Net profit', '<span style="color:'.($net>=0?'var(--green)':'var(--red)').'">'.money($net).'</span>', dash_delta($net, $cmp('net')), '🧾', $net>=0?'teal':'red') ?>
  <?= $tile('Return rate', $returnRate.'%', dash_delta($returnRate, $cmp('returnRate'), false, true), '↩️', 'red', 'sales.php?status=returned'.$sq) ?>
</div>

<!-- 3) profit & loss + trend -->
<div class="db-row db-r2">
  <div class="panel">
    <div class="panel-head"><h2>🧾 Profit &amp; Loss</h2><span class="muted" style="font-size:12px"><?= $isLifetime ? 'all time' : 'this period vs previous' ?></span></div>
    <div class="panel-body">
      <table class="db-pl">
        <tr class="h"><td></td><td class="r">This period</td><?php if(!$isLifetime): ?><td class="r pv">Previous</td><td class="r">Change</td><?php endif; ?></tr>
        <?php
          $plRows = [
            ['Revenue (delivered)', $revenue, $cmp('revenue'), true, ''],
            ['− Product cost', $cogs, $cmp('cogs'), false, ''],
            ['− Delivery charges', $delivery, $cmp('delivery'), false, ''],
            ['− Return / cancel charges', $returnLoss, $cmp('returnLoss'), false, ''],
          ];
          if ($exchangeLoss > 0 || ($cmp('exchangeLoss') ?? 0) > 0) $plRows[] = ['− Exchange charges', $exchangeLoss, $cmp('exchangeLoss'), false, ''];
          $plRows[] = ['= Gross profit · '.$margin.'%', $profit, $cmp('profit'), true, 'sub'];
          $plRows[] = ['− Expenses', $expenses, $cmp('expenses'), false, 'exp'];
          foreach ($plRows as [$l, $a, $b, $up, $cls]): $d = dash_delta($a, $b, $up);
        ?>
          <tr class="<?= $cls==='sub'?'sub':'' ?>"<?= $cls==='exp' && $expenseByCat ? ' style="cursor:pointer" onclick="toggleExpBreakdown()"' : '' ?>>
            <td><?= e($l) ?><?php if($cls==='exp' && $expenseByCat): ?> <span id="expChevron" class="muted" style="font-size:10px">▸ by category</span><?php endif; ?></td>
            <td class="r"<?= $cls==='sub'?' style="color:var(--green)"':'' ?>><?= money($a) ?></td>
            <?php if(!$isLifetime): ?><td class="r muted pv"><?= money($b) ?></td><td class="r"><span class="<?= $d[1] ?>"><?= e($d[0]) ?></span></td><?php endif; ?>
          </tr>
          <?php if($cls==='exp' && $expenseByCat): ?>
          <tbody id="expBreakdown" style="display:none">
            <?php foreach($expenseByCat as $ec): $pct = $expenses>0? round($ec['total']/$expenses*100,1):0; ?>
            <tr><td class="muted" style="padding-left:16px;font-size:12px">↳ <?= e($ec['category']) ?></td><td class="r muted" style="font-size:12px"><?= money($ec['total']) ?> (<?= $pct ?>%)</td><?php if(!$isLifetime): ?><td class="pv"></td><td></td><?php endif; ?></tr>
            <?php endforeach; ?>
          </tbody>
          <?php endif; ?>
        <?php endforeach; $d = dash_delta($net, $cmp('net')); ?>
        <tr class="tot"><td>Net profit · <?= $netMargin ?>% margin</td>
          <td class="r" style="color:<?= $net>=0?'var(--green)':'var(--red)' ?>"><?= money($net) ?></td>
          <?php if(!$isLifetime): ?><td class="r muted pv"><?= money($P['net']) ?></td><td class="r"><span class="<?= $d[1] ?>"><?= e($d[0]) ?></span></td><?php endif; ?></tr>
      </table>
      <?php if ($revenue > 0):
        $segs = [['Product cost', $cogs, '#f59e0b'], ['Delivery', $delivery, '#fb923c'], ['Returns', $returnLoss + $exchangeLoss, '#f43f5e'], ['Expenses', $expenses, '#ef4444'], ['Net', max(0, $net), '#14b8a6']];
        $sb = max($revenue, array_sum(array_column($segs, 1))); ?>
        <div class="db-split" title="Where delivered revenue went">
          <?php foreach ($segs as [$l, $v, $c]): if ($v <= 0) continue; $p = $v / $sb * 100; ?><i style="flex:<?= round($p,2) ?>;background:<?= $c ?>" title="<?= e($l.' '.money($v).' ('.round($p).'%)') ?>"><?= $p >= 10 ? e($l).' '.round($p).'%' : '' ?></i><?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>📈 <?= $byMonth ? 'Monthly' : 'Daily' ?> trend</h2><span class="muted" style="font-size:12px">revenue · net profit · expenses</span></div>
    <div class="panel-body"><div class="chart-box" style="height:300px"><canvas id="trendChart"></canvas></div></div>
  </div>
</div>

<!-- 4) orders · cash · attention -->
<div class="db-row db-r3">
  <div class="panel">
    <div class="panel-head"><h2>📦 Orders <small>· <?= number_format($total) ?> in <?= e(strtolower($rangeLabel)) ?></small></h2><a class="btn btn-sm" href="sales.php">Open Sales →</a></div>
    <div class="panel-body">
      <div class="vsplit">
        <a class="vcell tint-a" href="sales.php?status=pending<?= $sq ?>"><div class="vl">Pending</div><div class="vv" style="color:var(--amber)"><?= number_format($pending) ?></div></a>
        <a class="vcell tint-b" href="sales.php?status=processing,shipped<?= $sq ?>"><div class="vl">In Delivery</div><div class="vv" style="color:var(--blue)"><?= number_format($inDelivery) ?></div></a>
        <a class="vcell tint-g" href="sales.php?status=delivered<?= $sq ?>"><div class="vl">Delivered</div><div class="vv" style="color:var(--green)"><?= number_format($delivered) ?></div><div class="vs"><?= number_format($unitsDelivered) ?> pcs sold</div></a>
        <a class="vcell tint-r" href="sales.php?status=returned,cancelled<?= $sq ?>"><div class="vl">Returned · Cancelled</div><div class="vv" style="color:var(--red)"><?= number_format($returned) ?> · <?= number_format($cancelled) ?></div><div class="vs"><?= $returnRate ?>% · <?= $cancelRate ?>%</div></a>
      </div>
      <?php if($exchanged>0): ?><div class="info-row" style="margin-top:6px"><span class="ii p">🔁</span><span class="it">Exchanged</span><span class="iv"><?= number_format($exchanged) ?> · cost <?= money($exchangeLoss) ?></span></div><?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>💰 Cash on Delivery</h2><a class="btn btn-sm" href="cod.php">COD ledger →</a></div>
    <div class="panel-body" style="padding-top:6px">
      <div class="info-row"><span class="ii g">💵</span><span class="it">COD Collected</span><span class="iv"><?= money($codCollected) ?></span></div>
      <div class="info-row"><span class="ii t">⏳</span><span class="it"><a href="cod.php">COD In Transit (undelivered)</a></span><span class="iv"><?= money($codPending) ?></span></div>
      <div class="info-row"><span class="ii a">🚚</span><span class="it"><a href="cod.php">COD With Couriers (release pending)</a></span><span class="iv"><?= money($codHeld) ?></span></div>
      <div class="info-row"><span class="ii b">📦</span><span class="it">Pending order value</span><span class="iv"><?= money($pendingValue) ?></span></div>
    </div>
  </div>

  <?php if($todo): ?>
  <aside class="att-card panel noglow">
    <div class="att-head">⚡ Needs attention <span class="att-n"><?= count($todo) ?></span></div>
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
  <?php else: ?>
  <div class="panel"><div class="panel-head"><h2>⚡ Needs attention</h2></div><div class="panel-body"><div class="muted">✓ All clear — nothing needs your attention.</div></div></div>
  <?php endif; ?>
</div>

<!-- 5) charts + today -->
<div class="db-row db-r21">
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

<!-- 6) order values · return rate · stock -->
<div class="db-row db-r3">
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

  <div class="panel"><div class="panel-head"><h2>📦 Stock Details</h2><a class="btn btn-sm" href="products.php">Inventory →</a></div>
    <div class="panel-body" style="padding-top:6px">
      <div class="info-row"><span class="ii b">🏷️</span><span class="it">Products</span><span class="iv"><?= number_format($stkProducts) ?></span></div>
      <div class="info-row"><span class="ii t">📦</span><span class="it">Units in Stock</span><span class="iv"><?= number_format($stkUnits) ?></span></div>
      <div class="info-row"><span class="ii g">💰</span><span class="it">Stock Value (cost)</span><span class="iv"><?= money($stkValue) ?></span></div>
      <div class="info-row"><span class="ii a">⚠️</span><span class="it">Low Stock Items</span><span class="iv" style="color:var(--amber)"><?= $stkLow ?></span></div>
      <div class="info-row"><span class="ii r">🚫</span><span class="it">Out of Stock</span><span class="iv" style="color:var(--red)"><?= $stkOut ?></span></div>
    </div>
  </div>
</div>

<!-- 7) recent orders -->
<div class="panel">
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

</div><!-- /ncmv3 db -->

<script>
/* Profit & Loss: tap "Expenses" to reveal the per-category breakdown */
function toggleExpBreakdown(){
  var b = document.getElementById('expBreakdown'), c = document.getElementById('expChevron');
  if (!b) return;
  var open = b.style.display !== 'none';
  b.style.display = open ? 'none' : '';
  if (c) c.textContent = open ? '▸ by category' : '▾ by category';
}

/* Return Rate panel: toggle headline between return % and cancel % */
function rrToggle(showCancel){
  document.querySelectorAll('.rr-return').forEach(function(el){ el.style.display = showCancel ? 'none' : ''; });
  document.querySelectorAll('.rr-cancel').forEach(function(el){ el.style.display = showCancel ? '' : 'none'; });
  var lr = document.getElementById('rrLabelReturn'), lc = document.getElementById('rrLabelCancel');
  if (lr && lc) { lr.classList.toggle('on', !showCancel); lc.classList.toggle('on', showCancel); }
}

var CH_GRID = (getComputedStyle(document.body).getPropertyValue('--border')||'#e9edf3').trim();
var baseOpts = {responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}}};

/* revenue / net profit / expenses trend for the selected period */
new Chart(document.getElementById('trendChart'),{
  data:{labels:<?= json_encode(array_map(fn($k)=>$byMonth ? date('M Y',strtotime($k.'-01')) : date('j M',strtotime($k)), array_keys($trend))) ?>,
    datasets:[
      {type:'bar',label:'Revenue',data:<?= json_encode(array_values(array_map(fn($t)=>round($t['rev']), $trend))) ?>,backgroundColor:'rgba(59,130,246,.45)',borderRadius:5,maxBarThickness:38},
      {type:'line',label:'Net profit',data:<?= json_encode(array_values(array_map(fn($t)=>round($t['profit']), $trend))) ?>,borderColor:'#14b8a6',backgroundColor:'#14b8a6',tension:.35,pointRadius:2},
      {type:'line',label:'Expenses',data:<?= json_encode(array_values(array_map(fn($t)=>round($t['exp']), $trend))) ?>,borderColor:'#ef4444',backgroundColor:'#ef4444',borderDash:[5,4],tension:.35,pointRadius:0}
    ]},
  options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
    plugins:{legend:{display:true,position:'top',labels:{usePointStyle:true,padding:14}}},
    scales:{x:{grid:{display:false},ticks:{maxRotation:0,autoSkip:true}},y:{grid:{color:CH_GRID}}}}
});

/* order status bar (delivered excluded so every bar is visible) */
new Chart(document.getElementById('statusChart'),{
  type:'bar',
  data:{labels:<?= json_encode(array_keys($statusChart)) ?>,
    datasets:[{data:<?= json_encode(array_values($statusChart)) ?>,
      backgroundColor:['#94a3b8','#6366f1','#f59e0b','#dc2626','#f43f5e','#2563eb'],
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
