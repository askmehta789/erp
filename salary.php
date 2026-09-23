<?php
require_once __DIR__.'/functions.php'; require_once __DIR__.'/nepali_date.php';
require_login(); require_page_access();
/* lunch_orders / employees.lunch_rate / salary_entries.source (functions.php),
   and the extra Staff Directory profile columns — must run before handle_crud()
   below, since it writes straight to these columns */
ensure_lunch_system();
ensure_employee_profile_fields();
/* embedded Staff Directory posts carry _entity=employees; handled & redirected before salary handlers */
if (($_POST['_entity'] ?? '') === 'employees') handle_crud('employees');
$PAGE_TITLE='Staff Salary';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;

try { q("CREATE TABLE IF NOT EXISTS salary_entries(
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  ym CHAR(7) NOT NULL,                 /* salary month YYYY-MM (AD) */
  type VARCHAR(12) NOT NULL,           /* payment | advance | bonus | lunch | deduction */
  amount DECIMAL(12,2) NOT NULL,
  entry_date DATE NOT NULL,
  note VARCHAR(160) DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(employee_id), INDEX(ym))"); } catch (Exception $e) {}
ensure_banks();
ensure_expense_bank_columns();
try { q("ALTER TABLE salary_entries ADD COLUMN IF NOT EXISTS account_id INT NULL"); } catch (Exception $e) {}
try { q("ALTER TABLE salary_entries ADD COLUMN IF NOT EXISTS bank_txn_id INT NULL"); } catch (Exception $e) {}
try { q("ALTER TABLE salary_entries ADD COLUMN IF NOT EXISTS expense_id INT NULL"); } catch (Exception $e) {}

/* a salary payment/advance/bonus paid from a bank account mirrors into the
   expenses table (category 'Salary', via the same expense_bank_sync() that
   expenses.php uses) — so it shows on the Expenses page and counts toward
   Net Profit, same as any other expense. Money Dashboard already excludes
   category='Salary' from its own expense sum and reads salary_entries
   directly instead, so this never double-counts there. Deductions never
   touch a bank account (no cash actually leaves), so they're excluded.
   Returns [expense_id, bank_txn_id]. */
function salary_expense_sync(array $s, ?int $accountId): array {
  $oldExpId = (int)($s['expense_id'] ?? 0);
  if (!$accountId || !in_array($s['type'],['payment','advance','bonus'],true)) {
    if ($oldExpId) {
      $oldExp = row("SELECT * FROM expenses WHERE id=?",[$oldExpId]);
      if ($oldExp) {
        $tid=(int)($oldExp['bank_txn_id'] ?? 0);
        if ($tid) { try { q("DELETE FROM bank_txns WHERE id=?",[$tid]); } catch (Exception $e) {} }
        q("DELETE FROM expenses WHERE id=?",[$oldExpId]);
      }
    }
    return [null,null];
  }
  $empName = (string)val("SELECT name FROM employees WHERE id=?",[$s['employee_id']]);
  $desc = trim(($empName?:'Employee').' — '.ucfirst($s['type']).(($s['note']??'')!==''?' · '.$s['note']:''));
  if ($oldExpId) {
    q("UPDATE expenses SET expense_date=?, description=?, amount=?, category='Salary', pay_from='bank' WHERE id=?",
      [$s['entry_date'],$desc,(float)$s['amount'],$oldExpId]);
  } else {
    q("INSERT INTO expenses(expense_date,description,amount,currency,pay_from,category) VALUES(?,?,?,?,?,?)",
      [$s['entry_date'],$desc,(float)$s['amount'],'Rs','bank','Salary']);
    $oldExpId=(int)db()->lastInsertId();
  }
  $exp = row("SELECT * FROM expenses WHERE id=?",[$oldExpId]);
  $tid = expense_bank_sync($exp, $accountId);
  q("UPDATE expenses SET account_id=?, bank_txn_id=? WHERE id=?",[$accountId,$tid,$oldExpId]);
  return [$oldExpId,$tid];
}
$TYPES = ['payment'=>'💵 Payment','advance'=>'⏩ Advance','bonus'=>'🎁 Bonus','lunch'=>'🍱 Lunch Allowance','deduction'=>'✂️ Deduction'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act=$_POST['_action'] ?? '';
  if ($act==='add') {
    $eid=(int)($_POST['employee_id'] ?? 0);
    $type=isset($TYPES[$_POST['type'] ?? '']) ? $_POST['type'] : 'payment';
    $amt=(float)($_POST['amount'] ?? 0);
    $note=trim($_POST['note'] ?? '');
    $ym=preg_match('/^\d{4}-\d{2}$/',$_POST['ym'] ?? '')?$_POST['ym']:date('Y-m');
    /* lunch is entirely Lunch Management's domain now — it auto-syncs its own
       'lunch' row (source='lunch_mgmt') from the daily entries there, so this
       page no longer accepts manual Lunch entries at all */
    if ($type==='lunch') {
      flash('Lunch adjustments are managed on the Lunch Management page now — log the daily entry there instead.');
    } elseif ($eid && $amt>0) {
      q("INSERT INTO salary_entries(employee_id,ym,type,amount,entry_date,note) VALUES(?,?,?,?,?,?)",
        [$eid,$ym,$type,$amt,($_POST['entry_date'] ?? '') ?: date('Y-m-d'),$note]);
      $newId=(int)db()->lastInsertId();
      $acc=(int)($_POST['account_id'] ?? 0) ?: null;
      if ($acc) {
        $s = row("SELECT * FROM salary_entries WHERE id=?",[$newId]);
        [$expId,$tid] = salary_expense_sync($s, $acc);
        q("UPDATE salary_entries SET account_id=?, bank_txn_id=?, expense_id=? WHERE id=?",[$acc,$tid,$expId,$newId]);
      }
      log_activity("Salary $type ".money($amt)." (emp #$eid, $ym)",'Salary');
      flash(ucfirst($type).' of '.money($amt).' recorded.');
    } else flash('Pick an employee and enter an amount.');
    header('Location: salary.php?m='.urlencode($ym).(isset($_POST['back_emp'])&&$_POST['back_emp']?'&emp='.(int)$_POST['back_emp']:'')); exit;
  }
  if ($act==='del' && $isAdmin) {
    $delId=(int)($_POST['id'] ?? 0);
    $row0=row("SELECT bank_txn_id, expense_id FROM salary_entries WHERE id=?",[$delId]);
    if ($row0) {
      $expId0=(int)($row0['expense_id'] ?? 0);
      if ($expId0) {
        $tid0=(int)val("SELECT COALESCE(bank_txn_id,0) FROM expenses WHERE id=?",[$expId0]);
        if ($tid0) { try { q("DELETE FROM bank_txns WHERE id=?",[$tid0]); } catch (Exception $e) {} }
        q("DELETE FROM expenses WHERE id=?",[$expId0]);
      } elseif ((int)($row0['bank_txn_id'] ?? 0)) {
        try { q("DELETE FROM bank_txns WHERE id=?",[(int)$row0['bank_txn_id']]); } catch (Exception $e) {}
      }
    }
    q("DELETE FROM salary_entries WHERE id=?",[$delId]);
    flash('Entry deleted.');
    header('Location: salary.php?m='.urlencode($_POST['m'] ?? date('Y-m'))); exit;
  }
  if ($act==='pay_all' && $isAdmin) {
    $ym2=preg_match('/^\d{4}-\d{2}$/',$_POST['ym'] ?? '')?$_POST['ym']:date('Y-m');
    $acc2=(int)($_POST['account_id'] ?? 0) ?: null;
    $paidCount=0; $paidTotal=0;
    foreach (rows("SELECT id,salary FROM employees ORDER BY name") as $e2) {
      $eid2=(int)$e2['id'];
      $agg2=row("SELECT
          SUM(CASE WHEN type='payment' THEN amount ELSE 0 END) pay,
          SUM(CASE WHEN type='advance' THEN amount ELSE 0 END) adv,
          SUM(CASE WHEN type='bonus' THEN amount ELSE 0 END) bon,
          SUM(CASE WHEN type='lunch' THEN amount ELSE 0 END) lun,
          SUM(CASE WHEN type='deduction' THEN amount ELSE 0 END) ded
        FROM salary_entries WHERE ym=? AND employee_id=?",[$ym2,$eid2]);
      $payable2=(float)$e2['salary']+(float)($agg2['bon']??0)+(float)($agg2['lun']??0)-(float)($agg2['ded']??0);
      $paid2=(float)($agg2['pay']??0)+(float)($agg2['adv']??0);
      $due2=round($payable2-$paid2,2);
      if ($due2>0.01) {
        q("INSERT INTO salary_entries(employee_id,ym,type,amount,entry_date,note) VALUES(?,?,?,?,?,?)",
          [$eid2,$ym2,'payment',$due2,date('Y-m-d'),'Bulk pay all']);
        $nid=(int)db()->lastInsertId();
        if ($acc2) {
          $s2=row("SELECT * FROM salary_entries WHERE id=?",[$nid]);
          [$expId2,$tid2] = salary_expense_sync($s2,$acc2);
          q("UPDATE salary_entries SET account_id=?, bank_txn_id=?, expense_id=? WHERE id=?",[$acc2,$tid2,$expId2,$nid]);
        }
        $paidCount++; $paidTotal+=$due2;
      }
    }
    log_activity("Bulk paid $paidCount staff ".money($paidTotal)." ($ym2)",'Salary');
    flash($paidCount?"Paid $paidCount staff — ".money($paidTotal)." total.":'Nothing due — everyone is already paid up.');
    header('Location: salary.php?m='.urlencode($ym2)); exit;
  }
}

$m = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$linkAccounts = rows("SELECT * FROM bank_accounts WHERE archived=0 ORDER BY kind='cash' DESC, name");
$acctBal = [];
foreach($linkAccounts as $A){
  $in =(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='in'",[$A['id']]);
  $out=(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='out'",[$A['id']]);
  $acctBal[$A['id']]=(float)$A['opening']+$in-$out;
}
$empFilter = (int)($_GET['emp'] ?? 0);

$emps = rows("SELECT * FROM employees".($empFilter?" WHERE id=".$empFilter:"")." ORDER BY name");
$allEmps = rows("SELECT id,name,lunch_rate FROM employees ORDER BY name");
$empName=[]; $empLunchRate=[]; foreach($allEmps as $ae) { $empName[(int)$ae['id']]=$ae['name']; $empLunchRate[(int)$ae['id']]=(float)$ae['lunch_rate']; }

$mStart=$m.'-01';

/* month aggregates per employee */
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

$tPayable=0;$tPaid=0;$tDue=0;$tLunch=0;$tBonus=0;$tAdvance=0;
$rowsOut=[];
foreach($emps as $e){
  $id=(int)$e['id']; $base=(float)($e['salary'] ?? 0);
  $bon=$G($id,'bon'); $ded=$G($id,'ded'); $adv=$G($id,'adv'); $pay=$G($id,'pay'); $lun=$G($id,'lun');
  $payable = $base + $bon + $lun - $ded;
  $paid    = $pay + $adv;                     /* advances count toward the month */
  $due     = $payable - $paid;
  $st = $paid<=0.01 ? 'Pending' : ($due>0.01 ? 'Partial' : 'Paid');
  $tPayable+=$payable; $tPaid+=min($paid,$payable); $tDue+=max(0,$due); $tLunch+=$lun; $tBonus+=$bon; $tAdvance+=$adv;
  $rowsOut[]=compact('e','id','base','bon','lun','ded','adv','pay','payable','paid','due','st');
}

$entries = rows("SELECT s.*, e.name FROM salary_entries s LEFT JOIN employees e ON e.id=s.employee_id
                 WHERE s.ym=?".($empFilter?" AND s.employee_id=".$empFilter:"")." ORDER BY s.entry_date DESC, s.id DESC",[$m]);

/* per-employee full history (when filtered) */
$history=[];
if ($empFilter) {
  foreach (rows("SELECT ym,
          SUM(CASE WHEN type='payment' THEN amount ELSE 0 END) pay,
          SUM(CASE WHEN type='advance' THEN amount ELSE 0 END) adv,
          SUM(CASE WHEN type='bonus' THEN amount ELSE 0 END) bon,
          SUM(CASE WHEN type='lunch' THEN amount ELSE 0 END) lun,
          SUM(CASE WHEN type='deduction' THEN amount ELSE 0 END) ded
          FROM salary_entries WHERE employee_id=? GROUP BY ym ORDER BY ym DESC",[$empFilter]) as $h) $history[]=$h;
}

/* ---- 12-month trend (company-wide, or one employee when filtered) ---- */
$trendFrom=date('Y-m',strtotime($mStart.' -11 months'));
$trendMap=[];
foreach (rows("SELECT ym,
        SUM(CASE WHEN type IN ('payment','advance') THEN amount ELSE 0 END) paid,
        SUM(CASE WHEN type='bonus' THEN amount ELSE 0 END) bon,
        SUM(CASE WHEN type='lunch' THEN amount ELSE 0 END) lun
        FROM salary_entries WHERE ym>=?".($empFilter?" AND employee_id=".$empFilter:"")." GROUP BY ym",[$trendFrom]) as $t)
  $trendMap[$t['ym']]=$t;
$trendLabels=[];$trendPaid=[];$trendBon=[];$trendLun=[];
for($i=11;$i>=0;$i--){
  $ym2=date('Y-m',strtotime($mStart.' -'.$i.' months'));
  $trendLabels[]=date('M Y',strtotime($ym2.'-01'));
  $trendPaid[]=round((float)($trendMap[$ym2]['paid']??0),2);
  $trendBon[]=round((float)($trendMap[$ym2]['bon']??0),2);
  $trendLun[]=round((float)($trendMap[$ym2]['lun']??0),2);
}

/* ---- yearly summary (Paid = payments + advances, per employee per month) ---- */
$year=preg_match('/^\d{4}$/',$_GET['year'] ?? '')?(int)$_GET['year']:(int)date('Y');
$yearMap=[];
foreach (rows("SELECT employee_id,ym,SUM(CASE WHEN type IN ('payment','advance') THEN amount ELSE 0 END) paid
        FROM salary_entries WHERE ym LIKE ? GROUP BY employee_id,ym",[$year.'-%']) as $yr)
  $yearMap[(int)$yr['employee_id']][$yr['ym']]=(float)$yr['paid'];

require __DIR__.'/includes/header.php';
$stPill=fn($s)=>['Paid'=>'p-green','Partial'=>'p-yellow','Pending'=>'p-red'][$s];
?>
<div class="page-head">
  <div><h1>💵 Staff Salary</h1><p><?= e(date('F Y',strtotime($m.'-01'))) ?> · <?= e(bs_month_label($m)) ?> — salaries, advances, bonuses, lunch allowance &amp; deductions</p></div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="get" style="display:flex;gap:6px;align-items:center">
      <select name="emp"><option value="0">All staff</option>
        <?php foreach($allEmps as $ae): ?><option value="<?= (int)$ae['id'] ?>"<?= $empFilter===(int)$ae['id']?' selected':'' ?>><?= e($ae['name']) ?></option><?php endforeach; ?></select>
      <input type="month" name="m" value="<?= e($m) ?>"><button class="btn btn-sm">Go</button>
    </form>
    <a class="btn" style="background:var(--orange-bg);color:var(--orange)" href="lunch_management.php?m=<?= e($m) ?>">🍱 Lunch Management</a>
    <?php if($isAdmin && $tDue>0.5): ?><button class="btn" style="background:var(--green-bg);color:var(--green)" onclick="openPayAll()">💰 Pay All Due</button><?php endif; ?>
    <button class="btn btn-primary" onclick="openSal()">＋ Add Entry</button>
  </div>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="mgrid">
  <div class="metric blue"><div><div class="mv" style="font-size:19px"><?= money($tPayable) ?></div><div class="ml">Payable This Month</div><div class="ms">base + bonus + lunch − deduction</div></div><div class="mi">🧾</div></div>
  <div class="metric green"><div><div class="mv" style="font-size:19px"><?= money($tPaid) ?></div><div class="ml">Paid</div><div class="ms">payments + advances</div></div><div class="mi">✅</div></div>
  <div class="metric <?= $tDue>0.5?'amber':'teal' ?>"><div><div class="mv" style="font-size:19px"><?= money($tDue) ?></div><div class="ml">Remaining Due</div><div class="ms"><?= e(bs_month_label($m)) ?></div></div><div class="mi">⏳</div></div>
  <div class="metric orange"><div><div class="mv" style="font-size:19px"><?= money($tLunch) ?></div><div class="ml">Lunch Allowance</div><div class="ms">paid out this month</div></div><div class="mi">🍱</div></div>
  <div class="metric purple"><div><div class="mv" style="font-size:19px"><?= money($tBonus) ?></div><div class="ml">Bonuses</div><div class="ms">this month</div></div><div class="mi">🎁</div></div>
  <div class="metric indigo"><div><div class="mv" style="font-size:19px"><?= money($tAdvance) ?></div><div class="ml">Advances</div><div class="ms">this month</div></div><div class="mi">⏩</div></div>
</div>

<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>👥 Salary Sheet — <?= e(date('M Y',strtotime($m.'-01'))) ?> · <?= e(bs_month_label($m)) ?></h2>
    <span class="muted" style="font-size:12px">staff directory is below ⬇ — add &amp; edit employees there</span></div>
  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr>
    <th>Employee</th><th class="right">Base Salary</th><th class="right">Bonus</th><th class="right">Lunch</th><th class="right">Deduction</th><th class="right">Payable</th><th class="right">Advance</th><th class="right">Payment</th><th class="right">Remaining</th><th>Status</th><th></th>
  </tr></thead><tbody>
  <?php foreach($rowsOut as $r): ?>
    <tr>
      <td><b><?= e($r['e']['name']) ?></b><br><small class="muted"><?= e($r['e']['role'] ?: '') ?> · <?= money((float)$r['e']['lunch_rate']) ?>/day lunch</small></td>
      <td class="num right"><?= money($r['base']) ?></td>
      <td class="num right" style="color:var(--green)"><?= $r['bon']>0?'+'.money($r['bon']):'—' ?></td>
      <td class="num right" style="color:var(--orange)"><?= $r['lun']>0?'+'.money($r['lun']):'—' ?></td>
      <td class="num right" style="color:var(--red)"><?= $r['ded']>0?'−'.money($r['ded']):'—' ?></td>
      <td class="num right" style="font-weight:700"><?= money($r['payable']) ?></td>
      <td class="num right"><?= $r['adv']>0?money($r['adv']):'—' ?></td>
      <td class="num right"><?= $r['pay']>0?money($r['pay']):'—' ?></td>
      <td class="num right" style="font-weight:800;color:<?= $r['due']>0.01?'var(--amber)':($r['due']<-0.01?'var(--red)':'var(--green)') ?>"><?= money($r['due']) ?><?= $r['due']<-0.01?' ⚠ over':'' ?></td>
      <td><span class="pill <?= $stPill($r['st']) ?>"><?= $r['st'] ?></span></td>
      <td style="white-space:nowrap">
        <button class="btn btn-sm" onclick="openSal(<?= $r['id'] ?>)">＋</button>
        <a class="btn btn-sm" href="salary.php?emp=<?= $r['id'] ?>&m=<?= e($m) ?>" title="history">📜</a>
      </td>
    </tr>
  <?php endforeach; if(!$rowsOut) echo '<tr><td colspan="11"><div class="empty">No staff yet — add employees in <a href="hrm.php">HRM</a> first.</div></td></tr>'; ?>
  </tbody></table></div>
</div>

<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>📈 Salary Trend — Last 12 Months<?= $empFilter?' · '.e($empName[$empFilter] ?? ''):'' ?></h2></div>
  <div class="panel-body"><div style="height:260px"><canvas id="salTrendChart"></canvas></div></div>
</div>

<?php if($empFilter && $history): ?>
<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>📜 Full History — <?= e($empName[$empFilter] ?? '') ?></h2>
    <a class="btn btn-sm" href="salary.php?m=<?= e($m) ?>">← all staff</a></div>
  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr>
    <th>Month (AD)</th><th>Month (BS)</th><th class="right">Bonus</th><th class="right">Lunch</th><th class="right">Deduction</th><th class="right">Advance</th><th class="right">Payment</th><th class="right">Total Received</th>
  </tr></thead><tbody>
  <?php foreach($history as $h): ?>
    <tr>
      <td><b><?= e(date('M Y',strtotime($h['ym'].'-01'))) ?></b></td>
      <td><?= e(bs_month_label($h['ym'])) ?></td>
      <td class="num right" style="color:var(--green)"><?= money($h['bon']) ?></td>
      <td class="num right" style="color:var(--orange)"><?= money($h['lun']) ?></td>
      <td class="num right" style="color:var(--red)"><?= money($h['ded']) ?></td>
      <td class="num right"><?= money($h['adv']) ?></td>
      <td class="num right"><?= money($h['pay']) ?></td>
      <td class="num right" style="font-weight:800"><?= money((float)$h['pay']+(float)$h['adv']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>📅 Yearly Summary — <?= $year ?></h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <form method="get" style="display:inline-flex;gap:6px;align-items:center">
        <input type="hidden" name="m" value="<?= e($m) ?>"><input type="hidden" name="emp" value="<?= $empFilter ?>">
        <select name="year" onchange="this.form.submit()">
          <?php for($y=(int)date('Y');$y>=(int)date('Y')-4;$y--): ?><option value="<?= $y ?>"<?= $y===$year?' selected':'' ?>><?= $y ?></option><?php endfor; ?>
        </select>
      </form>
      <button type="button" class="btn btn-sm" onclick="salExportYearCsv()">⬇ Export CSV</button>
    </div>
  </div>
  <div class="table-wrap"><table class="tbl num-tbl" id="yearlyTbl"><thead><tr>
    <th>Employee</th><?php foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mn): ?><th class="right"><?= $mn ?></th><?php endforeach; ?><th class="right">Year Total</th>
  </tr></thead><tbody>
  <?php $yearColTotals=array_fill(1,12,0.0); $yearGrand=0.0; foreach($allEmps as $ae): $aeid=(int)$ae['id']; $rowTotal=0.0; ?>
    <tr>
      <td><b><?= e($ae['name']) ?></b></td>
      <?php for($mm=1;$mm<=12;$mm++): $ym3=sprintf('%04d-%02d',$year,$mm); $v=(float)($yearMap[$aeid][$ym3]??0); $rowTotal+=$v; $yearColTotals[$mm]+=$v; ?>
        <td class="num right"><?= $v>0?money($v):'—' ?></td>
      <?php endfor; $yearGrand+=$rowTotal; ?>
      <td class="num right" style="font-weight:800"><?= money($rowTotal) ?></td>
    </tr>
  <?php endforeach; if(!$allEmps) echo '<tr><td colspan="14"><div class="empty">No staff yet.</div></td></tr>'; ?>
  </tbody>
  <?php if($allEmps): ?>
  <tfoot><tr style="font-weight:800;background:var(--surface-2)">
    <td>Total</td>
    <?php for($mm=1;$mm<=12;$mm++): ?><td class="num right"><?= money($yearColTotals[$mm]) ?></td><?php endfor; ?>
    <td class="num right"><?= money($yearGrand) ?></td>
  </tr></tfoot>
  <?php endif; ?>
  </table></div>
  <p class="muted" style="font-size:11.5px;margin-top:8px">Monthly figures are total payments + advances actually paid out that month.</p>
</div>

<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>🧾 Entries — <?= e(date('M Y',strtotime($m.'-01'))) ?></h2></div>
  <div class="table-wrap"><table class="tbl led-tbl"><thead><tr>
    <th>Date (AD · BS)</th><th>Employee</th><th>Type</th><th style="text-align:right">Amount</th><th>Note</th><?php if($isAdmin): ?><th></th><?php endif; ?>
  </tr></thead><tbody>
  <?php foreach($entries as $en): ?>
    <tr>
      <td><?= e(dual_date($en['entry_date'])) ?></td>
      <td><b><?= e($en['name'] ?: ('#'.$en['employee_id'])) ?></b></td>
      <td><?= e($TYPES[$en['type']] ?? $en['type']) ?></td>
      <td style="text-align:right;font-weight:700;color:<?= $en['type']==='deduction'?'var(--red)':($en['type']==='lunch'?'var(--orange)':'var(--ink)') ?>"><?= money($en['amount']) ?></td>
      <td class="muted" style="white-space:normal"><?= e($en['note'] ?: '—') ?></td>
      <?php if($isAdmin): ?><td><form method="post" style="display:inline" onsubmit="return confirm('Delete this entry?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="del"><input type="hidden" name="id" value="<?= (int)$en['id'] ?>"><input type="hidden" name="m" value="<?= e($m) ?>"><button class="rowdel">🗑</button></form></td><?php endif; ?>
    </tr>
  <?php endforeach; if(!$entries) echo '<tr><td colspan="6"><div class="empty">No entries this month.</div></td></tr>'; ?>
  </tbody></table></div>
</div>

<!-- add entry modal -->
<div class="modal-bg" id="salModal" style="z-index:99990"><form class="modal" method="post" style="width:500px;max-width:94vw">
  <div class="modal-head"><span>＋ Salary Entry</span><span class="mx" onclick="closeSal()">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="add">
  <input type="hidden" name="back_emp" value="<?= $empFilter ?>">
  <div class="modal-body">
    <div><label>Employee *</label><select name="employee_id" id="sal_emp" required><option value="">—</option>
      <?php foreach($allEmps as $ae): ?><option value="<?= (int)$ae['id'] ?>"><?= e($ae['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Type</label><select name="type" id="sal_type"><?php foreach($TYPES as $k=>$l): if($k==='lunch') continue; ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label>Salary Month</label><input type="month" name="ym" value="<?= e($m) ?>"></div>
    <div><label>Amount (Rs.) *</label><input type="number" step="any" min="1" name="amount" id="sal_amount" required></div>
    <div><label>Account (Bank page) — leave "not linked" for advances/deductions you track only here</label>
      <select name="account_id">
        <option value="0">⏳ — not linked —</option>
        <?php foreach($linkAccounts as $A): ?>
          <option value="<?= (int)$A['id'] ?>"><?= $A['kind']==='cash'?'💵':'🏦' ?> <?= e($A['name']) ?> — <?= money($acctBal[$A['id']] ?? 0) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label>Date</label><input type="date" name="entry_date" id="sal_date" value="<?= date('Y-m-d') ?>" onchange="salBs()"></div>
    <div><label>&nbsp;</label><div id="sal_bs" class="muted" style="padding-top:9px;font-weight:700"><?= e(bs_pretty(date('Y-m-d'))) ?></div></div>
    <div class="full"><label>Note</label><input name="note" placeholder="optional"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="closeSal()">Cancel</button><button class="btn btn-primary">💾 Save</button></div>
</form></div>

<?php if($isAdmin): ?>
<!-- bulk pay-all modal -->
<div class="modal-bg" id="payAllModal" style="z-index:99990"><form class="modal" method="post" style="width:440px;max-width:94vw">
  <div class="modal-head"><span>💰 Pay All Remaining</span><span class="mx" onclick="closePayAll()">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="pay_all"><input type="hidden" name="ym" value="<?= e($m) ?>">
  <div class="modal-body">
    <p>Records a <b>Payment</b> for every staff member with remaining due in <?= e(date('M Y',strtotime($m.'-01'))) ?>, totalling <b><?= money($tDue) ?></b>.</p>
    <div><label>Pay from Account</label>
      <select name="account_id">
        <option value="0">⏳ — not linked (track only) —</option>
        <?php foreach($linkAccounts as $A): ?>
          <option value="<?= (int)$A['id'] ?>"><?= $A['kind']==='cash'?'💵':'🏦' ?> <?= e($A['name']) ?> — <?= money($acctBal[$A['id']] ?? 0) ?></option>
        <?php endforeach; ?>
      </select></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="closePayAll()">Cancel</button><button class="btn btn-primary">💾 Confirm &amp; Pay All</button></div>
</form></div>
<?php endif; ?>

<script>
var BS_LABELS=<?php $lbl=[]; for($i=-60;$i<=30;$i++){$d=date('Y-m-d',strtotime("$i days")); $lbl[$d]=bs_pretty($d);} echo json_encode($lbl); ?>;
function salBs(){var d=document.getElementById('sal_date').value;document.getElementById('sal_bs').textContent=BS_LABELS[d]||'';}
function openSal(eid){
  if(eid)document.getElementById('sal_emp').value=eid;
  document.getElementById('sal_type').value='payment';
  document.getElementById('salModal').classList.add('open');document.body.classList.add('modal-open');
}
function closeSal(){document.getElementById('salModal').classList.remove('open');document.body.classList.remove('modal-open');}
(function(){var mm=document.getElementById('salModal');mm.addEventListener('click',function(e){if(e.target===mm)closeSal();});})();

function openPayAll(){var m=document.getElementById('payAllModal');if(m){m.classList.add('open');document.body.classList.add('modal-open');}}
function closePayAll(){var m=document.getElementById('payAllModal');if(m){m.classList.remove('open');document.body.classList.remove('modal-open');}}
(function(){var pm=document.getElementById('payAllModal');if(pm)pm.addEventListener('click',function(e){if(e.target===pm)closePayAll();});})();

function salExportYearCsv(){
  var rows=[]; document.querySelectorAll('#yearlyTbl tr').forEach(function(tr){
    var cells=Array.prototype.map.call(tr.children,function(td){ var t=(td.textContent||'').trim().replace(/"/g,'""'); return '"'+t+'"'; });
    rows.push(cells.join(','));
  });
  var blob=new Blob([rows.join('\n')],{type:'text/csv'});
  var a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='staff-salary-<?= $year ?>.csv'; a.click();
}

new Chart(document.getElementById('salTrendChart'),{
  type:'bar',
  data:{labels:<?= json_encode($trendLabels) ?>,
    datasets:[
      {label:'Payments + Advances',data:<?= json_encode($trendPaid) ?>,backgroundColor:'#2563eb',borderRadius:5,maxBarThickness:34},
      {label:'Bonus',data:<?= json_encode($trendBon) ?>,backgroundColor:'#16a34a',borderRadius:5,maxBarThickness:34},
      {label:'Lunch',data:<?= json_encode($trendLun) ?>,backgroundColor:'#f97316',borderRadius:5,maxBarThickness:34}
    ]},
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:true,position:'bottom'}},
    scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}
});
</script>
<?php render_crud('employees','👥 Staff Directory','add & manage employees — base salary & daily lunch allowance set here','embed'); ?>
<?php require __DIR__.'/includes/footer.php'; ?>