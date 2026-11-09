<?php
/* Daraz Dashboard — the quick daily look. The full order sheet lives in Daraz Sales and
   the deep numbers in Daraz Analytics; this page answers "what's happening today and
   what needs fixing". Orders come in by Daraz order number (🔗 Link / check), not by a
   bulk import of every order. */
require_once __DIR__.'/daraz_common.php';
require_login(); require_page_access();
daraz_ensure_cols(); daraz_ensure_ads_table();
$PAGE_TITLE = 'Daraz Dashboard';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;
$darazConnected = (new Daraz())->connected();
$darazAccount   = setting('daraz_account','');
$dzId = daraz_courier_id();
try { daraz_fix_existing_phones($dzId); } catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf();
  $act = $_POST['_action'] ?? '';
  if ($act === 'add_ad') {
    $amt = (float)($_POST['amount'] ?? 0);
    if ($amt > 0) {
      q("INSERT INTO daraz_ads(spend_date,amount,note) VALUES(?,?,?)", [($_POST['spend_date'] ?? '') ?: date('Y-m-d'), $amt, trim($_POST['note'] ?? '')]);
      log_activity("Daraz ad spend ".money($amt), 'Daraz'); flash('Ad spend recorded: '.money($amt));
    } else flash('Amount must be greater than 0.');
  }
  if ($act === 'del_ad' && $isAdmin) { q("DELETE FROM daraz_ads WHERE id=?", [(int)($_POST['id'] ?? 0)]); flash('Ad entry deleted.'); }
  if ($act === 'map_sku') {
    try {
      $r = daraz_map_sku_to_product(trim((string)($_POST['sku'] ?? '')), (int)($_POST['product_id'] ?? 0));
      flash($r['fixed'] > 0 ? "Mapped — fixed {$r['fixed']} existing order line(s); new orders with this SKU match automatically." : 'Mapped — new orders with this SKU match automatically.');
    } catch (Exception $ex) { flash('Could not map: '.$ex->getMessage()); }
  }
  header('Location: daraz.php'); exit;
}

$today = date('Y-m-d'); $yday = date('Y-m-d', strtotime('-1 day'));
$mStart = date('Y-m-01'); $mEnd = date('Y-m-t');
$monthOrders = daraz_orders_between($mStart, $mEnd);
$M = daraz_metrics($monthOrders, daraz_ads_between($mStart, $mEnd));
$cnt = fn($sql, $p = []) => (int)val($sql, array_merge([$dzId], $p));
$newToday = $cnt("SELECT COUNT(DISTINCT COALESCE(daraz_order_id, CONCAT('e',id))) FROM orders WHERE courier_id=? AND order_date=?", [$today]);
$newYday  = $cnt("SELECT COUNT(DISTINCT COALESCE(daraz_order_id, CONCAT('e',id))) FROM orders WHERE courier_id=? AND order_date=?", [$yday]);
$toPack   = $cnt("SELECT COUNT(*) FROM orders WHERE courier_id=? AND status IN ('pending','processing')");
$inTransit= $cnt("SELECT COUNT(*) FROM orders WHERE courier_id=? AND status='shipped'");
$stuck    = $cnt("SELECT COUNT(*) FROM orders WHERE courier_id=? AND status IN ('pending','processing') AND order_date < ?", [date('Y-m-d', strtotime('-3 days'))]);
$noFees   = $cnt("SELECT COUNT(*) FROM orders WHERE courier_id=? AND status='delivered' AND delivery_charge<=0 AND COALESCE(daraz_fee_source,'')<>'finance' AND order_date>=?", [date('Y-m-d', strtotime('-60 days'))]);
/* booked in the ERP but no Daraz order number linked (neither the field nor the old "Daraz #id" remark) */
$unlinked = $cnt("SELECT COUNT(*) FROM orders WHERE courier_id=? AND (daraz_order_id IS NULL OR daraz_order_id='') AND COALESCE(remarks,'') NOT REGEXP '^(Daraz #)?[0-9]{6,}' AND status NOT IN ('cancelled') AND order_date>=?", [date('Y-m-d', strtotime('-60 days'))]);
$darazUnmatched = daraz_unmatched_skus();
$allProducts = $darazUnmatched ? rows("SELECT id,name,sku FROM products ORDER BY name") : [];

/* status board for this month */
$pipe = ['pack'=>['To pack',['pending','processing'],'#64748b'], 'shipped'=>['Shipped',['shipped'],'#e0851b'],
         'delivered'=>['Delivered',['delivered'],'#0f9d63'], 'returned'=>['Returned',['returned','cancelled','exchanged'],'#dc4437']];
$pipeData = [];
foreach ($pipe as $gk => $g) $pipeData[$gk] = ['n'=>0,'amt'=>0,'items'=>[]];
foreach ($monthOrders as $o) {
  $gk = 'pack'; foreach ($pipe as $k => $g) if (in_array($o['status'], $g[1], true)) $gk = $k;
  $pipeData[$gk]['n']++; $pipeData[$gk]['amt'] += (float)$o['sell_price']*(int)$o['qty'];
  if (count($pipeData[$gk]['items']) < 3) $pipeData[$gk]['items'][] = $o;
}

/* last 7 days: orders and profit per day */
$days = [];
for ($i = 6; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i days"))] = ['orders'=>[], 'profit'=>0.0];
foreach (daraz_orders_between(array_key_first($days), $today) as $o) {
  if (!isset($days[$o['order_date']])) continue;
  $days[$o['order_date']]['orders'][$o['daraz_order_id'] ?: ('e'.$o['id'])] = 1;
  $days[$o['order_date']]['profit'] += order_profit($o);
}

/* top products this month (delivered) */
$top = [];
foreach ($monthOrders as $o) {
  if ($o['status'] !== 'delivered') continue;
  $k = $o['product_name'] ?: ($o['daraz_item_name'] ?? '') ?: '—';
  $top[$k] ??= ['units'=>0,'rev'=>0,'profit'=>0];
  $top[$k]['units'] += (int)$o['qty']; $top[$k]['rev'] += (float)$o['sell_price']*(int)$o['qty']; $top[$k]['profit'] += order_profit($o);
}
uasort($top, fn($a, $b) => $b['rev'] <=> $a['rev']);
$top = array_slice($top, 0, 5, true);
$ads = rows("SELECT * FROM daraz_ads ORDER BY spend_date DESC, id DESC LIMIT 8");
$delta = $newToday - $newYday;

require __DIR__.'/includes/header.php';
echo delivery_disabled_banner('daraz.php');
echo daraz_shared_css();
?>
<style>
.dzd-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:16px;margin-top:16px;align-items:start}
@media(max-width:1024px){.dzd-grid{grid-template-columns:1fr}}
.dzd-sec{font-size:12px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin:18px 0 0}
.dzd-att{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;margin-top:8px;text-decoration:none;font-size:13px;font-weight:700}
.dzd-att.a1{background:rgba(245,158,11,.14);color:#92400e}.dzd-att.a2{background:rgba(239,68,68,.12);color:#b91c1c}.dzd-att.a3{background:rgba(59,130,246,.12);color:#1d4ed8}.dzd-att.a4{background:rgba(99,102,241,.12);color:#4338ca}
body.dark .dzd-att.a1{color:#fcd34d}body.dark .dzd-att.a2{color:#fca5a5}body.dark .dzd-att.a3{color:#93c5fd}body.dark .dzd-att.a4{color:#c7d2fe}
.dzd-ok{padding:12px 14px;border-radius:12px;background:rgba(15,157,99,.12);color:#047857;font-weight:700;font-size:13px;margin-top:8px}
.dz-pipe{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:10px}
@media(max-width:900px){.dz-pipe{grid-template-columns:repeat(2,minmax(0,1fr))}}
.dz-col{display:block;text-decoration:none;color:inherit;padding:12px 12px 10px;border-radius:16px;border:1px solid rgba(148,163,184,.25);background:rgba(255,255,255,.55);transition:transform .15s}
body.dark .dz-col{background:rgba(2,6,23,.35)}
.dz-col:hover{transform:translateY(-2px);border-color:var(--c)}
.dz-col-h{display:flex;justify-content:space-between;align-items:center;font-weight:800;font-size:13px}
.dz-col-n{background:var(--c);color:#fff;border-radius:999px;padding:1px 9px;font-size:12px}
.dz-col-amt{font-size:11.5px;color:var(--muted);margin:2px 0 6px}
.dz-mini{font-size:11.5px;padding:6px 8px;border-radius:9px;background:rgba(148,163,184,.12);margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border-left:3px solid var(--c)}
.dz-mini span{color:var(--muted)}
.dz-map-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:12px 0;border-bottom:1px solid rgba(148,163,184,.18)}
.dz-map-row:last-child{border-bottom:none}
.dz-map-panel{border-radius:18px;padding:4px 20px 8px;margin-top:16px;background:linear-gradient(135deg,rgba(251,191,36,.14),rgba(249,115,22,.08));border:1px solid rgba(245,158,11,.35)}
.dz-map-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 0 6px}
.dz-map-head h2{font-size:14px;margin:0;font-weight:800;color:#b45309}
body.dark .dz-map-head h2{color:#fbbf24}
</style>

<div class="ncmv3">
<div class="ncm-hero">
  <div class="nh-left">
    <h1><img src="assets/daraz-icon.ico" alt="Daraz" style="height:28px;vertical-align:-6px;margin-right:6px"><span class="grad">Daraz Dashboard</span></h1>
    <p>Today at a glance · orders are booked in Sales, then linked to their Daraz order number so status and Daraz charges sync from Daraz</p>
    <div class="nh-status">
      <?php if ($darazConnected): ?><span class="live"><span class="d"></span> LIVE · Connected as <?= e($darazAccount) ?></span>
      <?php else: ?><span class="live off"><span class="d"></span> Not connected — <a href="settings.php">authorize in Settings → Channel / Daraz API</a></span><?php endif; ?>
    </div>
  </div>
  <div class="nh-actions">
    <button type="button" class="btn nh-cta" onclick="dzlOpen('')">🔗 Check a Daraz order by ID</button>
  </div>
</div>
<?= daraz_tabs('daraz.php') ?>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="dzd-sec">Today · <?= e(date('D j M')) ?></div>
<div class="mgrid">
  <div class="metric blue"><span class="g">🛍</span><div class="mv"><?= $newToday ?></div><div class="ml">New orders today
    <?php if ($delta): ?><span class="<?= $delta>0?'dz-up':'dz-dn' ?>"><?= $delta>0?'▲':'▼' ?> <?= abs($delta) ?> vs yesterday</span><?php else: ?>· same as yesterday<?php endif; ?></div></div>
  <a class="metric amber" style="text-decoration:none;color:inherit" href="daraz_sales.php?view=list&from=2000-01-01&to=<?= $today ?>&st=pack"><span class="g">📦</span><div class="mv"><?= $toPack ?></div><div class="ml">To pack now</div></a>
  <a class="metric orange" style="text-decoration:none;color:inherit" href="daraz_sales.php?view=list&from=2000-01-01&to=<?= $today ?>&st=shipped"><span class="g">🚚</span><div class="mv"><?= $inTransit ?></div><div class="ml">In transit now</div></a>
  <div class="metric red"><span class="g">↩️</span><div class="mv"><?= (int)$M['returned'] ?></div><div class="ml">Returned this month<?= $M['ret_rate']!==null ? ' · '.round($M['ret_rate'],1).'%' : '' ?></div></div>
</div>

<div class="dzd-sec">This month · <?= e(date('F Y')) ?></div>
<div class="mgrid">
  <div class="metric green"><span class="g">💰</span><div class="mv" style="font-size:19px"><?= money($M['revenue']) ?></div><div class="ml">Delivered revenue · <?= number_format($M['units']) ?> pcs</div></div>
  <div class="metric <?= $M['net']>=0?'teal':'red' ?>"><span class="g">🧾</span><div class="mv" style="font-size:19px"><?= money($M['net']) ?></div><div class="ml">Net profit after ads<?= $M['margin']!==null ? ' · '.round($M['margin']).'%' : '' ?></div></div>
  <div class="metric orange"><span class="g">📣</span><div class="mv" style="font-size:19px"><?= money($M['ads']) ?></div><div class="ml">Ads spent</div></div>
  <div class="metric indigo"><span class="g">🎯</span><div class="mv"><?= $M['roas']!==null ? round($M['roas'],1).'×' : '—' ?></div><div class="ml">ROAS · revenue ÷ ads</div></div>
</div>

<?php if ($darazUnmatched): ?>
<div class="dz-map-panel" id="mapSku">
  <div class="dz-map-head"><h2>🔗 Map unmatched Daraz products (<?= count($darazUnmatched) ?>)</h2>
    <span class="muted" style="font-size:12px">Pick the matching product — fixes every order already in the ERP for it, and new ones match automatically.</span></div>
  <?php foreach ($darazUnmatched as $um): ?>
    <form method="post" class="dz-map-row">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="map_sku"><input type="hidden" name="sku" value="<?= e($um['daraz_sku']) ?>">
      <div style="min-width:220px;flex:1"><div style="font-weight:700;font-size:13px"><?= e($um['sample_name'] ?: 'Daraz item') ?></div>
        <div class="muted" style="font-size:11px">SKU: <?= e($um['daraz_sku']) ?> · <?= (int)$um['line_count'] ?> order line(s) waiting</div></div>
      <select name="product_id" required style="flex:1;min-width:200px;border-radius:10px;padding:8px 10px">
        <option value="">— pick the matching product —</option>
        <?php foreach ($allProducts as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?><?= $p['sku'] ? ' ('.e($p['sku']).')' : '' ?></option><?php endforeach; ?>
      </select>
      <button class="btn nh-cta" style="padding:8px 16px;font-size:12.5px">✓ Map it</button>
    </form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="dzd-grid">
<div>
  <div class="panel" style="padding:14px 18px 16px">
    <div class="panel-head" style="padding:0 0 4px"><h2>⚠ Needs attention</h2></div>
    <?php $any = false; ?>
    <?php if ($darazUnmatched): $any = true; ?><a class="dzd-att a1" href="#mapSku"><span>🔗 <?= count($darazUnmatched) ?> Daraz SKU(s) not matched to a product</span><span>Map →</span></a><?php endif; ?>
    <?php if ($noFees): $any = true; ?><a class="dzd-att a2" href="daraz_sales.php?view=list&from=<?= date('Y-m-d', strtotime('-60 days')) ?>&to=<?= $today ?>&f=nofee"><span>💸 <?= $noFees ?> delivered order(s) with no Daraz fees — profit looks too high</span><span>Fix →</span></a><?php endif; ?>
    <?php if ($stuck): $any = true; ?><a class="dzd-att a3" href="daraz_sales.php?view=list&from=2000-01-01&to=<?= $today ?>&st=pack"><span>⏳ <?= $stuck ?> order(s) waiting to pack for over 3 days</span><span>View →</span></a><?php endif; ?>
    <?php if ($unlinked): $any = true; ?><a class="dzd-att a4" href="daraz_sales.php?view=list&from=<?= date('Y-m-d', strtotime('-60 days')) ?>&to=<?= $today ?>&f=unlinked"><span>🔗 <?= $unlinked ?> booked order line(s) not linked to a Daraz order number yet (last 60 days)</span><span>Link →</span></a><?php endif; ?>
    <?php if (!$any): ?><div class="dzd-ok">✓ All clear — nothing needs fixing right now.</div><?php endif; ?>
  </div>

  <div class="dzd-sec">This month by status — click to open in Daraz Sales</div>
  <div class="dz-pipe">
    <?php foreach ($pipe as $gk => [$lbl,$sts,$col]): $pd = $pipeData[$gk]; ?>
    <a class="dz-col" style="--c:<?= $col ?>" href="daraz_sales.php?view=list&range=month&st=<?= $gk==='returned'?'returned':$gk ?>">
      <div class="dz-col-h"><span><?= e($lbl) ?></span><span class="dz-col-n"><?= $pd['n'] ?></span></div>
      <div class="dz-col-amt"><?= money($pd['amt']) ?> order value</div>
      <?php foreach ($pd['items'] as $it): ?><div class="dz-mini"><?= e($it['customer'] ?: '—') ?> <span>· <?= e($it['product_name'] ?: ($it['daraz_item_name'] ?? '') ?: 'item') ?></span></div><?php endforeach; ?>
      <?php if (!$pd['items']): ?><div class="dz-mini" style="border-left-color:transparent"><span>Nothing here</span></div><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="panel" style="margin-top:16px;padding:14px 18px">
    <div class="panel-head" style="padding:0 0 8px"><h2>📅 Last 7 days</h2><span class="muted" style="font-size:12px">orders (bars) · profit (line)</span></div>
    <div style="height:220px"><canvas id="dz7"></canvas></div>
  </div>
</div>

<aside>
  <div class="panel">
    <div class="panel-head"><h2>🏆 Top products this month</h2></div>
    <div class="panel-body" style="padding-top:4px">
      <?php if (!$top): ?><div class="muted" style="font-size:13px">No delivered Daraz orders yet this month.</div><?php endif; ?>
      <?php foreach ($top as $name => $t): ?>
        <div style="display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid rgba(148,163,184,.18);font-size:13px">
          <div style="min-width:0"><b style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($name) ?></b><span class="muted" style="font-size:11.5px"><?= $t['units'] ?> pcs · <?= money($t['rev']) ?></span></div>
          <b style="color:<?= $t['profit']>=0?'var(--green)':'var(--red)' ?>;white-space:nowrap"><?= money($t['profit']) ?></b>
        </div>
      <?php endforeach; ?>
      <a href="daraz_analytics.php" class="muted" style="font-size:12px;display:inline-block;margin-top:8px">Full product profit in Analytics →</a>
    </div>
  </div>

  <div class="panel" style="margin-top:16px">
    <div class="panel-head"><h2>📣 Ads spend</h2><span class="muted" style="font-size:12px">this month: <b><?= money($M['ads']) ?></b></span></div>
    <div class="panel-body" style="padding-top:6px">
      <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="add_ad">
        <input type="date" name="spend_date" value="<?= $today ?>" style="width:130px">
        <input type="number" step="any" min="1" name="amount" placeholder="Amount" required style="flex:1;min-width:90px">
        <input name="note" placeholder="note (optional)" style="width:100%">
        <button class="btn nh-cta" style="width:100%">＋ Add spend</button>
      </form>
      <?php foreach ($ads as $a): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid rgba(148,163,184,.18);font-size:12.5px">
          <span><?= e(date('j M', strtotime($a['spend_date']))) ?> <span class="muted"><?= e($a['note'] ?: '') ?></span></span>
          <span style="display:flex;gap:6px;align-items:center"><b><?= money($a['amount']) ?></b>
          <?php if ($isAdmin): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete this ad entry?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="del_ad"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="rowdel" title="Delete">🗑</button></form><?php endif; ?></span>
        </div>
      <?php endforeach; if (!$ads): ?><div class="muted" style="font-size:12.5px">No ad spend recorded yet.</div><?php endif; ?>
    </div>
  </div>
</aside>
</div>
</div><!-- /ncmv3 -->
<?= daraz_link_ui() ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function(){
  if(!window.Chart)return;
  var dark=document.body.classList.contains('dark'),grid=dark?'rgba(148,163,184,.15)':'rgba(148,163,184,.25)',ink=dark?'#cbd5e1':'#475569';
  new Chart(document.getElementById('dz7'),{
    data:{labels:<?= json_encode(array_map(fn($d)=>date('D j',strtotime($d)), array_keys($days))) ?>,
      datasets:[
        {type:'bar',label:'Orders',data:<?= json_encode(array_values(array_map(fn($d)=>count($d['orders']), $days))) ?>,backgroundColor:'rgba(248,86,6,.55)',borderRadius:6,yAxisID:'y'},
        {type:'line',label:'Profit (Rs.)',data:<?= json_encode(array_values(array_map(fn($d)=>round($d['profit']), $days))) ?>,borderColor:'#14b8a6',backgroundColor:'#14b8a6',tension:.35,yAxisID:'y1'}
      ]},
    options:{maintainAspectRatio:false,plugins:{legend:{labels:{color:ink}}},
      scales:{x:{ticks:{color:ink},grid:{display:false}},y:{beginAtZero:true,ticks:{color:ink,precision:0},grid:{color:grid}},y1:{position:'right',ticks:{color:ink},grid:{display:false}}}}
  });
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
