<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
$PAGE_TITLE='Ads & Vendors';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;

/* tables */
ensure_payees();
ensure_banks();
try { q("ALTER TABLE payee_ledger ADD COLUMN IF NOT EXISTS account_id INT NULL"); } catch (Exception $e) {}
try { q("ALTER TABLE payee_ledger ADD COLUMN IF NOT EXISTS bank_txn_id INT NULL"); } catch (Exception $e) {}

/* same proven pattern as expense_bank_sync()/salary_bank_sync() — only 'paid' entries
   are real cash leaving a bank account; 'due' just records a liability owed, no sync. */
function payee_bank_sync(array $entry, ?int $accountId): ?int {
  $old=(int)($entry['bank_txn_id'] ?? 0);
  if(!$accountId || $entry['type']!=='paid'){ if($old) q("DELETE FROM bank_txns WHERE id=?",[$old]); return null; }
  $pName = (string)val("SELECT name FROM payees WHERE id=?",[$entry['payee_id']]);
  $cat = 'Supplier Payment';
  $rem = trim(($pName?:'Payee').(($entry['label']??'')!==''?' — '.$entry['label']:''));
  if($old){
    q("UPDATE bank_txns SET account_id=?, txn_date=?, direction='out', amount=?, category=?, remarks=? WHERE id=?",
      [$accountId,$entry['entry_date'],(float)$entry['amount'],$cat,$rem,$old]);
    return $old;
  }
  q("INSERT INTO bank_txns(account_id,txn_date,direction,amount,category,remarks) VALUES(?,?,'out',?,?,?)",
    [$accountId,$entry['entry_date'],(float)$entry['amount'],$cat,$rem]);
  return (int)db()->lastInsertId();
}

/* actions */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act=$_POST['_action'] ?? '';
  if ($act==='add_payee') {
    $n=trim($_POST['name'] ?? '');
    if ($n!=='') { q("INSERT INTO payees(name,note) VALUES(?,?)",[$n,trim($_POST['note'] ?? '')]); flash('Payee added.'); }
    header('Location: payees.php'); exit;
  }
  if ($act==='add_entry') {
    $pid=(int)($_POST['payee_id'] ?? 0); $amt=(float)($_POST['amount'] ?? 0);
    $type=($_POST['type'] ?? '')==='due' ? 'due' : 'paid';
    if ($pid && $amt>0) {
      q("INSERT INTO payee_ledger(payee_id,entry_date,type,amount,label) VALUES(?,?,?,?,?)",
        [$pid, ($_POST['entry_date'] ?? '') ?: date('Y-m-d'), $type, $amt, trim($_POST['label'] ?? '')]);
      $newId=(int)db()->lastInsertId();
      $acc=(int)($_POST['account_id'] ?? 0) ?: null;
      if ($acc) {
        $entry = row("SELECT * FROM payee_ledger WHERE id=?",[$newId]);
        $tid = payee_bank_sync($entry, $acc);
        if ($tid) q("UPDATE payee_ledger SET account_id=?, bank_txn_id=? WHERE id=?",[$acc,$tid,$newId]);
      }
      log_activity(($type==='paid'?'Payment':'Due')." ".money($amt)." → payee #$pid",'Payees');
      flash(($type==='paid'?'💸 Payment':'🧾 Due')." of ".money($amt)." recorded.");
    } else flash('Pick a payee and enter an amount.');
    header('Location: payees.php'.($pid?'?id='.$pid:'')); exit;
  }
  if ($act==='del_entry' && $isAdmin) {
    $tid=(int)val("SELECT COALESCE(bank_txn_id,0) FROM payee_ledger WHERE id=?",[(int)$_POST['id']]);
    if ($tid) { try { q("DELETE FROM bank_txns WHERE id=?",[$tid]); } catch (Exception $e) {} }
    q("DELETE FROM payee_ledger WHERE id=?",[(int)$_POST['id']]); flash('Entry deleted.');
    header('Location: payees.php'.(isset($_POST['pid'])?'?id='.(int)$_POST['pid']:'')); exit;
  }
  if ($act==='set_ads_payee' && $isAdmin) {
    set_setting('ads_payee_id',(int)$_POST['id']);
    flash('⚡ New Ads expenses will now auto-bill to this payee.');
    header('Location: payees.php'); exit;
  }
  if ($act==='backfill_ads' && $isAdmin) {
    $pid=(int)setting('ads_payee_id',0);
    if(!$pid) flash('Set an Ads payee first (⚡ button on their row).');
    else {
      $n=0;
      foreach(rows("SELECT e.* FROM expenses e WHERE e.category='Ads'
                    AND NOT EXISTS(SELECT 1 FROM payee_ledger pl WHERE pl.ref_expense_id=e.id)") as $e){
        q("INSERT INTO payee_ledger(payee_id,entry_date,type,amount,label,ref_expense_id) VALUES(?,?,?,?,?,?)",
          [$pid,$e['expense_date'],'due',(float)$e['amount'],'Ads'.($e['product']?' — '.$e['product']:''),(int)$e['id']]);
        $n++;
      }
      log_activity("Backfilled $n ads expenses as dues",'Payees');
      flash("⚡ Imported $n past Ads expenses as dues. Running again will not duplicate.");
    }
    header('Location: payees.php'); exit;
  }
  if ($act==='repair_ads' && $isAdmin) {
    /* re-sync every ads due to its expense's real rupee amount (fixes dues that were
       stored in dollars by the old bug), and append a ($xx) note when the expense was in USD */
    $fixed=0;
    foreach(rows("SELECT pl.id AS lid, pl.amount AS lamt, pl.label AS llabel,
                         e.amount AS ramt, e.currency AS cur, e.usd_amount AS usd, e.product AS product
                  FROM payee_ledger pl JOIN expenses e ON e.id=pl.ref_expense_id
                  WHERE pl.type='due' AND e.category='Ads'") as $r){
      $correct=(float)$r['ramt'];
      $label='Ads'.($r['product']?' — '.$r['product']:'').(($r['cur']==='USD'&&$r['usd'])? ' ($'.rtrim(rtrim(number_format((float)$r['usd'],2),'0'),'.').')':'');
      if (abs((float)$r['lamt']-$correct) > 0.01 || $r['llabel']!==$label) {
        q("UPDATE payee_ledger SET amount=?, label=? WHERE id=?",[$correct,$label,(int)$r['lid']]);
        $fixed++;
      }
    }
    log_activity("Repaired $fixed ads dues to Rs values",'Payees');
    flash($fixed? "🔧 Fixed $fixed Ads due(s) — now shown in Rs (converted from \$)." : "✓ All Ads dues already correct.");
    header('Location: payees.php'); exit;
  }
  if ($act==='del_payee' && $isAdmin) {
    $pid=(int)$_POST['id'];
    q("DELETE FROM payee_ledger WHERE payee_id=?",[$pid]); q("DELETE FROM payees WHERE id=?",[$pid]);
    flash('Payee and their ledger removed.'); header('Location: payees.php'); exit;
  }
}

$mStart=date('Y-m-01');
$payees = rows("SELECT p.*, 
  COALESCE(SUM(CASE WHEN l.type='due' THEN l.amount END),0) tdue,
  COALESCE(SUM(CASE WHEN l.type='paid' THEN l.amount END),0) tpaid,
  COALESCE(SUM(CASE WHEN l.type='paid' AND l.entry_date>=? THEN l.amount END),0) mpaid,
  MAX(CASE WHEN l.type='paid' THEN l.entry_date END) lastpay,
  COUNT(l.id) nentries
  FROM payees p LEFT JOIN payee_ledger l ON l.payee_id=p.id
  GROUP BY p.id ORDER BY p.name",[$mStart]);
usort($payees, function($a,$b){
  $ra=(float)$a['tdue']-(float)$a['tpaid']; $rb=(float)$b['tdue']-(float)$b['tpaid'];
  $ta=$ra>0.5?0:($ra<-0.5?1:2);  /* 0 owing you money out · 1 advance parked · 2 settled */
  $tb=$rb>0.5?0:($rb<-0.5?1:2);
  if($ta!==$tb) return $ta<=>$tb;
  if(abs(abs($ra)-abs($rb))>0.5) return abs($rb)<=>abs($ra);
  return strcmp($a['name'],$b['name']);
});
$grandPaid=0; $grandRemain=0; $cntDue=0; $cntAdv=0; $cntSettled=0;
foreach($payees as $p){
  $rem=(float)$p['tdue']-(float)$p['tpaid'];
  $grandPaid+=(float)$p['tpaid']; $grandRemain+=max(0,$rem);
  if($rem>0.5)$cntDue++; elseif($rem<-0.5)$cntAdv++; else $cntSettled++;
}
$tMonthPaid = array_sum(array_column($payees,'mpaid'));

$view = (int)($_GET['id'] ?? 0);
$linkAccounts = rows("SELECT * FROM bank_accounts WHERE archived=0 ORDER BY kind='cash' DESC, name");
$acctBal = [];
foreach($linkAccounts as $A){
  $in =(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='in'",[$A['id']]);
  $out=(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='out'",[$A['id']]);
  $acctBal[$A['id']]=(float)$A['opening']+$in-$out;
}
$vp = $view ? row("SELECT * FROM payees WHERE id=?",[$view]) : null;

/* Product resolver for the ledger — prefer the linked expense's real product
   (set for Ads dues via ref_expense_id), else sniff a known product name out of
   the free-text label ("Ads — Nabhi Oil ($6)", "Heel Guard Plus X 50 pc @ Rs.170"),
   checked longest name first so "Nabhi Oil Plus" never loses to "Nabhi Oil". */
function payee_resolve_product($label, $expProduct, $productNames) {
  if ($expProduct) return $expProduct;
  $label = trim((string)$label);
  if ($label === '') return null;
  $labelLower = strtolower($label);
  foreach ($productNames as $pn) {
    if ($pn !== '' && strpos($labelLower, strtolower($pn)) === 0) return $pn;
  }
  if (strpos($label, '—') !== false) {
    $p = trim(preg_replace('/\(\$[\d.,]+\)\s*$/', '', trim(substr($label, strrpos($label,'—')+3))));
    if ($p !== '') return $p;
  }
  return null;
}

if ($vp) {
  $productNames = array_column(rows("SELECT name FROM products ORDER BY CHAR_LENGTH(name) DESC"), 'name');

  /* full unfiltered history — the true running-remaining balance and the product
     filter's dropdown both need the whole story, not just what's on screen */
  $fullHistory = rows("SELECT pl.*, e.product AS exp_product
                        FROM payee_ledger pl LEFT JOIN expenses e ON e.id=pl.ref_expense_id
                        WHERE pl.payee_id=? ORDER BY pl.entry_date ASC, pl.id ASC", [$view]);
  $run = 0; $runMap = []; $productSet = [];
  foreach ($fullHistory as &$l) {
    $l['product_name'] = payee_resolve_product($l['label'], $l['exp_product'], $productNames);
    $run += $l['type']==='due' ? (float)$l['amount'] : -(float)$l['amount'];
    $runMap[$l['id']] = $run;
    if ($l['product_name']) $productSet[$l['product_name']] = true;
  }
  unset($l);
  ksort($productSet);

  $vDue  = array_sum(array_map(fn($l)=>$l['type']==='due'  ? (float)$l['amount'] : 0, $fullHistory));
  $vPaid = array_sum(array_map(fn($l)=>$l['type']==='paid' ? (float)$l['amount'] : 0, $fullHistory));

  /* filters — type, product, date range, keyword — all via GET so links/bookmarks work */
  $fType = in_array($_GET['type'] ?? '', ['due','paid'], true) ? $_GET['type'] : '';
  $fFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
  $fTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : '';
  $fKw   = trim($_GET['kw'] ?? '');
  $fProd = trim($_GET['product'] ?? '');

  $matches = function($l) use ($fType,$fFrom,$fTo,$fKw) {
    if ($fType && $l['type']!==$fType) return false;
    if ($fFrom && $l['entry_date']<$fFrom) return false;
    if ($fTo && $l['entry_date']>$fTo) return false;
    if ($fKw && stripos($l['label'],$fKw)===false) return false;
    return true;
  };

  /* spend-by-product — respects type/date/keyword but not the product pick itself,
     so the breakdown always lists every product to choose from */
  $byProduct = [];
  foreach ($fullHistory as $l) {
    if (!$matches($l)) continue;
    $pn = $l['product_name'] ?: 'Other / General';
    if (!isset($byProduct[$pn])) $byProduct[$pn] = ['due'=>0,'paid'=>0,'n'=>0];
    $byProduct[$pn][$l['type']] += (float)$l['amount'];
    $byProduct[$pn]['n']++;
  }
  uasort($byProduct, function($a,$b){ return ($b['due']-$b['paid']) <=> ($a['due']-$a['paid']); });

  /* the line-item list — every filter applied, newest first, capped for the page */
  $ledger = array_values(array_filter($fullHistory, function($l) use ($matches,$fProd) {
    if (!$matches($l)) return false;
    if ($fProd !== '' && ($l['product_name'] ?: 'Other / General') !== $fProd) return false;
    return true;
  }));
  $ledger = array_reverse($ledger);
  $ledgerTotal = count($ledger);
  $ledger = array_slice($ledger, 0, 500);
}
require __DIR__.'/includes/header.php';
?>
<div class="page-head">
  <div><h1>📣 Ads & Vendors</h1><p>ads spend, vendor dues, payments — who you pay and what's left</p></div>
  <div style="display:flex;gap:9px;flex-wrap:wrap">
    <button class="btn" onclick="document.getElementById('npBg').classList.add('open');document.body.classList.add('modal-open')">👤 New Payee</button>
    <button class="btn btn-primary" onclick="openEntry('paid')">💸 Record Payment</button>
    <button class="btn" onclick="openEntry('due')">🧾 Record Due / Bill</button>
  </div>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="mgrid">
  <div class="metric blue"><div><div class="mv" style="font-size:19px"><?= money($grandPaid) ?></div><div class="ml">Total Paid Out</div><div class="ms">all payees, all time</div></div><div class="mi">🏦</div></div>
  <div class="metric <?= $grandRemain>0.5?'amber':'green' ?>"><div><div class="mv" style="font-size:19px"><?= money($grandRemain) ?></div><div class="ml">Remaining to Pay</div><div class="ms">total outstanding</div></div><div class="mi">⏳</div></div>
  <div class="metric teal"><div><div class="mv" style="font-size:19px"><?= money($tMonthPaid) ?></div><div class="ml">Paid This Month</div><div class="ms"><?= e(date('F Y')) ?></div></div><div class="mi">📅</div></div>
  <div class="metric purple"><div><div class="mv"><?= count($payees) ?></div><div class="ml">Payees</div><div class="ms">people & vendors</div></div><div class="mi">👥</div></div>
</div>

<?php $adsPid=(int)setting('ads_payee_id',0); ?>
<div class="dash-sec" style="margin:22px 0 10px">Payee Summary</div>
<div class="panel" style="padding:14px 16px 4px;margin-bottom:14px">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
    <span class="muted" style="font-size:11.5px"><?= $adsPid?'⚡ Ads auto-bill: ON':'⚡ tap the lightning on a payee to auto-bill new Ads expenses to them' ?></span>
    <?php if($isAdmin && $adsPid): ?><div style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="post" style="display:inline" onsubmit="return confirm('Import ALL past Ads expenses as dues to the ads payee? Safe to run twice — never duplicates.')">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="backfill_ads">
        <button class="btn btn-sm">⚡ Import past Ads expenses</button></form>
      <form method="post" style="display:inline" title="Re-sync ad dues to their rupee amount — fixes dollars shown as Rs.">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="repair_ads">
        <button class="btn btn-sm">🔧 Fix $→Rs on Ads dues</button></form>
    </div><?php endif; ?>
  </div>
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding-bottom:14px">
    <div class="chips" id="payChips" style="margin:0">
      <button class="chip on" data-f="all" onclick="payChipFilter('all',this)">All <b><?= count($payees) ?></b></button>
      <button class="chip" data-f="due" onclick="payChipFilter('due',this)">🟠 Due <b><?= $cntDue ?></b></button>
      <button class="chip" data-f="advance" onclick="payChipFilter('advance',this)">🔵 Advance <b><?= $cntAdv ?></b></button>
      <button class="chip" data-f="settled" onclick="payChipFilter('settled',this)">🟢 Settled <b><?= $cntSettled ?></b></button>
    </div>
    <input id="paySearch" type="search" placeholder="🔍 Search payee…" class="search-in" style="width:100%;max-width:260px" oninput="payFilter(this.value)">
  </div>
</div>

<div class="cour-cards" id="payCards">
<?php foreach($payees as $p): $rem=(float)$p['tdue']-(float)$p['tpaid'];
      $status = $rem>0.5?'due':($rem<-0.5?'advance':'settled');
      $col = $status==='due'?'#f59e0b':($status==='advance'?'#3b82f6':'#10b981');
      $days = $p['lastpay'] ? (int)floor((time()-strtotime($p['lastpay']))/86400) : null;
      $pct = $p['tdue']>0.5 ? min(100, round((float)$p['tpaid']/(float)$p['tdue']*100)) : 100;
?>
  <div class="ccard payee-card" data-status="<?= $status ?>" data-name="<?= e(mb_strtolower($p['name'].' '.$p['note'])) ?>" style="--ccol:<?= $col ?>">
    <div class="ccard-top">
      <div>
        <div class="ccard-name"><?= e($p['name']) ?> <?= ((int)$p['id']===$adsPid)?' <span class="pill p-yellow" style="font-size:9px">⚡ ADS PAYEE</span>':'' ?></div>
        <div class="ccard-sub"><?= $p['note']?e($p['note']).' · ':'' ?><?= (int)$p['nentries'] ?> entries</div>
      </div>
      <?php if($status==='due'): ?><span class="pill p-yellow">🟠 Due</span>
      <?php elseif($status==='advance'): ?><span class="pill p-blue">🔵 Advance</span>
      <?php else: ?><span class="pill p-green">🟢 Settled</span><?php endif; ?>
    </div>
    <div class="ccard-bar"><i style="width:<?= $pct ?>%"></i></div>
    <div class="ccard-chips">
      <?php if($p['lastpay']): ?><span title="last payment date">🕓 last paid <?= e(date('d M',strtotime($p['lastpay']))) ?> · <?= $days===0?'today':$days.'d ago' ?></span>
      <?php else: ?><span>🕓 never paid</span><?php endif; ?>
    </div>
    <div class="ccard-grid">
      <div class="cb cb-b"><div class="cbl">📋 Total Billed</div><div class="cbv" style="font-size:14px"><?= money($p['tdue']) ?></div></div>
      <div class="cb cb-g"><div class="cbl">💸 Total Paid</div><div class="cbv" style="font-size:14px"><?= money($p['tpaid']) ?></div></div>
      <div class="cb <?= $status==='due'?'cb-y':'cb-g' ?>"><div class="cbl">⏳ Remaining</div><div class="cbv" style="font-size:14px"><?= $rem>0.5?money($rem):($rem< -0.5?money(-$rem):'✓') ?></div></div>
      <div class="cb cb-r"><div class="cbl">📅 This Month</div><div class="cbv" style="font-size:14px"><?= (float)$p['mpaid']>0?money($p['mpaid']):'—' ?></div></div>
    </div>
    <div class="ccard-foot">
      <button class="pbtn pbtn-pay" onclick="payQuick(<?= (int)$p['id'] ?>,'paid')" title="Record a payment to this payee">💸 Pay</button>
      <button class="pbtn pbtn-due" onclick="payQuick(<?= (int)$p['id'] ?>,'due')" title="Record a new due/bill">🧾 Due</button>
      <a class="pbtn pbtn-ghost" href="payees.php?id=<?= (int)$p['id'] ?>">📜 Ledger</a>
      <div style="margin-left:auto;display:flex;gap:6px">
        <?php if($isAdmin && (int)$p['id']!==$adsPid): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="set_ads_payee"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="iact" title="Auto-bill new Ads expenses to this payee">⚡</button></form><?php endif; ?>
        <?php if($isAdmin): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete this payee AND all their entries?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="del_payee"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="iact del">🗑</button></form><?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; if(!$payees): ?>
  <div class="panel" style="grid-column:1/-1"><div class="empty">No payees yet — add Rohit, Raushan, Vedas Store… then record dues &amp; payments.</div></div>
<?php endif; ?>
</div>
<div class="empty" id="payNoMatch" style="display:none">No payees match this filter.</div>

<?php if($vp): ?>
<div class="dash-sec" style="margin:22px 0 10px">📜 <?= e($vp['name']) ?> — Ledger</div>
<div class="panel">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <span class="pill p-blue">Total <?= money($vDue) ?></span>
      <span class="pill p-green">Paid <?= money($vPaid) ?></span>
      <span class="pill <?= ($vDue-$vPaid)>0.5?'p-yellow':'p-green' ?>" style="font-weight:800">Remaining <?= money(max(0,$vDue-$vPaid)) ?></span>
    </div>
    <a class="btn btn-sm" href="payees.php">← All Payees</a>
  </div>

  <form method="get" class="advpanel" style="margin:12px 14px;box-shadow:none;border-style:dashed">
    <input type="hidden" name="id" value="<?= $view ?>">
    <div class="advgrid">
      <div class="advf"><label>Type</label><select name="type">
        <option value="">Any</option>
        <option value="due"  <?= $fType==='due'?'selected':'' ?>>🧾 Due</option>
        <option value="paid" <?= $fType==='paid'?'selected':'' ?>>💸 Paid</option>
      </select></div>
      <div class="advf"><label>Product</label><select name="product">
        <option value="">Any product</option>
        <?php foreach(array_keys($productSet) as $pn): ?><option value="<?= e($pn) ?>" <?= $fProd===$pn?'selected':'' ?>><?= e($pn) ?></option><?php endforeach; ?>
        <option value="Other / General" <?= $fProd==='Other / General'?'selected':'' ?>>Other / General</option>
      </select></div>
      <div class="advf advf-daterange"><label>Date from → to</label><div class="advrange"><input type="date" name="from" value="<?= e($fFrom) ?>"><span>→</span><input type="date" name="to" value="<?= e($fTo) ?>"></div></div>
      <div class="advf"><label>Keyword</label><input type="text" name="kw" value="<?= e($fKw) ?>" placeholder="search note…"></div>
    </div>
    <div class="advfoot">
      <span class="muted" id="ledgerSummary"><?= $ledgerTotal ?> entr<?= $ledgerTotal===1?'y':'ies' ?> match<?= $ledgerTotal>500?' — showing latest 500':'' ?></span>
      <a class="btn btn-sm" href="payees.php?id=<?= $view ?>">↺ Reset</a>
      <button class="btn btn-sm btn-primary">Apply</button>
    </div>
  </form>

  <?php if($byProduct): ?>
  <div class="panel-head" style="border-top:1px solid var(--border)"><h2>🏷️ Spend by Product</h2><span class="muted" style="font-size:11.5px"><?= count($byProduct) ?> product<?= count($byProduct)===1?'':'s' ?> · matches current type/date/keyword filters</span></div>
  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr>
    <th>Product</th><th class="right">Due</th><th class="right">Paid</th><th class="right">Remaining</th><th class="right">Entries</th><th></th>
  </tr></thead><tbody>
  <?php foreach($byProduct as $pn=>$s): $prem=$s['due']-$s['paid']; $isPicked=$fProd===$pn; ?>
    <tr<?= $isPicked?' style="background:var(--brand-soft)"':'' ?>>
      <td style="font-weight:700"><?= e($pn) ?></td>
      <td class="num right"><?= money($s['due']) ?></td>
      <td class="num right" style="color:var(--green)"><?= money($s['paid']) ?></td>
      <td class="num right" style="font-weight:800;color:<?= $prem>0.5?'var(--amber)':'var(--green)' ?>"><?= $prem>0.5?money($prem):'✓ settled' ?></td>
      <td class="num right"><?= (int)$s['n'] ?></td>
      <td class="right">
        <?php if($isPicked): ?><a class="btn btn-sm" href="?<?= e(http_build_query(array_diff_key($_GET,['product'=>1]))) ?>">✕ Clear</a>
        <?php else: ?><a class="btn btn-sm" href="?<?= e(http_build_query(array_merge($_GET,['product'=>$pn]))) ?>">🔍 View</a><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>

  <div class="panel-head" style="border-top:1px solid var(--border)"><h2>Entries</h2></div>
  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr>
    <th>Date</th><th>Type</th><th>Product</th><th>Note</th><th class="right">Amount</th><th class="right">Running Remaining</th><th></th>
  </tr></thead><tbody>
  <?php foreach($ledger as $l): ?>
    <tr>
      <td class="num"><?= e($l['entry_date']) ?></td>
      <td><?= $l['type']==='due' ? '<span class="pill p-blue">🧾 Due</span>' : '<span class="pill p-green">💸 Paid</span>' ?></td>
      <td><?= $l['product_name'] ? '<span class="pill p-grey" style="font-size:10px">'.e($l['product_name']).'</span>' : '<span class="muted">—</span>' ?></td>
      <td><?= e($l['label'] ?: '—') ?></td>
      <td class="num right" style="font-weight:700;color:<?= $l['type']==='due'?'var(--ink)':'var(--green)' ?>"><?= money($l['amount']) ?></td>
      <td class="num right" style="color:<?= $runMap[$l['id']]>0.5?'var(--amber)':'var(--green)' ?>"><?= money(max(0,$runMap[$l['id']])) ?></td>
      <td class="right"><?php if($isAdmin): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete entry?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="del_entry"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="pid" value="<?= $view ?>"><button class="iact del">🗑</button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; if(!$ledger) echo '<tr><td colspan="7"><div class="empty">No entries match these filters.</div></td></tr>'; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<!-- new payee modal -->
<div class="modal-bg" id="npBg" style="z-index:99990"><form class="modal" method="post" style="width:420px;max-width:96vw">
  <div class="modal-head"><span>👤 New Payee</span><span class="mx" onclick="closeM('npBg')">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="add_payee">
  <div class="modal-body">
    <div class="full"><label>Name *</label><input name="name" required placeholder="e.g. Rohit Babu Kushwaha"></div>
    <div class="full"><label>Note</label><input name="note" placeholder="e.g. Ads vendor / Facebook agent"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="closeM('npBg')">Cancel</button><button class="btn btn-primary">Add</button></div>
</form></div>

<!-- entry modal -->
<div class="modal-bg" id="enBg" style="z-index:99990"><form class="modal" method="post" style="width:460px;max-width:96vw">
  <div class="modal-head"><span id="enTitle">💸 Record Payment</span><span class="mx" onclick="closeM('enBg')">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="add_entry"><input type="hidden" name="type" id="enType" value="paid">
  <div class="modal-body">
    <div><label>Payee *</label><select name="payee_id" required><option value="">—</option>
      <?php foreach($payees as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $view===(int)$p['id']?' selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Date</label><input type="date" name="entry_date" value="<?= date('Y-m-d') ?>"></div>
    <div><label>Amount (Rs.) *</label><input type="number" step="any" min="1" name="amount" required></div>
    <div id="enAcctWrap"><label>Account (Bank page) — leave "not linked" for a Due entry</label>
      <select name="account_id">
        <option value="0">⏳ — not linked —</option>
        <?php foreach($linkAccounts as $A): ?>
          <option value="<?= (int)$A['id'] ?>"><?= $A['kind']==='cash'?'💵':'🏦' ?> <?= e($A['name']) ?> — <?= money($acctBal[$A['id']] ?? 0) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label>Expense Name / Note</label><input name="label" placeholder="e.g. Ads Payment · Salary Magh · Heel Guard"></div>
    <div class="full muted" id="enHint" style="font-size:11.5px"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="closeM('enBg')">Cancel</button><button class="btn btn-primary">💾 Save</button></div>
</form></div>

<script>
</script>
<style>
/* realistic, tactile action buttons */
.pbtn{display:inline-flex;align-items:center;gap:5px;border-radius:10px;padding:7px 13px;font:inherit;font-size:12px;font-weight:800;cursor:pointer;text-decoration:none;border:1px solid transparent;box-shadow:0 2px 0 rgba(0,0,0,.12),0 4px 10px rgba(30,40,80,.10);transition:transform .06s,box-shadow .06s,filter .12s;line-height:1}
.pbtn:hover{filter:brightness(1.05);box-shadow:0 3px 0 rgba(0,0,0,.12),0 7px 16px rgba(30,40,80,.14);transform:translateY(-1px)}
.pbtn:active{transform:translateY(1px);box-shadow:0 1px 0 rgba(0,0,0,.15),0 2px 6px rgba(30,40,80,.12)}
.pbtn-pay{background:linear-gradient(180deg,#34d17b,#16a34a);color:#fff;border-color:#12813b;text-shadow:0 1px 1px rgba(0,0,0,.18)}
.pbtn-due{background:linear-gradient(180deg,#ffd66b,#f0a51f);color:#5d3c00;border-color:#d18f12}
.pbtn-ghost{background:linear-gradient(180deg,#ffffff,#eef1f8);color:#33415e;border-color:#d5dbeb}
.iact{border:1px solid #d5dbeb;background:linear-gradient(180deg,#fff,#eef1f8);border-radius:9px;padding:6px 9px;cursor:pointer;box-shadow:0 2px 0 rgba(0,0,0,.08);transition:transform .06s}
.iact:hover{transform:translateY(-1px)} .iact:active{transform:translateY(1px)}
.iact.del:hover{background:linear-gradient(180deg,#ffe8e6,#ffd2cd);border-color:#f3a29a}
</style>
<script>
function openEntry(t){
  document.getElementById('enType').value=t;
  document.getElementById('enTitle').textContent = t==='paid' ? '💸 Record Payment (bank out)' : '🧾 Record Due / Bill';
  document.getElementById('enHint').textContent = t==='paid'
    ? 'Money that left your bank to this payee — reduces their Remaining.'
    : 'What you now owe them (their ad spend, invoice, rent bill…) — increases Remaining.';
  var aw=document.getElementById('enAcctWrap'); if(aw) aw.style.display = t==='paid' ? '' : 'none';
  document.getElementById('enBg').classList.add('open');document.body.classList.add('modal-open');
}
function closeM(id){document.getElementById(id).classList.remove('open');document.body.classList.remove('modal-open');}
var payChip='all', payQ='';
function payChipFilter(f,btn){
  payChip=f;
  document.querySelectorAll('#payChips .chip').forEach(function(c){c.classList.remove('on');});
  btn.classList.add('on');
  applyPayFilters();
}
function payFilter(q){ payQ=(q||'').toLowerCase(); applyPayFilters(); }
function applyPayFilters(){
  var shown=0;
  document.querySelectorAll('#payCards .payee-card').forEach(function(c){
    var okStatus = payChip==='all' || c.dataset.status===payChip;
    var okSearch = !payQ || (c.dataset.name||'').indexOf(payQ)!==-1;
    var vis = okStatus && okSearch;
    c.style.display = vis ? '' : 'none';
    if(vis) shown++;
  });
  var nm=document.getElementById('payNoMatch'); if(nm) nm.style.display = shown ? 'none' : '';
}
function payQuick(pid,type){
  openEntry(type);
  var sel=document.querySelector('#enBg select[name=payee_id]'); if(sel) sel.value=String(pid);
  var amt=document.querySelector('#enBg input[name=amount]'); if(amt) setTimeout(function(){amt.focus();},50);
}
['npBg','enBg'].forEach(function(id){var m=document.getElementById(id);m.addEventListener('click',function(e){if(e.target===m)closeM(id);});});
</script>

<?php require __DIR__.'/includes/footer.php'; ?>