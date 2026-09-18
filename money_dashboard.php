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
$couriers = rows("SELECT id,name FROM couriers");
$codCollected=0; $codCharges=0;
foreach($couriers as $c){
  $rows_ = rows("SELECT status, sell_price, qty, delivery_charge, cancel_charge FROM orders WHERE courier_id=?",[$c['id']]);
  foreach($rows_ as $o){
    if($o['status']==='delivered'){ $codCollected+=(float)$o['sell_price']*(int)$o['qty']; $codCharges+=(float)$o['delivery_charge']; }
    elseif(in_array($o['status'],['returned','cancelled'],true)){ $codCharges+=(float)$o['cancel_charge']; }
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
$oldSalaryExpAmt = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE category='Salary'");
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
<style>
.md-hero{background:linear-gradient(120deg,#0f172a,#1e3a5f);border-radius:22px;padding:24px 28px;color:#fff;margin-bottom:16px;box-shadow:0 18px 40px rgba(15,23,42,.28)}
.md-hero h1{font-size:22px;font-weight:900;margin:0}
.md-hero p{opacity:.8;font-size:12px;margin:3px 0 0}
.md-recon{display:inline-flex;align-items:center;gap:8px;border-radius:99px;padding:7px 16px;font-size:11.5px;font-weight:800;margin-top:14px}
.md-recon.ok{background:rgba(74,222,128,.15);border:1px solid rgba(74,222,128,.4);color:#4ade80}
.md-recon.warn{background:rgba(251,191,36,.15);border:1px solid rgba(251,191,36,.4);color:#fbbf24}
.md-bigrow{display:grid;grid-template-columns:1.3fr 1fr 1fr;gap:12px;margin-bottom:16px}
.md-big{background:#fff;border-radius:18px;padding:20px 22px;box-shadow:0 10px 28px rgba(30,41,80,.08)}
.md-big.total{background:linear-gradient(135deg,#4338ca,#6d28d9);color:#fff}
.md-big .l{font-size:11px;font-weight:800;opacity:.7;text-transform:uppercase;letter-spacing:.03em}
.md-big .v{font-size:26px;font-weight:900;margin-top:6px}
.md-big .s{font-size:11px;opacity:.65;margin-top:4px}
.md-split{display:flex;gap:14px;margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,.15)}
.md-split div{flex:1}
.md-split b{font-size:15px;display:block}
.md-split span{font-size:10px;opacity:.7}
.md-flow{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px}
.md-card{background:#fff;border-radius:18px;padding:18px 20px;box-shadow:0 8px 24px rgba(30,41,80,.07)}
.md-card h3{font-size:13.5px;font-weight:900;margin-bottom:12px;display:flex;align-items:center;gap:7px}
.md-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f1f4f9;font-size:12px}
.md-row:last-child{border-bottom:0}
.md-row .nm{display:flex;align-items:center;gap:8px;color:#334}
.md-row .amt{font-weight:800}
.md-dot{width:8px;height:8px;border-radius:99px;flex:none}
.md-accounts{background:#fff;border-radius:18px;padding:18px 20px;box-shadow:0 8px 24px rgba(30,41,80,.07);margin-bottom:16px}
.md-accounts h3{font-size:13.5px;font-weight:900;margin-bottom:12px}
.md-acctgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
.md-acct{background:#f8fafc;border-radius:13px;padding:12px 14px;border-left:4px solid #6366f1}
.md-acct .an{font-size:11px;font-weight:800;color:#334}
.md-acct .av{font-size:16px;font-weight:900;margin-top:3px}
.md-acct .src{font-size:9.5px;color:#8a93a8;margin-top:4px}
.md-timeline{background:#fff;border-radius:18px;padding:18px 20px;box-shadow:0 8px 24px rgba(30,41,80,.07)}
.md-timeline h3{font-size:13.5px;font-weight:900;margin-bottom:14px;display:flex;align-items:center;gap:14px}
.md-legend{display:flex;gap:14px;font-size:10.5px;font-weight:700;color:#6b7280}
.md-legend span{display:inline-flex;align-items:center;gap:5px}
.md-bars{display:flex;align-items:flex-end;gap:8px;height:130px}
.md-bargroup{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;height:100%;justify-content:flex-end}
.md-barpair{display:flex;gap:3px;align-items:flex-end;width:100%;height:100%;justify-content:center}
.md-bar{width:14px;border-radius:4px 4px 0 0}
.md-bar.in{background:#4ade80}
.md-bar.out{background:#f87171}
.md-barlbl{font-size:9px;color:#8a93a8;margin-top:4px;font-weight:700}
@media(max-width:820px){ .md-bigrow{grid-template-columns:1fr} .md-flow{grid-template-columns:1fr} }
</style>

<div class="md-hero">
  <h1>💰 Money Dashboard</h1>
  <p>Every rupee, all-time — pulled from COD Ledger, Bank Accounts, Expenses, and Salary together</p>
  <?php if($integrityIssues>0): ?>
    <div class="md-recon warn">⚠ <?= $integrityIssues ?> broken link(s) found — a linked entry points to a bank transaction that no longer exists</div>
  <?php elseif($oldSalaryExpAmt>0): ?>
    <div class="md-recon warn">⚠ <?= money($oldSalaryExpAmt) ?> in old "Salary"-category expenses excluded from this page — Staff &amp; Salary is now the one source for that. See Expenses to clean these up.</div>
  <?php elseif($totalUnlinked>0): ?>
    <div class="md-recon warn">ℹ️ <?= money($totalUnlinked) ?> across COD/Expenses/Salary/Vendor Payments isn't linked to a bank account yet — everything else checks out</div>
  <?php else: ?>
    <div class="md-recon ok">✓ Everything is linked and consistent — no mismatches found</div>
  <?php endif; ?>
  <?php if($adsExpAmt>0): ?>
    <div class="md-recon" style="background:rgba(236,72,153,.15);border:1px solid rgba(236,72,153,.4);color:#ec4899">ℹ️ <?= money($adsExpAmt) ?> recorded under the "Ads" expense category isn't counted separately here — 📣 Ads Payment below (what's actually paid to your Ads payee) is the one cash-out figure for ads on this page.</div>
  <?php endif; ?>
</div>

<div class="md-bigrow">
  <div class="md-big total">
    <div class="l">Total Cash Position</div>
    <div class="v"><?= money($totalPosition) ?></div>
    <div class="s">what you actually have, right now</div>
    <div class="md-split">
      <div><b><?= money($totalInBanks) ?></b><span>In Banks &amp; Wallets</span></div>
      <div><b><?= money($cashInHand) ?></b><span>Cash In Hand</span></div>
      <div><b><?= money($stillWithCouriers) ?></b><span>Still With Couriers</span></div>
    </div>
  </div>
  <div class="md-big">
    <div class="l">Total In, All-Time</div>
    <div class="v" style="color:#16a34a"><?= money($codCollected) ?></div>
    <div class="s">COD collected (gross), before courier charges</div>
  </div>
  <div class="md-big">
    <div class="l">Total Out, All-Time</div>
    <div class="v" style="color:#dc2626"><?= money($totalOutAllTime) ?></div>
    <div class="s">courier charges + expenses + salary + vendor payments + direct payouts</div>
  </div>
</div>

<div class="md-flow">
  <div class="md-card">
    <h3>📥 Where Money Came From</h3>
    <div class="md-row"><span class="nm"><span class="md-dot" style="background:#4ade80"></span>COD Released by Couriers</span><span class="amt"><?= money($codReleased) ?></span></div>
    <div class="md-row"><span class="nm"><span class="md-dot" style="background:#22d3ee"></span>Other Bank Deposits (non-COD)</span><span class="amt"><?= money($otherDeposits) ?></span></div>
  </div>
  <div class="md-card">
    <h3>📤 Where Money Went — by Category</h3>
    <div class="md-row"><span class="nm"><span class="md-dot" style="background:#f87171"></span>Courier Charges (kept by couriers)</span><span class="amt"><?= money($codCharges) ?></span></div>
    <?php foreach(array_slice($expByCat,0,4) as $ec): ?>
    <div class="md-row"><span class="nm"><span class="md-dot" style="background:#fb923c"></span><?= e($ec['category']) ?></span><span class="amt"><?= money($ec['amt']) ?></span></div>
    <?php endforeach; ?>
    <div class="md-row"><span class="nm"><span class="md-dot" style="background:#a78bfa"></span>Salary</span><span class="amt"><?= money($salTotal) ?></span></div>
    <?php if($adsPayeeId): ?><div class="md-row"><span class="nm"><span class="md-dot" style="background:#ec4899"></span>📣 Ads Payment</span><span class="amt"><?= money($adsPayTotal) ?></span></div><?php endif; ?>
    <div class="md-row"><span class="nm"><span class="md-dot" style="background:#f472b6"></span>🏭 Vendor Payment</span><span class="amt"><?= money($vendorPayTotal) ?></span></div>
    <div class="md-row"><span class="nm"><span class="md-dot" style="background:#94a3b8"></span>COD Money Out (direct payouts)</span><span class="amt"><?= money($codOut) ?></span></div>
  </div>
</div>

<div class="md-accounts">
  <h3>🏦 Every Account, Right Now</h3>
  <div class="md-acctgrid">
    <?php foreach($accounts as $A):
      $linkedN = (int)val("SELECT
        (SELECT COUNT(*) FROM expenses WHERE account_id=?) +
        (SELECT COUNT(*) FROM salary_entries WHERE account_id=?) +
        (SELECT COUNT(*) FROM cod_ledger WHERE account_id=?) +
        (SELECT COUNT(*) FROM payee_ledger WHERE account_id=?)",[$A['id'],$A['id'],$A['id'],$A['id']]); ?>
    <div class="md-acct">
      <div class="an"><?= $A['kind']==='cash'?'💵':'🏦' ?> <?= e($A['name']) ?></div>
      <div class="av"><?= money($acctBal[$A['id']] ?? 0) ?></div>
      <div class="src"><?= $linkedN ?> linked entr<?= $linkedN===1?'y':'ies' ?></div>
    </div>
    <?php endforeach; if(!$accounts): ?><div class="empty">No accounts yet — add one on the Bank Accounts page.</div><?php endif; ?>
  </div>
</div>

<div class="md-timeline">
  <h3>📊 Money In vs Out — Last 6 Months
    <span class="md-legend"><span><span class="md-dot" style="background:#4ade80;display:inline-block"></span>In</span><span><span class="md-dot" style="background:#f87171;display:inline-block"></span>Out</span></span>
  </h3>
  <div class="md-bars">
    <?php foreach($months as $mo): ?>
    <div class="md-bargroup">
      <div class="md-barpair">
        <div class="md-bar in" style="height:<?= max(3,round($monthIn[$mo]/$maxMonthVal*100)) ?>%" title="In: <?= money($monthIn[$mo]) ?>"></div>
        <div class="md-bar out" style="height:<?= max(3,round($monthOut[$mo]/$maxMonthVal*100)) ?>%" title="Out: <?= money($monthOut[$mo]) ?>"></div>
      </div>
      <div class="md-barlbl"><?= e(date('M',strtotime($mo.'-01'))) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require __DIR__.'/includes/footer.php'; ?>