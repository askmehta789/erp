<?php
require_once __DIR__.'/functions.php'; require_once __DIR__.'/nepali_date.php';
require_login(); require_page_access();
/* embedded Staff Directory posts carry _entity=employees; handled & redirected before salary handlers */
if (($_POST['_entity'] ?? '') === 'employees') handle_crud('employees');
$PAGE_TITLE='Staff Salary';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;

try { q("CREATE TABLE IF NOT EXISTS salary_entries(
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  ym CHAR(7) NOT NULL,                 /* salary month YYYY-MM (AD) */
  type VARCHAR(12) NOT NULL,           /* payment | advance | bonus | deduction */
  amount DECIMAL(12,2) NOT NULL,
  entry_date DATE NOT NULL,
  note VARCHAR(160) DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(employee_id), INDEX(ym))"); } catch (Exception $e) {}
ensure_banks();
try { q("ALTER TABLE salary_entries ADD COLUMN IF NOT EXISTS account_id INT NULL"); } catch (Exception $e) {}
try { q("ALTER TABLE salary_entries ADD COLUMN IF NOT EXISTS bank_txn_id INT NULL"); } catch (Exception $e) {}

/* same proven pattern as expense_bank_sync() — a salary payment/advance/bonus paid
   from a bank account syncs there automatically. Deductions never touch a bank
   account (they reduce what's owed, no cash actually leaves), so they're excluded. */
function salary_bank_sync(array $s, ?int $accountId): ?int {
  $old=(int)($s['bank_txn_id'] ?? 0);
  if(!$accountId || !in_array($s['type'],['payment','advance','bonus'],true)){ if($old) q("DELETE FROM bank_txns WHERE id=?",[$old]); return null; }
  $empName = (string)val("SELECT name FROM employees WHERE id=?",[$s['employee_id']]);
  $cat = 'Salary';
  $rem = trim(($empName?:'Employee').' — '.ucfirst($s['type']).(($s['note']??'')!==''?' · '.$s['note']:''));
  if($old){
    q("UPDATE bank_txns SET account_id=?, txn_date=?, direction='out', amount=?, category=?, remarks=? WHERE id=?",
      [$accountId,$s['entry_date'],(float)$s['amount'],$cat,$rem,$old]);
    return $old;
  }
  q("INSERT INTO bank_txns(account_id,txn_date,direction,amount,category,remarks) VALUES(?,?,'out',?,?,?)",
    [$accountId,$s['entry_date'],(float)$s['amount'],$cat,$rem]);
  return (int)db()->lastInsertId();
}

$TYPES = ['payment'=>'💵 Payment','advance'=>'⏩ Advance','bonus'=>'🎁 Bonus','deduction'=>'✂️ Deduction'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act=$_POST['_action'] ?? '';
  if ($act==='add') {
    $eid=(int)($_POST['employee_id'] ?? 0);
    $type=isset($TYPES[$_POST['type'] ?? '']) ? $_POST['type'] : 'payment';
    $amt=(float)($_POST['amount'] ?? 0);
    $ym=preg_match('/^\d{4}-\d{2}$/',$_POST['ym'] ?? '')?$_POST['ym']:date('Y-m');
    if ($eid && $amt>0) {
      q("INSERT INTO salary_entries(employee_id,ym,type,amount,entry_date,note) VALUES(?,?,?,?,?,?)",
        [$eid,$ym,$type,$amt,($_POST['entry_date'] ?? '') ?: date('Y-m-d'),trim($_POST['note'] ?? '')]);
      $newId=(int)db()->lastInsertId();
      $acc=(int)($_POST['account_id'] ?? 0) ?: null;
      if ($acc) {
        $s = row("SELECT * FROM salary_entries WHERE id=?",[$newId]);
        $tid = salary_bank_sync($s, $acc);
        if ($tid) q("UPDATE salary_entries SET account_id=?, bank_txn_id=? WHERE id=?",[$acc,$tid,$newId]);
      }
      log_activity("Salary $type ".money($amt)." (emp #$eid, $ym)",'Salary');
      flash(ucfirst($type).' of '.money($amt).' recorded.');
    } else flash('Pick an employee and enter an amount.');
    header('Location: salary.php?m='.urlencode($ym).(isset($_POST['back_emp'])&&$_POST['back_emp']?'&emp='.(int)$_POST['back_emp']:'')); exit;
  }
  if ($act==='del' && $isAdmin) {
    $tid=(int)val("SELECT COALESCE(bank_txn_id,0) FROM salary_entries WHERE id=?",[(int)($_POST['id'] ?? 0)]);
    if ($tid) { try { q("DELETE FROM bank_txns WHERE id=?",[$tid]); } catch (Exception $e) {} }
    q("DELETE FROM salary_entries WHERE id=?",[(int)($_POST['id'] ?? 0)]);
    flash('Entry deleted.');
    header('Location: salary.php?m='.urlencode($_POST['m'] ?? date('Y-m'))); exit;
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
$allEmps = rows("SELECT id,name FROM employees ORDER BY name");
$empName=[]; foreach($allEmps as $ae) $empName[(int)$ae['id']]=$ae['name'];

/* month aggregates per employee */
$agg=[];
foreach (rows("SELECT employee_id,
        SUM(CASE WHEN type='payment' THEN amount ELSE 0 END) pay,
        SUM(CASE WHEN type='advance' THEN amount ELSE 0 END) adv,
        SUM(CASE WHEN type='bonus' THEN amount ELSE 0 END) bon,
        SUM(CASE WHEN type='deduction' THEN amount ELSE 0 END) ded
        FROM salary_entries WHERE ym=? GROUP BY employee_id",[$m]) as $r)
  $agg[(int)$r['employee_id']]=$r;

$G=function($id,$k)use($agg){ return isset($agg[$id])?(float)$agg[$id][$k]:0; };

$tPayable=0;$tPaid=0;$tDue=0;
$rowsOut=[];
foreach($emps as $e){
  $id=(int)$e['id']; $base=(float)($e['salary'] ?? 0);
  $bon=$G($id,'bon'); $ded=$G($id,'ded'); $adv=$G($id,'adv'); $pay=$G($id,'pay');
  $payable = $base + $bon - $ded;
  $paid    = $pay + $adv;                     /* advances count toward the month */
  $due     = $payable - $paid;
  $st = $paid<=0.01 ? 'Pending' : ($due>0.01 ? 'Partial' : 'Paid');
  $tPayable+=$payable; $tPaid+=min($paid,$payable); $tDue+=max(0,$due);
  $rowsOut[]=compact('e','id','base','bon','ded','adv','pay','payable','paid','due','st');
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
          SUM(CASE WHEN type='deduction' THEN amount ELSE 0 END) ded
          FROM salary_entries WHERE employee_id=? GROUP BY ym ORDER BY ym DESC",[$empFilter]) as $h) $history[]=$h;
}

require __DIR__.'/includes/header.php';
$stPill=fn($s)=>['Paid'=>'p-green','Partial'=>'p-yellow','Pending'=>'p-red'][$s];
?>
<div class="page-head">
  <div><h1>💵 Staff Salary</h1><p><?= e(date('F Y',strtotime($m.'-01'))) ?> · <?= e(bs_month_label($m)) ?> — salaries, advances, bonuses &amp; deductions</p></div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="get" style="display:flex;gap:6px;align-items:center">
      <select name="emp"><option value="0">All staff</option>
        <?php foreach($allEmps as $ae): ?><option value="<?= (int)$ae['id'] ?>"<?= $empFilter===(int)$ae['id']?' selected':'' ?>><?= e($ae['name']) ?></option><?php endforeach; ?></select>
      <input type="month" name="m" value="<?= e($m) ?>"><button class="btn btn-sm">Go</button>
    </form>
    <button class="btn btn-primary" onclick="openSal()">＋ Add Entry</button>
  </div>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="mgrid">
  <div class="metric blue"><div><div class="mv" style="font-size:19px"><?= money($tPayable) ?></div><div class="ml">Payable This Month</div><div class="ms">base + bonus − deduction</div></div><div class="mi">🧾</div></div>
  <div class="metric green"><div><div class="mv" style="font-size:19px"><?= money($tPaid) ?></div><div class="ml">Paid</div><div class="ms">payments + advances</div></div><div class="mi">✅</div></div>
  <div class="metric <?= $tDue>0.5?'amber':'teal' ?>"><div><div class="mv" style="font-size:19px"><?= money($tDue) ?></div><div class="ml">Remaining Due</div><div class="ms"><?= e(bs_month_label($m)) ?></div></div><div class="mi">⏳</div></div>
</div>

<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>👥 Salary Sheet — <?= e(date('M Y',strtotime($m.'-01'))) ?> · <?= e(bs_month_label($m)) ?></h2>
    <span class="muted" style="font-size:12px">staff directory is below ⬇ — add &amp; edit employees there</span></div>
  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr>
    <th>Employee</th><th class="right">Base Salary</th><th class="right">Bonus</th><th class="right">Deduction</th><th class="right">Payable</th><th class="right">Advance</th><th class="right">Payment</th><th class="right">Remaining</th><th>Status</th><th></th>
  </tr></thead><tbody>
  <?php foreach($rowsOut as $r): ?>
    <tr>
      <td><b><?= e($r['e']['name']) ?></b><br><small class="muted"><?= e($r['e']['role'] ?: '') ?></small></td>
      <td class="num right"><?= money($r['base']) ?></td>
      <td class="num right" style="color:var(--green)"><?= $r['bon']>0?'+'.money($r['bon']):'—' ?></td>
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
  <?php endforeach; if(!$rowsOut) echo '<tr><td colspan="10"><div class="empty">No staff yet — add employees in <a href="hrm.php">HRM</a> first.</div></td></tr>'; ?>
  </tbody></table></div>
</div>

<?php if($empFilter && $history): ?>
<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>📜 Full History — <?= e($empName[$empFilter] ?? '') ?></h2>
    <a class="btn btn-sm" href="salary.php?m=<?= e($m) ?>">← all staff</a></div>
  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr>
    <th>Month (AD)</th><th>Month (BS)</th><th class="right">Bonus</th><th class="right">Deduction</th><th class="right">Advance</th><th class="right">Payment</th><th class="right">Total Received</th>
  </tr></thead><tbody>
  <?php foreach($history as $h): ?>
    <tr>
      <td><b><?= e(date('M Y',strtotime($h['ym'].'-01'))) ?></b></td>
      <td><?= e(bs_month_label($h['ym'])) ?></td>
      <td class="num right" style="color:var(--green)"><?= money($h['bon']) ?></td>
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
  <div class="panel-head"><h2>🧾 Entries — <?= e(date('M Y',strtotime($m.'-01'))) ?></h2></div>
  <div class="table-wrap"><table class="tbl led-tbl"><thead><tr>
    <th>Date (AD · BS)</th><th>Employee</th><th>Type</th><th style="text-align:right">Amount</th><th>Note</th><?php if($isAdmin): ?><th></th><?php endif; ?>
  </tr></thead><tbody>
  <?php foreach($entries as $en): ?>
    <tr>
      <td><?= e(dual_date($en['entry_date'])) ?></td>
      <td><b><?= e($en['name'] ?: ('#'.$en['employee_id'])) ?></b></td>
      <td><?= e($TYPES[$en['type']] ?? $en['type']) ?></td>
      <td style="text-align:right;font-weight:700;color:<?= $en['type']==='deduction'?'var(--red)':'var(--ink)' ?>"><?= money($en['amount']) ?></td>
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
    <div><label>Type</label><select name="type"><?php foreach($TYPES as $k=>$l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label>Salary Month</label><input type="month" name="ym" value="<?= e($m) ?>"></div>
    <div><label>Amount (Rs.) *</label><input type="number" step="any" min="1" name="amount" required></div>
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

<script>
var BS_LABELS=<?php $lbl=[]; for($i=-60;$i<=30;$i++){$d=date('Y-m-d',strtotime("$i days")); $lbl[$d]=bs_pretty($d);} echo json_encode($lbl); ?>;
function salBs(){var d=document.getElementById('sal_date').value;document.getElementById('sal_bs').textContent=BS_LABELS[d]||'';}
function openSal(eid){
  if(eid)document.getElementById('sal_emp').value=eid;
  document.getElementById('salModal').classList.add('open');document.body.classList.add('modal-open');
}
function closeSal(){document.getElementById('salModal').classList.remove('open');document.body.classList.remove('modal-open');}
(function(){var mm=document.getElementById('salModal');mm.addEventListener('click',function(e){if(e.target===mm)closeSal();});})();
</script>
<?php render_crud('employees','👥 Staff Directory','add & manage employees — base salary set here','embed'); ?>
<?php require __DIR__.'/includes/footer.php'; ?>