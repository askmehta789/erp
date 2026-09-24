<?php
require_once __DIR__.'/functions.php'; require_once __DIR__.'/nepali_date.php';
require_login(); require_page_access();
ensure_lunch_system();
$PAGE_TITLE='Lunch Management';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act=$_POST['_action'] ?? '';
  $backM=preg_match('/^\d{4}-\d{2}$/',$_POST['m'] ?? '')?$_POST['m']:bs_ym_of(date('Y-m-d'));
  $backEmp=(int)($_POST['back_emp'] ?? 0);

  if ($act==='save_entry') {
    $id=(int)($_POST['id'] ?? 0);
    $eid=(int)($_POST['employee_id'] ?? 0);
    $d=preg_match('/^\d{4}-\d{2}-\d{2}$/',$_POST['entry_date'] ?? '')?$_POST['entry_date']:date('Y-m-d');
    $att=in_array($_POST['attendance'] ?? '',['present','leave'],true)?$_POST['attendance']:'present';
    $ordered=(($_POST['lunch_ordered'] ?? '1')==='1') ? 1 : 0;
    $amt=max(0,(float)($_POST['order_amount'] ?? 0));
    $note=trim($_POST['note'] ?? '');
    /* leave days carry no lunch allowance at all — enforced server-side too, not just in the UI */
    if ($att==='leave') { $ordered=0; $amt=0; }
    if (!$eid) {
      flash('Pick an employee.');
    } else {
      $existing=row("SELECT id FROM lunch_orders WHERE employee_id=? AND order_date=?",[$eid,$d]);
      if ($existing && (int)$existing['id']!==$id) {
        flash('Lunch entry already exists for this employee on this date. Open it from the table below (✏️) to edit it instead of adding a new one.');
      } else {
        q("INSERT INTO lunch_orders(employee_id,order_date,order_amount,attendance,lunch_ordered,note) VALUES(?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE order_amount=VALUES(order_amount),attendance=VALUES(attendance),lunch_ordered=VALUES(lunch_ordered),note=VALUES(note)",
          [$eid,$d,$amt,$att,$ordered,$note]);
        lunch_sync_salary_entry($eid, bs_ym_of($d));
        log_activity(($id?'Updated':'Added')." lunch entry for emp #$eid on $d",'Lunch Management');
        flash('Lunch entry saved for '.dual_date($d).'.');
      }
    }
    header('Location: lunch_management.php?m='.urlencode($backM).($backEmp?'&emp='.$backEmp:'')); exit;
  }

  if ($act==='save_bulk') {
    $bd=preg_match('/^\d{4}-\d{2}-\d{2}$/',$_POST['entry_date'] ?? '')?$_POST['entry_date']:date('Y-m-d');
    $bym=bs_ym_of($bd);
    $eids=$_POST['employee_id'] ?? [];
    $atts=$_POST['attendance'] ?? [];
    $ords=$_POST['lunch_ordered'] ?? [];
    $amts=$_POST['order_amount'] ?? [];
    $notes=$_POST['note'] ?? [];
    $saved=0;
    foreach ($eids as $i=>$eidRaw) {
      $eid=(int)$eidRaw;
      if (!$eid) continue;
      $att=in_array($atts[$i] ?? '',['present','leave'],true)?$atts[$i]:'present';
      $ordered=(($ords[$i] ?? '1')==='1') ? 1 : 0;
      $amt=max(0,(float)($amts[$i] ?? 0));
      $note=trim($notes[$i] ?? '');
      if ($att==='leave') { $ordered=0; $amt=0; }
      q("INSERT INTO lunch_orders(employee_id,order_date,order_amount,attendance,lunch_ordered,note) VALUES(?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE order_amount=VALUES(order_amount),attendance=VALUES(attendance),lunch_ordered=VALUES(lunch_ordered),note=VALUES(note)",
        [$eid,$bd,$amt,$att,$ordered,$note]);
      lunch_sync_salary_entry($eid, $bym);
      $saved++;
    }
    log_activity("Bulk-logged lunch/attendance for $saved staff on $bd",'Lunch Management');
    flash("Saved attendance & lunch for $saved staff — ".dual_date($bd).".");
    header('Location: lunch_management.php?m='.urlencode($backM).'&d='.urlencode($bd)); exit;
  }

  if ($act==='del_entry' && $isAdmin) {
    $id=(int)($_POST['id'] ?? 0);
    $row=row("SELECT employee_id,order_date FROM lunch_orders WHERE id=?",[$id]);
    q("DELETE FROM lunch_orders WHERE id=?",[$id]);
    if ($row) lunch_sync_salary_entry((int)$row['employee_id'], bs_ym_of($row['order_date']));
    flash('Lunch entry deleted.');
    header('Location: lunch_management.php?m='.urlencode($backM).($backEmp?'&emp='.$backEmp:'')); exit;
  }

  if ($act==='recalc_month' && $isAdmin) {
    $n=0;
    foreach (rows("SELECT id FROM employees") as $e2) { lunch_sync_salary_entry((int)$e2['id'],$backM); $n++; }
    log_activity("Recalculated lunch adjustments for $n staff ($backM)",'Lunch Management');
    flash("Recalculated lunch adjustments for $n staff — ".$backM.".");
    header('Location: lunch_management.php?m='.urlencode($backM)); exit;
  }

  if ($act==='save_settings' && $isAdmin) {
    set_setting('lunch_leave_days', (string)max(0,(int)($_POST['lunch_leave_days'] ?? 4)));
    set_setting('lunch_month_days', (string)max(1,(int)($_POST['lunch_month_days'] ?? 30)));
    set_setting('lunch_pay_unused', (($_POST['lunch_pay_unused'] ?? 'yes')==='no') ? 'no' : 'yes');
    set_setting('lunch_over_allowance', (($_POST['lunch_over_allowance'] ?? 'review')==='ignore') ? 'ignore' : 'review');
    log_activity('Updated Lunch Management settings','Settings');
    flash('Lunch settings saved. Use "Recalculate This Month" to apply them to already-logged entries.');
    header('Location: lunch_management.php?m='.urlencode($backM)); exit;
  }
}

/* $m is a BS (Nepali) 'Y-m' key — real Nepali-month boundaries, not the AD calendar month */
$m = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : bs_ym_of(date('Y-m-d'));
$d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['d'] ?? '') ? $_GET['d'] : date('Y-m-d');
$empFilter = (int)($_GET['emp'] ?? 0);
$mParts = array_map('intval', explode('-', $m));
$bsY = $mParts[0]; $bsM = $mParts[1];
$mRange = bs_month_range($bsY,$bsM);
if (!$mRange) { $m = bs_ym_of(date('Y-m-d')); [$bsY,$bsM] = array_map('intval', explode('-', $m)); $mRange = bs_month_range($bsY,$bsM); }
[$mStart,$mEnd] = $mRange;

$allEmps = rows("SELECT id,name,salary,lunch_rate FROM employees ORDER BY name");
$empName = []; foreach ($allEmps as $ae) $empName[(int)$ae['id']] = $ae['name'];

$lunchAgg = lunch_monthly_agg($mStart, $mEnd, $empFilter);

/* daily entries for the selected month (all staff, or one when filtered) */
$monthEntries = rows("SELECT lo.*, e.name, e.lunch_rate FROM lunch_orders lo JOIN employees e ON e.id=lo.employee_id
                 WHERE lo.order_date BETWEEN ? AND ?".($empFilter?" AND lo.employee_id=".$empFilter:"")."
                 ORDER BY lo.order_date DESC, e.name",[$mStart,$mEnd]);

/* Daily Lunch Entries table: only the latest 10 show at once, rest on next page */
$dpPer = 10;
$dpCount = count($monthEntries);
$dpPages = max(1, (int)ceil($dpCount / $dpPer));
$dpPage = max(1, (int)($_GET['dp'] ?? 1));
if ($dpPage > $dpPages) $dpPage = $dpPages;
$dpOffset = ($dpPage-1) * $dpPer;
$entries = array_slice($monthEntries, $dpOffset, $dpPer);
function lm_dp_url($page,$m,$empFilter,$d) {
  $q = array_filter(['m'=>$m,'emp'=>$empFilter?:null,'d'=>$d,'dp'=>$page], fn($v)=>$v!==null && $v!=='');
  return '?'.http_build_query($q);
}

/* full rows already logged for the selected logging date — powers both the
   bulk "quick daily entry" prefill and the pending-count stat */
$dayEntries = [];
foreach (rows("SELECT * FROM lunch_orders WHERE order_date=?",[$d]) as $t) $dayEntries[(int)$t['employee_id']] = $t;
$loggedToday = array_fill_keys(array_keys($dayEntries), true);
$pendingEntries = count($allEmps) - count($loggedToday);

/* bonus/deduction from the main payroll ledger, so "Final Salary" here matches
   Staff Salary's Payable exactly — Payable = Base + Bonus + Lunch − Deduction */
$sAgg = [];
foreach (rows("SELECT employee_id,
        SUM(CASE WHEN type='bonus' THEN amount ELSE 0 END) bon,
        SUM(CASE WHEN type='deduction' THEN amount ELSE 0 END) ded
        FROM salary_entries WHERE ym=? GROUP BY employee_id",[$m]) as $r) $sAgg[(int)$r['employee_id']] = $r;

$scopeEmps = $empFilter ? array_values(array_filter($allEmps, fn($e)=>(int)$e['id']===$empFilter)) : $allEmps;

$summaryRows=[]; $tAllowance=0.0;$tActual=0.0;$tAdjustment=0.0;$tEligible=0;$tReview=0;
foreach ($scopeEmps as $e) {
  $eid=(int)$e['id'];
  $A = $lunchAgg[$eid] ?? ['days_present'=>0,'days_leave'=>0,'ordered'=>0.0,'allowance'=>0.0,'adjustment'=>0.0,'extra'=>0.0,'review'=>0];
  $bon=(float)($sAgg[$eid]['bon'] ?? 0); $ded=(float)($sAgg[$eid]['ded'] ?? 0);
  $final = (float)$e['salary'] + $bon + (float)$A['adjustment'] - $ded;
  $tAllowance+=$A['allowance']; $tActual+=$A['ordered']; $tAdjustment+=$A['adjustment']; $tEligible+=$A['days_present']; $tReview+=$A['review'];
  $summaryRows[]=['e'=>$e,'id'=>$eid,'A'=>$A,'bon'=>$bon,'ded'=>$ded,'final'=>$final];
}

/* duplicate-detection map for the Add/Edit modal, scoped to the currently loaded month
   (the server always re-checks fresh against the DB regardless, on save) */
$dupMap=[];
foreach ($monthEntries as $en) $dupMap[$en['employee_id'].'_'.$en['order_date']] = [
  'id'=>(int)$en['id'],'employee_id'=>(int)$en['employee_id'],'order_date'=>$en['order_date'],
  'attendance'=>$en['attendance'],'lunch_ordered'=>(int)$en['lunch_ordered'],'order_amount'=>(float)$en['order_amount'],'note'=>$en['note'],
];

/* ---- monthly calendar grid: every staff member x every day of the BS month,
   full present/leave/lunch detail at a glance ---- */
$calDim = bs_month_table()[$bsY][$bsM-1] ?? 30;
$calDays = [];
for ($dd=1; $dd<=$calDim; $dd++) $calDays[] = ['bs'=>$dd, 'ad'=>bs_to_ad($bsY,$bsM,$dd)];
$calMap = [];
foreach ($monthEntries as $en) $calMap[(int)$en['employee_id']][$en['order_date']] = $en;
$calToday = date('Y-m-d');

require __DIR__.'/includes/header.php';
$lunchPill = fn($s) => ['ok'=>'p-green','leave'=>'p-grey','review'=>'p-yellow'][$s] ?? 'p-grey';
$lunchLabel = fn($s) => ['ok'=>'Completed','leave'=>'Leave','review'=>'Requires Review'][$s] ?? ucfirst($s);
?>
<div class="page-head">
  <div><h1>🍱 Lunch Management</h1><p>Track daily lunch orders and automatically calculate salary adjustments.
    <a href="salary.php" class="muted" style="text-decoration:underline">← Staff Salary</a></p></div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="get" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
      <select name="emp"><option value="0">All Staff</option>
        <?php foreach($allEmps as $ae): ?><option value="<?= (int)$ae['id'] ?>"<?= $empFilter===(int)$ae['id']?' selected':'' ?>><?= e($ae['name']) ?></option><?php endforeach; ?></select>
      <a class="btn btn-sm" href="?m=<?= e(bs_ym_add($m,-1)) ?>&emp=<?= $empFilter ?>&d=<?= e($d) ?>" title="Previous Nepali month">◀</a>
      <span style="font-weight:800;padding:0 4px;white-space:nowrap"><?= e(bs_ym_label($m)) ?></span>
      <a class="btn btn-sm" href="?m=<?= e(bs_ym_add($m,1)) ?>&emp=<?= $empFilter ?>&d=<?= e($d) ?>" title="Next Nepali month">▶</a>
      <input type="hidden" name="m" value="<?= e($m) ?>">
      <input type="date" name="d" value="<?= e($d) ?>" title="Logging date">
      <button class="btn btn-sm">Go</button>
    </form>
    <button class="btn btn-primary" onclick="openEntry()">＋ Add Lunch Entry</button>
  </div>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="mgrid">
  <div class="metric blue"><div><div class="mv" style="font-size:19px"><?= number_format(count($allEmps)) ?></div><div class="ml">Total Staff</div></div><div class="mi">👥</div></div>
  <div class="metric teal"><div><div class="mv" style="font-size:19px"><?= number_format($tEligible) ?></div><div class="ml">Eligible Lunch Days</div><div class="ms">from actual attendance</div></div><div class="mi">📅</div></div>
  <div class="metric indigo"><div><div class="mv" style="font-size:19px"><?= money($tAllowance) ?></div><div class="ml">Lunch Allowance</div><div class="ms"><?= e(bs_ym_label($m)) ?></div></div><div class="mi">🍱</div></div>
  <div class="metric red"><div><div class="mv" style="font-size:19px"><?= money($tActual) ?></div><div class="ml">Actual Lunch Expense</div></div><div class="mi">🧾</div></div>
  <div class="metric orange"><div><div class="mv" style="font-size:19px"><?= money($tAdjustment) ?></div><div class="ml">Salary Adjustment</div><div class="ms">added to Payable</div></div><div class="mi">💰</div></div>
  <div class="metric <?= $pendingEntries>0?'amber':'green' ?>"><div><div class="mv" style="font-size:19px"><?= number_format(max(0,$pendingEntries)) ?></div><div class="ml">Pending Entries</div><div class="ms">not logged for <?= e(date('d M',strtotime($d))) ?></div></div><div class="mi">⏳</div></div>
</div>
<?php if($tReview>0): ?><div class="flash" style="background:var(--amber-bg,#fef3c7);color:var(--amber)">⚠ <b><?= $tReview ?></b> entr<?= $tReview>1?'ies':'y' ?> this month exceed the daily allowance and need review.</div><?php endif; ?>

<style>
.cal-tbl{border-collapse:separate;border-spacing:2px}
.cal-tbl th,.cal-tbl td{padding:0;text-align:center;font-size:10.5px}
.cal-tbl th{font-weight:800;color:var(--muted);padding:2px 0;min-width:24px}
.cal-tbl td.cal-name{text-align:left;padding:2px 10px 2px 2px;font-weight:700;white-space:nowrap;position:sticky;left:0;background:var(--surface);z-index:1}
.cal-today{background:rgba(99,102,241,.12);border-radius:4px}
.cal-cell a{display:block;text-decoration:none;border-radius:4px;padding:4px 0;font-weight:800;min-width:22px}
.cal-ok a{background:var(--green-bg);color:var(--green)}
.cal-noorder a{background:var(--surface-2);color:var(--muted)}
.cal-review a{background:var(--amber-bg,#fef3c7);color:var(--amber)}
.cal-leave a{background:var(--surface-2);color:var(--muted-2)}
.cal-blank a{color:var(--muted-2);opacity:.35}
</style>
<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>🗓 Monthly Calendar — <?= e(bs_ym_label($m)) ?></h2>
    <span class="muted" style="font-size:12px">every staff member, every day — click a cell to fix that date</span></div>
  <div class="table-wrap" style="overflow-x:auto">
    <table class="cal-tbl"><thead><tr>
      <th class="cal-name">Employee</th>
      <?php foreach($calDays as $cd): $isToday=$cd['ad']===$calToday; ?>
        <th class="<?= $isToday?'cal-today':'' ?>" title="<?= e(date('d M Y',strtotime($cd['ad']))) ?>"><?= np_digits($cd['bs']) ?></th>
      <?php endforeach; ?>
    </tr></thead><tbody>
    <?php foreach($scopeEmps as $e): $eid=(int)$e['id']; ?>
      <tr>
        <td class="cal-name"><?= e($e['name']) ?></td>
        <?php foreach($calDays as $cd):
          $row = $calMap[$eid][$cd['ad']] ?? null; $isToday=$cd['ad']===$calToday;
          if (!$row) { $cls='cal-blank'; $txt='·'; $title='Not logged — click to add'; }
          else {
            $c = lunch_day_calc($e['lunch_rate'],$row['attendance'],(int)$row['lunch_ordered'],$row['order_amount']);
            if ($row['attendance']!=='present') { $cls='cal-leave'; $txt='L'; $title='Leave'; }
            elseif (!$row['lunch_ordered']) { $cls='cal-noorder'; $txt='P'; $title='Present · lunch not ordered'; }
            elseif ($c['status']==='review') { $cls='cal-review'; $txt='P'; $title='Present · lunch '.money($row['order_amount']).' — over allowance, needs review'; }
            else { $cls='cal-ok'; $txt='P'; $title='Present · lunch '.money($row['order_amount']); }
          }
        ?>
          <td class="cal-cell <?= $cls ?><?= $isToday?' cal-today':'' ?>">
            <a href="?m=<?= e($m) ?>&d=<?= e($cd['ad']) ?>&emp=<?= $empFilter ?>#quickDaily" title="<?= e(date('d M Y',strtotime($cd['ad']))).' — '.e($title) ?>"><?= $txt ?></a>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; if(!$scopeEmps): ?><tr><td colspan="<?= count($calDays)+1 ?>"><div class="empty">No staff yet.</div></td></tr><?php endif; ?>
    </tbody></table>
  </div>
  <p class="muted" style="font-size:11px;margin-top:8px"><span class="cal-cell cal-ok" style="display:inline-block;width:18px"><a style="pointer-events:none">P</a></span> present + lunch ordered &nbsp; <span class="cal-cell cal-noorder" style="display:inline-block;width:18px"><a style="pointer-events:none">P</a></span> present, no lunch &nbsp; <span class="cal-cell cal-review" style="display:inline-block;width:18px"><a style="pointer-events:none">P</a></span> over allowance, needs review &nbsp; <span class="cal-cell cal-leave" style="display:inline-block;width:18px"><a style="pointer-events:none">L</a></span> leave &nbsp; <b>·</b> nothing logged yet</p>
</div>

<div class="panel" style="margin-top:16px" id="quickDaily">
  <div class="panel-head"><h2>📋 Quick Daily Entry — <?= e(dual_date($d)) ?></h2>
    <span class="muted" style="font-size:12px">mark everyone at once instead of one by one — edit only the rows that differ, then Save All</span></div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="save_bulk">
    <input type="hidden" name="entry_date" value="<?= e($d) ?>"><input type="hidden" name="m" value="<?= e($m) ?>">
    <div class="table-wrap"><table class="tbl num-tbl" id="bulkTbl"><thead><tr>
      <th>Employee</th><th>Attendance</th><th>Lunch Ordered</th><th class="right">Amount</th><th>Note</th>
    </tr></thead><tbody>
    <?php foreach($allEmps as $ae): $eid=(int)$ae['id']; $ex=$dayEntries[$eid] ?? null;
      $rAtt = $ex ? $ex['attendance'] : 'present';
      $rOrd = $ex ? (int)$ex['lunch_ordered'] : 1;
      $rAmt = $ex ? (float)$ex['order_amount'] : (float)$ae['lunch_rate'];
      $rNote = $ex ? (string)$ex['note'] : '';
    ?>
      <tr data-rate="<?= (float)$ae['lunch_rate'] ?>">
        <td><b><?= e($ae['name']) ?></b><input type="hidden" name="employee_id[]" value="<?= $eid ?>"></td>
        <td><select name="attendance[]" class="bulk-att" onchange="bulkRowToggle(this)">
          <option value="present"<?= $rAtt==='present'?' selected':'' ?>>Present</option>
          <option value="leave"<?= $rAtt==='leave'?' selected':'' ?>>Leave</option>
        </select></td>
        <td><select name="lunch_ordered[]" class="bulk-ord" onchange="bulkRowToggle(this)"<?= $rAtt==='leave'?' disabled':'' ?>>
          <option value="1"<?= $rOrd?' selected':'' ?>>Yes</option>
          <option value="0"<?= !$rOrd?' selected':'' ?>>No</option>
        </select></td>
        <td class="right"><input type="number" name="order_amount[]" class="bulk-amt" value="<?= $rAmt ?>" step="any" min="0" style="width:100px;text-align:right"<?= ($rAtt==='leave'||!$rOrd)?' disabled':'' ?>></td>
        <td><input type="text" name="note[]" value="<?= e($rNote) ?>" placeholder="optional" style="width:100%"></td>
      </tr>
    <?php endforeach; if(!$allEmps): ?><tr><td colspan="5"><div class="empty">No staff yet.</div></td></tr><?php endif; ?>
    </tbody></table></div>
    <div style="padding:14px 0 4px"><button class="btn btn-primary">💾 Save All (<?= count($allEmps) ?> staff)</button></div>
  </form>
</div>

<?php if($empFilter && $summaryRows): $sr=$summaryRows[0]; $ae=$sr['e']; ?>
<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>👤 <?= e($ae['name']) ?> — Lunch Overview</h2>
    <a class="btn btn-sm" href="lunch_management.php?m=<?= e($m) ?>">← all staff</a></div>
  <div class="panel-body" style="padding-top:6px">
    <div class="info-row"><span class="it">Basic Salary</span><span class="iv"><?= money($ae['salary']) ?></span></div>
    <div class="info-row"><span class="it">Daily Lunch Rate</span><span class="iv"><?= money($ae['lunch_rate']) ?></span></div>
    <div class="info-row"><span class="it">Present Days</span><span class="iv"><?= (int)$sr['A']['days_present'] ?></span></div>
    <div class="info-row"><span class="it">Leave Days</span><span class="iv"><?= (int)$sr['A']['days_leave'] ?></span></div>
    <div class="info-row"><span class="it">Eligible Lunch Days</span><span class="iv"><?= (int)$sr['A']['days_present'] ?></span></div>
    <div class="info-row"><span class="it">Total Lunch Allowance</span><span class="iv"><?= money($sr['A']['allowance']) ?></span></div>
    <div class="info-row"><span class="it">Total Actual Lunch</span><span class="iv"><?= money($sr['A']['ordered']) ?></span></div>
    <div class="info-row"><span class="it">Total Salary Adjustment</span><span class="iv" style="color:var(--orange)"><?= money($sr['A']['adjustment']) ?></span></div>
    <div class="info-row"><span class="it">Final Salary (this month)</span><span class="iv" style="font-weight:800"><?= money($sr['final']) ?></span></div>
  </div>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>🧾 Daily Lunch Entries — <?= e(bs_ym_label($m)) ?></h2>
    <div style="display:flex;gap:8px"><button type="button" class="btn btn-sm" onclick="lmExportDailyCsv('daily-lunch-<?= e($m) ?>.csv')">⬇ Export CSV</button><button type="button" class="btn btn-sm" onclick="window.print()">🖨 Print</button></div>
  </div>
  <div class="table-wrap"><table class="tbl led-tbl" id="dailyTbl"><thead><tr>
    <th>Date (AD · BS)</th><th>Employee</th><th>Attendance</th><th>Lunch Ordered</th><th class="right">Allowance</th><th class="right">Actual Lunch</th><th class="right">Salary Adjustment</th><th>Status</th><th>Remarks</th><th></th>
  </tr></thead><tbody>
  <?php foreach($entries as $en): $c=lunch_day_calc($en['lunch_rate'],$en['attendance'],(int)$en['lunch_ordered'],$en['order_amount']); ?>
    <tr>
      <td><?= e(dual_date($en['order_date'])) ?></td>
      <td><b><?= e($en['name'] ?: ('#'.$en['employee_id'])) ?></b></td>
      <td><span class="pill <?= $en['attendance']==='present'?'p-green':'p-grey' ?>"><?= $en['attendance']==='present'?'Present':'Leave' ?></span></td>
      <td><?= ((int)$en['lunch_ordered'] && $en['attendance']==='present') ? 'Yes' : 'No' ?></td>
      <td class="num right"><?= $c['allowance']>0?money($c['allowance']):'—' ?></td>
      <td class="num right"><?= $c['actual']>0?money($c['actual']):'—' ?></td>
      <td class="num right" style="font-weight:700;color:var(--orange)"><?= money($c['adjustment']) ?><?= $c['extra']>0?' <span class="muted" style="font-size:11px">(+'.money($c['extra']).' extra)</span>':'' ?></td>
      <td><span class="pill <?= $lunchPill($c['status']) ?>"><?= e($lunchLabel($c['status'])) ?></span></td>
      <td class="muted" style="white-space:normal"><?= e($en['note'] ?: '—') ?></td>
      <td style="white-space:nowrap">
        <button type="button" class="iact" title="Edit" data-rec="<?= e(json_encode($en)) ?>" onclick="editEntry(JSON.parse(this.getAttribute('data-rec')))">✏️</button>
        <?php if($isAdmin): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete this lunch entry?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="del_entry"><input type="hidden" name="id" value="<?= (int)$en['id'] ?>"><input type="hidden" name="m" value="<?= e($m) ?>"><button class="iact del" title="Delete">🗑</button></form><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if(!$entries) echo '<tr><td colspan="10"><div class="empty">No lunch entries logged this month yet.</div></td></tr>'; ?>
  </tbody></table></div>
  <?php if($dpCount>0): $dpShowFrom=$dpOffset+1; $dpShowTo=min($dpCount,$dpOffset+$dpPer); ?>
  <div class="pager">
    <span class="pg-info muted">Showing <?= $dpShowFrom ?>–<?= $dpShowTo ?> of <?= $dpCount ?></span>
    <span class="pg-btns">
      <a class="btn btn-sm" href="<?= e(lm_dp_url(1,$m,$empFilter,$d)) ?>" <?= $dpPage<=1?'style="pointer-events:none;opacity:.4"':'' ?>>« First</a>
      <a class="btn btn-sm" href="<?= e(lm_dp_url(max(1,$dpPage-1),$m,$empFilter,$d)) ?>" <?= $dpPage<=1?'style="pointer-events:none;opacity:.4"':'' ?>>‹ Prev</a>
      <span class="muted" style="padding:0 8px">Page <?= $dpPage ?> / <?= $dpPages ?></span>
      <a class="btn btn-sm" href="<?= e(lm_dp_url(min($dpPages,$dpPage+1),$m,$empFilter,$d)) ?>" <?= $dpPage>=$dpPages?'style="pointer-events:none;opacity:.4"':'' ?>>Next ›</a>
      <a class="btn btn-sm" href="<?= e(lm_dp_url($dpPages,$m,$empFilter,$d)) ?>" <?= $dpPage>=$dpPages?'style="pointer-events:none;opacity:.4"':'' ?>>Last »</a>
    </span>
  </div>
  <?php endif; ?>
</div>

<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>📊 Monthly Lunch Summary — <?= e(bs_ym_label($m)) ?></h2>
    <button type="button" class="btn btn-sm" onclick="lmExportCsv('summaryTbl','lunch-summary-<?= e($m) ?>.csv')">⬇ Export CSV</button>
  </div>
  <div class="table-wrap"><table class="tbl num-tbl" id="summaryTbl"><thead><tr>
    <th>Employee</th><th class="right">Basic Salary</th><th class="right">Present Days</th><th class="right">Leave Days</th><th class="right">Eligible Lunch Days</th><th class="right">Lunch Allowance</th><th class="right">Actual Lunch Expense</th><th class="right">Salary Adjustment</th><th class="right">Final Salary</th>
  </tr></thead><tbody>
  <?php foreach($summaryRows as $sr): $ae=$sr['e']; ?>
    <tr>
      <td><b><?= e($ae['name']) ?></b></td>
      <td class="num right"><?= money($ae['salary']) ?></td>
      <td class="num right"><?= (int)$sr['A']['days_present'] ?></td>
      <td class="num right"><?= (int)$sr['A']['days_leave'] ?></td>
      <td class="num right"><?= (int)$sr['A']['days_present'] ?></td>
      <td class="num right"><?= money($sr['A']['allowance']) ?></td>
      <td class="num right"><?= money($sr['A']['ordered']) ?></td>
      <td class="num right" style="color:var(--orange);font-weight:700"><?= money($sr['A']['adjustment']) ?></td>
      <td class="num right" style="font-weight:800"><?= money($sr['final']) ?></td>
    </tr>
  <?php endforeach; if(!$summaryRows) echo '<tr><td colspan="9"><div class="empty">No staff yet.</div></td></tr>'; ?>
  </tbody></table></div>
  <p class="muted" style="font-size:11.5px;margin-top:8px">Final Salary = Basic Salary + Bonus + Lunch Salary Adjustment − Deduction (matches Payable on the <a href="salary.php?m=<?= e($m) ?>">Staff Salary</a> page).</p>
</div>

<?php if($isAdmin): ?>
<div class="grid cols-2" style="margin-top:16px;align-items:start">
  <div class="panel">
    <div class="panel-head"><h2>⚙️ Lunch Settings</h2></div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="save_settings"><input type="hidden" name="m" value="<?= e($m) ?>">
      <div class="panel-body">
        <div class="form-grid" style="grid-template-columns:1fr 1fr">
          <div><label>Standard Leave Days / month</label><input type="number" name="lunch_leave_days" value="<?= (int)lunch_leave_days_setting() ?>"></div>
          <div><label>Standard Month Days</label><input type="number" name="lunch_month_days" value="<?= (int)lunch_month_days_setting() ?>"></div>
          <div><label>Pay unused daily lunch allowance when no lunch is ordered</label>
            <select name="lunch_pay_unused"><option value="yes"<?= lunch_pay_unused()?' selected':'' ?>>Yes</option><option value="no"<?= !lunch_pay_unused()?' selected':'' ?>>No</option></select></div>
          <div><label>When lunch exceeds the allowance</label>
            <select name="lunch_over_allowance"><option value="review"<?= lunch_over_allowance_setting()==='review'?' selected':'' ?>>Require Review</option><option value="ignore"<?= lunch_over_allowance_setting()==='ignore'?' selected':'' ?>>Ignore (cap silently)</option></select></div>
        </div>
        <p class="muted" style="font-size:11.5px;margin-top:10px">Eligible days are shown for reference only (<?= (int)lunch_eligible_days_setting() ?> = <?= (int)lunch_month_days_setting() ?> − <?= (int)lunch_leave_days_setting() ?>) — actual eligibility always comes from logged attendance, never a fixed number. The daily lunch rate is per-employee, set in <a href="salary.php">Staff Directory</a> (defaults to Rs.150).</p>
      </div>
      <div class="modal-foot" style="justify-content:flex-start;border:none;padding:0 20px 18px"><button class="btn btn-primary">💾 Save Settings</button></div>
    </form>
  </div>
  <div class="panel">
    <div class="panel-head"><h2>🔄 Recalculate</h2></div>
    <div class="panel-body">
      <p class="muted" style="font-size:12.5px">If you changed a setting above, existing entries this month won't reflect it until you recalculate. This re-syncs every staff member's Lunch figure on the Staff Salary page from their logged entries — it never creates duplicates.</p>
      <form method="post" onsubmit="return confirm('Recalculate lunch adjustments for everyone in '+<?= json_encode(bs_ym_label($m,false)) ?>+'?')">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="recalc_month"><input type="hidden" name="m" value="<?= e($m) ?>">
        <button class="btn btn-primary">🔄 Recalculate This Month</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- add/edit lunch entry modal -->
<div class="modal-bg" id="lmModal" style="z-index:99990"><form class="modal" method="post" style="width:520px;max-width:94vw">
  <div class="modal-head"><span id="lmTitle">＋ Daily Lunch Entry</span><span class="mx" onclick="closeEntry()">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="save_entry"><input type="hidden" name="id" id="lm_id" value="">
  <input type="hidden" name="m" value="<?= e($m) ?>"><input type="hidden" name="back_emp" value="<?= $empFilter ?>">
  <div class="modal-body">
    <div><label>Date *</label><input type="date" name="entry_date" id="lm_date" value="<?= e($d) ?>" required onchange="lmCheckDup();lmCalc()"></div>
    <div><label>Employee *</label><select name="employee_id" id="lm_emp" required onchange="lmCheckDup();lmCalc()"><option value="">—</option>
      <?php foreach($allEmps as $ae): ?><option value="<?= (int)$ae['id'] ?>" data-rate="<?= (float)$ae['lunch_rate'] ?>"><?= e($ae['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Attendance *</label><select name="attendance" id="lm_att" onchange="lmCalc()"><option value="present">Present</option><option value="leave">Leave</option></select></div>
    <div><label>Lunch Ordered</label><select name="lunch_ordered" id="lm_ordered" onchange="lmCalc()"><option value="1">Yes</option><option value="0">No</option></select></div>
    <div class="full" id="lm_dup_warn" style="display:none;background:var(--red-bg);color:var(--red);padding:9px 12px;border-radius:9px;font-size:12.5px"></div>
    <div><label>Actual Lunch Amount (Rs.)</label><input type="number" step="any" min="0" name="order_amount" id="lm_amount" value="0" oninput="lmCalc()"></div>
    <div><label>&nbsp;</label><div id="lm_bs" class="muted" style="padding-top:9px;font-weight:700"></div></div>
    <div class="full" id="lm_preview" style="background:var(--surface-2);border-radius:10px;padding:10px 14px;font-size:13px"></div>
    <div class="full"><label>Remarks</label><input type="text" name="note" id="lm_note" placeholder="optional"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="closeEntry()">Cancel</button><button class="btn btn-primary" id="lm_save">💾 Save</button></div>
</form></div>

<script>
var LM_PAY_UNUSED = <?= lunch_pay_unused() ? 'true' : 'false' ?>;
var LM_OVER_MODE = <?= json_encode(lunch_over_allowance_setting()) ?>;
var DUP_MAP = <?= json_encode($dupMap) ?>;
var BS_LABELS=<?php $lbl=[]; for($i=-60;$i<=30;$i++){$dd=date('Y-m-d',strtotime("$i days")); $lbl[$dd]=bs_pretty($dd);} echo json_encode($lbl); ?>;

function bulkRowToggle(el){
  var tr=el.closest('tr'), rate=parseFloat(tr.getAttribute('data-rate')||'0');
  var attSel=tr.querySelector('.bulk-att'), ordSel=tr.querySelector('.bulk-ord'), amtEl=tr.querySelector('.bulk-amt');
  var isLeave = attSel.value==='leave';
  ordSel.disabled = isLeave;
  var isOrdered = !isLeave && ordSel.value==='1';
  amtEl.disabled = !isOrdered;
  if (isLeave) { amtEl.value = 0; }
  else if (isOrdered && parseFloat(amtEl.value||'0')===0) { amtEl.value = rate; }
}

function lmRate(){
  var emp=document.getElementById('lm_emp'), opt=emp.options[emp.selectedIndex];
  return opt ? parseFloat(opt.getAttribute('data-rate')||'0') : 0;
}
function lmCalc(){
  var att=document.getElementById('lm_att').value;
  var orderedSel=document.getElementById('lm_ordered');
  var amtEl=document.getElementById('lm_amount');
  var rate=lmRate();
  var isLeave = att==='leave';
  orderedSel.disabled = isLeave; amtEl.disabled = isLeave || orderedSel.value==='0';
  document.getElementById('lm_bs').textContent = BS_LABELS[document.getElementById('lm_date').value] || '';
  var prev=document.getElementById('lm_preview');
  if (isLeave) {
    prev.innerHTML = '<b>Leave day</b> — no lunch allowance, no salary adjustment.<br>Lunch Allowance&nbsp;&nbsp;Rs.0<br>Actual Lunch&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Rs.0<br>Salary Adjustment&nbsp;&nbsp;Rs.0';
    return;
  }
  var ordered = orderedSel.value==='1';
  if (!ordered) {
    var adj = LM_PAY_UNUSED ? rate : 0;
    prev.innerHTML = 'Lunch Allowance&nbsp;&nbsp;Rs.'+rate.toFixed(2)+'<br>Actual Lunch&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Rs.0.00 (not ordered)<br><b>Salary Adjustment&nbsp;&nbsp;Rs.'+adj.toFixed(2)+'</b>';
    return;
  }
  var actual = parseFloat(amtEl.value || '0');
  if (actual > rate) {
    var extra = (actual-rate).toFixed(2);
    var warn = LM_OVER_MODE==='ignore' ? '' : '<br><span style="color:var(--red)">⚠ Actual lunch exceeds the daily allowance by Rs.'+extra+'. Status: Requires Review.</span>';
    prev.innerHTML = 'Lunch Allowance&nbsp;&nbsp;Rs.'+rate.toFixed(2)+'<br>Actual Lunch&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Rs.'+actual.toFixed(2)+'<br><b>Salary Adjustment&nbsp;&nbsp;Rs.0.00</b>'+warn;
    return;
  }
  var adjustment = Math.max(0, rate-actual);
  prev.innerHTML = 'Lunch Allowance&nbsp;&nbsp;Rs.'+rate.toFixed(2)+'<br>Actual Lunch&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Rs.'+actual.toFixed(2)+'<br><b>Salary Adjustment&nbsp;&nbsp;Rs.'+adjustment.toFixed(2)+'</b>';
}
function lmCheckDup(){
  var eid=document.getElementById('lm_emp').value, dt=document.getElementById('lm_date').value;
  var myId=document.getElementById('lm_id').value;
  var warn=document.getElementById('lm_dup_warn'), save=document.getElementById('lm_save');
  var hit = eid && dt ? DUP_MAP[eid+'_'+dt] : null;
  warn.textContent=''; warn.replaceChildren();
  if (hit && String(hit.id)!==String(myId)) {
    warn.style.display='';
    warn.appendChild(document.createTextNode('⚠ Lunch entry already exists for this employee on this date. '));
    var btn=document.createElement('button');
    btn.type='button'; btn.className='btn btn-sm'; btn.style.marginLeft='6px'; btn.textContent='✏️ Edit Existing Entry';
    btn.addEventListener('click', function(){ editEntry(hit); });
    warn.appendChild(btn);
    save.disabled = true;
  } else {
    warn.style.display='none'; save.disabled=false;
  }
}
function openEntry(eid){
  document.getElementById('lmTitle').textContent='＋ Daily Lunch Entry';
  document.getElementById('lm_id').value='';
  document.getElementById('lm_date').value=document.querySelector('input[name="d"]')?document.querySelector('input[name="d"]').value:'<?= e($d) ?>';
  if(eid) document.getElementById('lm_emp').value=eid;
  document.getElementById('lm_att').value='present';
  document.getElementById('lm_ordered').value='1';
  document.getElementById('lm_amount').value='0';
  document.getElementById('lm_note').value='';
  document.getElementById('lm_dup_warn').style.display='none';
  document.getElementById('lm_save').disabled=false;
  lmCalc(); lmCheckDup();
  document.getElementById('lmModal').classList.add('open'); document.body.classList.add('modal-open');
}
function editEntry(rec){
  document.getElementById('lmTitle').textContent='✏️ Edit Daily Lunch Entry';
  document.getElementById('lm_id').value=rec.id;
  document.getElementById('lm_date').value=rec.order_date;
  document.getElementById('lm_emp').value=rec.employee_id;
  document.getElementById('lm_att').value=rec.attendance;
  document.getElementById('lm_ordered').value=String(rec.lunch_ordered);
  document.getElementById('lm_amount').value=rec.order_amount;
  document.getElementById('lm_note').value=rec.note||'';
  document.getElementById('lm_dup_warn').style.display='none';
  document.getElementById('lm_save').disabled=false;
  lmCalc();
  document.getElementById('lmModal').classList.add('open'); document.body.classList.add('modal-open');
}
function closeEntry(){document.getElementById('lmModal').classList.remove('open');document.body.classList.remove('modal-open');}
(function(){var mm=document.getElementById('lmModal');mm.addEventListener('click',function(e){if(e.target===mm)closeEntry();});})();

function lmExportCsv(tableId,filename){
  var rows=[]; document.querySelectorAll('#'+tableId+' tr').forEach(function(tr){
    var cells=Array.prototype.map.call(tr.children,function(td){ var t=(td.textContent||'').trim().replace(/"/g,'""'); return '"'+t+'"'; });
    rows.push(cells.join(','));
  });
  var blob=new Blob([rows.join('\n')],{type:'text/csv'});
  var a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download=filename; a.click();
}
/* Daily Lunch Entries is paginated (10/page) on screen, but Export CSV should
   still cover the whole month — so this exports server-rendered data for
   every entry, not just whatever page happens to be visible. */
var DAILY_CSV=<?php
  $csvRows=[['Date (AD)','Date (BS)','Employee','Attendance','Lunch Ordered','Allowance','Actual Lunch','Salary Adjustment','Status','Remarks']];
  foreach ($monthEntries as $en) {
    $c=lunch_day_calc($en['lunch_rate'],$en['attendance'],(int)$en['lunch_ordered'],$en['order_amount']);
    $csvRows[]=[
      date('d M Y',strtotime($en['order_date'])),
      bs_pretty($en['order_date'],false),
      $en['name'] ?: ('#'.$en['employee_id']),
      $en['attendance']==='present'?'Present':'Leave',
      ((int)$en['lunch_ordered'] && $en['attendance']==='present')?'Yes':'No',
      $c['allowance'], $c['actual'], $c['adjustment'],
      $lunchLabel($c['status']),
      $en['note'] ?: '',
    ];
  }
  echo json_encode($csvRows);
?>;
function lmExportDailyCsv(filename){
  var rows=DAILY_CSV.map(function(r){ return r.map(function(v){ var t=String(v).replace(/"/g,'""'); return '"'+t+'"'; }).join(','); });
  var blob=new Blob([rows.join('\n')],{type:'text/csv'});
  var a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download=filename; a.click();
}
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
