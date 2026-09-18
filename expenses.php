<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
ensure_banks();
try { q("ALTER TABLE expenses ADD COLUMN IF NOT EXISTS account_id INT NULL"); } catch (Exception $e) {}
try { q("ALTER TABLE expenses ADD COLUMN IF NOT EXISTS bank_txn_id INT NULL"); } catch (Exception $e) {}
/* create/refresh/remove the linked bank transaction for one expense — same proven
   pattern as cod_bank_sync() in cod.php, so paying an expense from a bank account
   never needs entering the money-out a second time on the Bank page. */
if (!function_exists('expense_bank_sync')) {
function expense_bank_sync(array $e, ?int $accountId): ?int {
  $old=(int)($e['bank_txn_id'] ?? 0);
  if(!$accountId){ if($old) q("DELETE FROM bank_txns WHERE id=?",[$old]); return null; }
  $cat = 'Expense — '.($e['category']??'Other');
  $rem = trim((string)($e['description']??''));
  if($old){
    q("UPDATE bank_txns SET account_id=?, txn_date=?, direction='out', amount=?, category=?, remarks=? WHERE id=?",
      [$accountId,$e['expense_date'],(float)$e['amount'],$cat,$rem,$old]);
    return $old;
  }
  q("INSERT INTO bank_txns(account_id,txn_date,direction,amount,category,remarks) VALUES(?,?,'out',?,?,?)",
    [$accountId,$e['expense_date'],(float)$e['amount'],$cat,$rem]);
  return (int)db()->lastInsertId();
}
}
/* keep the auto-billed "due" entry (created by ads_autobill on add) in sync when an
   expense is edited — updates it if still an Ads expense, removes it if the category
   changed away from Ads, creates it if the category changed TO Ads. Never touches
   any 'paid' entries for the vendor, since those aren't tied to one specific expense. */
if (!function_exists('ads_autobill_sync')) {
function ads_autobill_sync($expenseId, $isAdsCategory, $date, $amountRs, $product, $usd=null){
  $existingDue = row("SELECT id FROM payee_ledger WHERE ref_expense_id=? AND type='due'",[$expenseId]);
  if (!$isAdsCategory) {
    if ($existingDue) q("DELETE FROM payee_ledger WHERE id=?",[(int)$existingDue['id']]);
    return;
  }
  $pid=(int)setting('ads_payee_id',0); if(!$pid) return;
  ensure_payees();
  $label='Ads'.($product?' — '.$product:'').($usd? ' ($'.rtrim(rtrim(number_format((float)$usd,2),'0'),'.').')' : '');
  if ($existingDue) {
    q("UPDATE payee_ledger SET entry_date=?, amount=?, label=? WHERE id=?",[$date,(float)$amountRs,$label,(int)$existingDue['id']]);
  } else {
    q("INSERT INTO payee_ledger(payee_id,entry_date,type,amount,label,ref_expense_id) VALUES(?,?,?,?,?,?)",
      [$pid,$date,'due',(float)$amountRs,$label,(int)$expenseId]);
  }
}
}
$RATE=(float)setting('usd_rate', USD_RATE);
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf(); $a=$_POST['_action']??'';
  if ($a==='delete') {
    try {
      ensure_payees();
      try { q("DELETE FROM payee_ledger WHERE ref_expense_id=?",[(int)$_POST['id']]); } catch (Exception $e2) {}
      $tid=(int)val("SELECT COALESCE(bank_txn_id,0) FROM expenses WHERE id=?",[(int)$_POST['id']]);
      if ($tid) { try { q("DELETE FROM bank_txns WHERE id=?",[$tid]); } catch (Exception $e3) {} }
      q("DELETE FROM expenses WHERE id=?", [(int)$_POST['id']]); flash('Expense deleted.'); }
    catch (Exception $ex) { flash('Error: '.$ex->getMessage()); }
  } elseif ($a==='edit') {
    $id=(int)($_POST['id']??0);
    $cur=$_POST['currency']==='USD'?'USD':'Rs';
    $amt=(float)($_POST['amount']??0); $usd=null; $rs=$amt;
    if ($cur==='USD') {
      if (trim($_POST['description']??'')==='') { flash('Description is required for USD expenses.'); header('Location: expenses.php'); exit; }
      $usd=$amt; $rs=round($amt*$RATE);
    }
    if ($amt<=0) { flash('Enter an amount greater than 0.'); header('Location: expenses.php'); exit; }
    try {
      $category = $_POST['category'] ?? 'Other';
      $product  = ($_POST['product']??'')?:null;
      $expDate  = $_POST['expense_date']?:date('Y-m-d');
      q("UPDATE expenses SET expense_date=?, description=?, amount=?, currency=?, usd_amount=?, pay_from=?, category=?, product=? WHERE id=?",
        [$expDate, trim($_POST['description']??'')?:($category.' expense'), $rs, $cur, $usd, $_POST['pay_from']??'cash', $category, $product, $id]);

      $acc=(int)($_POST['account_id'] ?? 0) ?: null;
      $updatedExp = row("SELECT * FROM expenses WHERE id=?",[$id]);
      $tid = expense_bank_sync($updatedExp, $acc);
      q("UPDATE expenses SET account_id=?, bank_txn_id=? WHERE id=?",[$acc,$tid,$id]);

      ads_autobill_sync($id, strcasecmp(trim($category),'Ads')===0, $expDate, $rs, trim($product ?? ''), $usd);

      log_activity('Edited expense #'.$id,'Expenses'); flash('Expense updated.');
    } catch (Exception $ex) { flash('Error saving expense: '.$ex->getMessage()); }
  } else {
    $cur=$_POST['currency']==='USD'?'USD':'Rs';
    $amt=(float)($_POST['amount']??0); $usd=null; $rs=$amt;
    if ($cur==='USD') {
      if (trim($_POST['description']??'')==='') { flash('Description is required for USD expenses.'); header('Location: expenses.php'); exit; }
      $usd=$amt; $rs=round($amt*$RATE);
    }
    if ($amt<=0) { flash('Enter an amount greater than 0.'); header('Location: expenses.php'); exit; }
    try {
      $category = $_POST['category'] ?? 'Other';
      $product  = ($_POST['product']??'')?:null;
      $expDate  = $_POST['expense_date']?:date('Y-m-d');
      q("INSERT INTO expenses(expense_date,description,amount,currency,usd_amount,pay_from,category,product) VALUES(?,?,?,?,?,?,?,?)",
        [$expDate, trim($_POST['description']??'')?:($category.' expense'),
         $rs, $cur, $usd, $_POST['pay_from']??'cash', $category, $product]);
      $newExpId=(int)val("SELECT LAST_INSERT_ID()");
      $acc=(int)($_POST['account_id'] ?? 0) ?: null;
      if ($acc) {
        $newExp = row("SELECT * FROM expenses WHERE id=?",[$newExpId]);
        $tid = expense_bank_sync($newExp, $acc);
        q("UPDATE expenses SET account_id=?, bank_txn_id=? WHERE id=?",[$acc,$tid,$newExpId]);
      }
      ads_autobill_sync($newExpId, strcasecmp(trim($category),'Ads')===0, $expDate, $rs, trim($product ?? ''), $usd);
      log_activity('Added expense','Expenses'); flash('Expense added.');
    } catch (Exception $ex) { flash('Error saving expense: '.$ex->getMessage()); }
  }
  header('Location: expenses.php'); exit;
}
$PAGE_TITLE='Expenses'; require __DIR__.'/includes/header.php';
$ex=rows("SELECT * FROM expenses ORDER BY expense_date DESC, id DESC");
$products=rows("SELECT name FROM products ORDER BY name");
$total=array_sum(array_column($ex,'amount'));
$cash=array_sum(array_map(fn($e)=>$e['pay_from']==='cash'?$e['amount']:0,$ex));
$bank=$total-$cash;
$ads=array_sum(array_map(fn($e)=>$e['category']==='Ads'?$e['amount']:0,$ex));
$thisMonth=array_sum(array_map(fn($e)=>substr($e['expense_date'],0,7)===date('Y-m')?$e['amount']:0,$ex));
$openCash=(float)setting('opening_cash',0); $openBank=(float)setting('opening_bank',0);
$linkAccounts = rows("SELECT * FROM bank_accounts WHERE archived=0 ORDER BY kind='cash' DESC, name");
$acctBal = [];
foreach($linkAccounts as $A){
  $in =(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='in'",[$A['id']]);
  $out=(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='out'",[$A['id']]);
  $acctBal[$A['id']]=(float)$A['opening']+$in-$out;
}
$cashBal=$openCash-$cash; $bankBal=$openBank-$bank;
$byCat=[]; foreach($ex as $e){ $byCat[$e['category']]=($byCat[$e['category']]??0)+$e['amount']; } arsort($byCat);
$catMax=$byCat?max($byCat):1;
$adsByProd=[]; foreach($ex as $e){ if($e['category']==='Ads'&&$e['product']) $adsByProd[$e['product']]=($adsByProd[$e['product']]??0)+$e['amount']; } arsort($adsByProd);
$cats=['Ads','Office','Rent','Utilities','Delivery','Other','Stock Purchase'];
?>
<div class="page-head"><div><h1>Expenses</h1><p>Track cash, bank & ad spend</p></div></div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>
<?php $oldSalaryExp = (int)val("SELECT COUNT(*) FROM expenses WHERE category='Salary'");
if($oldSalaryExp>0): $oldSalaryAmt=(float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE category='Salary'"); ?>
<div class="flash" style="background:var(--amber-bg,#fef3c7);color:#8a5a00">
  ⚠️ <b><?= $oldSalaryExp ?></b> old expense(s) totaling <b><?= money($oldSalaryAmt) ?></b> are still categorized "Salary" — that category has been removed since <a href="salary.php" style="color:#8a5a00;font-weight:800;text-decoration:underline">Staff &amp; Salary</a> is now the one place for this, and having both was double-counting on the Money Dashboard. These old entries are excluded from dashboard totals, but consider deleting them if they duplicate something already in Staff &amp; Salary.
</div>
<?php endif; ?>
<div class="kgrid">
<div class="kc"><div class="kl">Total Expenses</div><div class="kv num" style="color:var(--red)"><?= money($total) ?></div><div class="ks">cash + bank</div></div>
<div class="kc"><div class="kl">This Month</div><div class="kv num" style="color:var(--amber)"><?= money($thisMonth) ?></div></div>
<div class="kc"><div class="kl">Total Ads Spent</div><div class="kv num" style="color:var(--amber)"><?= money($ads) ?></div></div>
<div class="kc"><div class="kl">Cash Balance</div><div class="kv num"><?= money($cashBal) ?></div><div class="ks">Bank: <?= money($bankBal) ?></div></div>
</div>
<div class="grid cols-2" style="margin-top:18px">
<div class="panel"><div class="panel-head"><h2>By Category</h2></div><div class="panel-body">
<?php foreach($byCat as $c=>$v): ?>
<div class="cat-row"><div class="cat-name"><?= e($c) ?></div><div class="cat-track"><div class="cat-fill" style="width:<?= max(3,round($v/$catMax*100)) ?>%;background:var(--brand)"></div></div><div class="cat-amt num"><?= money($v) ?></div></div>
<?php endforeach; if(!$byCat) echo '<div class="muted">No expenses yet.</div>'; ?>
</div></div>
<div class="panel"><div class="panel-head"><h2>📣 Ads Spend by Product</h2></div><div class="panel-body">
<?php if($adsByProd): $am=max($adsByProd); foreach($adsByProd as $p=>$v): ?>
<div class="cat-row"><div class="cat-name"><?= e($p) ?></div><div class="cat-track"><div class="cat-fill" style="width:<?= max(3,round($v/$am*100)) ?>%;background:#ef4444"></div></div><div class="cat-amt num"><?= money($v) ?></div></div>
<?php endforeach; else: ?><div class="muted">No product-wise ad spend yet. Add an Ads expense and pick a product.</div><?php endif; ?>
</div></div>
</div>

<!-- ===== Advertising dashboard: daily + monthly per product, Rs + $, vs sales ===== -->
<?php
/* accepts either a from/to date range (new) or falls back to the current month (old links still work) */
$am2 = preg_match('/^\d{4}-\d{2}$/', $_GET['am'] ?? '') ? $_GET['am'] : date('Y-m');
$amFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $am2.'-01';
$amTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-t',strtotime($amFrom));
if ($amFrom > $amTo) { $t=$amFrom; $amFrom=$amTo; $amTo=$t; }
$amS=$amFrom; $amE=$amTo;
$prodFilter = trim((string)($_GET['prod'] ?? ''));
$adExSql = "SELECT expense_date, amount, COALESCE(usd_amount,0) usd, COALESCE(product,'') product
            FROM expenses WHERE category='Ads' AND expense_date BETWEEN ? AND ?";
$adExParams = [$amS,$amE];
if ($prodFilter !== '') { $adExSql .= " AND product=?"; $adExParams[] = $prodFilter; }
$adEx = rows($adExSql, $adExParams);
$AD=[];   /* product => [mRs,mUsd,tRs,tUsd] */
$today=date('Y-m-d');
foreach($adEx as $e){
  $p=$e['product']!==''?$e['product']:'(untagged)';
  if(!isset($AD[$p])) $AD[$p]=['mRs'=>0,'mUsd'=>0,'tRs'=>0,'tUsd'=>0];
  $usd=(float)$e['usd']>0?(float)$e['usd']:((float)$e['amount']/max(1,$RATE));
  $AD[$p]['mRs']+=(float)$e['amount']; $AD[$p]['mUsd']+=$usd;
  if($e['expense_date']===$today){ $AD[$p]['tRs']+=(float)$e['amount']; $AD[$p]['tUsd']+=$usd; }
}
/* delivered revenue per product in the same range */
$REV=[];
foreach(rows("SELECT p.name, SUM(o.sell_price*o.qty) rev FROM orders o JOIN products p ON p.id=o.product_id
              WHERE o.status='delivered' AND o.order_date BETWEEN ? AND ? GROUP BY p.name",[$amS,$amE]) as $r)
  $REV[$r['name']]=(float)$r['rev'];
uasort($AD, fn($a,$b)=>$b['mRs']<=>$a['mRs']);
$sumRs=0;$sumUsd=0;$sumRev=0;
$rangeLabel = ($amS===$amE) ? date('d M Y',strtotime($amS)) : date('d M Y',strtotime($amS)).' – '.date('d M Y',strtotime($amE));
?>
<div class="panel" style="margin-top:18px">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px">
    <h2>📊 Advertising Dashboard — <?= e($rangeLabel) ?></h2>
    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="date" name="from" value="<?= e($amFrom) ?>" style="border:1px solid var(--border);border-radius:8px;padding:6px 10px;font-size:12px">
      <span class="muted">→</span>
      <input type="date" name="to" value="<?= e($amTo) ?>" style="border:1px solid var(--border);border-radius:8px;padding:6px 10px;font-size:12px">
      <select name="prod" style="border:1px solid var(--border);border-radius:8px;padding:6px 10px;font-size:12px">
        <option value="">All products</option>
        <?php foreach($products as $p): ?><option value="<?= e($p['name']) ?>" <?= $prodFilter===$p['name']?'selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm">Filter</button>
      <span class="muted" style="font-size:11.5px">· $1 = Rs. <?= e(number_format($RATE)) ?> when $ not recorded</span>
    </form>
  </div>

  <?php if($prodFilter!==''): $pTotUsd=array_sum(array_column($AD,'mUsd')); $pTotRs=array_sum(array_column($AD,'mRs')); ?>
  <div style="margin:0 18px 14px;background:linear-gradient(120deg,#0369a1,#0c4a6e);border-radius:14px;padding:16px 20px;color:#fff">
    <div style="font-size:10px;opacity:.8;font-weight:800;text-transform:uppercase">Ad Spend on <?= e($prodFilter) ?> · <?= e($rangeLabel) ?></div>
    <div style="font-size:26px;font-weight:900;margin-top:4px">$<?= number_format($pTotUsd,2) ?><span style="font-size:14px;font-weight:600;opacity:.85;margin-left:10px"><?= money($pTotRs) ?></span></div>
  </div>
  <?php endif; ?>

  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr>
    <th>Product</th><th class="right">Today (Rs.)</th><th class="right">Today ($)</th><th class="right">Range (Rs.)</th><th class="right">Range ($)</th><th class="right">Sales (delivered)</th><th class="right">ROAS</th><th class="right">Ads % of Sales</th>
  </tr></thead><tbody>
  <?php foreach($AD as $p=>$v): $rev=$REV[$p]??0; $roas=$v['mRs']>0?$rev/$v['mRs']:0; $pct=$rev>0?$v['mRs']/$rev*100:0;
    $sumRs+=$v['mRs'];$sumUsd+=$v['mUsd'];$sumRev+=$rev; ?>
    <tr>
      <td><b><?= e($p) ?></b></td>
      <td class="num right"><?= $v['tRs']>0?money($v['tRs']):'—' ?></td>
      <td class="num right muted"><?= $v['tUsd']>0?'$'.number_format($v['tUsd'],2):'—' ?></td>
      <td class="num right" style="color:var(--red);font-weight:700"><?= money($v['mRs']) ?></td>
      <td class="num right muted">$<?= number_format($v['mUsd'],2) ?></td>
      <td class="num right"><?= $rev>0?money($rev):'<span class="muted">—</span>' ?></td>
      <td class="num right" style="font-weight:800;color:<?= $roas>=2?'var(--green)':($roas>=1?'var(--amber)':'var(--red)') ?>"><?= $v['mRs']>0&&$rev>0?number_format($roas,2).'×':'—' ?></td>
      <td class="num right"><?= $rev>0?number_format($pct,1).'%':'—' ?></td>
    </tr>
  <?php endforeach; if(!$AD) echo '<tr><td colspan="8"><div class="empty">No ad spend recorded in this range.</div></td></tr>'; ?>
  <?php if($AD): ?>
    <tr style="border-top:2px solid var(--border);background:rgba(59,130,246,.06)">
      <td><b>Total</b></td><td></td><td></td>
      <td class="num right" style="font-weight:800;color:var(--red)"><?= money($sumRs) ?></td>
      <td class="num right" style="font-weight:700">$<?= number_format($sumUsd,2) ?></td>
      <td class="num right" style="font-weight:800"><?= money($sumRev) ?></td>
      <td class="num right" style="font-weight:800"><?= $sumRs>0&&$sumRev>0?number_format($sumRev/$sumRs,2).'×':'—' ?></td>
      <td class="num right"><?= $sumRev>0?number_format($sumRs/$sumRev*100,1).'%':'—' ?></td>
    </tr>
  <?php endif; ?>
  </tbody></table></div>
  <p class="muted" style="font-size:11.5px;padding:0 18px 14px">ROAS = delivered sales ÷ ad spend. Tag every Ads expense to a product for accurate rows — untagged spend appears as "(untagged)".</p>
</div>
<div class="card" style="margin-top:18px"><div class="panel-head"><h2>➕ Add Expense</h2></div><form method="post" style="padding:18px">
<input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="add">
<div class="form-grid">
<div><label>Date</label><input name="expense_date" type="date" value="<?= date('Y-m-d') ?>"></div>
<div><label id="amtLbl">Amount (Rs.)</label><input name="amount" type="number" step="any" value="0"></div>
<div><label>Currency</label><select name="currency" onchange="document.getElementById('amtLbl').textContent=this.value==='USD'?'Amount ($)':'Amount (Rs.)'"><option value="Rs">Rs. (NPR)</option><option value="USD">USD ($)</option></select></div>
<div><label>Pay From</label><select name="pay_from"><option value="cash">💰 Cash</option><option value="bank">🏦 Bank</option></select></div>
<div><label>Account (Bank page) — leave as "not linked" for cash you don't track there</label>
  <select name="account_id">
    <option value="0">⏳ — not linked —</option>
    <?php foreach($linkAccounts as $A): ?>
      <option value="<?= (int)$A['id'] ?>"><?= $A['kind']==='cash'?'💵':'🏦' ?> <?= e($A['name']) ?> — <?= money($acctBal[$A['id']] ?? 0) ?></option>
    <?php endforeach; ?>
  </select></div>
<div><label>Category</label><select name="category" id="expCat" onchange="expCatChange()"><?php foreach($cats as $c) echo '<option'.($c==='Stock Purchase'?' data-warn="1"':'').'>'.e($c).'</option>'; ?></select></div>
<div><label>Product (for ads)</label><select name="product"><option value="">— None —</option><?php foreach($products as $p) echo '<option>'.e($p['name']).'</option>'; ?></select></div>
<div class="full" style="grid-column:span 2"><label>Description (required for USD $)</label><input name="description" placeholder="What was this expense for?"></div>
</div>
<div id="stockWarn" style="display:none;margin-top:12px;background:var(--amber-bg,#fef3c7);border:1px solid #f0c040;color:#8a5a00;border-radius:12px;padding:12px 14px;font-size:12.5px;line-height:1.6">
  ⚠️ <b>Heads up — this can double-count your cost.</b><br>
  If you're buying stock to sell (like VitiGO from a vendor), record it in <a href="purchases.php" style="color:#8a5a00;font-weight:800;text-decoration:underline">Purchasing / Restock</a> instead. That builds the cost into each product automatically, so it's subtracted once as you sell.<br>
  Logging it here <b>as well</b> would subtract the same money twice and can make your <b>Net Profit show a fake loss</b>. Only use this category for stock you are <b>not</b> tracking as a batch.
</div>
<button class="btn btn-primary" style="margin-top:14px;width:100%;justify-content:center">Add Expense</button>
</form></div>
<div class="card" style="margin-top:18px"><div class="panel-head"><h2>All Expenses</h2><span class="muted">· <?= count($ex) ?> entries · Total <?= money($total) ?></span></div>
<div class="table-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Paid From</th><th class="right">Amount</th><th></th></tr></thead><tbody>
<?php foreach($ex as $e): ?>
<tr><td class="num"><?= e($e['expense_date']) ?></td>
<td><?= e($e['description']) ?><?php if($e['product']) echo ' <span class="pill p-grey">📦 '.e($e['product']).'</span>'; if($e['currency']==='USD') echo ' <span class="pill p-blue">$'.e($e['usd_amount']).'</span>'; ?></td>
<td><span class="pill p-grey"><?= e($e['category']) ?></span></td><td><?= $e['pay_from']==='cash'?'💰 Cash':'🏦 Bank' ?><?php if(!empty($e['account_id'])): $an=(string)val("SELECT name FROM bank_accounts WHERE id=?",[$e['account_id']]); ?> <span class="pill p-blue" style="font-size:9.5px" title="Synced to Bank page — no separate entry needed there">🔗 <?= e($an) ?></span><?php endif; ?></td>
<td class="num right"><b><?= money($e['amount']) ?></b></td>
<td class="right">
  <button class="iact" title="Edit" onclick='openEditExp(<?= json_encode($e, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✏️</button>
  <form method="post" style="display:inline" onsubmit="return confirm('Delete this expense?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= $e['id'] ?>"><button class="iact del">🗑</button></form>
</td></tr>
<?php endforeach; if(!$ex) echo '<tr><td colspan="6"><div class="empty">No expenses yet.</div></td></tr>'; ?>
</tbody></table></div></div>

<!-- edit expense modal -->
<div class="modal-bg" id="editExpModal" style="z-index:99990">
  <div class="modal" style="width:520px;max-width:94vw">
    <div class="modal-head"><span>✏️ Edit Expense</span><span class="mx" onclick="closeEditExp()">✕</span></div>
    <form method="post" style="padding:18px 22px" class="form-grid">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="edit"><input type="hidden" name="id" id="ee_id">
      <div><label>Date</label><input name="expense_date" id="ee_date" type="date"></div>
      <div><label id="ee_amtLbl">Amount (Rs.)</label><input name="amount" id="ee_amount" type="number" step="any"></div>
      <div><label>Currency</label><select name="currency" id="ee_currency" onchange="document.getElementById('ee_amtLbl').textContent=this.value==='USD'?'Amount ($)':'Amount (Rs.)'"><option value="Rs">Rs. (NPR)</option><option value="USD">USD ($)</option></select></div>
      <div><label>Pay From</label><select name="pay_from" id="ee_payfrom"><option value="cash">💰 Cash</option><option value="bank">🏦 Bank</option></select></div>
      <div><label>Account (Bank page)</label>
        <select name="account_id" id="ee_account">
          <option value="0">⏳ — not linked —</option>
          <?php foreach($linkAccounts as $A): ?>
            <option value="<?= (int)$A['id'] ?>"><?= $A['kind']==='cash'?'💵':'🏦' ?> <?= e($A['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Category</label><select name="category" id="ee_category"><?php foreach($cats as $c) echo '<option>'.e($c).'</option>'; ?></select></div>
      <div><label>Product (for ads)</label><select name="product" id="ee_product"><option value="">— None —</option><?php foreach($products as $p) echo '<option>'.e($p['name']).'</option>'; ?></select></div>
      <div class="full" style="grid-column:span 2"><label>Description</label><input name="description" id="ee_desc"></div>
      <div class="full" style="display:flex;justify-content:flex-end;gap:10px;margin-top:4px">
        <button type="button" class="btn" onclick="closeEditExp()">Cancel</button>
        <button class="btn btn-primary">💾 Save Changes</button>
      </div>
    </form>
  </div>
</div>
<script>
function openEditExp(e){
  document.getElementById('ee_id').value=e.id;
  document.getElementById('ee_date').value=e.expense_date;
  var amt = e.currency==='USD' ? (e.usd_amount||0) : e.amount;
  document.getElementById('ee_amount').value=amt;
  document.getElementById('ee_currency').value=e.currency||'Rs';
  document.getElementById('ee_amtLbl').textContent = e.currency==='USD' ? 'Amount ($)' : 'Amount (Rs.)';
  document.getElementById('ee_payfrom').value=e.pay_from||'cash';
  document.getElementById('ee_account').value=e.account_id||0;
  document.getElementById('ee_category').value=e.category||'Other';
  document.getElementById('ee_product').value=e.product||'';
  document.getElementById('ee_desc').value=e.description||'';
  document.getElementById('editExpModal').classList.add('open');
}
function closeEditExp(){ document.getElementById('editExpModal').classList.remove('open'); }
document.getElementById('editExpModal').addEventListener('click',function(ev){ if(ev.target===this) closeEditExp(); });
</script>

<script>
function expCatChange(){
  var sel=document.getElementById('expCat');
  var opt=sel && sel.options[sel.selectedIndex];
  var warn=document.getElementById('stockWarn');
  if(warn) warn.style.display = (opt && opt.getAttribute('data-warn')==='1') ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded',expCatChange);
</script>

<?php require __DIR__.'/includes/footer.php'; ?>