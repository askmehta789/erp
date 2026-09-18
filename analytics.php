<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
repair_zero_cost_profit();   /* auto-fix orders saved with Rs.0 cost so profit is honest everywhere */
$PAGE_TITLE='Analytics';

/* ---- controls ---- */
$g    = in_array($_GET['g'] ?? '', ['day','week','month'], true) ? $_GET['g'] : 'day';
$to   = $_GET['to']   ?? date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-d', strtotime($g==='month' ? '-6 months' : ($g==='week' ? '-8 weeks' : '-14 days')));
if ($from > $to) { $t=$from; $from=$to; $to=$t; }

/* period key + label */
function pkey($date,$g){ $ts=strtotime($date); if($g==='month')return date('Y-m',$ts); if($g==='week')return date('o-\WW',$ts); return date('Y-m-d',$ts); }
function plabel($k,$g){ if($g==='month')return date('M Y',strtotime($k.'-01')); if($g==='week')return str_replace('-W',' W',$k); return date('d M',strtotime($k)); }

$adKw=['advert','ad spend','ads','marketing','facebook','fb','insta','google','tiktok','boost','meta','ppc','campaign','promotion'];

$pid = (int)($_GET['pid'] ?? 0);
$pw  = $pid ? " AND o.product_id = ".$pid : "";
$orders = rows("SELECT o.*, p.name AS product_name, c.name AS courier_name
                FROM orders o LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id
                WHERE o.order_date BETWEEN ? AND ? $pw ORDER BY o.order_date", [$from,$to]);
$allProducts = rows("SELECT id,name FROM products ORDER BY name");
$pName = $pid ? (string)val("SELECT name FROM products WHERE id=?",[$pid]) : '';

/* ---- per-period aggregation ---- */
$periods=[]; $prod=[]; $prodPeriod=[];
$ensure=function(&$a,$k){ if(!isset($a[$k])) $a[$k]=['orders'=>0,'oqty'=>0,'ordval'=>0,'delivered'=>0,'units'=>0,'revenue'=>0,'cogs'=>0,'delivery'=>0,'profit'=>0,'ads'=>0,'returned'=>0,'runits'=>0,'rloss'=>0]; };
$Q=['pending'=>0,'processing'=>0,'shipped'=>0,'delivered'=>0,'returned'=>0,'cancelled'=>0];
$deep=[];
foreach($orders as $o){
  $k=pkey($o['order_date'],$g); $ensure($periods,$k);
  $line=(float)$o['sell_price']*(int)$o['qty'];
  $q=(int)$o['qty'];
  $periods[$k]['orders']++;
  $periods[$k]['oqty']+=$q; $periods[$k]['ordval']+=$line;
  if(isset($Q[$o['status']])) $Q[$o['status']]+=$q;
  $dn=$o['product_name']?:'(none)';
  if(!isset($deep[$dn])) $deep[$dn]=['orders'=>0,'oq'=>0,'dq'=>0,'pq'=>0,'rq'=>0,'rev'=>0,'cogs'=>0,'prof'=>0];
  $deep[$dn]['orders']++; $deep[$dn]['oq']+=$q;
  if($o['status']==='delivered'){
    $deep[$dn]['dq']+=$q; $deep[$dn]['rev']+=$line;
    $deep[$dn]['cogs']+=(float)$o['cost_price']*$q;
    $deep[$dn]['prof']+=((float)$o['sell_price']-(float)$o['cost_price'])*$q-(float)$o['delivery_charge'];
  } elseif(in_array($o['status'],['returned','cancelled'],true)){
    $deep[$dn]['rq']+=$q; $deep[$dn]['prof']-=(float)$o['cancel_charge'];
  } else { $deep[$dn]['pq']+=$q; }
  if($o['status']==='delivered'){
    $periods[$k]['delivered']++; $periods[$k]['units']+=(int)$o['qty']; $periods[$k]['revenue']+=$line;
    $periods[$k]['cogs']+=(float)$o['cost_price']*(int)$o['qty'];
    $periods[$k]['delivery']+=(float)$o['delivery_charge'];
    $periods[$k]['profit']+=((float)$o['sell_price']-(float)$o['cost_price'])*(int)$o['qty']-(float)$o['delivery_charge'];
    $pn=$o['product_name']?:'(none)';
    if(!isset($prod[$pn])) $prod[$pn]=['units'=>0,'revenue'=>0,'profit'=>0];
    $prod[$pn]['units']+=(int)$o['qty']; $prod[$pn]['revenue']+=$line;
    $prod[$pn]['profit']+=((float)$o['sell_price']-(float)$o['cost_price'])*(int)$o['qty']-(float)$o['delivery_charge'];
    $prodPeriod[$pn][$k]=($prodPeriod[$pn][$k]??0)+$line;
  } elseif(in_array($o['status'],['returned','cancelled'],true)) {
    $periods[$k]['returned']++; $periods[$k]['runits']+=$q; $periods[$k]['profit']-=(float)$o['cancel_charge']; $periods[$k]['rloss']+=(float)$o['cancel_charge'];
    $pn=$o['product_name']?:'(none)'; if(!isset($prod[$pn])) $prod[$pn]=['units'=>0,'revenue'=>0,'profit'=>0];
    $prod[$pn]['profit']-=(float)$o['cancel_charge'];
  }
}
/* ad spend per period */
$adRows=rows("SELECT expense_date, amount, category, COALESCE(usd_amount,0) usd_amount, COALESCE(product,'') product FROM expenses WHERE expense_date BETWEEN ? AND ?",[$from,$to]);
/* when a product filter is on, count only ads TAGGED to that product */
$adFits=function($e) use($pid,$pName){
  if(!$pid) return true;
  return strcasecmp(trim((string)$e['product']), trim((string)$pName))===0;
};
$adsUntagged=0;
foreach($adRows as $e){ $cat=strtolower((string)$e['category']); $isAd=false; foreach($adKw as $kw){ if(strpos($cat,$kw)!==false){$isAd=true;break;} }
  if(!$isAd) continue;
  if(!$adFits($e)){ $adsUntagged+=(float)$e['amount']; continue; }
  $k=pkey($e['expense_date'],$g); $ensure($periods,$k); $periods[$k]['ads']+=(float)$e['amount']; }
ksort($periods);

/* ---- range totals ---- */
$T=['orders'=>0,'oqty'=>0,'ordval'=>0,'delivered'=>0,'units'=>0,'revenue'=>0,'cogs'=>0,'delivery'=>0,'profit'=>0,'ads'=>0,'returned'=>0,'runits'=>0,'rloss'=>0];
$todaySnap=row("SELECT COUNT(*) n, COALESCE(SUM(qty),0) q, COALESCE(SUM(sell_price*qty),0) v FROM orders WHERE order_date=CURDATE()");
foreach($periods as $p) foreach($T as $k=>$v) $T[$k]+=$p[$k];
$aov       = $T['delivered']? $T['revenue']/$T['delivered'] : 0;
$roas      = $T['ads']>0? $T['revenue']/$T['ads'] : 0;
$adsPerOrder = $T['orders']? $T['ads']/$T['orders'] : 0;
$netAfterAds = $T['profit'] - $T['ads'];
$returnRate  = $T['orders']? $T['returned']/$T['orders']*100 : 0;

/* ---- growth vs previous equal-length period ---- */
$span=(strtotime($to)-strtotime($from))/86400+1;
$prevTo=date('Y-m-d',strtotime("$from -1 day"));
$prevFrom=date('Y-m-d',strtotime("$prevTo -".($span-1)." day"));
$prevRev=0;$prevOrders=0;$prevProfit=0;
foreach(rows("SELECT sell_price,qty,cost_price,delivery_charge,cancel_charge,status FROM orders WHERE order_date BETWEEN ? AND ?",[$prevFrom,$prevTo]) as $o){
  $prevOrders++;
  if($o['status']==='delivered'){ $prevRev+=(float)$o['sell_price']*(int)$o['qty']; $prevProfit+=((float)$o['sell_price']-(float)$o['cost_price'])*(int)$o['qty']-(float)$o['delivery_charge']; }
  elseif(in_array($o['status'],['returned','cancelled'],true)){ $prevProfit-=(float)$o['cancel_charge']; }
}
$gRev   = $prevRev>0? round(($T['revenue']-$prevRev)/$prevRev*100,1) : null;
$gOrd   = $prevOrders>0? round(($T['orders']-$prevOrders)/$prevOrders*100,1) : null;
$gProf  = $prevProfit!=0? round(($T['profit']-$prevProfit)/abs($prevProfit)*100,1) : null;

/* ---- top products + best period ---- */
arsort($prod); $topProd=array_slice($prod,0,10,true);
$top5=array_slice(array_keys($prod),0,5);
$bestPeriod=null;$bestRev=-1; foreach($periods as $k=>$p){ if($p['revenue']>$bestRev){$bestRev=$p['revenue'];$bestPeriod=$k;} }



/* chart series */
$labels=array_map(fn($k)=>plabel($k,$g), array_keys($periods));
$serRev=array_map(fn($p)=>round($p['revenue']), array_values($periods));
$serOrd=array_map(fn($p)=>$p['orders'], array_values($periods));
$serProf=array_map(fn($p)=>round($p['profit']), array_values($periods));
$serAds=array_map(fn($p)=>round($p['ads']), array_values($periods));
$serRoas=array_map(fn($p)=>$p['ads']>0?round($p['revenue']/$p['ads'],2):0, array_values($periods));
$palette=['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ef4444','#14b8a6'];
$stackDs=[]; $i=0; foreach($top5 as $pn){ $data=[]; foreach(array_keys($periods) as $k) $data[]=round($prodPeriod[$pn][$k]??0); $stackDs[]=['label'=>$pn,'data'=>$data,'backgroundColor'=>$palette[$i%count($palette)]]; $i++; }
/* ---- simple linear-trend forecast for the next period ---- */
function forecast_next(array $ys): ?float {
  $ys=array_values(array_map('floatval',$ys)); $n=count($ys);
  if($n<3) return null;
  $ys=array_slice($ys,-8); $n=count($ys);                 // use up to last 8 periods
  $sx=0;$sy=0;$sxy=0;$sxx=0;
  foreach($ys as $i=>$y){ $x=$i+1; $sx+=$x;$sy+=$y;$sxy+=$x*$y;$sxx+=$x*$x; }
  $den=$n*$sxx-$sx*$sx; if($den==0) return null;
  $b=($n*$sxy-$sx*$sy)/$den; $a=($sy-$b*$sx)/$n;
  return max(0, $a+$b*($n+1));
}
$fRev  = forecast_next($serRev);
$fOrd  = forecast_next($serOrd);
$fProf = forecast_next($serProf);
$trendUp = ($fRev!==null && count($serRev)>0) ? ($fRev >= end($serRev)) : null;


function grow($v){ if($v===null) return '<span class="muted" style="font-size:11px">—</span>'; $c=$v>=0?'var(--green)':'var(--red)'; $a=$v>=0?'▲':'▼'; return '<span style="color:'.$c.';font-size:11px;font-weight:800">'.$a.' '.abs($v).'%</span>'; }

require __DIR__.'/includes/header.php';
?>
<div class="page-head">
  <div><h1>📈 Analytics</h1><p><?= ucfirst($g) ?> report · <?= e($from) ?> → <?= e($to) ?> · vs previous <?= (int)$span ?> days</p></div>
<a class="btn" href="reports.php" style="align-self:center">📑 Classic Reports</a>
  <div style="display:flex;gap:10px">
    <button class="btn" onclick="window.print()">🖨 Print</button>
    <button class="btn btn-primary" onclick="exportPeriods()">⬇ CSV</button>
  </div>
</div>

<div class="card" style="margin-bottom:18px"><form method="get" class="toolbar" style="align-items:flex-end;flex-wrap:wrap;gap:12px">
  <div><label style="font-size:12px;color:var(--muted);font-weight:700">View</label><br>
    <select name="g" onchange="this.form.submit()">
      <option value="day"<?= $g==='day'?' selected':'' ?>>Daily</option>
      <option value="week"<?= $g==='week'?' selected':'' ?>>Weekly</option>
      <option value="month"<?= $g==='month'?' selected':'' ?>>Monthly</option>
    </select></div>
  <div><label style="font-size:12px;color:var(--muted);font-weight:700">Product</label><br>
    <select name="pid"><option value="0">All products</option>
    <?php foreach($allProducts as $ap): ?><option value="<?= (int)$ap['id'] ?>"<?= $pid===(int)$ap['id']?' selected':'' ?>><?= e($ap['name']) ?></option><?php endforeach; ?></select></div>
  <div><label style="font-size:12px;color:var(--muted);font-weight:700">From</label><br><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label style="font-size:12px;color:var(--muted);font-weight:700">To</label><br><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-primary">Apply</button>
  <div style="display:flex;gap:6px">
    <a class="btn btn-sm" href="?g=day&from=<?= date('Y-m-d',strtotime('-6 days')) ?>&to=<?= date('Y-m-d') ?>">7 days</a>
    <a class="btn btn-sm" href="?g=day&from=<?= date('Y-m-d',strtotime('-29 days')) ?>&to=<?= date('Y-m-d') ?>">30 days</a>
    <a class="btn btn-sm" href="?g=week&from=<?= date('Y-m-d',strtotime('-11 weeks')) ?>&to=<?= date('Y-m-d') ?>">12 weeks</a>
    <a class="btn btn-sm" href="?g=month&from=<?= date('Y-m-01',strtotime('-11 months')) ?>&to=<?= date('Y-m-d') ?>">12 months</a>
  </div>
</form></div>

<!-- KPIs with growth -->
<div class="mgrid">
  <div class="metric green"><div><div class="mv" style="font-size:21px"><?= money($T['revenue']) ?></div><div class="ml">Revenue <?= grow($gRev) ?></div><div class="ms">≈ <?= usd($T['revenue']) ?></div></div><div class="mi">📈</div></div>
  <div class="metric blue"><div><div class="mv"><?= number_format($T['orders']) ?></div><div class="ml">Orders <?= grow($gOrd) ?></div><div class="ms"><?= $T['delivered'] ?> delivered</div></div><div class="mi">📦</div></div>
  <div class="metric purple"><div><div class="mv"><?= number_format($T['units']) ?> <span style="font-size:13px">pcs</span></div><div class="ml">Units Delivered</div><div class="ms">of <?= number_format($T['oqty']) ?> pcs ordered</div></div><div class="mi">🔢</div></div>
  <div class="metric blue"><div><div class="mv" style="font-size:19px"><?= money((float)($todaySnap['v']??0)) ?></div><div class="ml">Today's Order Value</div><div class="ms"><?= (int)($todaySnap['n']??0) ?> orders · <?= number_format((int)($todaySnap['q']??0)) ?> pcs — all statuses</div></div><div class="mi">📆</div></div>
  <div class="metric orange"><div><div class="mv" style="font-size:19px"><?= $T['units']>0?money($T['ads']/$T['units']):'—' ?></div><div class="ml">Ads / Unit</div><div class="ms">spend ÷ delivered pcs</div></div><div class="mi">📣</div></div>
  <div class="metric teal"><div><div class="mv" style="font-size:21px"><?= money($T['profit']) ?></div><div class="ml">Gross Profit <?= grow($gProf) ?></div><div class="ms">≈ <?= usd($T['profit']) ?></div></div><div class="mi">💵</div></div>
  <div class="metric red"><div><div class="mv" style="font-size:21px"><?= money($T['ads']) ?></div><div class="ml">Ad Spend</div><div class="ms">≈ <?= usd($T['ads']) ?> · <?= money(round($adsPerOrder)) ?>/order</div></div><div class="mi">📣</div></div>
  <div class="metric purple"><div><div class="mv"><?= $roas>0?number_format($roas,2).'×':'—' ?></div><div class="ml">ROAS</div><div class="ms">rev ÷ ads</div></div><div class="mi">🚀</div></div>
  <div class="metric <?= $netAfterAds>=0?'indigo':'red' ?>"><div><div class="mv" style="font-size:21px"><?= money($netAfterAds) ?></div><div class="ml">Profit after Ads</div></div><div class="mi">🧾</div></div>
  <div class="metric amber"><div><div class="mv" style="font-size:21px"><?= money(round($aov)) ?></div><div class="ml">Avg Order Value</div></div><div class="mi">🎯</div></div>
  <div class="metric orange"><div><div class="mv"><?= round($returnRate,1) ?>%</div><div class="ml">Return Rate</div></div><div class="mi">↩️</div></div>
</div>

<div class="qstrip">
  <b>📦 Units by status:</b>
  <span class="qchip"><i style="background:#3b82f6"></i>Pending <b><?= number_format($Q['pending']) ?></b></span>
  <span class="qchip"><i style="background:#8b5cf6"></i>Processing <b><?= number_format($Q['processing']) ?></b></span>
  <span class="qchip"><i style="background:#f59e0b"></i>Shipped <b><?= number_format($Q['shipped']) ?></b></span>
  <span class="qchip"><i style="background:#22c55e"></i>Delivered <b><?= number_format($Q['delivered']) ?></b></span>
  <span class="qchip"><i style="background:#ef4444"></i>Returned <b><?= number_format($Q['returned']) ?></b></span>
  <span class="qchip"><i style="background:#94a3b8"></i>Cancelled <b><?= number_format($Q['cancelled']) ?></b></span>
</div>


<?php if($fRev!==null): ?>
<div class="panel" style="margin-top:20px;border:1px solid var(--brand-soft)">
  <div class="panel-head"><h2>🔮 Forecast — next <?= $g==='day'?'day':($g==='week'?'week':'month') ?></h2><span class="muted" style="font-size:12px">linear trend of your last <?= min(8,count($serRev)) ?> <?= $g ?>s</span></div>
  <div class="panel-body"><div class="mgrid">
    <div class="metric <?= $trendUp?'green':'amber' ?>"><div><div class="mv" style="font-size:20px"><?= money(round($fRev)) ?></div><div class="ml">Projected Revenue</div><div class="ms">trend <?= $trendUp?'rising ▲':'cooling ▼' ?></div></div><div class="mi">📈</div></div>
    <div class="metric blue"><div><div class="mv"><?= (int)round($fOrd??0) ?></div><div class="ml">Projected Orders</div></div><div class="mi">📦</div></div>
    <div class="metric teal"><div><div class="mv" style="font-size:20px"><?= money(round($fProf??0)) ?></div><div class="ml">Projected Gross Profit</div></div><div class="mi">💵</div></div>
  </div>
  <div class="muted" style="font-size:12px;margin-top:6px">Simple straight-line projection from recent history — treat as a guide, not a promise. More history = better estimate.</div>
  </div>
</div>
<?php endif; ?>

<!-- trend charts -->
<div class="grid cols-2" style="margin-top:20px;align-items:start">
  <div class="panel"><div class="panel-head"><h2>Revenue Trend</h2></div><div class="panel-body"><div class="chart-box" style="height:230px"><canvas id="cRev"></canvas></div></div></div>
  <div class="panel"><div class="panel-head"><h2>Orders / Sales Trend</h2></div><div class="panel-body"><div class="chart-box" style="height:230px"><canvas id="cOrd"></canvas></div></div></div>
</div>
<div class="grid cols-2" style="margin-top:20px;align-items:start">
  <div class="panel"><div class="panel-head"><h2>Profit Trend</h2></div><div class="panel-body"><div class="chart-box" style="height:230px"><canvas id="cProf"></canvas></div></div></div>
  <div class="panel"><div class="panel-head"><h2>Ad Spend vs Revenue</h2></div><div class="panel-body"><div class="chart-box" style="height:230px"><canvas id="cAds"></canvas></div></div></div>
</div>
<div class="grid cols-2" style="margin-top:20px;align-items:start">
  <div class="panel"><div class="panel-head"><h2>ROAS Trend</h2></div><div class="panel-body"><div class="chart-box" style="height:230px"><canvas id="cRoas"></canvas></div></div></div>
  <div class="panel"><div class="panel-head"><h2>Top Products by Revenue (per <?= $g ?>)</h2></div><div class="panel-body"><div class="chart-box" style="height:230px"><canvas id="cStack"></canvas></div></div></div>
</div>

<!-- period breakdown table -->
<div class="panel" style="margin-top:20px"><div class="panel-head"><h2>📅 <?= ucfirst($g) ?> Breakdown</h2><span class="muted" style="font-size:12px"><?= count($periods) ?> <?= $g==='day'?'day':($g==='week'?'week':'month') ?><?= count($periods)==1?'':'s' ?></span></div>
<div class="table-wrap"><table class="tbl num-tbl" id="periodTbl"><thead><tr>
  <th><?= ucfirst($g) ?></th><th class="right">Orders</th><th class="right">Qty Ordered</th><th class="right">Order Value</th><th class="right">Delivered</th><th class="right">Units</th><th class="right">↩ Returned</th><th class="right">Return Loss</th><th class="right">Revenue</th><th class="right">COGS</th><th class="right">Delivery</th><th class="right">Gross Profit</th><th class="right">Ad Spend</th><th class="right">Ads/Unit</th><th class="right">Net (after ads)</th><th class="right">ROAS</th><th class="right">Ads/Order</th>
</tr></thead><tbody>
<?php foreach($periods as $k=>$p): $net=$p['profit']-$p['ads']; $ro=$p['ads']>0?$p['revenue']/$p['ads']:0; $apo=$p['orders']?$p['ads']/$p['orders']:0; ?>
  <tr<?= $k===$bestPeriod && $bestRev>0?' style="background:var(--green-bg)"':'' ?>>
    <td><b><?= e(plabel($k,$g)) ?></b></td>
    <td class="num right"><?= $p['orders'] ?></td>
    <td class="num right"><?= number_format($p['oqty']) ?></td>
    <td class="num right muted"><?= money($p['ordval']) ?></td>
    <td class="num right"><?= $p['delivered'] ?></td>
    <td class="num right" style="font-weight:700;color:var(--green)"><?= number_format($p['units']) ?></td>
    <td class="num right" style="<?= $p['returned']>0?'font-weight:700;color:var(--red)':'color:var(--muted)' ?>" title="returned/cancelled orders · pieces"><?= $p['returned']>0 ? $p['returned'].' · '.number_format($p['runits']).' pcs' : '—' ?></td>
    <td class="num right" style="<?= $p['rloss']>0?'color:var(--red)':'color:var(--muted)' ?>"><?= $p['rloss']>0?money($p['rloss']):'—' ?></td>
    <td class="num right"><?= money($p['revenue']) ?></td>
    <td class="num right muted"><?= money($p['cogs']) ?></td>
    <td class="num right muted"><?= money($p['delivery']) ?></td>
    <td class="num right" style="color:var(--green)"><?= money($p['profit']) ?></td>
    <td class="num right" style="color:var(--red)"><?= money($p['ads']) ?></td>
    <td class="num right muted"><?= $p['units']>0?money($p['ads']/$p['units']):'—' ?></td>
    <td class="num right" style="color:<?= $net>=0?'var(--green)':'var(--red)' ?>;font-weight:700"><?= money($net) ?></td>
    <td class="num right"><?= $ro>0?number_format($ro,2).'×':'—' ?></td>
    <td class="num right"><?= money(round($apo)) ?></td>
  </tr>
<?php endforeach; if(!$periods) echo '<tr><td colspan="17"><div class="empty">No data in this range.</div></td></tr>'; ?>
</tbody></table></div></div>

<!-- monthly report -->
<?php
$mon=[]; $y=date('Y');
foreach(rows("SELECT o.order_date d, o.status, o.qty, o.sell_price, o.cost_price, o.delivery_charge, o.cancel_charge
              FROM orders o WHERE YEAR(o.order_date)=? $pw",[$y]) as $o){
  $m=(int)substr($o['d'],5,2);
  if(!isset($mon[$m])) $mon[$m]=['q'=>0,'rev'=>0,'del'=>0,'gross'=>0];
  if($o['status']==='delivered'){ $q=(int)$o['qty'];
    $mon[$m]['q']+=$q; $mon[$m]['rev']+=(float)$o['sell_price']*$q; $mon[$m]['del']+=(float)$o['delivery_charge'];
    $mon[$m]['gross']+=((float)$o['sell_price']-(float)$o['cost_price'])*$q-(float)$o['delivery_charge'];
  } elseif(in_array($o['status'],['returned','cancelled'],true)) $mon[$m]['gross']-=(float)$o['cancel_charge'];
}
$adsMon=[];
foreach(rows("SELECT expense_date, amount, category, COALESCE(product,'') product FROM expenses WHERE YEAR(expense_date)=?",[$y]) as $e){
  $cat=strtolower((string)$e['category']); $isAd=false; foreach($adKw as $kw){ if(strpos($cat,$kw)!==false){$isAd=true;break;} }
  if($isAd && $adFits($e)){ $m=(int)substr($e['expense_date'],5,2); $adsMon[$m]=($adsMon[$m]??0)+(float)$e['amount']; }
}
$GT=['q'=>0,'rev'=>0,'del'=>0,'gross'=>0,'ads'=>0,'net'=>0];
?>
<div class="panel" style="margin-top:20px"><div class="panel-head" style="flex-wrap:wrap;gap:10px">
  <h2>🗓 Monthly Report — <?= $y ?><?= $pName?' · '.e($pName):'' ?></h2>
  <button class="btn btn-sm" onclick="tblCsvX('monTbl','monthly-report')">⬇ CSV</button></div>
<div class="table-wrap"><table class="tbl num-tbl" id="monTbl"><thead><tr>
  <th>Month</th><th class="right">Quantity</th><th class="right">Revenue</th><th class="right">Revenue ($)</th><th class="right">Delivery Charges</th><th class="right">Profit without Ads</th><th class="right">Ads Spent</th><th class="right">Ads ($)</th><th class="right">Net Profit</th><th class="right">Net ($)</th>
</tr></thead><tbody>
<?php for($m=1;$m<=12;$m++): if(!isset($mon[$m]) && !isset($adsMon[$m])) continue;
  $v=$mon[$m]??['q'=>0,'rev'=>0,'del'=>0,'gross'=>0]; $ads=$adsMon[$m]??0; $net=$v['gross']-$ads;
  $GT['q']+=$v['q'];$GT['rev']+=$v['rev'];$GT['del']+=$v['del'];$GT['gross']+=$v['gross'];$GT['ads']+=$ads;$GT['net']+=$net; ?>
  <tr>
    <td><b><?= date('M', mktime(0,0,0,$m,1)) ?></b></td>
    <td class="num right" style="font-weight:700"><?= number_format($v['q']) ?></td>
    <td class="num right"><?= money($v['rev']) ?></td>
    <td class="num right muted"><?= usd($v['rev']) ?></td>
    <td class="num right muted"><?= money($v['del']) ?></td>
    <td class="num right" style="color:var(--green)"><?= money($v['gross']) ?></td>
    <td class="num right" style="color:var(--red)"><?= money($ads) ?></td>
    <td class="num right muted"><?= usd($ads) ?></td>
    <td class="num right" style="font-weight:800;color:<?= $net>=0?'var(--green)':'var(--red)' ?>"><?= money($net) ?></td>
    <td class="num right muted" style="font-weight:700;color:<?= $net>=0?'var(--green)':'var(--red)' ?>"><?= usd($net) ?></td>
  </tr>
<?php endfor; ?>
  <tr style="border-top:2px solid var(--border);background:rgba(59,130,246,.06)">
    <td><b>Grand Total</b></td>
    <td class="num right" style="font-weight:800"><?= number_format($GT['q']) ?></td>
    <td class="num right" style="font-weight:800"><?= money($GT['rev']) ?></td>
    <td class="num right" style="font-weight:700"><?= usd($GT['rev']) ?></td>
    <td class="num right" style="font-weight:700"><?= money($GT['del']) ?></td>
    <td class="num right" style="font-weight:800;color:var(--green)"><?= money($GT['gross']) ?></td>
    <td class="num right" style="font-weight:700;color:var(--red)"><?= money($GT['ads']) ?></td>
    <td class="num right" style="font-weight:700"><?= usd($GT['ads']) ?></td>
    <td class="num right" style="font-weight:800;color:<?= $GT['net']>=0?'var(--green)':'var(--red)' ?>"><?= money($GT['net']) ?></td>
    <td class="num right" style="font-weight:800;color:<?= $GT['net']>=0?'var(--green)':'var(--red)' ?>"><?= usd($GT['net']) ?></td>
  </tr>
</tbody></table></div></div>

<script>
function tblCsvX(id,name){
  var t=document.getElementById(id); if(!t)return;
  var rows=[].map.call(t.querySelectorAll('tr'),function(tr){
    return [].map.call(tr.querySelectorAll('th,td'),function(c){return '"'+c.textContent.trim().replace(/"/g,'""')+'"';}).join(',');
  }).join('\n');
  var a=document.createElement('a');a.href=URL.createObjectURL(new Blob([rows],{type:'text/csv'}));a.download=name+'.csv';a.click();
}
</script>

<!-- product performance -->
<div class="panel" style="margin-top:20px"><div class="panel-head"><h2>🏷️ Product Performance</h2><button class="btn btn-sm" onclick="exportProducts()">⬇ CSV</button></div>
<div class="table-wrap"><table class="tbl num-tbl" id="prodTbl"><thead><tr>
  <th>Product</th><th class="right">Orders</th><th class="right">Qty Ordered</th><th class="right">Delivered</th><th class="right">Pending</th><th class="right">Returned</th><th class="right">Revenue</th><th class="right">COGS</th><th class="right">Profit</th><th class="right">Margin</th><th class="right">% of Revenue</th>
</tr></thead><tbody>
<?php uasort($deep, fn($x,$y)=>$y['rev']<=>$x['rev']);
foreach($deep as $n=>$pd): $share=$T['revenue']>0?round($pd['rev']/$T['revenue']*100,1):0;
  $mar=$pd['rev']>0?round($pd['prof']/$pd['rev']*100,1):0; ?>
  <tr>
    <td><b><?= e($n) ?></b></td>
    <td class="num right"><?= (int)$pd['orders'] ?></td>
    <td class="num right"><?= number_format($pd['oq']) ?></td>
    <td class="num right" style="font-weight:700;color:var(--green)"><?= number_format($pd['dq']) ?></td>
    <td class="num right" style="color:var(--amber)"><?= number_format($pd['pq']) ?></td>
    <td class="num right" style="color:var(--red)"><?= number_format($pd['rq']) ?></td>
    <td class="num right"><?= money($pd['rev']) ?></td>
    <td class="num right muted"><?= money($pd['cogs']) ?></td>
    <td class="num right" style="font-weight:700;color:<?= $pd['prof']>=0?'var(--green)':'var(--red)' ?>"><?= money($pd['prof']) ?></td>
    <td class="num right"><?= $mar ?>%</td>
    <td class="num right"><?= $share ?>%</td>
  </tr>
<?php endforeach; if(!$deep) echo '<tr><td colspan="11"><div class="empty">No orders in this range.</div></td></tr>'; ?>
</tbody></table></div></div>

<script>
var CH_GRID=(getComputedStyle(document.body).getPropertyValue('--border')||'#e9edf3').trim();
var LB=<?= json_encode($labels) ?>;
var money1=v=>'Rs.'+Number(v).toLocaleString();
var base={responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:CH_GRID},ticks:{callback:v=>v>=1000?(v/1000)+'K':v}},x:{grid:{display:false}}}};
new Chart(cRev,{type:'bar',data:{labels:LB,datasets:[{data:<?= json_encode($serRev) ?>,backgroundColor:'#10b981',borderRadius:5}]},options:base});
new Chart(cOrd,{type:'line',data:{labels:LB,datasets:[{data:<?= json_encode($serOrd) ?>,borderColor:'#3b82f6',backgroundColor:'rgba(59,130,246,.12)',fill:true,tension:.3}]},options:Object.assign({},base,{scales:{y:{beginAtZero:true,grid:{color:CH_GRID},ticks:{precision:0}},x:{grid:{display:false}}}})});
new Chart(cProf,{type:'bar',data:{labels:LB,datasets:[{data:<?= json_encode($serProf) ?>,backgroundColor:<?= json_encode(array_map(fn($v)=>$v>=0?'#14b8a6':'#ef4444',$serProf)) ?>,borderRadius:5}]},options:base});
new Chart(cAds,{type:'bar',data:{labels:LB,datasets:[{label:'Revenue',data:<?= json_encode($serRev) ?>,backgroundColor:'#93c5fd',borderRadius:4},{label:'Ad Spend',data:<?= json_encode($serAds) ?>,backgroundColor:'#ef4444',borderRadius:4}]},options:Object.assign({},base,{plugins:{legend:{display:true,position:'bottom',labels:{boxWidth:10,font:{size:10}}}}})});
new Chart(cRoas,{type:'line',data:{labels:LB,datasets:[{data:<?= json_encode($serRoas) ?>,borderColor:'#8b5cf6',backgroundColor:'rgba(139,92,246,.12)',fill:true,tension:.3}]},options:Object.assign({},base,{scales:{y:{beginAtZero:true,grid:{color:CH_GRID},ticks:{callback:v=>v+'×'}},x:{grid:{display:false}}}})});
new Chart(cStack,{type:'bar',data:{labels:LB,datasets:<?= json_encode($stackDs) ?>},options:Object.assign({},base,{plugins:{legend:{display:true,position:'bottom',labels:{boxWidth:10,font:{size:10}}}},scales:{x:{stacked:true,grid:{display:false}},y:{stacked:true,beginAtZero:true,grid:{color:CH_GRID},ticks:{callback:v=>v>=1000?(v/1000)+'K':v}}}})});

function dl(name,rows){var csv=rows.map(r=>r.map(c=>'"'+String(c==null?'':c).replace(/"/g,'""')+'"').join(',')).join('\n');var b=new Blob([csv],{type:'text/csv'});var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download=name;a.click();}
function tblRows(id){return Array.from(document.querySelectorAll('#'+id+' tr')).map(tr=>Array.from(tr.querySelectorAll('th,td')).map(td=>td.textContent.trim()));}
function exportPeriods(){dl('analytics_<?= $g ?>.csv',tblRows('periodTbl'));}
function exportProducts(){dl('product_performance.csv',tblRows('prodTbl'));}
</script>
<?php require __DIR__.'/includes/footer.php'; ?>