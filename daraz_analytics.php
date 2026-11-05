<?php
/* Daraz Analytics — profit & loss, period-vs-period comparison, trend, product profit,
   ads efficiency, returns, Daraz vs your own channels and customers. All money uses the
   same rules as everywhere else in the ERP (order_profit), so numbers agree with Sales. */
require_once __DIR__.'/daraz_common.php';
require_login(); require_page_access();
daraz_ensure_cols(); daraz_ensure_ads_table();
$PAGE_TITLE = 'Daraz Analytics';
$R = daraz_range($_GET);
$days = (int)round((strtotime($R['end']) - strtotime($R['start'])) / 86400) + 1;
$pEnd = date('Y-m-d', strtotime($R['start'].' -1 day'));
$pStart = date('Y-m-d', strtotime($pEnd.' -'.($days-1).' days'));
/* calendar months compare to the previous calendar month, not "same number of days" */
if ($R['start'] === date('Y-m-01', strtotime($R['start'])) && $R['end'] === date('Y-m-t', strtotime($R['start']))) {
  $pStart = date('Y-m-01', strtotime($R['start'].' -1 month')); $pEnd = date('Y-m-t', strtotime($pStart));
}
$cur  = daraz_orders_between($R['start'], $R['end']);
$prev = daraz_orders_between($pStart, $pEnd);
$C = daraz_metrics($cur,  daraz_ads_between($R['start'], $R['end']));
$P = daraz_metrics($prev, daraz_ads_between($pStart, $pEnd));
$prevLabel = date('j M', strtotime($pStart)).' – '.date('j M Y', strtotime($pEnd));

/* % change helper: [text, class] — $goodUp=false for costs (up is bad) */
function dza_delta($now, $before, bool $goodUp = true, bool $points = false): array {
  if ($now === null || $before === null) return ['', ''];
  if ($points) { $d = $now - $before; if (abs($d) < 0.05) return ['no change', 'muted'];
    return [($d > 0 ? '▲ ' : '▼ ').round(abs($d), 1).' pts', (($d > 0) === $goodUp) ? 'dz-up' : 'dz-dn']; }
  if (abs($before) < 0.01) return [$now > 0 ? 'new' : '—', 'muted'];
  $pc = ($now - $before) / abs($before) * 100;
  if (abs($pc) < 0.5) return ['no change', 'muted'];
  return [($pc > 0 ? '▲ ' : '▼ ').round(abs($pc)).'%', (($pc > 0) === $goodUp) ? 'dz-up' : 'dz-dn'];
}

/* ---- trend: daily for short ranges, weekly for long ones ---- */
$weekly = $days > 45;
$bucket = fn($d) => $weekly ? date('Y-m-d', strtotime('monday this week', strtotime($d))) : $d;
$trend = [];
for ($t = strtotime($R['start']); $t <= strtotime($R['end']); $t += 86400) $trend[$bucket(date('Y-m-d', $t))] ??= ['rev'=>0.0,'profit'=>0.0,'ads'=>0.0,'orders'=>[]];
foreach ($cur as $o) {
  $k = $bucket($o['order_date']); if (!isset($trend[$k])) continue;
  if ($o['status'] === 'delivered') $trend[$k]['rev'] += (float)$o['sell_price']*(int)$o['qty'];
  $trend[$k]['profit'] += order_profit($o);
  $trend[$k]['orders'][$o['daraz_order_id'] ?: ('e'.$o['id'])] = 1;
}
foreach (rows("SELECT spend_date, SUM(amount) a FROM daraz_ads WHERE spend_date BETWEEN ? AND ? GROUP BY spend_date", [$R['start'], $R['end']]) as $a) {
  $k = $bucket($a['spend_date']); if (isset($trend[$k])) { $trend[$k]['ads'] += (float)$a['a']; $trend[$k]['profit'] -= (float)$a['a']; }
}

/* ---- product profitability ---- */
$prod = [];
foreach ($cur as $o) {
  $k = $o['product_name'] ?: ($o['daraz_item_name'] ?? '') ?: '— unmapped —';
  $prod[$k] ??= ['units'=>0,'rev'=>0.0,'cost'=>0.0,'fees'=>0.0,'del'=>0,'ret'=>0,'ret_loss'=>0.0,'profit'=>0.0,'open'=>0];
  $q = (int)$o['qty'];
  if ($o['status'] === 'delivered') { $prod[$k]['del']++; $prod[$k]['units'] += $q; $prod[$k]['rev'] += (float)$o['sell_price']*$q; $prod[$k]['cost'] += (float)$o['cost_price']*$q; $prod[$k]['fees'] += (float)$o['delivery_charge']; }
  elseif (in_array($o['status'], ['returned','cancelled','exchanged'], true)) { $prod[$k]['ret']++; $prod[$k]['ret_loss'] += max(0, -order_profit($o)); }
  else $prod[$k]['open']++;
  $prod[$k]['profit'] += order_profit($o);
}
uasort($prod, fn($a, $b) => $b['profit'] <=> $a['profit']);

/* ---- Daraz vs your own channels (everything that isn't Daraz), same period, before ads ---- */
$others = rows("SELECT o.* FROM orders o WHERE COALESCE(o.courier_id,0)<>? AND o.order_date BETWEEN ? AND ?", [daraz_courier_id(), $R['start'], $R['end']]);
$O = daraz_metrics($others, 0.0);
$chan = function(array $m): array {
  return ['aov'=>$m['delivered'] ? $m['revenue']/$m['delivered'] : null,
          'ppo'=>$m['delivered'] ? ($m['gross'])/$m['delivered'] : null,
          'ret'=>$m['ret_rate'], 'margin'=>$m['revenue'] > 0 ? $m['gross']/$m['revenue']*100 : null, 'orders'=>$m['delivered']];
};
$chD = $chan($C); $chO = $chan($O);

/* ---- customers: top cities + repeat buyers ---- */
$cities = []; $phones = [];
foreach ($cur as $o) {
  $parts = array_map('trim', explode(',', (string)$o['address'])); $city = end($parts) ?: '—';
  $cities[$city] = ($cities[$city] ?? 0) + 1;
  $ph = preg_replace('/\D+/', '', (string)$o['phone']); if ($ph !== '') $phones[$ph][$o['daraz_order_id'] ?: ('e'.$o['id'])] = 1;
}
arsort($cities); $cities = array_slice($cities, 0, 8, true);
$buyers = count($phones); $repeat = count(array_filter($phones, fn($x) => count($x) > 1));

/* ---- weekly ads table ---- */
$adsWeeks = [];
foreach ($trend as $k => $t) { $wk = date('Y-m-d', strtotime('monday this week', strtotime($k))); $adsWeeks[$wk] ??= ['ads'=>0,'rev'=>0]; $adsWeeks[$wk]['ads'] += $t['ads']; $adsWeeks[$wk]['rev'] += $t['rev']; }

require __DIR__.'/includes/header.php';
echo delivery_disabled_banner('daraz.php');
echo daraz_shared_css();
$tile = function($label, $val, $d, $icon, $cls) {
  return '<div class="metric '.$cls.'"><span class="g">'.$icon.'</span><div class="mv" style="font-size:19px">'.$val.'</div><div class="ml">'.e($label)
    .($d[0] !== '' ? ' <span class="'.$d[1].'">'.e($d[0]).'</span>' : '').'</div></div>';
};
?>
<style>
.dza-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;margin-top:16px;align-items:start}
@media(max-width:1024px){.dza-grid{grid-template-columns:1fr}}
.dza-pl td{padding:7px 4px;font-size:13px}
.dza-pl td.r{text-align:right}
.dza-pl tr.tot td{border-top:2px solid rgba(148,163,184,.4);font-weight:800}
.dza-bar{height:7px;border-radius:99px;background:rgba(148,163,184,.2);overflow:hidden;margin-top:3px}
.dza-bar i{display:block;height:100%;background:#f85606}
.dza-vs{display:grid;grid-template-columns:1.3fr 1fr 1fr;gap:0;font-size:13px}
.dza-vs div{padding:8px 6px;border-bottom:1px solid rgba(148,163,184,.18)}
.dza-vs .h{font-weight:800;color:var(--muted);font-size:12px}
</style>

<div class="ncmv3">
<div class="ncm-hero">
  <div class="nh-left">
    <h1><img src="assets/daraz-icon.ico" alt="Daraz" style="height:28px;vertical-align:-6px;margin-right:6px"><span class="grad">Daraz Analytics</span></h1>
    <p><?= e($R['label']) ?> compared with <?= e($prevLabel) ?></p>
  </div>
</div>
<?= daraz_tabs('daraz_analytics.php') ?>
<?= daraz_range_bar($R, 'daraz_analytics.php') ?>

<?php if ($C['missing_fees'] > 0): ?>
<div class="flash" style="background:rgba(245,158,11,.14);color:#92400e;margin-top:14px">⚠ <?= $C['missing_fees'] ?> delivered order(s) in this period have no Daraz fees yet, so profit here is higher than real.
  <a href="daraz_sales.php?<?= e($R['qs']) ?>&f=nofee" style="font-weight:800">Add charges or sync with Daraz →</a></div>
<?php endif; ?>

<div class="mgrid" style="margin-top:16px">
  <?= $tile('Orders', number_format($C['orders']), dza_delta($C['orders'], $P['orders']), '🛍', 'blue') ?>
  <?= $tile('Delivered revenue', money($C['revenue']), dza_delta($C['revenue'], $P['revenue']), '💰', 'green') ?>
  <?= $tile('Net profit after ads', money($C['net']), dza_delta($C['net'], $P['net']), '🧾', $C['net']>=0?'teal':'red') ?>
  <?= $tile('Ads spent', money($C['ads']), dza_delta($C['ads'], $P['ads'], false), '📣', 'orange') ?>
  <?= $tile('ROAS', $C['roas']!==null ? round($C['roas'],1).'×' : '—', dza_delta($C['roas'], $P['roas']), '🎯', 'indigo') ?>
  <?= $tile('Return rate', $C['ret_rate']!==null ? round($C['ret_rate'],1).'%' : '—', dza_delta($C['ret_rate'], $P['ret_rate'], false, true), '↩️', 'red') ?>
</div>

<div class="dza-grid">
  <div class="panel" style="padding:14px 18px">
    <div class="panel-head" style="padding:0 0 6px"><h2>🧾 Profit & loss</h2><span class="muted" style="font-size:12px">this period vs previous</span></div>
    <table class="dza-pl" style="width:100%;border-collapse:collapse">
      <tr><td class="muted" style="font-size:12px"></td><td class="r muted" style="font-size:12px">This period</td><td class="r muted" style="font-size:12px">Previous</td><td class="r muted" style="font-size:12px">Change</td></tr>
      <?php foreach ([
        ['Revenue (delivered)', $C['revenue'], $P['revenue'], true],
        ['− Product cost', $C['cost'], $P['cost'], false],
        ['− Daraz fees', $C['fees'], $P['fees'], false],
        ['− Return losses', $C['ret_loss'], $P['ret_loss'], false],
        ['− Ads', $C['ads'], $P['ads'], false],
      ] as [$l, $a, $b, $up]): $d = dza_delta($a, $b, $up); ?>
        <tr><td><?= e($l) ?></td><td class="r"><?= money($a) ?></td><td class="r muted"><?= money($b) ?></td><td class="r"><span class="<?= $d[1] ?>"><?= e($d[0]) ?></span></td></tr>
      <?php endforeach; $d = dza_delta($C['net'], $P['net']); ?>
      <tr class="tot"><td>Net profit<?= $C['margin']!==null ? ' · '.round($C['margin'],1).'% margin' : '' ?></td>
        <td class="r" style="color:<?= $C['net']>=0?'var(--green)':'var(--red)' ?>"><?= money($C['net']) ?></td><td class="r muted"><?= money($P['net']) ?></td><td class="r"><span class="<?= $d[1] ?>"><?= e($d[0]) ?></span></td></tr>
    </table>
    <?php if ($C['revenue'] > 0): $segs = [['Cost',$C['cost'],'#f59e0b'],['Daraz fees',$C['fees'],'#f85606'],['Ads',$C['ads'],'#8b5cf6'],['Returns',$C['ret_loss'],'#ef4444'],['Net',max(0,$C['net']),'#14b8a6']]; $base = max($C['revenue'], array_sum(array_column($segs,1))); ?>
      <div style="display:flex;height:26px;border-radius:10px;overflow:hidden;margin-top:12px">
        <?php foreach ($segs as [$l,$v,$c]): if ($v <= 0) continue; $pc = $v/$base*100; ?><i title="<?= e($l.' '.money($v).' ('.round($pc).'%)') ?>" style="flex:<?= round($pc,2) ?>;background:<?= $c ?>;display:flex;align-items:center;justify-content:center;color:#fff;font-style:normal;font-size:10.5px;font-weight:800;overflow:hidden;white-space:nowrap"><?= $pc>=9 ? e($l).' '.round($pc).'%' : '' ?></i><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="panel" style="padding:14px 18px">
    <div class="panel-head" style="padding:0 0 6px"><h2>📈 <?= $weekly ? 'Weekly' : 'Daily' ?> trend</h2><span class="muted" style="font-size:12px">revenue · profit after ads · ads</span></div>
    <div style="height:260px"><canvas id="dzaTrend"></canvas></div>
  </div>
</div>

<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>🏷️ Profit by product</h2><span class="muted" style="font-size:12px">sorted by profit · red = losing money</span></div>
  <div class="table-wrap"><table class="tbl num-tbl">
    <thead><tr><th>Product</th><th class="right">Units sold</th><th class="right">Revenue</th><th class="right">Cost</th><th class="right">Daraz fees</th><th class="right">Returns</th><th class="right">Return loss</th><th class="right">Profit</th><th class="right">Margin</th></tr></thead>
    <tbody>
    <?php foreach ($prod as $name => $p): $rr = ($p['del']+$p['ret']) ? $p['ret']/($p['del']+$p['ret'])*100 : null; $mg = $p['rev']>0 ? $p['profit']/$p['rev']*100 : null; $bad = $p['profit'] < 0; ?>
      <tr<?= $bad ? ' style="background:rgba(239,68,68,.06)"' : '' ?>>
        <td><b><?= e($name) ?></b><?php if ($p['open']): ?><div class="dz-sub"><?= $p['open'] ?> still open</div><?php endif; ?></td>
        <td class="num right"><?= number_format($p['units']) ?></td>
        <td class="num right"><?= money($p['rev']) ?></td>
        <td class="num right"><?= money($p['cost']) ?></td>
        <td class="num right"><?= money($p['fees']) ?></td>
        <td class="num right" style="<?= $rr!==null && $rr>=10 ? 'color:var(--red);font-weight:800' : '' ?>"><?= $p['ret'] ?><?= $rr!==null ? ' · '.round($rr).'%' : '' ?></td>
        <td class="num right"><?= money($p['ret_loss']) ?></td>
        <td class="num right" style="font-weight:800;color:<?= $bad?'var(--red)':'var(--green)' ?>"><?= money($p['profit']) ?></td>
        <td class="num right"><?= $mg!==null ? round($mg).'%' : '—' ?></td>
      </tr>
    <?php endforeach; if (!$prod): ?><tr><td colspan="9"><div class="empty">No Daraz orders in this period.</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:11.5px;margin:8px 0 0">Profit per product is before ads (ads aren't tracked per product yet).</p>
</div>

<div class="dza-grid">
  <div class="panel" style="padding:14px 18px">
    <div class="panel-head" style="padding:0 0 6px"><h2>📣 Ads</h2></div>
    <div class="mgrid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin:0">
      <div class="metric indigo" style="padding:12px 14px"><div class="mv" style="font-size:18px"><?= $C['roas']!==null ? round($C['roas'],1).'×' : '—' ?></div><div class="ml">ROAS</div></div>
      <div class="metric orange" style="padding:12px 14px"><div class="mv" style="font-size:18px"><?= $C['revenue']>0 ? round($C['ads']/$C['revenue']*100,1).'%' : '—' ?></div><div class="ml">Ads ÷ revenue</div></div>
      <div class="metric amber" style="padding:12px 14px"><div class="mv" style="font-size:18px"><?= $C['delivered'] ? money($C['ads']/$C['delivered']) : '—' ?></div><div class="ml">Ads per delivered order</div></div>
    </div>
    <table class="tbl" style="margin-top:12px"><thead><tr><th>Week of</th><th class="right">Ads</th><th class="right">Revenue</th><th class="right">ROAS</th></tr></thead><tbody>
      <?php foreach ($adsWeeks as $wk => $w): ?><tr><td><?= e(date('j M', strtotime($wk))) ?></td><td class="right"><?= money($w['ads']) ?></td><td class="right"><?= money($w['rev']) ?></td><td class="right"><?= $w['ads']>0 ? round($w['rev']/$w['ads'],1).'×' : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>

  <div>
    <div class="panel" style="padding:14px 18px">
      <div class="panel-head" style="padding:0 0 6px"><h2>⚖️ Daraz vs your own channels</h2><span class="muted" style="font-size:12px">same period · before ads</span></div>
      <div class="dza-vs">
        <div class="h"></div><div class="h">Daraz</div><div class="h">Own (NCM, P&amp;D…)</div>
        <div>Delivered orders</div><div><b><?= number_format($chD['orders']) ?></b></div><div><?= number_format($chO['orders']) ?></div>
        <div>Average order value</div><div><b><?= $chD['aov']!==null ? money($chD['aov']) : '—' ?></b></div><div><?= $chO['aov']!==null ? money($chO['aov']) : '—' ?></div>
        <div>Profit per delivered order</div><div><b><?= $chD['ppo']!==null ? money($chD['ppo']) : '—' ?></b></div><div><?= $chO['ppo']!==null ? money($chO['ppo']) : '—' ?></div>
        <div>Margin</div><div><b><?= $chD['margin']!==null ? round($chD['margin']).'%' : '—' ?></b></div><div><?= $chO['margin']!==null ? round($chO['margin']).'%' : '—' ?></div>
        <div>Return rate</div><div><b><?= $chD['ret']!==null ? round($chD['ret'],1).'%' : '—' ?></b></div><div><?= $chO['ret']!==null ? round($chO['ret'],1).'%' : '—' ?></div>
      </div>
    </div>
    <div class="panel" style="padding:14px 18px;margin-top:16px">
      <div class="panel-head" style="padding:0 0 6px"><h2>👥 Customers</h2><span class="muted" style="font-size:12px"><?= $buyers ?> buyers · <?= $repeat ?> bought more than once</span></div>
      <?php $maxC = $cities ? max($cities) : 1; foreach ($cities as $city => $n): ?>
        <div style="font-size:12.5px;margin-top:6px"><div style="display:flex;justify-content:space-between"><span><?= e($city) ?></span><b><?= $n ?></b></div><div class="dza-bar"><i style="width:<?= round($n/$maxC*100) ?>%"></i></div></div>
      <?php endforeach; if (!$cities): ?><div class="muted" style="font-size:12.5px">No orders in this period.</div><?php endif; ?>
    </div>
  </div>
</div>
</div><!-- /ncmv3 -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function(){
  if(!window.Chart)return;
  var dark=document.body.classList.contains('dark'),grid=dark?'rgba(148,163,184,.15)':'rgba(148,163,184,.25)',ink=dark?'#cbd5e1':'#475569';
  new Chart(document.getElementById('dzaTrend'),{
    data:{labels:<?= json_encode(array_map(fn($d)=>($weekly?'Wk ':'').date('j M',strtotime($d)), array_keys($trend))) ?>,
      datasets:[
        {type:'bar',label:'Revenue',data:<?= json_encode(array_values(array_map(fn($t)=>round($t['rev']), $trend))) ?>,backgroundColor:'rgba(248,86,6,.45)',borderRadius:5},
        {type:'line',label:'Profit after ads',data:<?= json_encode(array_values(array_map(fn($t)=>round($t['profit']), $trend))) ?>,borderColor:'#14b8a6',backgroundColor:'#14b8a6',tension:.35},
        {type:'line',label:'Ads',data:<?= json_encode(array_values(array_map(fn($t)=>round($t['ads']), $trend))) ?>,borderColor:'#8b5cf6',backgroundColor:'#8b5cf6',borderDash:[5,4],tension:.35}
      ]},
    options:{maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{labels:{color:ink}}},
      scales:{x:{ticks:{color:ink,maxRotation:0,autoSkip:true},grid:{display:false}},y:{ticks:{color:ink},grid:{color:grid}}}}
  });
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
