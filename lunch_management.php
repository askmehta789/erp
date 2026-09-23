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
  $backM=preg_match('/^\d{4}-\d{2}$/',$_POST['m'] ?? '')?$_POST['m']:date('Y-m');
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
        lunch_sync_salary_entry($eid, substr($d,0,7));
        log_activity(($id?'Updated':'Added')." lunch entry for emp #$eid on $d",'Lunch Management');
        flash('Lunch entry saved for '.dual_date($d).'.');
      }
    }
    header('Location: lunch_management.php?m='.urlencode($backM).($backEmp?'&emp='.$backEmp:'')); exit;
  }

  if ($act==='del_entry' && $isAdmin) {
    $id=(int)($_POST['id'] ?? 0);
    $row=row("SELECT employee_id,order_date FROM lunch_orders WHERE id=?",[$id]);
    q("DELETE FROM lunch_orders WHERE id=?",[$id]);
    if ($row) lunch_sync_salary_entry((int)$row['employee_id'], substr($row['order_date'],0,7));
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

$m = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['d'] ?? '') ? $_GET['d'] : date('Y-m-d');
$empFilter = (int)($_GET['emp'] ?? 0);
$mStart = $m.'-01'; $mEnd = date('Y-m-t', strtotime($mStart));

$allEmps = rows("SELECT id,name,salary,lunch_rate FROM employees ORDER BY name");
$empName = []; foreach ($allEmps as $ae) $empName[(int)$ae['id']] = $ae['name'];

$lunchAgg = lunch_monthly_agg($mStart, $mEnd, $empFilter);

/* daily entries for the selected month (all staff, or one when filtered) */
$entries = rows("SELECT lo.*, e.name, e.lunch_rate FROM lunch_orders lo JOIN employees e ON e.id=lo.employee_id
                 WHERE lo.order_date BETWEEN ? AND ?".($empFilter?" AND lo.employee_id=".$empFilter:"")."
                 ORDER BY lo.order_date DESC, e.name",[$mStart,$mEnd]);

/* who already has an entry for the selected logging date (duplicate map + pending count) */
$loggedToday = [];
foreach (rows("SELECT employee_id FROM lunch_orders WHERE order_date=?",[$d]) as $t) $loggedToday[(int)$t['employee_id']] = true;
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
foreach ($entries as $en) $dupMap[$en['employee_id'].'_'.$en['order_date']] = [
  'id'=>(int)$en['id'],'employee_id'=>(int)$en['employee_id'],'order_date'=>$en['order_date'],
  'attendance'=>$en['attendance'],'lunch_ordered'=>(int)$en['lunch_ordered'],'order_amount'=>(float)$en['order_amount'],'note'=>$en['note'],
];

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
      <input type="month" name="m" value="<?= e($m) ?>">
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
  <div class="metric indigo"><div><div class="mv" style="font-size:19px"><?= money($tAllowance) ?></div><div class="ml">Lunch Allowance</div><div class="ms"><?= e(date('M Y',strtotime($m.'-01'))) ?></div></div><div class="mi">🍱</div></div>
  <div class="metric red"><div><div class="mv" style="font-size:19px"><?= money($tActual) ?></div><div class="ml">Actual Lunch Expense</div></div><div class="mi">🧾</div></div>
  <div class="metric orange"><div><div class="mv" style="font-size:19px"><?= money($tAdjustment) ?></div><div class="ml">Salary Adjustment</div><div class="ms">added to Payable</div></div><div class="mi">💰</div></div>
  <div class="metric <?= $pendingEntries>0?'amber':'green' ?>"><div><div class="mv" style="font-size:19px"><?= number_format(max(0,$pendingEntries)) ?></div><div class="ml">Pending Entries</div><div class="ms">not logged for <?= e(date('d M',strtotime($d))) ?></div></div><div class="mi">⏳</div></div>
</div>
<?php if($tReview>0): ?><div class="flash" style="background:var(--amber-bg,#fef3c7);color:var(--amber)">⚠ <b><?= $tReview ?></b> entr<?= $tReview>1?'ies':'y' ?> this month exceed the daily allowance and need review.</div><?php endif; ?>

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
  <div class="panel-head"><h2>🧾 Daily Lunch Entries — <?= e(date('M Y',strtotime($m.'-01'))) ?> · <?= e(bs_month_label($m)) ?></h2>
    <div style="display:flex;gap:8px"><button type="button" class="btn btn-sm" onclick="lmExportCsv('dailyTbl','daily-lunch-<?= e($m) ?>.csv')">⬇ Export CSV</button><button type="button" class="btn btn-sm" onclick="window.print()">🖨 Print</button></div>
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
</div>

<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>📊 Monthly Lunch Summary — <?= e(date('M Y',strtotime($m.'-01'))) ?> · <?= e(bs_month_label($m)) ?></h2>
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
      <form method="post" onsubmit="return confirm('Recalculate lunch adjustments for everyone in '+<?= json_encode(date('M Y',strtotime($m.'-01'))) ?>+'?')">
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
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
