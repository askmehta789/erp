<?php
/* ============================================================
   BRANCH & COURIER PERFORMANCE
   Reached via a link from analytics.php. Not in the main nav.

   HONEST DATA NOTE: NCM does not store a permanent "destination
   branch" field on the order once it's delivered — the live status
   text gets overwritten as the parcel moves, so by the time an
   order is finished there's often no trace of which branch handled
   it. This page estimates the branch from the delivery ADDRESS
   using its own self-contained matching logic — reliable for the
   large majority of orders since NCM branches map to real geographic
   areas, but it IS an estimate, and is labeled as one throughout
   this page.
   ============================================================ */
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
require_once __DIR__.'/ncm_api.php';
ncm_ensure_cols();   /* ncm_status/ncm_order_id/ncm_return_flag must exist before we can query them —
                        ncm.php always does this first; this page never did, which is a real bug */
repair_zero_cost_profit();

/* Self-contained branch matching — deliberately NOT reusing ncm_match_branch()/ncm_guess_branch()
   from ncm_api.php. Those only moved there during a later refactor; if that specific update hasn't
   fully landed on the server yet, calling them would be an undefined-function fatal and the whole
   page would fail to open. Defining our own copies here means this page can never break because of
   what state some OTHER file happens to be in. */
if (!function_exists('bp_match_branch')) {
  function bp_match_branch($name,$names){
    $t = mb_strtoupper(trim((string)$name)); if ($t==='') return '';
    foreach ($names as $b) if (mb_strtoupper($b)===$t) return $b;
    foreach ($names as $b) if (mb_strpos(mb_strtoupper($b),$t)!==false || mb_strpos($t,mb_strtoupper($b))!==false) return $b;
    return '';
  }
  function bp_guess_branch($addr,$names){
    $A = mb_strtoupper((string)$addr); if ($A==='') return '';
    $best=''; $bestLen=0;
    foreach ($names as $b) { $B=mb_strtoupper($b); if ($B!=='' && mb_strpos($A,$B)!==false && mb_strlen($B)>$bestLen) { $best=$b; $bestLen=mb_strlen($B); } }
    return $best;
  }
}
$PAGE_TITLE='Branch & Courier Performance';

$to   = $_GET['to']   ?? date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-90 days'));
if ($from > $to) { $t=$from; $from=$to; $to=$t; }
$pid = (int)($_GET['pid'] ?? 0);
$pw  = $pid ? " AND o.product_id = ".$pid : "";
$allProducts = rows("SELECT id,name FROM products ORDER BY name");
$pName = $pid ? (string)val("SELECT name FROM products WHERE id=?",[$pid]) : '';

$connected=false; $branchNames=[];
if (ncm()->configured()) {
  try { $bs=ncm()->branches(); $connected=is_array($bs); foreach($bs as $b) if(is_array($b) && !empty($b['name'])) $branchNames[]=$b['name']; sort($branchNames); }
  catch (Throwable $e) { $connected=false; }
}

/* ---- courier-level split (this part IS exact, no estimation) ---- */
$byCourier=[]; $dataErr='';
try {
  $courierOrders = rows("SELECT o.status, o.cancel_charge, c.name AS courier_name
    FROM orders o LEFT JOIN couriers c ON c.id=o.courier_id
    WHERE o.order_date BETWEEN ? AND ? $pw", [$from,$to]);
} catch (Throwable $e) { $courierOrders=[]; $dataErr=$e->getMessage(); }
foreach($courierOrders as $o){
  $cn=$o['courier_name']?:'Unassigned';
  if(!isset($byCourier[$cn])) $byCourier[$cn]=['orders'=>0,'ret'=>0,'charge'=>0];
  $byCourier[$cn]['orders']++;
  if(in_array($o['status'],['returned','cancelled'],true)){ $byCourier[$cn]['ret']++; $byCourier[$cn]['charge']+=(float)$o['cancel_charge']; }
}
foreach($byCourier as &$row){ $row['rate']=$row['orders']?round($row['ret']/$row['orders']*100,1):0; } unset($row);
uasort($byCourier, fn($a,$b)=>$b['orders']<=>$a['orders']);

/* ---- branch-level split (ESTIMATED from address) — NCM orders only ---- */
$byBranch=[]; $unmatched=0; $ncmTotal=0;
if ($connected && $branchNames) {
  try {
    $ncmOrders = rows("SELECT o.address, o.status, o.cancel_charge, o.ncm_status
      FROM orders o LEFT JOIN couriers c ON c.id=o.courier_id
      WHERE c.name LIKE '%NCM%' AND o.order_date BETWEEN ? AND ? $pw", [$from,$to]);
  } catch (Throwable $e) { $ncmOrders=[]; $dataErr=$dataErr?:$e->getMessage(); }
  foreach($ncmOrders as $o){
    $ncmTotal++;
    /* prefer a branch name mentioned in the last known live status; fall back to the address */
    $br = $o['ncm_status'] ? bp_match_branch($o['ncm_status'], $branchNames) : '';
    if ($br==='') $br = bp_guess_branch($o['address'], $branchNames);
    if ($br===''){ $unmatched++; continue; }
    if(!isset($byBranch[$br])) $byBranch[$br]=['orders'=>0,'ret'=>0,'charge'=>0];
    $byBranch[$br]['orders']++;
    if(in_array($o['status'],['returned','cancelled'],true)){ $byBranch[$br]['ret']++; $byBranch[$br]['charge']+=(float)$o['cancel_charge']; }
  }
  foreach($byBranch as &$row){ $row['rate']=$row['orders']?round($row['ret']/$row['orders']*100,1):0; } unset($row);
}
/* only branches with enough volume to be statistically meaningful */
$minOrders = 5;
$byBranchReliable = array_filter($byBranch, fn($r)=>$r['orders']>=$minOrders);
uasort($byBranchReliable, fn($a,$b)=>$b['rate']<=>$a['rate']);
$worst10 = array_slice($byBranchReliable,0,10,true);
$best5   = array_slice(array_reverse($byBranchReliable,true),0,5,true);
$matchRate = $ncmTotal ? round((($ncmTotal-$unmatched)/$ncmTotal)*100,1) : 0;

require __DIR__.'/includes/header.php';
?>
<style>
.bp-hero{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:linear-gradient(120deg,#0e7490,#155e75);border-radius:18px;padding:22px 26px;color:#fff;margin-bottom:14px;box-shadow:0 14px 34px rgba(14,116,144,.25)}
.bp-hero h1{font-size:22px;font-weight:900;margin:0}
.bp-hero p{opacity:.85;font-size:12.5px;margin:2px 0 0}
.bp-controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.bp-controls select,.bp-controls input{border:1px solid var(--border);border-radius:9px;padding:7px 10px;font:inherit;font-size:12.5px}
.bp-note{background:#ecfeff;border:1.5px solid #a5f3fc;color:#0e7490;border-radius:12px;padding:10px 16px;font-size:12.3px;margin-bottom:16px}
.bp-panel{background:rgba(255,255,255,.72);backdrop-filter:blur(18px) saturate(1.6);border:1px solid rgba(255,255,255,.7);border-radius:16px;box-shadow:0 8px 22px rgba(30,41,80,.08);overflow:hidden;margin-bottom:16px}
.bp-panel-h{padding:14px 18px;font-weight:800;font-size:13.5px;border-bottom:1px solid rgba(200,210,230,.3)}
.bp-panel-b{padding:16px 18px}
.br-row{display:flex;align-items:center;gap:11px;padding:8px 0;border-bottom:1px solid rgba(210,218,235,.35)}
.br-row:last-child{border-bottom:0}
.br-name{font-weight:800;font-size:12.5px;min-width:110px}
.br-bar-wrap{flex:1;background:#eef0f8;border-radius:99px;height:8px;overflow:hidden;min-width:60px}
.br-bar{height:100%;border-radius:99px}
.br-pct{font-weight:900;font-size:12.5px;min-width:46px;text-align:right}
@media(max-width:640px){
  .bp-hero{padding:16px 18px}
  .bp-hero h1{font-size:18px}
  .bp-controls{width:100%}
  .bp-controls label{width:100%}
  .bp-controls input[type=date]{width:100%}
  .bp-controls select{width:100%}
  .br-row{flex-wrap:wrap;gap:6px 11px}
  .br-name{min-width:0;flex:1 1 auto}
  .br-bar-wrap{flex:1 1 100%;order:3;min-width:100%;margin-top:2px}
  .br-pct{order:2}
  .br-lost{order:4;min-width:0;margin-left:auto}
}
</style>

<div class="bp-hero">
  <span style="font-size:32px">📍</span>
  <div style="flex:1;min-width:220px">
    <h1>Branch &amp; Courier Performance</h1>
    <p>Which branches and couriers are costing you the most in returns<?= $pName?' — '.e($pName).' only':'' ?></p>
  </div>
  <a class="btn" style="background:rgba(255,255,255,.16);color:#fff" href="analytics.php">← Analytics</a>
</div>

<form method="get" class="bp-controls">
  <label class="muted" style="font-size:12px">From <input type="date" name="from" value="<?= e($from) ?>" onchange="this.form.submit()"></label>
  <label class="muted" style="font-size:12px">To <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
  <select name="pid" onchange="this.form.submit()">
    <option value="0">All products</option>
    <?php foreach($allProducts as $pp): ?><option value="<?= (int)$pp['id'] ?>" <?= $pid===(int)$pp['id']?'selected':'' ?>><?= e($pp['name']) ?></option><?php endforeach; ?>
  </select>
</form>

<?php if($dataErr): ?>
  <div class="bp-note" style="background:var(--red-bg,#fee2e2);border-color:#f3b3ac;color:var(--red)">⚠ Couldn't load order data: <b><?= e($dataErr) ?></b>. If this keeps happening, send this exact message.</div>
<?php endif; ?>
<?php if(!$connected): ?>
  <div class="bp-note" style="background:var(--amber-bg,#fef3c7);border-color:#f0d9ad;color:#92610a">⚠ NCM isn't connected, so branch-level data can't be estimated. Connect it in <a href="settings.php"><b>Settings</b></a> to unlock this section. Courier-level comparison below still works.</div>
<?php else: ?>
  <div class="bp-note">ℹ️ <b>Branch is estimated</b> from each order's live NCM status or delivery address, matched against NCM's branch list — not a field NCM stores directly on the order. Matched <b><?= $matchRate ?>%</b> of <?= $ncmTotal ?> NCM orders in this range (<?= $unmatched ?> addresses didn't clearly match a branch and are excluded below). Branches under <?= $minOrders ?> orders are hidden — too few to be meaningful.</div>
<?php endif; ?>

<div class="bp-panel">
  <div class="bp-panel-h">🚚 By Courier <span class="muted" style="font-weight:600;font-size:11.5px">exact, not estimated</span></div>
  <div class="bp-panel-b">
    <div class="table-wrap"><table class="tbl num-tbl">
      <thead><tr><th>Courier</th><th class="right">Orders</th><th class="right">Returned/Cancelled</th><th class="right">Rate</th><th class="right">Charge Lost</th></tr></thead><tbody>
      <?php foreach($byCourier as $cn=>$row): ?>
      <tr><td><b><?= e($cn) ?></b></td><td class="right"><?= $row['orders'] ?></td>
        <td class="right" style="color:var(--red)"><?= $row['ret'] ?></td>
        <td class="right"><span class="pill <?= $row['rate']>=15?'p-red':($row['rate']>=8?'p-yellow':'p-green') ?>"><?= $row['rate'] ?>%</span></td>
        <td class="right" style="font-weight:800"><?= money($row['charge']) ?></td></tr>
      <?php endforeach; if(!$byCourier) echo '<tr><td colspan="5"><div class="empty">No orders in this range.</div></td></tr>'; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php if($connected && $byBranchReliable): ?>
<div class="bp-panel">
  <div class="bp-panel-h">🔴 Worst 10 NCM Branches by Return Rate <span class="muted" style="font-weight:600;font-size:11.5px">estimated, min. <?= $minOrders ?> orders</span></div>
  <div class="bp-panel-b">
    <?php foreach($worst10 as $bn=>$row): $col = $row['rate']>=20?'#ef4444':($row['rate']>=10?'#f59e0b':'#22c55e'); ?>
    <div class="br-row">
      <div class="br-name"><?= e($bn) ?><div class="muted" style="font-size:10px;font-weight:600"><?= $row['orders'] ?> orders</div></div>
      <div class="br-bar-wrap"><div class="br-bar" style="width:<?= min(100,$row['rate']) ?>%;background:<?= $col ?>"></div></div>
      <span class="br-pct" style="color:<?= $col ?>"><?= $row['rate'] ?>%</span>
      <span class="muted br-lost" style="font-size:11px;min-width:80px;text-align:right"><?= money($row['charge']) ?> lost</span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="bp-panel">
  <div class="bp-panel-h">🟢 Best 5 NCM Branches <span class="muted" style="font-weight:600;font-size:11.5px">lowest return rate, min. <?= $minOrders ?> orders</span></div>
  <div class="bp-panel-b">
    <?php foreach($best5 as $bn=>$row): ?>
    <div class="br-row">
      <div class="br-name"><?= e($bn) ?><div class="muted" style="font-size:10px;font-weight:600"><?= $row['orders'] ?> orders</div></div>
      <div class="br-bar-wrap"><div class="br-bar" style="width:<?= min(100,$row['rate']) ?>%;background:#22c55e"></div></div>
      <span class="br-pct" style="color:#22c55e"><?= $row['rate'] ?>%</span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px"><h2>📋 All Branches</h2>
    <div style="display:flex;gap:8px;align-items:center">
      <input id="brSearch" class="ncm-search" placeholder="🔍 branch name…" oninput="brFilter()">
      <button class="btn btn-sm" onclick="brCSV()">⬇ CSV</button>
    </div>
  </div>
  <div class="table-wrap"><table class="tbl num-tbl" id="brTbl">
    <thead><tr><th>Branch</th><th class="right">Orders</th><th class="right">Returned/Cancelled</th><th class="right">Rate</th><th class="right">Charge Lost</th></tr></thead><tbody>
    <?php $sorted=$byBranchReliable; uasort($sorted, fn($a,$b)=>$b['rate']<=>$a['rate']); foreach($sorted as $bn=>$row): ?>
    <tr data-s="<?= e(mb_strtolower($bn)) ?>">
      <td><b><?= e($bn) ?></b></td><td class="right"><?= $row['orders'] ?></td>
      <td class="right" style="color:var(--red)"><?= $row['ret'] ?></td>
      <td class="right"><span class="pill <?= $row['rate']>=15?'p-red':($row['rate']>=8?'p-yellow':'p-green') ?>"><?= $row['rate'] ?>%</span></td>
      <td class="right" style="font-weight:800"><?= money($row['charge']) ?></td>
    </tr>
    <?php endforeach; if(!$sorted): ?><tr><td colspan="5"><div class="empty">No branches with enough volume in this range.</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php elseif($connected): ?>
  <div class="bp-panel"><div class="bp-panel-b"><div class="empty">No branch had <?= $minOrders ?>+ orders in this range yet — try widening the date range.</div></div></div>
<?php endif; ?>

<script>
function brFilter(){
  var q=(document.getElementById('brSearch').value||'').toLowerCase().trim();
  document.querySelectorAll('#brTbl tbody tr[data-s]').forEach(function(tr){
    tr.style.display=(!q||(tr.getAttribute('data-s')||'').indexOf(q)!==-1)?'':'none';
  });
}
function brCSV(){
  var lines=[['Branch','Orders','Returned/Cancelled','Rate','Charge Lost'].join(',')];
  document.querySelectorAll('#brTbl tbody tr[data-s]').forEach(function(tr){
    if(tr.style.display==='none')return;
    var c=tr.querySelectorAll('td');
    lines.push([c[0].innerText,c[1].innerText,c[2].innerText,c[3].innerText,c[4].innerText].map(function(v){return '"'+String(v).replace(/"/g,'""')+'"';}).join(','));
  });
  var blob=new Blob([lines.join('\n')],{type:'text/csv'});
  var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='branch-performance.csv';a.click();
}
</script>
<?php require __DIR__.'/includes/footer.php'; ?>