<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
ensure_banks();
ensure_expense_bank_columns();
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
$allCats = rows("SELECT DISTINCT category FROM expenses WHERE category IS NOT NULL AND category<>'' ORDER BY category");
$allCats = array_values(array_unique(array_merge($cats, array_column($allCats,'category'))));

/* ===== Expense History: search / filter / sort / paginate (separate from the
   dashboard KPIs above, which always reflect ALL expenses) ===== */
$hq    = trim($_GET['hq'] ?? '');
$hcat  = trim($_GET['hcat'] ?? '');
$hpay  = in_array($_GET['hpay'] ?? '', ['cash','bank'], true) ? $_GET['hpay'] : '';
$hprod = trim($_GET['hprod'] ?? '');
$hcur  = in_array($_GET['hcur'] ?? '', ['Rs','USD'], true) ? $_GET['hcur'] : '';
$hfrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hfrom'] ?? '') ? $_GET['hfrom'] : '';
$hto   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hto']   ?? '') ? $_GET['hto']   : '';
$hsort = $_GET['hsort'] ?? 'date_desc';
$hper  = in_array($_GET['hper'] ?? '25', ['25','50','100','200'], true) ? (int)($_GET['hper'] ?? 25) : 25;
$hpage = max(1, (int)($_GET['hpage'] ?? 1));

$hWhere=[]; $hParams=[];
if ($hq!=='')    { $hWhere[]="(description LIKE ? OR product LIKE ? OR category LIKE ?)"; $like='%'.$hq.'%'; array_push($hParams,$like,$like,$like); }
if ($hcat!=='')  { $hWhere[]="category=?"; $hParams[]=$hcat; }
if ($hpay!=='')  { $hWhere[]="pay_from=?"; $hParams[]=$hpay; }
if ($hprod!=='') { $hWhere[]="product=?"; $hParams[]=$hprod; }
if ($hcur!=='')  { $hWhere[]="currency=?"; $hParams[]=$hcur; }
if ($hfrom!=='') { $hWhere[]="expense_date>=?"; $hParams[]=$hfrom; }
if ($hto!=='')   { $hWhere[]="expense_date<=?"; $hParams[]=$hto; }
$hWhereSql = $hWhere ? ('WHERE '.implode(' AND ',$hWhere)) : '';

$hSortMap = ['date_desc'=>'expense_date DESC, id DESC','date_asc'=>'expense_date ASC, id ASC',
             'amount_desc'=>'amount DESC, id DESC','amount_asc'=>'amount ASC, id DESC'];
$hOrderSql = $hSortMap[$hsort] ?? $hSortMap['date_desc'];

$hCount = (int)val("SELECT COUNT(*) FROM expenses $hWhereSql", $hParams);
$hSum   = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses $hWhereSql", $hParams);
$hPages = max(1, (int)ceil($hCount / $hper));
if ($hpage > $hPages) $hpage = $hPages;
$hOffset = ($hpage-1) * $hper;
$hRows  = rows("SELECT * FROM expenses $hWhereSql ORDER BY $hOrderSql LIMIT $hper OFFSET $hOffset", $hParams);
$hCatCounts = []; foreach($ex as $e){ $hCatCounts[$e['category']] = ($hCatCounts[$e['category']]??0)+1; }
$hHasFilter = ($hq!=='' || $hcat!=='' || $hpay!=='' || $hprod!=='' || $hcur!=='' || $hfrom!=='' || $hto!=='');

/* build a querystring preserving current history filters, overriding given keys */
function hqs($overrides=[]) {
  $base = ['hq'=>$_GET['hq']??'','hcat'=>$_GET['hcat']??'','hpay'=>$_GET['hpay']??'','hprod'=>$_GET['hprod']??'',
           'hcur'=>$_GET['hcur']??'','hfrom'=>$_GET['hfrom']??'','hto'=>$_GET['hto']??'','hsort'=>$_GET['hsort']??'',
           'hper'=>$_GET['hper']??'','hpage'=>$_GET['hpage']??''];
  $merged = array_filter(array_merge($base,$overrides), fn($v)=>$v!=='' && $v!==null);
  return '?'.http_build_query($merged).'#expense-history';
}
?>
<div class="page-head"><div><h1>Expenses</h1><p>Track cash, bank & ad spend</p></div></div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>
<?php /* only flag Salary-category rows NOT linked from a Staff & Salary entry —
   linked ones are auto-synced from there (see salary_expense_sync() in salary.php)
   and are meant to be here; only truly-orphaned manual ones need cleanup. */
$orphanSalaryExp = rows("SELECT e.id,e.amount FROM expenses e WHERE e.category='Salary'
  AND NOT EXISTS (SELECT 1 FROM salary_entries s WHERE s.expense_id=e.id)");
if($orphanSalaryExp): $oldSalaryAmt=array_sum(array_column($orphanSalaryExp,'amount')); ?>
<div class="flash" style="background:var(--amber-bg,#fef3c7);color:#8a5a00">
  ⚠️ <b><?= count($orphanSalaryExp) ?></b> old expense(s) totaling <b><?= money($oldSalaryAmt) ?></b> are categorized "Salary" but aren't linked to any <a href="salary.php" style="color:#8a5a00;font-weight:800;text-decoration:underline">Staff &amp; Salary</a> entry — likely added here manually before that page auto-synced its own. Check whether they duplicate something already in Staff &amp; Salary, and delete them if so.
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
$yesterday=date('Y-m-d',strtotime('-1 day'));
foreach($adEx as $e){
  $p=$e['product']!==''?$e['product']:'(untagged)';
  if(!isset($AD[$p])) $AD[$p]=['mRs'=>0,'mUsd'=>0,'tRs'=>0,'tUsd'=>0];
  $usd=(float)$e['usd']>0?(float)$e['usd']:((float)$e['amount']/max(1,$RATE));
  $AD[$p]['mRs']+=(float)$e['amount']; $AD[$p]['mUsd']+=$usd;
  if($e['expense_date']===$yesterday){ $AD[$p]['tRs']+=(float)$e['amount']; $AD[$p]['tUsd']+=$usd; }
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
    <th>Product</th><th class="right">Yesterday (Rs.)</th><th class="right">Yesterday ($)</th><th class="right">Range (Rs.)</th><th class="right">Range ($)</th><th class="right">Sales (delivered)</th><th class="right">ROAS</th><th class="right">Ads % of Sales</th>
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
  If you're buying stock to sell (like VitiGO from a vendor), record it as a <a href="products.php" style="color:#8a5a00;font-weight:800;text-decoration:underline">Restock</a> instead. That builds the cost into each product automatically, so it's subtracted once as you sell.<br>
  Logging it here <b>as well</b> would subtract the same money twice and can make your <b>Net Profit show a fake loss</b>. Only use this category for stock you are <b>not</b> tracking as a batch.
</div>
<button class="btn btn-primary" style="margin-top:14px;width:100%;justify-content:center">Add Expense</button>
</form></div>
<div class="card" id="expense-history" style="margin-top:18px;scroll-margin-top:18px">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px">
    <h2>🧾 Expense History</h2>
    <span class="muted">· <?= $hCount ?> of <?= count($ex) ?> entries · Total <?= money($hSum) ?><?= $hHasFilter?' (filtered)':'' ?></span>
  </div>

  <form method="get" id="histForm" style="padding:0 18px 12px">
    <input type="hidden" name="am" value="<?= e($_GET['am']??'') ?>">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input class="search-in" type="text" name="hq" value="<?= e($hq) ?>" placeholder="🔍 Search description, category, product…" style="max-width:280px">
      <button class="btn btn-sm" type="submit">Search</button>
      <button type="button" class="btn btn-sm" id="histAdvBtn" onclick="toggleHistAdv()">⚙ Filters<span id="histAdvCount" class="advc" style="display:none">0</span></button>
      <select name="hsort" class="search-in" style="max-width:170px;flex:none" onchange="document.getElementById('histForm').submit()">
        <option value="date_desc"   <?= $hsort==='date_desc'?'selected':'' ?>>Newest first</option>
        <option value="date_asc"    <?= $hsort==='date_asc'?'selected':'' ?>>Oldest first</option>
        <option value="amount_desc" <?= $hsort==='amount_desc'?'selected':'' ?>>Amount: high → low</option>
        <option value="amount_asc"  <?= $hsort==='amount_asc'?'selected':'' ?>>Amount: low → high</option>
      </select>
      <select name="hper" class="search-in" style="max-width:110px;flex:none" onchange="document.getElementById('histForm').submit()">
        <?php foreach([25,50,100,200] as $pp): ?><option value="<?= $pp ?>" <?= $hper===$pp?'selected':'' ?>><?= $pp ?>/page</option><?php endforeach; ?>
      </select>
      <?php if($hHasFilter): ?><a class="btn btn-sm" href="<?= e(hqs(['hq'=>'','hcat'=>'','hpay'=>'','hprod'=>'','hcur'=>'','hfrom'=>'','hto'=>'','hpage'=>''])) ?>">↺ Reset</a><?php endif; ?>
    </div>

    <div class="chips" style="margin-top:10px">
      <a class="chip <?= $hcat===''?'on':'' ?>" href="<?= e(hqs(['hcat'=>'','hpage'=>''])) ?>">All <b><?= count($ex) ?></b></a>
      <?php foreach($allCats as $c): ?>
        <a class="chip <?= $hcat===$c?'on':'' ?>" href="<?= e(hqs(['hcat'=>$c,'hpage'=>''])) ?>"><?= e($c) ?> <b><?= (int)($hCatCounts[$c]??0) ?></b></a>
      <?php endforeach; ?>
    </div>

    <div class="advpanel" id="histAdvPanel" style="display:<?= ($hpay!==''||$hprod!==''||$hcur!==''||$hfrom!==''||$hto!=='')?'block':'none' ?>">
      <div class="advgrid">
        <div class="advf"><label>Paid From</label>
          <select name="hpay" onchange="document.getElementById('histForm').submit()">
            <option value="">Any</option>
            <option value="cash" <?= $hpay==='cash'?'selected':'' ?>>💰 Cash</option>
            <option value="bank" <?= $hpay==='bank'?'selected':'' ?>>🏦 Bank</option>
          </select></div>
        <div class="advf"><label>Product</label>
          <select name="hprod" onchange="document.getElementById('histForm').submit()">
            <option value="">Any</option>
            <?php foreach($products as $p): ?><option value="<?= e($p['name']) ?>" <?= $hprod===$p['name']?'selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="advf"><label>Currency</label>
          <select name="hcur" onchange="document.getElementById('histForm').submit()">
            <option value="">Any</option>
            <option value="Rs" <?= $hcur==='Rs'?'selected':'' ?>>Rs. (NPR)</option>
            <option value="USD" <?= $hcur==='USD'?'selected':'' ?>>USD ($)</option>
          </select></div>
        <div class="advf advf-daterange"><label>Date from → to</label>
          <div class="advrange"><input type="date" name="hfrom" value="<?= e($hfrom) ?>"><span>→</span><input type="date" name="hto" value="<?= e($hto) ?>"></div></div>
      </div>
      <div class="advfoot">
        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
      </div>
    </div>
  </form>

  <div class="table-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Paid From</th><th class="right">Amount</th><th></th></tr></thead><tbody>
<?php foreach($hRows as $e): ?>
<tr><td class="num"><?= e($e['expense_date']) ?></td>
<td><?= e($e['description']) ?><?php if($e['product']) echo ' <span class="pill p-grey">📦 '.e($e['product']).'</span>'; if($e['currency']==='USD') echo ' <span class="pill p-blue">$'.e($e['usd_amount']).'</span>'; ?></td>
<td><span class="pill p-grey"><?= e($e['category']) ?></span></td><td><?= $e['pay_from']==='cash'?'💰 Cash':'🏦 Bank' ?><?php if(!empty($e['account_id'])): $an=(string)val("SELECT name FROM bank_accounts WHERE id=?",[$e['account_id']]); ?> <span class="pill p-blue" style="font-size:9.5px" title="Synced to Bank page — no separate entry needed there">🔗 <?= e($an) ?></span><?php endif; ?></td>
<td class="num right"><b><?= money($e['amount']) ?></b></td>
<td class="right">
  <button class="iact" title="View details" onclick='openViewExp(<?= json_encode($e, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>👁</button>
  <button class="iact" title="Edit" onclick='openEditExp(<?= json_encode($e, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✏️</button>
  <form method="post" style="display:inline" onsubmit="return confirm('Delete this expense?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= $e['id'] ?>"><button class="iact del">🗑</button></form>
</td></tr>
<?php endforeach; if(!$hRows) echo '<tr><td colspan="6"><div class="empty">No expenses match these filters.</div></td></tr>'; ?>
</tbody></table></div>

<?php if($hCount>0): $hShowFrom=$hOffset+1; $hShowTo=min($hCount,$hOffset+$hper); ?>
<div class="pager">
  <span class="pg-info muted">Showing <?= $hShowFrom ?>–<?= $hShowTo ?> of <?= $hCount ?></span>
  <span class="pg-btns">
    <a class="btn btn-sm" href="<?= e(hqs(['hpage'=>1])) ?>" <?= $hpage<=1?'style="pointer-events:none;opacity:.4"':'' ?>>« First</a>
    <a class="btn btn-sm" href="<?= e(hqs(['hpage'=>max(1,$hpage-1)])) ?>" <?= $hpage<=1?'style="pointer-events:none;opacity:.4"':'' ?>>‹ Prev</a>
    <span class="muted" style="padding:0 8px">Page <?= $hpage ?> / <?= $hPages ?></span>
    <a class="btn btn-sm" href="<?= e(hqs(['hpage'=>min($hPages,$hpage+1)])) ?>" <?= $hpage>=$hPages?'style="pointer-events:none;opacity:.4"':'' ?>>Next ›</a>
    <a class="btn btn-sm" href="<?= e(hqs(['hpage'=>$hPages])) ?>" <?= $hpage>=$hPages?'style="pointer-events:none;opacity:.4"':'' ?>>Last »</a>
  </span>
</div>
<?php endif; ?>
</div>

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

<!-- view expense details modal -->
<div class="modal-bg" id="viewExpModal" style="z-index:99990">
  <div class="modal" style="width:460px;max-width:94vw">
    <div class="modal-head"><span>🧾 Expense Details</span><span class="mx" onclick="closeViewExp()">✕</span></div>
    <div style="padding:20px 22px" id="ve_body">
      <div class="dl-row"><span class="dl-k">Date</span><span class="dl-v" id="ve_date"></span></div>
      <div class="dl-row"><span class="dl-k">Amount</span><span class="dl-v" id="ve_amount"></span></div>
      <div class="dl-row"><span class="dl-k">Category</span><span class="dl-v" id="ve_category"></span></div>
      <div class="dl-row"><span class="dl-k">Description</span><span class="dl-v" id="ve_desc"></span></div>
      <div class="dl-row" id="ve_prod_row"><span class="dl-k">Product</span><span class="dl-v" id="ve_prod"></span></div>
      <div class="dl-row"><span class="dl-k">Paid From</span><span class="dl-v" id="ve_payfrom"></span></div>
      <div class="dl-row" id="ve_acct_row"><span class="dl-k">Linked Account</span><span class="dl-v" id="ve_acct"></span></div>
      <div class="dl-row"><span class="dl-k">Expense ID</span><span class="dl-v muted" id="ve_id"></span></div>
    </div>
    <div class="modal-foot">
      <button class="btn" onclick="closeViewExp()">Close</button>
      <button class="btn btn-primary" id="ve_editBtn">✏️ Edit</button>
    </div>
  </div>
</div>
<style>
.dl-row{display:flex;justify-content:space-between;gap:14px;padding:8px 0;border-bottom:1px solid var(--border);font-size:13.5px}
.dl-row:last-child{border-bottom:none}
.dl-k{color:var(--muted);font-weight:700}
.dl-v{text-align:right;font-weight:600}
</style>
<script>
var ACCOUNTS=<?= json_encode(array_column($linkAccounts,'name','id')) ?>;
var VE_CURRENT=null;
function openViewExp(e){
  VE_CURRENT=e;
  document.getElementById('ve_date').textContent=e.expense_date||'';
  var amt = e.currency==='USD' ? ('$'+e.usd_amount+'  ('+fmtRs(e.amount)+')') : fmtRs(e.amount);
  document.getElementById('ve_amount').textContent=amt;
  document.getElementById('ve_category').textContent=e.category||'';
  document.getElementById('ve_desc').textContent=e.description||'—';
  var pr=document.getElementById('ve_prod_row');
  if(e.product){ pr.style.display='flex'; document.getElementById('ve_prod').textContent=e.product; } else { pr.style.display='none'; }
  document.getElementById('ve_payfrom').textContent=e.pay_from==='cash'?'💰 Cash':'🏦 Bank';
  var ar=document.getElementById('ve_acct_row');
  if(e.account_id && ACCOUNTS[e.account_id]){ ar.style.display='flex'; document.getElementById('ve_acct').textContent='🔗 '+ACCOUNTS[e.account_id]; } else { ar.style.display='none'; }
  document.getElementById('ve_id').textContent='#'+e.id;
  document.getElementById('viewExpModal').classList.add('open');
}
function fmtRs(n){ return '<?= addslashes(CURRENCY) ?> '+Number(parseFloat(n)||0).toLocaleString('en-IN',{maximumFractionDigits:0}); }
function closeViewExp(){ document.getElementById('viewExpModal').classList.remove('open'); }
document.getElementById('viewExpModal').addEventListener('click',function(ev){ if(ev.target===this) closeViewExp(); });
document.getElementById('ve_editBtn').addEventListener('click',function(){ if(VE_CURRENT){ closeViewExp(); openEditExp(VE_CURRENT); } });
function toggleHistAdv(){
  var p=document.getElementById('histAdvPanel');
  p.style.display = (p.style.display==='none'||!p.style.display) ? 'block' : 'none';
}
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