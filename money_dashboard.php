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
<style>
.md-hero{position:relative;overflow:hidden;background:linear-gradient(125deg,#0b1220,#132239 55%,#1c2f4a);border-radius:24px;padding:28px 32px;color:#fff;margin-bottom:18px;box-shadow:0 22px 48px rgba(11,18,32,.35)}
.md-hero:before{content:'';position:absolute;inset:0;background:radial-gradient(560px 260px at 88% -10%,rgba(250,204,21,.14),transparent 60%),radial-gradient(420px 220px at 8% 110%,rgba(52,211,153,.12),transparent 60%);pointer-events:none}
.md-hero>*{position:relative}
.md-hero h1{font-size:23px;font-weight:900;margin:0;display:flex;align-items:center;gap:10px;letter-spacing:-.01em}
.md-hero p{opacity:.72;font-size:12.5px;margin:4px 0 0}
.md-recon{display:inline-flex;align-items:center;gap:8px;border-radius:99px;padding:7px 16px;font-size:11.5px;font-weight:800;margin-top:14px;margin-right:8px;backdrop-filter:blur(6px)}
.md-recon.ok{background:rgba(52,211,153,.14);border:1px solid rgba(52,211,153,.35);color:#6ee7b7}
.md-recon.warn{background:rgba(251,191,36,.14);border:1px solid rgba(251,191,36,.35);color:#fcd34d}

.md-bigrow{display:grid;grid-template-columns:1.35fr 1fr 1fr;gap:14px;margin-bottom:16px}
.md-big{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:22px 24px;box-shadow:0 10px 28px rgba(30,41,80,.06);transition:transform .18s ease,box-shadow .18s ease}
.md-big:hover{transform:translateY(-2px);box-shadow:0 16px 36px rgba(30,41,80,.1)}
.md-big.total{position:relative;overflow:hidden;background:linear-gradient(140deg,#0f2540,#143a5c 55%,#0f2540);border:1px solid rgba(255,255,255,.08);color:#fff}
.md-big.total:before{content:'';position:absolute;inset:0;background:radial-gradient(320px 180px at 100% 0%,rgba(250,204,21,.16),transparent 65%);pointer-events:none}
.md-big.total>*{position:relative}
.md-big .l{font-size:10.5px;font-weight:800;opacity:.6;text-transform:uppercase;letter-spacing:.06em;display:flex;align-items:center;gap:6px}
.md-big .v{font-size:27px;font-weight:900;margin-top:8px;letter-spacing:-.01em;font-variant-numeric:tabular-nums}
.md-big .s{font-size:11px;opacity:.6;margin-top:4px}
.md-split{display:flex;gap:16px;margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,.12)}
.md-split div{flex:1}
.md-split b{font-size:15px;display:block;font-variant-numeric:tabular-nums}
.md-split span{font-size:9.5px;opacity:.62;text-transform:uppercase;letter-spacing:.03em;font-weight:700}

.md-flow{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px}
.md-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:20px 22px;box-shadow:0 8px 24px rgba(30,41,80,.05)}
.md-card h3{font-size:13px;font-weight:900;margin-bottom:12px;display:flex;align-items:center;gap:8px;color:var(--ink)}
.md-row{position:relative;display:flex;justify-content:space-between;align-items:center;padding:10px 4px;border-radius:10px;font-size:12.5px;overflow:hidden}
.md-row+.md-row{margin-top:2px}
.md-row:hover{background:var(--surface-2)}
.md-row .fill{position:absolute;left:0;top:0;bottom:0;background:currentColor;opacity:.06;border-radius:10px;z-index:0}
.md-row .nm{position:relative;z-index:1;display:flex;align-items:center;gap:9px;color:var(--ink);font-weight:600}
.md-row .amt{position:relative;z-index:1;font-weight:800;font-variant-numeric:tabular-nums;color:var(--ink)}
.md-dot{width:9px;height:9px;border-radius:99px;flex:none;box-shadow:0 0 0 3px currentColor,0 0 0 3px transparent;opacity:1}

.md-accounts{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:20px 22px;box-shadow:0 8px 24px rgba(30,41,80,.05);margin-bottom:16px}
.md-accounts h3{font-size:13px;font-weight:900;margin-bottom:14px;color:var(--ink)}
.md-acctgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
.md-acct{background:var(--surface-2);border-radius:15px;padding:14px 16px;border-left:4px solid #6366f1;transition:transform .15s ease}
.md-acct:hover{transform:translateY(-2px)}
.md-acct.cash{border-left-color:#16a34a}
.md-acct .an{font-size:11px;font-weight:800;color:var(--ink)}
.md-acct .av{font-size:17px;font-weight:900;margin-top:4px;color:var(--ink);font-variant-numeric:tabular-nums}
.md-acct .src{font-size:9.5px;color:var(--muted);margin-top:5px;font-weight:600}

.md-timeline{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:20px 22px;box-shadow:0 8px 24px rgba(30,41,80,.05)}
.md-timeline h3{font-size:13px;font-weight:900;margin-bottom:16px;display:flex;align-items:center;gap:14px;color:var(--ink)}
.md-legend{display:flex;gap:14px;font-size:10.5px;font-weight:700;color:var(--muted)}
.md-legend span{display:inline-flex;align-items:center;gap:5px}
.md-bars{display:flex;align-items:flex-end;gap:10px;height:140px;padding-top:6px}
.md-bargroup{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;height:100%;justify-content:flex-end}
.md-barpair{display:flex;gap:4px;align-items:flex-end;width:100%;height:100%;justify-content:center}
.md-bar{width:15px;border-radius:6px 6px 2px 2px;transition:opacity .15s ease}
.md-bar:hover{opacity:.75}
.md-bar.in{background:linear-gradient(180deg,#4ade80,#16a34a)}
.md-bar.out{background:linear-gradient(180deg,#fb7185,#dc2626)}
.md-barlbl{font-size:9.5px;color:var(--muted);margin-top:6px;font-weight:700}

body.dark .md-hero{background:linear-gradient(125deg,#070c16,#0d1830 55%,#132239);box-shadow:0 22px 48px rgba(0,0,0,.5)}
body.dark .md-big.total{background:linear-gradient(140deg,#0a1930,#0f2b46 55%,#0a1930)}
body.dark .md-row .fill{opacity:.14}

@media(max-width:820px){ .md-bigrow{grid-template-columns:1fr} .md-flow{grid-template-columns:1fr} }
@media(max-width:520px){ .md-hero{padding:20px 20px} .md-hero h1{font-size:19px} .md-big .v{font-size:22px} }
</style>

<?php
/* proportional fill-bar widths for the two flow cards (visual only) */
$inMax = max(1, $codReleased, $otherDeposits);
$outRows = array_merge([$codCharges], array_column(array_slice($expByCat,0,4),'amt'), [$salTotal, $adsPayeeId?$adsPayTotal:0, $vendorPayTotal, $codOut]);
$outMax = max(1, ...array_map('floatval',$outRows));
?>
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
    <div class="md-row" style="color:#16a34a"><span class="fill" style="width:<?= round($codReleased/$inMax*100) ?>%"></span><span class="nm"><span class="md-dot" style="background:#4ade80"></span>COD Released by Couriers</span><span class="amt"><?= money($codReleased) ?></span></div>
    <div class="md-row" style="color:#0891b2"><span class="fill" style="width:<?= round($otherDeposits/$inMax*100) ?>%"></span><span class="nm"><span class="md-dot" style="background:#22d3ee"></span>Other Bank Deposits (non-COD)</span><span class="amt"><?= money($otherDeposits) ?></span></div>
  </div>
  <div class="md-card">
    <h3>📤 Where Money Went — by Category</h3>
    <div class="md-row" style="color:#dc2626"><span class="fill" style="width:<?= round($codCharges/$outMax*100) ?>%"></span><span class="nm"><span class="md-dot" style="background:#f87171"></span>Courier Charges (kept by couriers)</span><span class="amt"><?= money($codCharges) ?></span></div>
    <?php foreach(array_slice($expByCat,0,4) as $ec): ?>
    <div class="md-row" style="color:#ea580c"><span class="fill" style="width:<?= round($ec['amt']/$outMax*100) ?>%"></span><span class="nm"><span class="md-dot" style="background:#fb923c"></span><?= e($ec['category']) ?></span><span class="amt"><?= money($ec['amt']) ?></span></div>
    <?php endforeach; ?>
    <div class="md-row" style="color:#7c3aed"><span class="fill" style="width:<?= round($salTotal/$outMax*100) ?>%"></span><span class="nm"><span class="md-dot" style="background:#a78bfa"></span>Salary</span><span class="amt"><?= money($salTotal) ?></span></div>
    <?php if($adsPayeeId): ?><div class="md-row" style="color:#db2777"><span class="fill" style="width:<?= round($adsPayTotal/$outMax*100) ?>%"></span><span class="nm"><span class="md-dot" style="background:#ec4899"></span>📣 Ads Payment</span><span class="amt"><?= money($adsPayTotal) ?></span></div><?php endif; ?>
    <div class="md-row" style="color:#db2777"><span class="fill" style="width:<?= round($vendorPayTotal/$outMax*100) ?>%"></span><span class="nm"><span class="md-dot" style="background:#f472b6"></span>🏭 Vendor Payment</span><span class="amt"><?= money($vendorPayTotal) ?></span></div>
    <div class="md-row" style="color:#64748b"><span class="fill" style="width:<?= round($codOut/$outMax*100) ?>%"></span><span class="nm"><span class="md-dot" style="background:#94a3b8"></span>COD Money Out (direct payouts)</span><span class="amt"><?= money($codOut) ?></span></div>
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
    <div class="md-acct<?= $A['kind']==='cash'?' cash':'' ?>">
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