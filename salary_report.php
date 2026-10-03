<?php
/* Printable staff salary statement — single employee or all staff, for one BS month.
   Browser's own "Print / Save as PDF" is used (same pattern as invoice.php / labels.php),
   no server-side PDF library needed. */
require_once __DIR__.'/functions.php'; require_once __DIR__.'/nepali_date.php';
require_login(); require_page_access();
ensure_lunch_system();
ensure_employee_profile_fields();

$m = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : bs_ym_of(date('Y-m-d'));
[$bsY,$bsM] = array_map('intval', explode('-', $m));
$adRange = bs_month_range($bsY,$bsM);
if (!$adRange) { $m = bs_ym_of(date('Y-m-d')); [$bsY,$bsM] = array_map('intval', explode('-', $m)); $adRange = bs_month_range($bsY,$bsM); }
[$adMonthStart,$adMonthEnd] = $adRange;

$empFilter = (int)($_GET['emp'] ?? 0);
$emps = rows("SELECT * FROM employees".($empFilter?" WHERE id=".$empFilter:"")." ORDER BY name");
if ($empFilter && !$emps) die('Employee not found.');

$agg=[];
foreach (rows("SELECT employee_id,
        SUM(CASE WHEN type='payment' THEN amount ELSE 0 END) pay,
        SUM(CASE WHEN type='advance' THEN amount ELSE 0 END) adv,
        SUM(CASE WHEN type='bonus' THEN amount ELSE 0 END) bon,
        SUM(CASE WHEN type='lunch' THEN amount ELSE 0 END) lun,
        SUM(CASE WHEN type='deduction' THEN amount ELSE 0 END) ded
        FROM salary_entries WHERE ym=? GROUP BY employee_id",[$m]) as $r)
  $agg[(int)$r['employee_id']]=$r;
$G=function($id,$k)use($agg){ return isset($agg[$id])?(float)$agg[$id][$k]:0; };

$rowsOut=[];
$tBase=$tBon=$tLun=$tDed=$tPayable=$tAdv=$tPay=$tDue=0.0;
foreach ($emps as $e) {
  $id=(int)$e['id']; $base=(float)($e['salary']??0);
  $bon=$G($id,'bon'); $ded=$G($id,'ded'); $adv=$G($id,'adv'); $pay=$G($id,'pay'); $lun=$G($id,'lun');
  $payable=$base+$bon+$lun-$ded; $paid=$pay+$adv; $due=$payable-$paid;
  $st = $paid<=0.01 ? 'Pending' : ($due>0.01 ? 'Partial' : 'Paid');
  $tBase+=$base; $tBon+=$bon; $tLun+=$lun; $tDed+=$ded; $tPayable+=$payable; $tAdv+=$adv; $tPay+=$pay; $tDue+=$due;
  $rowsOut[]=compact('e','id','base','bon','lun','ded','adv','pay','payable','paid','due','st');
}

$TYPES = ['payment'=>'Payment','advance'=>'Advance','bonus'=>'Bonus','lunch'=>'Lunch Allowance','deduction'=>'Deduction'];
$entries=[];
if ($empFilter) $entries = rows("SELECT * FROM salary_entries WHERE ym=? AND employee_id=? ORDER BY entry_date,id",[$m,$empFilter]);

$store=setting('store_name','Luprah Trading PVT.LTD'); $pan=setting('store_pan','');
$phone=setting('store_phone',''); $email=setting('store_email',''); $addr=setting('store_address','');
$single = $empFilter ? ($emps[0] ?? null) : null;
$title = $single ? 'Salary Statement' : 'Staff Salary Report';
$periodLabel = bs_ym_label($m).' ('.date('d M',strtotime($adMonthStart)).' – '.date('d M Y',strtotime($adMonthEnd)).')';
$stPill=fn($s)=>['Paid'=>'#16a34a','Partial'=>'#d97706','Pending'=>'#dc2626'][$s] ?? '#667085';
?>
<!doctype html><html><head><meta charset="utf-8"><title><?= e($title) ?> — <?= e($single?$single['name']:'All Staff') ?> — <?= e(bs_ym_label($m,false)) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font:13.5px/1.5 'Segoe UI',Arial,sans-serif;color:#111;background:#f4f6f9;padding:28px}
.sheet{max-width:<?= $single?'760px':'1040px' ?>;margin:0 auto;background:#fff;border:1px solid #e5e9f0;border-radius:12px;padding:34px}
.top{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #111;padding-bottom:18px;margin-bottom:20px}
.logo{display:flex;gap:10px;align-items:center}.logo img{height:44px}.logo b{font-size:19px}
.muted{color:#667085;font-size:12.5px}.tag{font-size:24px;font-weight:800;letter-spacing:.03em}
.grid{display:flex;justify-content:space-between;gap:20px;margin-bottom:22px;flex-wrap:wrap}
.box b{display:block;font-size:12px;text-transform:uppercase;color:#667085;margin-bottom:5px;letter-spacing:.05em}
table{width:100%;border-collapse:collapse;margin:8px 0 16px}
th{background:#f1f5f9;text-align:left;padding:9px 10px;font-size:11.5px;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}
td{padding:9px 10px;border-bottom:1px solid #eef1f6}
.r{text-align:right}.totals{margin-left:auto;width:300px}
.totals td{padding:7px 10px;border:0}.totals tr:last-child td{border-top:2px solid #111;font-weight:800;font-size:15px}
tfoot td{font-weight:800;background:#f8fafc;border-top:2px solid #111}
.pill{display:inline-block;border-radius:5px;padding:2px 8px;font-size:11px;font-weight:700;color:#fff}
.foot{margin-top:26px;display:flex;justify-content:space-between;align-items:flex-end}
.sig{border-top:1px solid #999;width:200px;text-align:center;padding-top:6px;font-size:12px;color:#667085}
.noprint{max-width:<?= $single?'760px':'1040px' ?>;margin:0 auto 14px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
.btn{background:#3b82f6;color:#fff;border:0;border-radius:8px;padding:10px 16px;font-weight:700;cursor:pointer;text-decoration:none;font-size:13px}
.btn.gray{background:#64748b}
select.btn{appearance:auto}
@media print{body{background:#fff;padding:0}.noprint{display:none}.sheet{border:0;border-radius:0;max-width:none}}
</style></head><body>
<div class="noprint">
  <form method="get" style="display:flex;gap:8px;align-items:center">
    <input type="hidden" name="m" value="<?= e($m) ?>">
    <select name="emp" class="btn gray" onchange="this.form.submit()" style="padding:9px 10px">
      <option value="0"<?= $empFilter?'':' selected' ?>>👥 All Staff</option>
      <?php foreach (rows("SELECT id,name FROM employees ORDER BY name") as $ae): ?>
        <option value="<?= (int)$ae['id'] ?>"<?= $empFilter===(int)$ae['id']?' selected':'' ?>><?= e($ae['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <a class="btn gray" href="salary.php?m=<?= e($m) ?><?= $empFilter?('&emp='.$empFilter):'' ?>">← Back to Salary</a>
  <button class="btn" onclick="window.print()">🖨 Print / Save PDF</button>
</div>
<div class="sheet">
  <div class="top">
    <div class="logo"><img src="assets/luprah-logo.png" alt=""><div><b><?= e($store) ?></b>
      <div class="muted"><?= e($addr) ?><?= $addr?' · ':'' ?><?= e($phone) ?><?= $email?' · '.e($email):'' ?><?= $pan?' · PAN: '.e($pan):'' ?></div></div></div>
    <div style="text-align:right"><div class="tag"><?= strtoupper($title) ?></div>
      <div class="muted"><?= e($periodLabel) ?></div></div>
  </div>

  <?php if ($single): ?>
  <div class="grid">
    <div class="box"><b>Employee</b><?= e($single['name']) ?><br>
      <?= e($single['role'] ?: '—') ?><?= $single['department']?' · '.e($single['department']):'' ?><br>
      <?= e($single['phone'] ?: '') ?><?= $single['email']?' · '.e($single['email']):'' ?></div>
    <div class="box" style="text-align:right"><b>Payroll Details</b>
      Joined: <?= $single['joined_date']?e(dual_date($single['joined_date'])):'—' ?><br>
      Bank: <?= e($single['bank_name'] ?: '—') ?><?= $single['bank_account']?' · '.e($single['bank_account']):'' ?><br>
      PAN: <?= e($single['pan_number'] ?: '—') ?></div>
  </div>
  <?php endif; ?>

  <table><thead><tr>
    <?php if(!$single): ?><th>Employee</th><?php endif; ?>
    <th class="r">Base Salary</th><th class="r">Bonus</th><th class="r">Lunch Allowance</th><th class="r">Deduction</th>
    <th class="r">Payable</th><th class="r">Advance</th><th class="r">Payment</th><th class="r">Remaining</th><th>Status</th>
  </tr></thead><tbody>
  <?php foreach ($rowsOut as $r): ?>
    <tr>
      <?php if(!$single): ?><td><b><?= e($r['e']['name']) ?></b><?= $r['e']['role']?'<div class="muted">'.e($r['e']['role']).'</div>':'' ?></td><?php endif; ?>
      <td class="r"><?= money($r['base']) ?></td>
      <td class="r"><?= $r['bon']>0?'+'.money($r['bon']):'—' ?></td>
      <td class="r"><?= $r['lun']>0?'+'.money($r['lun']):'—' ?></td>
      <td class="r"><?= $r['ded']>0?'−'.money($r['ded']):'—' ?></td>
      <td class="r" style="font-weight:700"><?= money($r['payable']) ?></td>
      <td class="r"><?= $r['adv']>0?money($r['adv']):'—' ?></td>
      <td class="r"><?= $r['pay']>0?money($r['pay']):'—' ?></td>
      <td class="r" style="font-weight:800"><?= money($r['due']) ?></td>
      <td><span class="pill" style="background:<?= $stPill($r['st']) ?>"><?= $r['st'] ?></span></td>
    </tr>
  <?php endforeach; if(!$rowsOut) echo '<tr><td colspan="10"><div class="muted" style="padding:14px 0">No staff found.</div></td></tr>'; ?>
  </tbody>
  <?php if (!$single && $rowsOut): ?>
  <tfoot><tr>
    <td>Total (<?= count($rowsOut) ?> staff)</td>
    <td class="r"><?= money($tBase) ?></td><td class="r"><?= money($tBon) ?></td><td class="r"><?= money($tLun) ?></td><td class="r"><?= money($tDed) ?></td>
    <td class="r"><?= money($tPayable) ?></td><td class="r"><?= money($tAdv) ?></td><td class="r"><?= money($tPay) ?></td><td class="r"><?= money($tDue) ?></td><td></td>
  </tr></tfoot>
  <?php endif; ?>
  </table>

  <?php if ($single): ?>
  <table class="totals">
    <tr><td>Base Salary</td><td class="r"><?= money($rowsOut[0]['base'] ?? 0) ?></td></tr>
    <tr><td>Bonus</td><td class="r"><?= money($rowsOut[0]['bon'] ?? 0) ?></td></tr>
    <tr><td>Lunch Allowance</td><td class="r"><?= money($rowsOut[0]['lun'] ?? 0) ?></td></tr>
    <tr><td>Deduction</td><td class="r">− <?= money($rowsOut[0]['ded'] ?? 0) ?></td></tr>
    <tr><td>Payable</td><td class="r"><?= money($rowsOut[0]['payable'] ?? 0) ?></td></tr>
    <tr><td>Already Paid (payment + advance)</td><td class="r"><?= money($rowsOut[0]['paid'] ?? 0) ?></td></tr>
    <tr><td>Remaining Due</td><td class="r"><?= money($rowsOut[0]['due'] ?? 0) ?></td></tr>
  </table>

  <?php if ($entries): ?>
  <div class="box" style="margin-bottom:8px"><b>Entries this month</b></div>
  <table><thead><tr><th>Date (AD · BS)</th><th>Type</th><th class="r">Amount</th><th>Note</th></tr></thead><tbody>
    <?php foreach ($entries as $en): ?>
    <tr>
      <td><?= e(dual_date($en['entry_date'])) ?></td>
      <td><?= e($TYPES[$en['type']] ?? $en['type']) ?></td>
      <td class="r"><?= money($en['amount']) ?></td>
      <td class="muted"><?= e($en['note'] ?: '—') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <?php endif; ?>

  <div class="foot"><div class="muted">Generated <?= e(date('d M Y, h:i A')) ?></div><div class="sig">Authorized Signature</div></div>
</div>
</body></html>
