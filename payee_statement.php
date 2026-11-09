<?php
/* Party statement for one Ads / Vendor payee — what was billed (Due), what was paid, and the
   running balance left to pay, for any date range (and optionally one product). Made to be sent
   to the vendor: the browser's own "Print / Save as PDF" turns it into a PDF (same pattern as
   salary_report.php / invoice.php — no PDF library needed on the server). */
require_once __DIR__.'/functions.php';
require_login();
if (!can_see('payees.php')) { http_response_code(403); die('Access denied for your role.'); }
ensure_payees();

$pid = (int)($_GET['id'] ?? 0);
$payee = $pid ? row("SELECT * FROM payees WHERE id=?", [$pid]) : null;
if (!$payee) die('Vendor not found.');

$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
$preset = (string)($_GET['range'] ?? '');
$from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : '';
$to   = $isDate($_GET['to'] ?? '') ? $_GET['to'] : '';
if ($preset === 'month')     { $from = date('Y-m-01'); $to = date('Y-m-t'); }
if ($preset === 'lastmonth') { $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('last day of last month')); }
if ($preset === '3m')        { $from = date('Y-m-01', strtotime('-2 months')); $to = date('Y-m-d'); }
if ($preset === 'all')       { $from = ''; $to = ''; }
if ($from && $to && $from > $to) [$from, $to] = [$to, $from];
/* no product filter here on purpose: payments are not tied to a product, so a product-only
   statement would show its bills but none of the payments and overstate what is owed */
$product = '';

/* product filter matches the ledger's own product tag (or the label when untagged) */
$pw = ''; $pp = [];
if ($product === 'Other / General') { $pw = " AND COALESCE(product,'') = ''"; }
elseif ($product !== '') { $pw = " AND (COALESCE(product,'') = ? OR (COALESCE(product,'') = '' AND label LIKE ?))"; $pp = [$product, '%'.$product.'%']; }

/* balance carried in from before the period */
$opening = 0.0;
if ($from) {
  $opening = (float)val("SELECT COALESCE(SUM(CASE WHEN type='due' THEN amount ELSE -amount END),0) FROM payee_ledger
                         WHERE payee_id=? AND entry_date < ?$pw", array_merge([$pid, $from], $pp));
}
$where = "payee_id=?".($from ? " AND entry_date >= ?" : '').($to ? " AND entry_date <= ?" : '').$pw;
$params = array_merge([$pid], $from ? [$from] : [], $to ? [$to] : [], $pp);
$entries = rows("SELECT * FROM payee_ledger WHERE $where ORDER BY entry_date ASC, id ASC", $params);

/* running balance, oldest first; shown newest first like a bank statement */
$bal = $opening; $tDue = 0.0; $tPaid = 0.0; $lines = [];
foreach ($entries as $l) {
  $amt = (float)$l['amount'];
  if ($l['type'] === 'due') { $bal += $amt; $tDue += $amt; } else { $bal -= $amt; $tPaid += $amt; }
  $what = trim((string)$l['label']);
  $prod = trim((string)($l['product'] ?? ''));
  if ($prod !== '' && stripos($what, $prod) === false) $what = $prod.($what !== '' ? ' — '.$what : '');
  $lines[] = ['date'=>$l['entry_date'], 'ref'=>'#'.$l['id'], 'what'=>$what !== '' ? $what : ($l['type'] === 'due' ? 'Bill' : 'Payment'),
              'type'=>$l['type'], 'due'=>$l['type'] === 'due' ? $amt : null, 'paid'=>$l['type'] === 'paid' ? $amt : null, 'bal'=>$bal];
}
$lines = array_reverse($lines);
$closing = $bal;

$products = array_column(rows("SELECT DISTINCT product FROM payee_ledger WHERE payee_id=? AND product IS NOT NULL AND product<>'' ORDER BY product", [$pid]), 'product');
$store = setting('store_name', 'Luprah Trading PVT.LTD'); $sPhone = setting('store_phone', ''); $sAddr = setting('store_address', ''); $sPan = setting('store_pan', '');
$period = ($from || $to) ? (($from ? date('d M Y', strtotime($from)) : 'Start').' – '.($to ? date('d M Y', strtotime($to)) : date('d M Y'))) : 'All time';
$rs = fn($v) => 'Rs '.number_format((float)$v, 0);
$fileTitle = 'Statement - '.$payee['name'].' - '.date('Y-m-d');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($fileTitle) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:24px 14px;background:#eef1f6;font-family:Inter,Segoe UI,Roboto,Arial,sans-serif;color:#1f2937;font-size:13px}
.sheet{max-width:900px;margin:0 auto;background:#fff;border-radius:14px;padding:32px 34px;border:1px solid #e5e7eb}
.top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;border-bottom:2px solid #1f2937;padding-bottom:14px;margin-bottom:18px}
.logo{display:flex;gap:10px;align-items:center}.logo img{height:40px}
.muted{color:#667085;font-size:12px}
h1{font-size:24px;margin:0 0 4px;font-weight:700}
.meta{display:flex;gap:28px;flex-wrap:wrap;margin:0 0 16px;font-size:13.5px}
.meta b{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#667085;margin-bottom:2px}
table{width:100%;border-collapse:collapse}
th{background:#2b80c5;color:#fff;text-align:left;padding:10px 11px;font-size:13px;font-weight:600;white-space:nowrap}
td{padding:9px 11px;border:1px solid #e5e7eb;vertical-align:top}
.r{text-align:right;white-space:nowrap}.c{text-align:center}
tr.open td{background:#f8fafc;font-style:italic;color:#475569}
tr.row-paid td.amt{color:#047857}
.sum{margin-top:20px;font-size:15px;line-height:1.9}
.sum .big{font-size:18px;font-weight:800}
.due{color:#b45309}.adv{color:#047857}
.foot{margin-top:30px;display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap}
.sig{border-top:1px solid #999;width:200px;text-align:center;padding-top:6px;font-size:12px;color:#667085}
.bar{max-width:900px;margin:0 auto 14px;display:flex;gap:8px;justify-content:space-between;flex-wrap:wrap;align-items:center}
.bar form{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.bar input,.bar select{border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;font-size:13px;background:#fff}
.btn{background:#3b82f6;color:#fff;border:0;border-radius:8px;padding:9px 14px;font-weight:700;cursor:pointer;text-decoration:none;font-size:13px;display:inline-block}
.btn.gray{background:#64748b}.btn.light{background:#fff;color:#1f2937;border:1px solid #cbd5e1}
.pills{display:flex;gap:6px;flex-wrap:wrap}
@media(max-width:640px){.sheet{padding:18px 14px}h1{font-size:19px}th,td{padding:7px 6px;font-size:11.5px}.hide-sm{display:none}}
@media print{body{background:#fff;padding:0}.bar{display:none}.sheet{border:0;border-radius:0;max-width:none;padding:0}
  th{-webkit-print-color-adjust:exact;print-color-adjust:exact}tr.open td{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style></head><body>

<div class="bar">
  <div class="pills">
    <a class="btn light" href="?id=<?= $pid ?>&range=month<?= $product!==''?'&product='.urlencode($product):'' ?>">This month</a>
    <a class="btn light" href="?id=<?= $pid ?>&range=lastmonth<?= $product!==''?'&product='.urlencode($product):'' ?>">Last month</a>
    <a class="btn light" href="?id=<?= $pid ?>&range=3m<?= $product!==''?'&product='.urlencode($product):'' ?>">Last 3 months</a>
    <a class="btn light" href="?id=<?= $pid ?>&range=all<?= $product!==''?'&product='.urlencode($product):'' ?>">All time</a>
  </div>
  <form method="get">
    <input type="hidden" name="id" value="<?= $pid ?>">
    <input type="date" name="from" value="<?= e($from) ?>" aria-label="From date"> <span class="muted">to</span>
    <input type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
    <button class="btn gray">Apply</button>
  </form>
  <div style="display:flex;gap:8px">
    <a class="btn light" href="payees.php?id=<?= $pid ?>">← Back</a>
    <button class="btn" onclick="window.print()">⬇ Download PDF</button>
  </div>
</div>

<div class="sheet">
  <div class="top">
    <div class="logo"><img src="assets/luprah-logo.png" alt=""><div><b><?= e($store) ?></b>
      <div class="muted"><?= e($sAddr) ?><?= $sAddr && $sPhone ? ' · ' : '' ?><?= e($sPhone) ?><?= $sPan ? ' · PAN: '.e($sPan) : '' ?></div></div></div>
    <div class="muted" style="text-align:right">Generated: <?= date('d/m/Y H:i') ?></div>
  </div>

  <h1>Party Statement — <?= e($payee['name']) ?></h1>
  <div class="meta">
    <div><b>Period</b><?= e($period) ?></div>
    <?php if ($product !== ''): ?><div><b>Product</b><?= e($product) ?></div><?php endif; ?>
    <?php if (!empty($payee['note'])): ?><div><b>Note</b><?= e($payee['note']) ?></div><?php endif; ?>
    <div><b>Entries</b><?= count($lines) ?></div>
  </div>

  <table>
    <thead><tr><th>Date</th><th class="hide-sm">Ref</th><th>Particulars</th><th class="r">Bill (Due)</th><th class="r">Paid</th><th class="r">Balance</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $L): ?>
      <tr class="row-<?= $L['type'] ?>">
        <td><?= e($L['date']) ?></td>
        <td class="hide-sm"><?= e($L['ref']) ?></td>
        <td><?= e($L['what']) ?></td>
        <td class="r"><?= $L['due'] !== null ? $rs($L['due']) : '-' ?></td>
        <td class="r amt"><?= $L['paid'] !== null ? $rs($L['paid']) : '-' ?></td>
        <td class="r"><?= $rs($L['bal']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($from): ?>
      <tr class="open"><td><?= e(date('Y-m-d', strtotime($from.' -1 day'))) ?></td><td class="hide-sm"></td><td>Opening balance (carried forward)</td><td class="r"></td><td class="r"></td><td class="r"><?= $rs($opening) ?></td></tr>
    <?php endif; ?>
    <?php if (!$lines && !$from): ?><tr><td colspan="6" class="c muted" style="padding:24px">No entries for this vendor yet.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <div class="sum">
    <?php if ($from): ?><div>Opening Balance: <?= $rs($opening) ?></div><?php endif; ?>
    <div>Total Bills (Due): <?= $rs($tDue) ?></div>
    <div>Total Paid: <?= $rs($tPaid) ?></div>
    <div class="big <?= $closing > 0.5 ? 'due' : 'adv' ?>">Balance: <?= $rs(abs($closing)) ?>
      <?= $closing > 0.5 ? '(Left to pay)' : ($closing < -0.5 ? '(Advance paid)' : '(Settled)') ?></div>
  </div>

  <div class="foot">
    <div class="muted">Balance = bills − payments<?= $from ? ', including the opening balance' : '' ?>. Please contact us if anything doesn't match your records.</div>
    <div class="sig">Authorised signature</div>
  </div>
</div>
</body></html>
