<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
$PAGE_TITLE='Customers';
$orders = rows("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id
                WHERE COALESCE(o.customer,'')<>'' OR COALESCE(o.phone,'')<>'' ORDER BY o.id DESC");

/* group by phone (fallback name) */
$cust=[];
foreach($orders as $o){
  $key = trim((string)$o['phone']) !== '' ? trim((string)$o['phone']) : 'name:'.strtolower(trim((string)$o['customer']));
  if(!isset($cust[$key])) $cust[$key]=['name'=>$o['customer'],'phone'=>$o['phone'],'address'=>$o['address'],'orders'=>0,'delivered'=>0,'returned'=>0,'revenue'=>0,'profit'=>0,'last'=>$o['order_date'],'first'=>$o['order_date']];
  $c=&$cust[$key]; $c['orders']++;
  if($o['customer'] && !$c['name']) $c['name']=$o['customer'];
  if($o['order_date']<$c['first'])$c['first']=$o['order_date'];
  if($o['order_date']>$c['last'])$c['last']=$o['order_date'];
  if($o['status']==='delivered'){ $c['delivered']++; $c['revenue']+=(float)$o['sell_price']*(int)$o['qty'];
    $c['profit']+=((float)$o['sell_price']-(float)$o['cost_price'])*(int)$o['qty']-(float)$o['delivery_charge']; }
  elseif(in_array($o['status'],['returned','cancelled'],true)) $c['returned']++;
  unset($c);
}
foreach($cust as &$c){ $c['retRate']=$c['orders']?round($c['returned']/$c['orders']*100):0;
  $c['risky']=($c['returned']>=2)||($c['orders']>=2 && $c['retRate']>=50);
  $c['repeat']=$c['orders']>=2; } unset($c);

$total=count($cust);
$repeat=count(array_filter($cust,fn($c)=>$c['repeat']));
$risky=array_filter($cust,fn($c)=>$c['risky']);
$repeatRate=$total?round($repeat/$total*100,1):0;
uasort($cust,fn($a,$b)=>$b['revenue']<=>$a['revenue']);
$top=array_slice($cust,0,10,true);
$avgLifetime=$total?array_sum(array_column($cust,'revenue'))/$total:0;

$f=$_GET['f']??'all';
$list=$cust;
if($f==='repeat')$list=array_filter($cust,fn($c)=>$c['repeat']);
elseif($f==='risky')$list=$risky;
elseif($f==='new')$list=array_filter($cust,fn($c)=>$c['orders']===1);
require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>👥 Customer Analytics</h1><p>Repeat buyers, top customers & return-risk warnings</p></div></div>

<div class="mgrid">
  <div class="metric blue"><div><div class="mv"><?= number_format($total) ?></div><div class="ml">Customers</div></div><div class="mi">👥</div></div>
  <div class="metric green"><div><div class="mv"><?= $repeatRate ?>%</div><div class="ml">Repeat Rate</div><div class="ms"><?= $repeat ?> repeat buyers</div></div><div class="mi">🔁</div></div>
  <div class="metric teal"><div><div class="mv" style="font-size:20px"><?= money(round($avgLifetime)) ?></div><div class="ml">Avg Lifetime Value</div></div><div class="mi">💎</div></div>
  <div class="metric red"><div><div class="mv"><?= count($risky) ?></div><div class="ml">Return-Risk</div><div class="ms">2+ returns or ≥50%</div></div><div class="mi">🚩</div></div>
</div>

<?php if($risky): ?>
<div class="panel" style="margin-top:20px;border:1px solid var(--red-bg)">
  <div class="panel-head"><h2 style="color:var(--red)">🚩 Return-Risk Customers — confirm carefully before dispatch</h2></div>
  <div class="table-wrap"><table class="tbl"><thead><tr><th>Customer</th><th>Phone</th><th class="right">Orders</th><th class="right">Returned</th><th class="right">Return %</th><th>Last Order</th></tr></thead><tbody>
  <?php foreach($risky as $c): ?>
    <tr class="row-danger" style="background:var(--red-bg)"><td><b><?= e($c['name']?:'—') ?></b></td><td class="num"><?= e($c['phone']?:'—') ?></td>
    <td class="num right"><?= $c['orders'] ?></td><td class="num right"><?= $c['returned'] ?></td>
    <td class="num right"><b><?= $c['retRate'] ?>%</b></td><td class="num muted"><?= e($c['last']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:20px">
  <div class="panel-head"><h2>All Customers</h2>
    <div style="display:flex;gap:8px">
      <?php foreach(['all'=>'All','repeat'=>'Repeat','new'=>'One-time','risky'=>'Risky'] as $k=>$lbl): ?>
        <a class="btn btn-sm <?= $f===$k?'btn-primary':'' ?>" href="customers.php?f=<?= $k ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card" style="border:0"><div class="toolbar"><input class="search-in" placeholder="🔍 Search name / phone…" onkeyup="filterTable(this)"></div></div>
  <div class="table-wrap"><table class="tbl" id="mainTable"><thead><tr>
    <th>Customer</th><th>Phone</th><th class="right">Orders</th><th class="right">Delivered</th><th class="right">Returned</th><th class="right">Revenue</th><th class="right">Profit</th><th>Type</th><th>Last Order</th>
  </tr></thead><tbody>
  <?php foreach($list as $c): ?>
    <tr<?= $c['risky']?' style="background:var(--red-bg)"':'' ?>>
      <td><b><?= e($c['name']?:'—') ?></b></td>
      <td class="num"><?= e($c['phone']?:'—') ?></td>
      <td class="num right"><?= $c['orders'] ?></td>
      <td class="num right"><?= $c['delivered'] ?></td>
      <td class="num right"><?= $c['returned'] ?></td>
      <td class="num right"><?= money($c['revenue']) ?></td>
      <td class="num right" style="color:<?= $c['profit']>=0?'var(--green)':'var(--red)' ?>"><?= money($c['profit']) ?></td>
      <td><?= $c['risky']?'<span class="pill p-red">Risky</span>':($c['repeat']?'<span class="pill p-green">Repeat</span>':'<span class="pill p-grey">New</span>') ?></td>
      <td class="num muted"><?= e($c['last']) ?></td>
    </tr>
  <?php endforeach; if(!$list) echo '<tr><td colspan="9"><div class="empty">No customers in this view yet.</div></td></tr>'; ?>
  </tbody></table></div>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
