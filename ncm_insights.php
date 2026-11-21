<?php
/* NCM Insights — delivery speed & stuck parcels · charge check vs NCM's standard rate ·
   COD settlement (what NCM collected vs what it has paid you). Data: ncm_insights_lib.php */
require_once __DIR__.'/functions.php'; require_once __DIR__.'/ncm_insights_lib.php';
require_login();
/* the rate lookup also serves the booking form on ncm.php, so anyone who can book may use it */
if (($_GET['ajax'] ?? '') !== 'rate' || !can_see('ncm.php')) require_page_access();
ncm_ins_ensure();
$PAGE_TITLE = 'NCM Insights';
$connected = ncm()->configured();

/* ---- tiny JSON endpoint: standard rate for one route (used by the booking form) ---- */
if (($_GET['ajax'] ?? '') === 'rate') {
  header('Content-Type: application/json');
  try {
    if (!$connected) throw new Exception('NCM not connected');
    $from = trim((string)($_GET['from'] ?? '')); $to = trim((string)($_GET['to'] ?? '')); $rt = ncm_ins_rate_type($_GET['type'] ?? '');
    if ($from === '' || $to === '') throw new Exception('Pick both branches');
    $cache = ncm_ins_rates(); $k = ncm_ins_rate_key($from, $to, $rt);
    if (!isset($cache[$k]) || time() - (int)$cache[$k]['t'] > 7*86400) {
      $v = ncm_ins_rate_value(ncm()->rate($from, $to, $rt));
      if ($v === null) throw new Exception('NCM gave no rate for this route');
      $cache[$k] = ['r'=>$v, 't'=>time()]; kv_set('ncm_rate_cache', $cache);
    }
    echo json_encode(['ok'=>true, 'rate'=>(float)$cache[$k]['r']]);
  } catch (Exception $e) { echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); }
  exit;
}

$tab = in_array($_GET['tab'] ?? '', ['speed','charges','cod'], true) ? $_GET['tab'] : 'speed';
$canEdit = role_rank(current_user()['role'] ?? '') >= 2;

/* ---------------- actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf(); $act = $_POST['_action'] ?? '';
  try {
    if ($act === 'refresh') {
      if (!$connected) throw new Exception('Add your NCM API key in Settings first.');
      if (function_exists('set_time_limit')) @set_time_limit(240);
      $r = ncm_insights_refresh(160);
      flash("Updated {$r['orders']} NCM order(s) from NCM".($r['rates'] ? " · {$r['rates']} route rate(s)" : '').($r['errors'] ? " · {$r['errors']} could not be read" : '').($r['queued'] ? " · {$r['queued']} more waiting — press Refresh again or let the cron catch up" : ''));
    }
    elseif (!$canEdit) throw new Exception('Only managers can record settlements.');
    elseif ($act === 'settle') {
      $ids = array_values(array_filter(array_map(fn($x) => preg_replace('/\D+/', '', $x), explode(',', (string)($_POST['ids'] ?? '')))));
      if (!$ids) throw new Exception('Tick the orders NCM has paid you for.');
      $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ?? '') ? $_POST['date'] : date('Y-m-d');
      $byId = []; foreach (ncm_ins_orders() as $r) $byId[$r['nid']] = $r;
      $exp = 0.0; foreach ($ids as $id) if (isset($byId[$id])) $exp += ncm_ins_state($byId[$id]) === 'delivered' ? ncm_ins_cod($byId[$id]) - ncm_ins_billed($byId[$id]) : -ncm_ins_billed($byId[$id]);
      $amt = trim((string)($_POST['amount'] ?? '')) === '' ? $exp : (float)$_POST['amount'];
      q("INSERT INTO ncm_settlements(ref,settle_date,amount,orders,source,note,created_by) VALUES(?,?,?,?, 'manual', ?, ?)",
        [trim((string)($_POST['ref'] ?? '')) ?: null, $date, $amt, count($ids), trim((string)($_POST['note'] ?? '')) ?: null, current_user()['id'] ?? null]);
      $sid = (int)db()->lastInsertId();
      foreach ($ids as $id) {
        q("INSERT IGNORE INTO ncm_meta(ncm_order_id) VALUES(?)", [$id]);
        $net = isset($byId[$id]) ? (ncm_ins_state($byId[$id]) === 'delivered' ? ncm_ins_cod($byId[$id]) - ncm_ins_billed($byId[$id]) : -ncm_ins_billed($byId[$id])) : null;
        q("UPDATE ncm_meta SET settlement_id=?, settled_amount=? WHERE ncm_order_id=?", [$sid, $net, $id]);
      }
      log_activity('NCM COD settlement #'.$sid.' recorded: '.count($ids).' orders, '.money($amt), 'NCM');
      flash('Recorded payment of '.money($amt).' for '.count($ids).' order(s)'.(abs($amt - $exp) > 1 ? ' — note: expected '.money($exp).', difference '.money($amt - $exp) : '').'.');
    }
    elseif ($act === 'import') {
      if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) throw new Exception('Choose NCM\'s payment statement (CSV) first.');
      $st = ncm_ins_parse_statement($_FILES['file']['tmp_name']);
      $byId = []; foreach (ncm_ins_orders() as $r) $byId[$r['nid']] = $r;
      $match = []; $unknown = []; $already = 0; $stmtTotal = 0.0; $expTotal = 0.0;
      foreach ($st['rows'] as $id => $amt) {
        if (!isset($byId[$id])) { $unknown[] = $id; continue; }
        if ($byId[$id]['settlement_id']) { $already++; continue; }
        $r = $byId[$id];
        $exp = ncm_ins_state($r) === 'returned' ? -ncm_ins_billed($r) : ncm_ins_cod($r) - ncm_ins_billed($r);
        $match[$id] = $amt ?? $exp; $stmtTotal += $match[$id]; $expTotal += $exp;
      }
      if (!$match) throw new Exception('No new orders matched. '.($already ? "$already already recorded. " : '').($unknown ? count($unknown).' order ID(s) are not in the ERP: '.implode(', ', array_slice($unknown, 0, 8)) : ''));
      $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ?? '') ? $_POST['date'] : date('Y-m-d');
      q("INSERT INTO ncm_settlements(ref,settle_date,amount,orders,source,note,created_by) VALUES(?,?,?,?, 'statement', ?, ?)",
        [trim((string)($_POST['ref'] ?? '')) ?: ($_FILES['file']['name'] ?? null), $date, $stmtTotal, count($match), 'Imported from '.basename((string)($_FILES['file']['name'] ?? 'file')), current_user()['id'] ?? null]);
      $sid = (int)db()->lastInsertId();
      foreach ($match as $id => $amt) { q("INSERT IGNORE INTO ncm_meta(ncm_order_id) VALUES(?)", [$id]); q("UPDATE ncm_meta SET settlement_id=?, settled_amount=? WHERE ncm_order_id=?", [$sid, $amt, $id]); }
      log_activity("NCM statement imported as settlement #$sid: ".count($match).' orders', 'NCM');
      $msg = 'Matched '.count($match).' order(s) · statement '.money($stmtTotal);
      if ($st['amt_col'] !== null && abs($stmtTotal - $expTotal) > 1) $msg .= ' · ERP expected '.money($expTotal).' → difference '.money($stmtTotal - $expTotal).' (see Payments history)';
      if ($already) $msg .= " · $already already recorded";
      if ($unknown) $msg .= ' · '.count($unknown).' not in ERP: '.implode(', ', array_slice($unknown, 0, 8)).(count($unknown) > 8 ? '…' : '');
      if ($st['amt_col'] === null) $msg .= ' · no amount column found, used the ERP\'s expected amounts';
      flash($msg);
    }
    elseif ($act === 'undo') {
      $sid = (int)($_POST['sid'] ?? 0);
      q("UPDATE ncm_meta SET settlement_id=NULL, settled_amount=NULL WHERE settlement_id=?", [$sid]);
      q("DELETE FROM ncm_settlements WHERE id=?", [$sid]);
      log_activity("NCM settlement #$sid removed", 'NCM');
      flash('Payment record removed — its orders are unpaid again.');
    }
  } catch (Exception $e) { flash('⚠ '.$e->getMessage()); }
  header('Location: ncm_insights.php?tab='.urlencode($_POST['tab'] ?? $tab)); exit;
}

/* ---------------- data ---------------- */
$all = ncm_ins_orders();
$now = time();
$last = kv_get('ncm_insights_last');
$notFetched = count(array_filter($all, fn($r) => !$r['hist_at']));
$days = fn($a, $b) => ($a && $b) ? max(0, (strtotime($b) - strtotime($a)) / 86400) : null;
$med = function (array $v) { if (!$v) return null; sort($v); $n = count($v); return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n/2 - 1] + $v[$n/2]) / 2; };
$fmtD = fn($d) => $d === null ? '—' : ($d < 1 ? round($d * 24).' h' : round($d, 1).' d');

/* speed & stuck */
$stuckDays = max(1, (int)($_GET['sd'] ?? setting('ncm_stuck_days', 3)));
$period = in_array($_GET['p'] ?? '', ['30','90','all'], true) ? $_GET['p'] : '30';
$since = $period === 'all' ? 0 : $now - (int)$period * 86400;
$stuck = ncm_ins_stuck($stuckDays); $speeds = []; $pickups = []; $byBranch = [];
foreach ($all as $r) {
  $state = ncm_ins_state($r);
  $ref = $r['delivered_at'] ?: $r['returned_at'] ?: $r['order_date'];
  if (strtotime($ref) < $since) continue;
  $br = $r['branch'] ?: '— unknown —';
  $byBranch[$br] ??= ['n'=>0, 'del'=>0, 'ret'=>0, 'sp'=>[], 'active'=>0];
  $byBranch[$br]['n']++;
  if ($state === 'active') $byBranch[$br]['active']++;
  if ($state === 'returned') $byBranch[$br]['ret']++;
  if ($state === 'delivered') {
    $byBranch[$br]['del']++;
    $s = $days($r['picked_at'] ?: $r['created_at'], $r['delivered_at']);
    if ($s !== null) { $speeds[] = $s; $byBranch[$br]['sp'][] = $s; }
    $pk = $days($r['created_at'], $r['picked_at']); if ($pk !== null) $pickups[] = $pk;
  }
}
uasort($byBranch, fn($a, $b) => $b['n'] <=> $a['n']);
$fast = $speeds ? round(count(array_filter($speeds, fn($s) => $s <= 3)) / count($speeds) * 100) : null;

/* charges */
$rateCache = ncm_ins_rates();
$chk = []; $cMatch = 0; $cHigh = 0; $cLow = 0; $cNoRate = 0; $extra = 0.0;
foreach ($all as $r) {
  if (ncm_ins_state($r) !== 'delivered') continue;
  $exp = ncm_ins_expected($r, $rateCache); $bill = ncm_ins_billed($r);
  if ($exp === null) { $cNoRate++; continue; }
  $diff = $bill - $exp;
  if ($diff > 1) { $cHigh++; $extra += $diff; } elseif ($diff < -1) $cLow++; else $cMatch++;
  $chk[] = $r + ['exp'=>$exp, 'bill'=>$bill, 'diff'=>$diff];
}
$showAllCharges = ($_GET['all'] ?? '') === '1';
$chkShown = array_values(array_filter($chk, fn($c) => $showAllCharges || $c['diff'] > 1));
usort($chkShown, fn($a, $b) => $b['diff'] <=> $a['diff']);

/* COD */
$unpaid = []; $codAll = 0.0; $dueNow = 0.0; $paidTotal = 0.0; $ncmSaysPaid = 0;
foreach ($all as $r) {
  $state = ncm_ins_state($r);
  if (!in_array($state, ['delivered','returned'], true)) continue;
  $cod = $state === 'delivered' ? ncm_ins_cod($r) : 0.0; $bill = ncm_ins_billed($r);
  $net = $cod - $bill;
  if ($state === 'delivered') $codAll += $cod;
  if ($r['settlement_id']) { $paidTotal += $r['settled_amount'] !== null ? (float)$r['settled_amount'] : $net; continue; }
  if ($state === 'returned' && $bill <= 0) continue;
  $r['ncm_paid'] = ncm_ins_ncm_paid($r['pay_status']); if ($r['ncm_paid']) $ncmSaysPaid++;
  $r['net'] = $net; $r['cod_v'] = $cod; $r['bill'] = $bill; $r['state'] = $state;
  $r['wait'] = (int)floor(($now - strtotime($r['delivered_at'] ?: $r['returned_at'] ?: $r['order_date'])) / 86400);
  $dueNow += $net; $unpaid[] = $r;
}
usort($unpaid, fn($a, $b) => $b['wait'] <=> $a['wait']);
$oldest = $unpaid ? $unpaid[0]['wait'] : 0;
$settlements = rows("SELECT s.*, (SELECT COUNT(*) FROM ncm_meta m WHERE m.settlement_id=s.id) AS n FROM ncm_settlements s ORDER BY s.settle_date DESC, s.id DESC LIMIT 50");

require __DIR__.'/includes/header.php';
$link = fn($nid) => '<a href="'.e(ncm_portal_url($nid)).'" target="_blank" rel="noopener">#'.e($nid).'</a>';
?>
<style>
.ni-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}
.ni-tabs a{padding:8px 16px;border-radius:999px;border:1px solid var(--border);background:var(--surface);font-weight:700;font-size:13px;color:var(--ink);text-decoration:none}
.ni-tabs a.on{background:var(--brand,#4f46e5);border-color:transparent;color:#fff}
.ni-tabs a .ct{display:inline-block;margin-left:6px;padding:0 7px;border-radius:99px;background:var(--red-bg);color:var(--red);font-size:11px}
.ni-tabs a.on .ct{background:rgba(255,255,255,.25);color:#fff}
.ni-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:12px 16px}
.ni-bar form{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0}
.ni-bar input,.ni-bar select{border:1px solid var(--border);border-radius:9px;padding:6px 9px;font:inherit;background:var(--surface);color:var(--ink)}
.ni-note{font-size:12px;color:var(--muted);padding:10px 16px}
.ni-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}
.ni-pos{color:var(--green);font-weight:700}.ni-neg{color:var(--red);font-weight:700}
.ni-wrap{overflow-x:auto}
.ni-sec{margin-bottom:16px}
.row-danger td{background:var(--red-bg)!important}.row-warn td{background:var(--amber-bg)!important}
</style>

<div class="page-head"><div>
  <h1><img src="assets/ncm-icon.png" alt="" style="height:24px">NCM Insights</h1>
  <p>Delivery speed · stuck parcels · charge check · COD settlement
    <?php if ($last): ?> · NCM data updated <?= e(date('j M, g:i a', strtotime($last['v']['at'] ?? $last['at']))) ?><?php endif; ?></p>
</div>
<div style="display:flex;gap:8px;flex-wrap:wrap">
  <a class="btn" href="ncm.php">← NCM Courier</a>
  <?php if ($connected): ?><form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="refresh"><input type="hidden" name="tab" value="<?= e($tab) ?>">
    <button class="btn btn-primary" onclick="this.disabled=true;this.textContent='Updating from NCM…';this.form.submit()">🔄 Refresh from NCM</button></form><?php endif; ?>
</div></div>

<?php if ($m = flash()): ?><div class="flash"><?= e($m) ?></div><?php endif; ?>
<?php if (!$connected): ?><div class="flash" style="background:var(--amber-bg)">NCM API key is not set — add it in Settings to fill these reports.</div><?php endif; ?>
<?php if ($notFetched): ?><div class="flash" style="background:var(--amber-bg)"><?= $notFetched ?> NCM order(s) haven't been read from NCM yet, so they're missing from speed, charge and COD figures. Press <b>Refresh from NCM</b> (a batch at a time) — the cron also catches up automatically.</div><?php endif; ?>

<div class="ni-tabs">
  <a href="?tab=speed" class="<?= $tab==='speed'?'on':'' ?>">⏱ Speed &amp; Stuck<?php if ($stuck): ?><span class="ct"><?= count($stuck) ?> stuck</span><?php endif; ?></a>
  <a href="?tab=charges" class="<?= $tab==='charges'?'on':'' ?>">🧮 Charge Check<?php if ($cHigh): ?><span class="ct"><?= $cHigh ?> high</span><?php endif; ?></a>
  <a href="?tab=cod" class="<?= $tab==='cod'?'on':'' ?>">💰 COD Settlement<?php if ($unpaid): ?><span class="ct"><?= money($dueNow) ?> due</span><?php endif; ?></a>
</div>

<?php if ($tab === 'speed'): ?>
<div class="ni-grid">
  <div class="metric blue"><div><div class="mv"><?= $fmtD($speeds ? array_sum($speeds)/count($speeds) : null) ?></div><div class="ml">Avg pickup → delivery</div><div class="ms">median <?= $fmtD($med($speeds)) ?> · <?= count($speeds) ?> deliveries</div></div></div>
  <div class="metric green"><div><div class="mv"><?= $fast === null ? '—' : $fast.'%' ?></div><div class="ml">Delivered within 3 days</div></div></div>
  <div class="metric purple"><div><div class="mv"><?= $fmtD($pickups ? array_sum($pickups)/count($pickups) : null) ?></div><div class="ml">Avg booking → pickup</div></div></div>
  <div class="metric red"><div><div class="mv"><?= count($stuck) ?></div><div class="ml">Stuck <?= $stuckDays ?>+ days in one status</div></div></div>
</div>

<div class="panel ni-sec">
  <div class="panel-head"><h2>🚨 Stuck parcels</h2>
    <form method="get" style="margin:0;display:flex;gap:6px;align-items:center;font-size:12px"><input type="hidden" name="tab" value="speed"><input type="hidden" name="p" value="<?= e($period) ?>">
      no status change for <input type="number" name="sd" min="1" max="30" value="<?= $stuckDays ?>" style="width:56px;border:1px solid var(--border);border-radius:8px;padding:4px 6px"> days <button class="btn btn-sm">Apply</button></form></div>
  <?php if (!$stuck): ?><div class="ni-note">✅ Nothing stuck — every active parcel has moved within the last <?= $stuckDays ?> day(s).</div>
  <?php else: ?><div class="ni-wrap"><table class="tbl"><thead><tr><th>Customer</th><th>NCM</th><th>Destination</th><th>Current NCM status</th><th class="right">Stuck for</th><th>Since</th><th></th></tr></thead><tbody>
    <?php foreach ($stuck as $r): ?><tr<?= $r['stuck_d'] >= $stuckDays * 2 ? ' class="row-danger"' : '' ?>>
      <td><b><?= e($r['customer']) ?></b><div class="muted" style="font-size:11px"><?= e($r['code']) ?> · <?= e($r['phone']) ?></div></td>
      <td><?= $link($r['nid']) ?></td><td><?= e($r['branch'] ?: '—') ?></td>
      <td><span class="pill <?= ncm_status_class((string)$r['last_status']) ?>"><?= e($r['last_status']) ?></span></td>
      <td class="right"><b class="ni-neg"><?= $fmtD($r['stuck_d']) ?></b></td><td><?= e(date('j M, g:i a', strtotime($r['last_change_at']))) ?></td>
      <td class="right"><a class="btn btn-sm btn-primary" href="ncm_comments.php?c=<?= e($r['nid']) ?>">💬 Ask NCM</a> <a class="btn btn-sm" target="_blank" rel="noopener" href="https://wa.me/<?= e(wa_phone($r['phone'])) ?>">WhatsApp</a></td>
    </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>

<div class="panel ni-sec">
  <div class="panel-head"><h2>📍 Speed &amp; returns by destination</h2>
    <span style="font-size:12px"><?php foreach (['30'=>'30 days','90'=>'90 days','all'=>'All time'] as $k => $l): ?><a class="btn btn-sm<?= $period===$k?' btn-primary':'' ?>" href="?tab=speed&p=<?= $k ?>&sd=<?= $stuckDays ?>"><?= $l ?></a> <?php endforeach; ?></span></div>
  <?php if (!$byBranch): ?><div class="ni-note">No NCM orders in this period.</div>
  <?php else: ?><div class="ni-wrap"><table class="tbl"><thead><tr><th>Destination</th><th class="right">Orders</th><th class="right">Delivered</th><th class="right">Returned</th><th class="right">Return rate</th><th class="right">Avg delivery</th><th class="right">Slowest</th><th class="right">Still moving</th></tr></thead><tbody>
    <?php foreach ($byBranch as $b => $s): $fin = $s['del'] + $s['ret']; $rr = $fin ? round($s['ret'] / $fin * 100) : null; ?><tr>
      <td><b><?= e($b) ?></b></td><td class="right"><?= $s['n'] ?></td><td class="right"><?= $s['del'] ?></td><td class="right"><?= $s['ret'] ?></td>
      <td class="right"><?= $rr === null ? '—' : '<span class="'.($rr >= 25 ? 'ni-neg' : '').'">'.$rr.'%</span>' ?></td>
      <td class="right"><?= $fmtD($s['sp'] ? array_sum($s['sp'])/count($s['sp']) : null) ?></td><td class="right"><?= $fmtD($s['sp'] ? max($s['sp']) : null) ?></td><td class="right"><?= $s['active'] ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
  <div class="ni-note">Delivery time = from NCM picking the parcel up to the customer receiving it, using NCM's own status times. Period is by delivery / return date.</div>
</div>

<?php elseif ($tab === 'charges'): ?>
<div class="ni-grid">
  <div class="metric blue"><div><div class="mv"><?= count($chk) ?></div><div class="ml">Delivered orders checked</div><div class="ms"><?= $cNoRate ?> without a known route/rate yet</div></div></div>
  <div class="metric green"><div><div class="mv"><?= $cMatch ?></div><div class="ml">Charged the standard rate</div></div></div>
  <div class="metric red"><div><div class="mv"><?= $cHigh ?></div><div class="ml">Charged more than standard</div></div></div>
  <div class="metric orange"><div><div class="mv" style="font-size:20px"><?= money($extra) ?></div><div class="ml">Extra paid vs standard</div></div></div>
</div>
<div class="panel ni-sec">
  <div class="panel-head"><h2>🧮 <?= $showAllCharges ? 'All checked orders' : 'Charged more than the standard rate' ?></h2>
    <a class="btn btn-sm" href="?tab=charges<?= $showAllCharges ? '' : '&all=1' ?>"><?= $showAllCharges ? 'Only show higher' : 'Show all' ?></a></div>
  <?php if (!$chkShown): ?><div class="ni-note"><?= $chk ? '✅ Every checked order was charged NCM\'s standard rate (within Rs. 1).' : 'Nothing to check yet — press Refresh from NCM so the ERP can read each order\'s route.' ?></div>
  <?php else: ?><div class="ni-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Customer</th><th>NCM</th><th>Route</th><th>Type</th><th class="right">Standard</th><th class="right">Charged</th><th class="right">Difference</th><th></th></tr></thead><tbody>
    <?php foreach ($chkShown as $c): ?><tr>
      <td><?= e(date('j M Y', strtotime($c['order_date']))) ?></td><td><b><?= e($c['customer']) ?></b><div class="muted" style="font-size:11px"><?= e($c['code']) ?></div></td><td><?= $link($c['nid']) ?></td>
      <td><?= e(($c['fbranch'] ?: setting('ncm_from_branch','TINKUNE')).' → '.$c['branch']) ?></td><td><?= e($c['dtype'] ?: 'Door2Door') ?></td>
      <td class="right"><?= money($c['exp']) ?></td><td class="right"><?= money($c['bill']) ?></td>
      <td class="right"><span class="<?= $c['diff'] > 1 ? 'ni-neg' : ($c['diff'] < -1 ? 'ni-pos' : 'muted') ?>"><?= ($c['diff'] > 0 ? '+' : '').money($c['diff']) ?></span></td>
      <td class="right"><?php if ($c['diff'] > 1): ?><a class="btn btn-sm" href="ncm_comments.php?c=<?= e($c['nid']) ?>" title="Ask NCM why">💬 Ask NCM</a><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
  <div class="ni-note">Standard = NCM's own shipping-rate for that route and delivery type (rechecked weekly). A higher charge is often extra weight (over 1 kg) or a re-route — ask NCM before treating it as an overcharge. Returned orders are left out because NCM charges a return fee instead.</div>
</div>

<?php else: /* cod */ ?>
<div class="ni-grid">
  <div class="metric blue"><div><div class="mv" style="font-size:20px"><?= money($codAll) ?></div><div class="ml">COD collected by NCM</div><div class="ms">all delivered orders</div></div></div>
  <div class="metric orange"><div><div class="mv" style="font-size:20px"><?= money($dueNow) ?></div><div class="ml">NCM still owes you</div><div class="ms"><?= count($unpaid) ?> order(s) · COD minus charges</div></div></div>
  <div class="metric green"><div><div class="mv" style="font-size:20px"><?= money($paidTotal) ?></div><div class="ml">Paid out to you</div></div></div>
  <div class="metric red"><div><div class="mv"><?= $oldest ?> d</div><div class="ml">Oldest unpaid order</div></div></div>
</div>

<?php if ($canEdit): ?>
<div class="panel ni-sec"><div class="panel-head"><h2>📥 Import NCM payment statement</h2></div>
  <form method="post" enctype="multipart/form-data" class="ni-bar"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="import"><input type="hidden" name="tab" value="cod">
    <input type="file" name="file" accept=".csv,.txt,.tsv" required>
    <input name="ref" placeholder="Payment ref (optional)"><input type="date" name="date" value="<?= date('Y-m-d') ?>">
    <button class="btn btn-primary">Import &amp; match</button></form>
  <div class="ni-note">Export the payment / settlement report from the NCM portal as CSV (Excel → Save As → CSV). The ERP finds the order-ID column and the amount column itself, marks those orders as paid, and tells you any difference from what it expected.</div>
</div>
<?php endif; ?>

<div class="panel ni-sec">
  <div class="panel-head"><h2>⏳ Not paid out yet</h2><?php if ($ncmSaysPaid): ?><span class="pill p-yellow"><?= $ncmSaysPaid ?> marked paid by NCM — record them below</span><?php endif; ?></div>
  <?php if (!$unpaid): ?><div class="ni-note">✅ Every delivered order has been paid out.</div>
  <?php else: ?>
  <form method="post" id="niSettle"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="settle"><input type="hidden" name="tab" value="cod"><input type="hidden" name="ids" id="niIds">
  <div class="ni-wrap"><table class="tbl"><thead><tr><th><?php if ($canEdit): ?><input type="checkbox" onclick="document.querySelectorAll('.niC').forEach(c=>{c.checked=this.checked});niSum()"><?php endif; ?></th><th>Delivered</th><th>Customer</th><th>NCM</th><th class="right">COD</th><th class="right">Charge</th><th class="right">Net to you</th><th class="right">Waiting</th><th>NCM payment status</th></tr></thead><tbody>
    <?php foreach ($unpaid as $u): ?><tr<?= $u['wait'] > 14 ? ' class="row-warn"' : '' ?>>
      <td><?php if ($canEdit): ?><input type="checkbox" class="niC" value="<?= e($u['nid']) ?>" data-net="<?= round($u['net'], 2) ?>" onchange="niSum()"><?php endif; ?></td>
      <td><?= e(date('j M Y', strtotime($u['delivered_at'] ?: $u['returned_at'] ?: $u['order_date']))) ?><?= $u['state'] === 'returned' ? ' <span class="pill p-red">return</span>' : '' ?></td>
      <td><b><?= e($u['customer']) ?></b><div class="muted" style="font-size:11px"><?= e($u['code']) ?></div></td><td><?= $link($u['nid']) ?></td>
      <td class="right"><?= money($u['cod_v']) ?></td><td class="right"><?= money($u['bill']) ?></td>
      <td class="right"><b class="<?= $u['net'] < 0 ? 'ni-neg' : '' ?>"><?= money($u['net']) ?></b></td><td class="right"><?= $u['wait'] ?> d</td>
      <td><?= $u['pay_status'] ? '<span class="pill '.($u['ncm_paid'] ? 'p-green' : 'p-grey').'">'.e($u['pay_status']).'</span>' : '<span class="muted">—</span>' ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
  <?php if ($canEdit): ?><div class="ni-bar" style="border-top:1px solid var(--border)"><b><span id="niN">0</span> selected · <span id="niT">Rs. 0</span></b>
    <input name="amount" id="niAmt" type="number" step="any" placeholder="Amount received" style="width:150px"><input name="ref" placeholder="Payment ref"><input type="date" name="date" value="<?= date('Y-m-d') ?>"><input name="note" placeholder="Note (optional)">
    <button class="btn btn-primary" onclick="return niSubmit()">✓ Mark as paid by NCM</button></div><?php endif; ?>
  </form>
  <?php endif; ?>
  <div class="ni-note">Net to you = COD collected − NCM's delivery charge. Returned parcels appear with a negative amount, because NCM deducts their return fee from your payout. Orders show here once NCM marks them delivered or returned.</div>
</div>

<div class="panel ni-sec"><div class="panel-head"><h2>🧾 Payments history</h2></div>
  <?php if (!$settlements): ?><div class="ni-note">No NCM payments recorded yet.</div>
  <?php else: ?><div class="ni-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Ref</th><th class="right">Orders</th><th class="right">Amount</th><th>Source</th><th>Note</th><th></th></tr></thead><tbody>
    <?php foreach ($settlements as $s): ?><tr><td><?= e(date('j M Y', strtotime($s['settle_date']))) ?></td><td><?= e($s['ref'] ?: '—') ?></td><td class="right"><?= (int)$s['n'] ?></td><td class="right"><b><?= money($s['amount']) ?></b></td>
      <td><?= $s['source'] === 'statement' ? 'Statement import' : 'Manual' ?></td><td class="muted"><?= e($s['note'] ?: '') ?></td>
      <td class="right"><?php if ($canEdit): ?><form method="post" style="margin:0" onsubmit="return confirm('Remove this payment record? Its orders go back to unpaid.')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="undo"><input type="hidden" name="tab" value="cod"><input type="hidden" name="sid" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-danger">Undo</button></form><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>
<script>
function niSum(){var n=0,t=0;document.querySelectorAll('.niC:checked').forEach(function(c){n++;t+=parseFloat(c.dataset.net)||0;});
  document.getElementById('niN').textContent=n;document.getElementById('niT').textContent='Rs. '+Math.round(t).toLocaleString('en-IN');document.getElementById('niAmt').placeholder='Amount received ('+Math.round(t)+')';}
function niSubmit(){var ids=[].map.call(document.querySelectorAll('.niC:checked'),function(c){return c.value;});
  if(!ids.length){alert('Tick the orders NCM has paid you for.');return false;}
  document.getElementById('niIds').value=ids.join(',');return confirm('Record NCM payment for '+ids.length+' order(s)?');}
</script>
<?php endif; ?>

<?php require __DIR__.'/includes/footer.php'; ?>
