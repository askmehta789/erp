<?php
/* ============================================================
   MONEY DASHBOARD
   Pulls together COD Ledger, Bank Accounts, Expenses, and Salary
   into one place. Every "money out" figure here is read from its
   own ORIGIN table (expenses, salary_entries, cod_ledger) — never
   summed from bank_txns directly — so linked and not-yet-linked
   entries are never double-counted or silently dropped.
   ============================================================ */
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
ensure_banks();
$PAGE_TITLE='Money Dashboard';

/* ---- Bank Accounts: live balances ---- */
$accounts = rows("SELECT * FROM bank_accounts WHERE archived=0 ORDER BY kind='cash' DESC, name");
$acctBal=[]; $acctIn=[]; $acctOut=[]; $totalInBanks=0; $cashInHand=0;
foreach($accounts as $A){
  $in =(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='in'",[$A['id']]);
  $out=(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='out'",[$A['id']]);
  $bal=(float)$A['opening']+$in-$out;
  $acctBal[$A['id']]=$bal; $acctIn[$A['id']]=$in; $acctOut[$A['id']]=$out;
  if($A['kind']==='cash') $cashInHand+=$bal; else $totalInBanks+=$bal;
}
$totalPosition = $totalInBanks + $cashInHand;

/* ---- COD Ledger: collected / charges / released / with couriers ---- */
try { q("CREATE TABLE IF NOT EXISTS cod_ledger(id INT AUTO_INCREMENT PRIMARY KEY, entry_date DATE NOT NULL,
  type ENUM('in','out') NOT NULL DEFAULT 'in', courier_id INT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  method VARCHAR(20) DEFAULT 'cash', reference VARCHAR(80) DEFAULT '', note VARCHAR(255) DEFAULT '',
  created_by VARCHAR(80) DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch (Exception $e) {}
ensure_order_exchange_cols();
$couriers = rows("SELECT id,name FROM couriers");
$codCollected=0; $codCharges=0;
foreach($couriers as $c){
  $rows_ = rows("SELECT status, sell_price, qty, delivery_charge, cancel_charge, exchange_charge FROM orders WHERE courier_id=?",[$c['id']]);
  foreach($rows_ as $o){
    if($o['status']==='delivered'){ $codCollected+=(float)$o['sell_price']*(int)$o['qty']; $codCharges+=(float)$o['delivery_charge']; }
    elseif(in_array($o['status'],['returned','cancelled'],true)){ $codCharges+=(float)$o['cancel_charge']; }
    elseif($o['status']==='exchanged'){ $codCharges+=(float)$o['exchange_charge']; }
  }
}
$codNetPayable = $codCollected - $codCharges;
$codReleased = (float)val("SELECT COALESCE(SUM(amount),0) FROM cod_ledger WHERE type='in'");
$codOut = (float)val("SELECT COALESCE(SUM(amount),0) FROM cod_ledger WHERE type='out'");
$stillWithCouriers = $codNetPayable - $codReleased;
$codOutUnlinked = (float)val("SELECT COALESCE(SUM(amount),0) FROM cod_ledger WHERE type='out' AND (account_id IS NULL OR account_id=0)");

/* ---- Expenses: total + by category ----
   'Salary' and 'Ads' are deliberately excluded here. Staff & Salary is the one
   authoritative source for salary. For Ads: recording an "Ads" expense auto-bills
   the designated Ads payee (see ads_autobill() in db.php) — so once that payment
   actually happens, "Ads Payment" below IS the real cash-out figure, and counting
   the original Ads expense too would double it. 'Ads' still stays a selectable
   category on the Expenses page itself — this only affects this dashboard's totals. */
$expTotal = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE category NOT IN ('Salary','Ads')");
$expByCat = rows("SELECT category, COALESCE(SUM(amount),0) amt FROM expenses WHERE category NOT IN ('Salary','Ads') GROUP BY category ORDER BY amt DESC");
$expUnlinked = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE category NOT IN ('Salary','Ads') AND (account_id IS NULL OR account_id=0)");
/* only orphaned Salary-category expenses (not linked from a Staff & Salary entry)
   are a problem — linked ones are the normal auto-sync from salary_expense_sync() */
$oldSalaryExpAmt = (float)val("SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE e.category='Salary'
  AND NOT EXISTS (SELECT 1 FROM salary_entries s WHERE s.expense_id=e.id)");
$adsExpAmt = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE category='Ads'");

/* ---- Salary: total real cash out (payment/advance/bonus — deductions never touch cash) ---- */
$salTotal = (float)val("SELECT COALESCE(SUM(amount),0) FROM salary_entries WHERE type IN ('payment','advance','bonus')");
$salUnlinked = (float)val("SELECT COALESCE(SUM(amount),0) FROM salary_entries WHERE type IN ('payment','advance','bonus') AND (account_id IS NULL OR account_id=0)");

/* ---- Vendor/Ad payments (payees.php) — only 'paid' is real cash out, 'due' is just a liability ---- */
try { q("CREATE TABLE IF NOT EXISTS payee_ledger(id INT AUTO_INCREMENT PRIMARY KEY, payee_id INT NOT NULL,
  entry_date DATE NOT NULL, type VARCHAR(6) NOT NULL DEFAULT 'due', amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  label VARCHAR(160) DEFAULT '', ref_expense_id INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch (Exception $e) {}
try { q("ALTER TABLE payee_ledger ADD COLUMN IF NOT EXISTS account_id INT NULL"); } catch (Exception $e) {}
try { q("ALTER TABLE payee_ledger ADD COLUMN IF NOT EXISTS bank_txn_id INT NULL"); } catch (Exception $e) {}
$adsPayeeId = (int)setting('ads_payee_id',0);
$adsPayTotal = $adsPayeeId ? (float)val("SELECT COALESCE(SUM(amount),0) FROM payee_ledger WHERE type='paid' AND payee_id=?",[$adsPayeeId]) : 0;
$vendorPayTotal = (float)val("SELECT COALESCE(SUM(amount),0) FROM payee_ledger WHERE type='paid'".($adsPayeeId?" AND payee_id<>?":""),$adsPayeeId?[$adsPayeeId]:[]);
$vendorTotal = $adsPayTotal + $vendorPayTotal;   /* kept for the integrity/unlinked totals below, unchanged in sum */
$vendorUnlinked = (float)val("SELECT COALESCE(SUM(amount),0) FROM payee_ledger WHERE type='paid' AND (account_id IS NULL OR account_id=0)");

$totalOutAllTime = $codCharges + $expTotal + $salTotal + $vendorTotal + $codOut;
$totalUnlinked = $codOutUnlinked + $expUnlinked + $salUnlinked + $vendorUnlinked;

/* ---- data-integrity check: any bank_txn_id pointing at a row that no longer exists? ---- */
$integrityIssues = 0;
try {
  $integrityIssues += (int)val("SELECT COUNT(*) FROM expenses e WHERE e.bank_txn_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM bank_txns b WHERE b.id=e.bank_txn_id)");
  $integrityIssues += (int)val("SELECT COUNT(*) FROM salary_entries s WHERE s.bank_txn_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM bank_txns b WHERE b.id=s.bank_txn_id)");
  $integrityIssues += (int)val("SELECT COUNT(*) FROM cod_ledger c WHERE c.bank_txn_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM bank_txns b WHERE b.id=c.bank_txn_id)");
  $integrityIssues += (int)val("SELECT COUNT(*) FROM payee_ledger p WHERE p.bank_txn_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM bank_txns b WHERE b.id=p.bank_txn_id)");
} catch (Exception $e) {}

/* ---- money in vs out, last 6 months (bank_txns is the one true record of actual cash movement) ---- */
$months=[]; for($i=5;$i>=0;$i--){ $months[]=date('Y-m',strtotime("-$i months")); }
$monthIn=[]; $monthOut=[];
foreach($months as $mo){
  $monthIn[$mo]=(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE direction='in' AND DATE_FORMAT(txn_date,'%Y-%m')=?",[$mo]);
  $monthOut[$mo]=(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE direction='out' AND DATE_FORMAT(txn_date,'%Y-%m')=?",[$mo]);
}
$maxMonthVal = max(1, max(array_merge(array_values($monthIn),array_values($monthOut))));

$otherDeposits = (float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE direction='in' AND category NOT LIKE 'COD %'");

require __DIR__.'/includes/header.php';
?>
<?php
/* ---------- view data (visual only — every figure comes from the queries above) ---------- */
$netAllTime = $codCollected - $totalOutAllTime;
$compTotal  = max(1, max(0,$totalInBanks) + max(0,$cashInHand) + max(0,$stillWithCouriers));
$comp = [
  ['In banks & wallets', $totalInBanks,      '#2563eb'],
  ['Cash in hand',       $cashInHand,        '#16a34a'],
  ['Still with couriers',$stillWithCouriers, '#f59e0b'],
];
/* every row opens its own data, filtered, all-time (not just the page) */
$codFirst = (string)val("SELECT COALESCE(MIN(entry_date),CURDATE()) FROM cod_ledger");
$codAll   = 'from='.urlencode($codFirst).'&to='.date('Y-m-d');
$outList = [['Courier charges', $codCharges, 'kept by couriers', 'cod.php?'.$codAll]];
foreach ($expByCat as $ec) $outList[] = [$ec['category'] ?: 'Uncategorised', (float)$ec['amt'], 'expense', 'expenses.php'.(($ec['category'] ?? '') !== '' ? '?hcat='.urlencode($ec['category']) : '').'#expense-history'];
$outList[] = ['Salary', $salTotal, 'payments, advances & bonuses', 'salary.php'];
if ($adsPayeeId) $outList[] = ['Ads payment', $adsPayTotal, 'paid to your Ads payee', 'payees.php?id='.$adsPayeeId.'&type=paid'];
$outList[] = ['Vendor payments', $vendorPayTotal, 'paid to vendors', 'payees.php'];
$outList[] = ['COD direct payouts', $codOut, 'money out of COD', 'cod.php?t=out&'.$codAll.'#ledTbl'];
usort($outList, fn($a,$b) => $b[1] <=> $a[1]);
$outSum = max(1, array_sum(array_map(fn($r) => max(0,$r[1]), $outList)));
$inList = [['COD released by couriers', $codReleased, 'cod.php?t=in&'.$codAll.'#ledTbl'], ['Other bank deposits', $otherDeposits, 'banks.php']];
$inSum = max(1, $codReleased + $otherDeposits);
$acctPos = max(1, array_sum(array_map(fn($v) => max(0,$v), $acctBal)));
$moLabels = array_map(fn($mo) => date('M y', strtotime($mo.'-01')), $months);
$pct = fn($v,$t) => $t > 0 ? max(0, min(100, round($v / $t * 100))) : 0;
?>
<style>
.mx{container-type:inline-size;--mx-in:#16a34a;--mx-out:#e11d48;--mx-soft:var(--surface-2)}
.mx-head{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.mx-head h1{margin:0;font-size:22px;font-weight:850;letter-spacing:-.02em;color:var(--ink)}
.mx-head p{margin:3px 0 0;font-size:12.5px;color:var(--muted)}
.mx-links{display:flex;gap:6px;flex-wrap:wrap}
.mx-links a{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--ink);font-size:12px;font-weight:650}
.mx-links a:hover{border-color:var(--muted-2)}
.mx-note{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-radius:12px;font-size:12.5px;margin-bottom:10px;border:1px solid var(--border);background:var(--surface);color:var(--ink)}
.mx-note .ic{width:22px;height:22px;border-radius:7px;display:grid;place-items:center;flex:none;font-size:12px;font-weight:900}
.mx-note.ok .ic{background:var(--green-bg);color:var(--mx-in)}
.mx-note.warn .ic{background:var(--amber-bg);color:#b45309}
.mx-note.info .ic{background:var(--blue-bg);color:#2563eb}
.mx-note small{display:block;color:var(--muted);font-size:11.5px;margin-top:1px}
.mx-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:18px 20px}
.mx-card h3{margin:0 0 14px;font-size:13px;font-weight:800;color:var(--ink);display:flex;justify-content:space-between;align-items:center;gap:10px}
.mx-card h3 .sub{font-size:11.5px;font-weight:600;color:var(--muted)}
.mx-lbl{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.mx-top{display:grid;grid-template-columns:1.5fr 1fr;gap:14px;margin:14px 0}
.mx-pos .v{font-size:34px;font-weight:850;letter-spacing:-.02em;color:var(--ink);margin-top:6px;font-variant-numeric:tabular-nums}
.mx-pos .s{font-size:12px;color:var(--muted);margin-top:2px}
.mx-stack{display:flex;height:10px;border-radius:99px;overflow:hidden;background:var(--mx-soft);margin:18px 0 14px;gap:2px}
.mx-stack i{display:block;height:100%}
.mx-legend{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.mx-legend div{padding:10px 12px;border-radius:12px;background:var(--mx-soft)}
.mx-legend b{display:block;font-size:15px;font-weight:800;color:var(--ink);font-variant-numeric:tabular-nums;margin-top:3px}
.mx-legend span{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:650;color:var(--muted)}
.mx-legend span:before{content:'';width:8px;height:8px;border-radius:3px;background:var(--c)}
.mx-tiles{display:grid;grid-template-rows:repeat(3,1fr);gap:10px}
.mx-tile{display:flex;justify-content:space-between;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:14px 18px}
.mx-tile .v{font-size:20px;font-weight:850;font-variant-numeric:tabular-nums;margin-top:3px}
.mx-tile .s{font-size:11px;color:var(--muted);margin-top:2px}
.mx-tile .ico{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;font-size:17px;font-weight:900;flex:none}
.mx-mid{display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:14px}
.mx-bot{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.mx-row{display:grid;grid-template-columns:1fr auto;gap:4px 12px;padding:10px 0;border-bottom:1px solid var(--border);align-items:center}
.mx-row:last-child{border-bottom:0;padding-bottom:0}
.mx-row .nm{font-size:13px;font-weight:650;color:var(--ink);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mx-row .nm small{font-weight:500;color:var(--muted);font-size:11px;margin-left:6px}
.mx-row .amt{font-size:13px;font-weight:800;color:var(--ink);text-align:right;font-variant-numeric:tabular-nums}
.mx-row .amt small{display:inline-block;min-width:34px;font-weight:600;color:var(--muted);font-size:11px;margin-left:6px}
.mx-row .bar{grid-column:1/-1;height:5px;border-radius:99px;background:var(--mx-soft);overflow:hidden}
.mx-row .bar i{display:block;height:100%;border-radius:99px;background:var(--c,#64748b)}
.mx-row a.nm:hover{text-decoration:underline}
.mx-chart{height:250px}
.mx-empty{padding:18px;text-align:center;color:var(--muted);font-size:12.5px}
body.dark .mx-note.warn .ic{color:#fbbf24}
body.dark .mx-note.info .ic{color:#60a5fa}
@container (max-width:900px){.mx-top,.mx-mid,.mx-bot{grid-template-columns:1fr}.mx-tiles{grid-template-rows:none;grid-template-columns:repeat(3,1fr)}}
@container (max-width:560px){.mx-tiles,.mx-legend{grid-template-columns:1fr}.mx-pos .v{font-size:28px}}
</style>

<div class="mx">
<div class="mx-head">
  <div><h1>Money Dashboard</h1><p>All-time · bank accounts, COD ledger, expenses, salary &amp; vendor payments in one place</p></div>
  <div class="mx-links">
    <a href="banks.php">🏦 Bank Accounts</a><a href="cod.php">💵 COD Ledger</a><a href="expenses.php">🧾 Expenses</a>
  </div>
</div>

<?php if($integrityIssues>0): ?>
  <div class="mx-note warn"><span class="ic">!</span><div><b><?= $integrityIssues ?> broken link(s) found</b><small>A linked entry points to a bank transaction that no longer exists.</small></div></div>
<?php elseif($oldSalaryExpAmt>0): ?>
  <div class="mx-note warn"><span class="ic">!</span><div><b><?= money($oldSalaryExpAmt) ?> in old "Salary" expenses is left out</b><small>Staff &amp; Salary is the one source for salary now — clean these up on the Expenses page.</small></div></div>
<?php elseif($totalUnlinked>0): ?>
  <div class="mx-note warn"><span class="ic">i</span><div><b><?= money($totalUnlinked) ?> isn't linked to a bank account yet</b><small>Across COD payouts, expenses, salary and vendor payments — everything else checks out.</small></div></div>
<?php else: ?>
  <div class="mx-note ok"><span class="ic">✓</span><div><b>Everything is linked and consistent</b><small>No mismatches found.</small></div></div>
<?php endif; ?>
<?php if($adsExpAmt>0): ?>
  <div class="mx-note info"><span class="ic">i</span><div><b><?= money($adsExpAmt) ?> in "Ads" expenses isn't counted twice</b><small>Ads payment (what's actually paid to your Ads payee) is the one cash-out figure for ads here.</small></div></div>
<?php endif; ?>

<div class="mx-top">
  <div class="mx-card mx-pos">
    <div class="mx-lbl">Cash position · right now</div>
    <div class="v"><?= money($totalPosition) ?></div>
    <div class="s">banks &amp; wallets + cash in hand<?= $stillWithCouriers > 0 ? ' · '.money($stillWithCouriers).' more is still with couriers' : '' ?></div>
    <div class="mx-stack"><?php foreach($comp as $c): if($c[1] > 0): ?><i style="width:<?= round($c[1]/$compTotal*100,2) ?>%;background:<?= $c[2] ?>" title="<?= e($c[0].': '.money($c[1])) ?>"></i><?php endif; endforeach; ?></div>
    <div class="mx-legend">
      <?php foreach($comp as $c): ?><div><span style="--c:<?= $c[2] ?>"><?= e($c[0]) ?></span><b><?= money($c[1]) ?></b></div><?php endforeach; ?>
    </div>
  </div>
  <div class="mx-tiles">
    <div class="mx-tile"><div><div class="mx-lbl">Money in</div><div class="v" style="color:var(--mx-in)"><?= money($codCollected) ?></div><div class="s">COD collected, before courier charges</div></div><div class="ico" style="background:var(--green-bg);color:var(--mx-in)">↓</div></div>
    <div class="mx-tile"><div><div class="mx-lbl">Money out</div><div class="v" style="color:var(--mx-out)"><?= money($totalOutAllTime) ?></div><div class="s">charges, expenses, salary, vendors &amp; payouts</div></div><div class="ico" style="background:var(--red-bg);color:var(--mx-out)">↑</div></div>
    <div class="mx-tile"><div><div class="mx-lbl">Net</div><div class="v" style="color:<?= $netAllTime >= 0 ? 'var(--mx-in)' : 'var(--mx-out)' ?>"><?= ($netAllTime < 0 ? '−' : '').money(abs($netAllTime)) ?></div><div class="s">money in − money out</div></div><div class="ico" style="background:var(--blue-bg);color:#2563eb">=</div></div>
  </div>
</div>

<div class="mx-mid">
  <div class="mx-card">
    <h3>Money in vs out <span class="sub">last 6 months · from bank &amp; cash accounts</span></h3>
    <div class="mx-chart"><canvas id="mxFlow"></canvas></div>
  </div>
  <div class="mx-card">
    <h3>Where money came from <span class="sub"><?= money($codReleased + $otherDeposits) ?></span></h3>
    <?php foreach($inList as $r): ?>
      <div class="mx-row"><a class="nm" href="<?= e($r[2]) ?>"><?= e($r[0]) ?></a><div class="amt"><?= money($r[1]) ?><small><?= $pct($r[1],$inSum) ?>%</small></div><div class="bar"><i style="width:<?= $pct($r[1],$inSum) ?>%;--c:var(--mx-in)"></i></div></div>
    <?php endforeach; ?>
  </div>
</div>

<div class="mx-bot">
  <div class="mx-card">
    <h3>Where money went <span class="sub"><?= money($totalOutAllTime) ?> total</span></h3>
    <?php foreach($outList as $r): ?>
      <div class="mx-row"><a class="nm" href="<?= e($r[3]) ?>"><?= e($r[0]) ?><small><?= e($r[2]) ?></small></a><div class="amt"><?= money($r[1]) ?><small><?= $pct($r[1],$outSum) ?>%</small></div><div class="bar"><i style="width:<?= $pct($r[1],$outSum) ?>%;--c:#64748b"></i></div></div>
    <?php endforeach; ?>
  </div>
  <div class="mx-card">
    <h3>Accounts <span class="sub"><?= count($accounts) ?> active</span></h3>
    <?php foreach($accounts as $A):
      $linkedN = (int)val("SELECT
        (SELECT COUNT(*) FROM expenses WHERE account_id=?) +
        (SELECT COUNT(*) FROM salary_entries WHERE account_id=?) +
        (SELECT COUNT(*) FROM cod_ledger WHERE account_id=?) +
        (SELECT COUNT(*) FROM payee_ledger WHERE account_id=?)",[$A['id'],$A['id'],$A['id'],$A['id']]);
      $bal = $acctBal[$A['id']] ?? 0; ?>
      <div class="mx-row"><a class="nm" href="banks.php?a=<?= (int)$A['id'] ?>"><?= $A['kind']==='cash'?'💵':'🏦' ?> <?= e($A['name']) ?><small><?= $linkedN ?> linked entr<?= $linkedN===1?'y':'ies' ?></small></a><div class="amt" style="<?= $bal < 0 ? 'color:var(--mx-out)' : '' ?>"><?= money($bal) ?></div><div class="bar"><i style="width:<?= $pct($bal,$acctPos) ?>%;--c:<?= $A['kind']==='cash' ? '#16a34a' : '#2563eb' ?>"></i></div></div>
    <?php endforeach; if(!$accounts): ?><div class="mx-empty">No accounts yet — add one on the <a href="banks.php" style="text-decoration:underline">Bank Accounts</a> page.</div><?php endif; ?>
  </div>
</div>
</div>

<script>
(function(){
  var el=document.getElementById('mxFlow'); if(!el||!window.Chart) return;
  var ink='#94a3b8', grid='rgba(148,163,184,.18)';   /* neutral — reads well in light and dark */
  var inD=<?= json_encode(array_values(array_map(fn($v)=>round($v,2),$monthIn))) ?>, outD=<?= json_encode(array_values(array_map(fn($v)=>round($v,2),$monthOut))) ?>;
  new Chart(el,{type:'bar',
    data:{labels:<?= json_encode($moLabels) ?>,datasets:[
      {label:'In',data:inD,backgroundColor:'#16a34a',borderRadius:6,maxBarThickness:26},
      {label:'Out',data:outD,backgroundColor:'#e11d48',borderRadius:6,maxBarThickness:26},
      {label:'Net',type:'line',data:inD.map(function(v,i){return Math.round((v-outD[i])*100)/100;}),borderColor:'#2563eb',backgroundColor:'#2563eb',borderWidth:2,pointRadius:3,tension:.35}
    ]},
    options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
      plugins:{legend:{position:'bottom',labels:{color:ink,boxWidth:10,boxHeight:10,usePointStyle:true}},
        tooltip:{callbacks:{label:function(c){return ' '+c.dataset.label+': Rs. '+Math.round(c.parsed.y).toLocaleString('en-IN');}}}},
      scales:{x:{grid:{display:false},ticks:{color:ink}},
        y:{beginAtZero:true,suggestedMax:1000,grid:{color:grid},border:{display:false},ticks:{color:ink,precision:0,callback:function(v){return Math.abs(v)>=1000?(v/1000)+'k':v;}}}}}});
})();
</script>

<?php require __DIR__.'/includes/footer.php'; ?>
