<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
$PAGE_TITLE='Bank Accounts';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;

ensure_banks();
try { q("CREATE TABLE IF NOT EXISTS cod_ledger(
  id INT AUTO_INCREMENT PRIMARY KEY,
  entry_date DATE NOT NULL,
  type ENUM('in','out') NOT NULL DEFAULT 'in',
  courier_id INT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  method VARCHAR(20) DEFAULT 'cash',
  reference VARCHAR(80) DEFAULT '',
  note VARCHAR(255) DEFAULT '',
  created_by VARCHAR(80) DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch (Exception $e) {}
try { q("ALTER TABLE cod_ledger ADD COLUMN IF NOT EXISTS account_id INT NULL"); } catch (Exception $e) {}
try { q("ALTER TABLE cod_ledger ADD COLUMN IF NOT EXISTS bank_txn_id INT NULL"); } catch (Exception $e) {}

/* Mirror a COD-categorized bank transaction INTO the COD Ledger — the reverse
   direction of cod_bank_sync() in cod.php. created_by='Bank Sync' marks this
   cod_ledger row as a MIRROR (not a primary record), so if the bank side is
   ever deleted, we know to delete the mirror too rather than just unlink it —
   the exact opposite of what del_txn already correctly does for entries that
   originated FROM the COD Ledger. */
function bank_cod_sync(int $txnId, int $accountId, string $direction, float $amount, string $date, string $category, string $remarks): void {
  if (!in_array($category, ['COD Deposit','COD Money Out'], true)) return;
  $type = $direction==='out' ? 'out' : 'in';
  q("INSERT INTO cod_ledger(entry_date,type,amount,method,account_id,bank_txn_id,note,created_by) VALUES(?,?,?,?,?,?,?,?)",
    [$date, $type, $amount, 'bank', $accountId, $txnId, $remarks, 'Bank Sync']);
}

/* one-time backfill: bank transactions already categorized as COD-related that were
   created BEFORE this sync existed, and never got a matching COD Ledger entry */
if (setting('bank_cod_backfill_done','') !== '1') {
  try {
    $orphans = rows("SELECT * FROM bank_txns WHERE category IN ('COD Deposit','COD Money Out')
                      AND id NOT IN (SELECT COALESCE(bank_txn_id,0) FROM cod_ledger)");
    foreach ($orphans as $t) {
      bank_cod_sync((int)$t['id'], (int)$t['account_id'], $t['direction'], (float)$t['amount'], $t['txn_date'], $t['category'], (string)($t['remarks'] ?? ''));
    }
    if ($orphans) log_activity('Backfilled '.count($orphans).' old COD-categorized bank transaction(s) into COD Ledger','Banks');
  } catch (Exception $e) {}
  set_setting('bank_cod_backfill_done','1');
}

/* ---------------- POST handlers ---------------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act=$_POST['_action'] ?? '';

  if ($act==='add_bank') {
    $n=trim($_POST['name'] ?? '');
    $kind=in_array($_POST['kind'] ?? 'bank',['bank','wallet','cash'],true)?$_POST['kind']:'bank';
    if ($n!=='') {
      q("INSERT INTO bank_accounts(name,acct_no,kind,opening,remarks) VALUES(?,?,?,?,?)",
        [$n, trim($_POST['acct_no'] ?? ''), $kind, (float)($_POST['opening'] ?? 0), trim($_POST['remarks'] ?? '')]);
      log_activity("Bank account added: $n",'Banks');
      flash('🏦 Account added.');
    } else flash('Please enter an account name.');
    header('Location: banks.php'); exit;
  }

  if ($act==='edit_bank') {
    $id=(int)($_POST['id'] ?? 0);
    $n=trim($_POST['name'] ?? '');
    if ($id && $n!=='') {
      $kind=in_array($_POST['kind'] ?? 'bank',['bank','wallet','cash'],true)?$_POST['kind']:'bank';
      q("UPDATE bank_accounts SET name=?, acct_no=?, kind=?, opening=?, remarks=? WHERE id=?",
        [$n, trim($_POST['acct_no'] ?? ''), $kind, (float)($_POST['opening'] ?? 0), trim($_POST['remarks'] ?? ''), $id]);
      flash('✎ Account updated.');
    }
    header('Location: banks.php'); exit;
  }

  if ($act==='archive_bank' && $isAdmin) {
    q("UPDATE bank_accounts SET archived=1 WHERE id=?",[(int)($_POST['id'] ?? 0)]);
    flash('Account archived (its transactions are kept).');
    header('Location: banks.php'); exit;
  }

  if ($act==='add_txn') {
    $accId=(int)($_POST['account_id'] ?? 0);
    $amt=(float)($_POST['amount'] ?? 0);
    $dir=($_POST['direction'] ?? '')==='out' ? 'out' : 'in';
    if ($accId && $amt>0) {
      $txnDate = ($_POST['txn_date'] ?? '') ?: date('Y-m-d');
      $cat = trim($_POST['category'] ?? '');
      $rem = trim($_POST['remarks'] ?? '');
      q("INSERT INTO bank_txns(account_id,txn_date,direction,amount,category,remarks) VALUES(?,?,?,?,?,?)",
        [$accId, $txnDate, $dir, $amt, $cat, $rem]);
      $newTxnId = (int)db()->lastInsertId();
      try { bank_cod_sync($newTxnId, $accId, $dir, $amt, $txnDate, $cat, $rem); } catch (Exception $e) {}
      log_activity(($dir==='in'?'Money in ':'Money out ').money($amt)." (account #$accId)",'Banks');
      flash(($dir==='in'?'➕ Money in ':'➖ Money out ').money($amt).' recorded.');
    } else flash('Pick an account and enter an amount.');
    header('Location: banks.php'.($accId?'?a='.$accId:'')); exit;
  }

  if ($act==='edit_txn') {
    $id=(int)($_POST['id']??0);
    $t = row("SELECT * FROM bank_txns WHERE id=?",[$id]);
    if ($t) {
      $newCat  = trim($_POST['category']??'');
      $newAmt  = (float)($_POST['amount']??$t['amount']);
      $newDate = ($_POST['txn_date']??'') ?: $t['txn_date'];
      $newRem  = trim($_POST['remarks']??'');
      q("UPDATE bank_txns SET txn_date=?, amount=?, category=?, remarks=? WHERE id=?",[$newDate,$newAmt,$newCat,$newRem,$id]);

      $mirror   = row("SELECT * FROM cod_ledger WHERE bank_txn_id=? AND created_by='Bank Sync'",[$id]);
      $isCodCat = in_array($newCat, ['COD Deposit','COD Money Out'], true);

      if ($mirror && !$isCodCat) {
        q("DELETE FROM cod_ledger WHERE id=?",[$mirror['id']]);   /* category moved OFF cod-related — mirror no longer belongs */
      } elseif ($mirror && $isCodCat) {
        q("UPDATE cod_ledger SET entry_date=?, amount=?, note=? WHERE id=?",[$newDate,$newAmt,$newRem,$mirror['id']]);  /* still cod-related — keep mirror in sync */
      } elseif (!$mirror && $isCodCat) {
        $realLink = (int)val("SELECT COUNT(*) FROM cod_ledger WHERE bank_txn_id=?",[$id]);
        if (!$realLink) { try { bank_cod_sync($id,(int)$t['account_id'],$t['direction'],$newAmt,$newDate,$newCat,$newRem); } catch (Exception $e) {} }
      }
      flash('Transaction updated.');
    }
    header('Location: banks.php'.(isset($_POST['a'])?'?a='.(int)$_POST['a']:'')); exit;
  }

  if ($act==='del_txn') {
    /* if this txn was created FROM the COD Ledger, un-link that entry (it becomes ⏳ not banked).
       If instead this txn's cod_ledger row is a MIRROR we created (via bank_cod_sync), delete
       that mirror outright — it has no reason to exist without the bank side it was copying. */
    try {
      $mirrorId = (int)val("SELECT id FROM cod_ledger WHERE bank_txn_id=? AND created_by='Bank Sync'",[(int)($_POST['id'] ?? 0)]);
      if ($mirrorId) { q("DELETE FROM cod_ledger WHERE id=?",[$mirrorId]); }
      else { q("UPDATE cod_ledger SET account_id=NULL, bank_txn_id=NULL WHERE bank_txn_id=?",[(int)($_POST['id'] ?? 0)]); }
    } catch (Exception $e) {}
    q("DELETE FROM bank_txns WHERE id=?",[(int)($_POST['id'] ?? 0)]);
    flash('Transaction deleted.');
    header('Location: banks.php'.(isset($_POST['a'])?'?a='.(int)$_POST['a']:'')); exit;
  }
}

/* ---------------- data ---------------- */
$accounts = rows("SELECT * FROM bank_accounts WHERE archived=0 ORDER BY kind='cash', name");
/* COD-linked flows per account (transactions created from the COD Ledger) */
$codFlow=[];
try {
  foreach(rows("SELECT account_id, direction, COALESCE(SUM(amount),0) s FROM bank_txns
                WHERE category LIKE 'COD %' OR category='COD Money Out' OR category LIKE 'COD Release%'
                GROUP BY account_id, direction") as $r)
    $codFlow[(int)$r['account_id']][$r['direction']]=(float)$r['s'];
} catch (Exception $e) {}
$view = isset($_GET['a']) ? (int)$_GET['a'] : 0;   /* 0 = all transactions */

/* totals */
$totAll=0; $totBank=0; $totCash=0;
foreach ($accounts as &$a) {
  $a['balance']=bank_balance($a['id']);
  $totAll += $a['balance'];
  if ($a['kind']==='cash') $totCash += $a['balance']; else $totBank += $a['balance'];
}
unset($a);

/* this-month movement across all accounts */
$mStart=date('Y-m-01');
$mIn =(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE direction='in'  AND txn_date>=?",[$mStart]);
$mOut=(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE direction='out' AND txn_date>=?",[$mStart]);
$mNet=$mIn-$mOut;

/* transaction list (all, or for one account) with running balance per account */
if ($view) {
  $txns = rows("SELECT t.*, b.name AS acct_name, b.kind FROM bank_txns t
                JOIN bank_accounts b ON b.id=t.account_id
                WHERE t.account_id=? ORDER BY t.txn_date ASC, t.id ASC",[$view]);
} else {
  $txns = rows("SELECT t.*, b.name AS acct_name, b.kind FROM bank_txns t
                JOIN bank_accounts b ON b.id=t.account_id
                ORDER BY t.txn_date ASC, t.id ASC");
}
/* compute running balance per account (opening + running) */
$openMap=[]; foreach ($accounts as $a) $openMap[$a['id']]=(float)$a['opening'];
$run=[]; foreach ($accounts as $a) $run[$a['id']]=(float)$a['opening'];
foreach ($txns as &$t) {
  $aid=$t['account_id'];
  if (!isset($run[$aid])) $run[$aid]=0;
  $run[$aid] += ($t['direction']==='in' ? (float)$t['amount'] : -(float)$t['amount']);
  $t['balance_after']=$run[$aid];
}
unset($t);
$txns = array_reverse($txns);   /* newest first for display */

$kindIcon = ['bank'=>'🏦','wallet'=>'📱','cash'=>'💵'];
$catList = ['COD Deposit','COD Money Out','Transfer','Supplier Payment','Ad Spend','Salary','Rent','Interest','Refund','Correction','Other'];

require __DIR__.'/includes/header.php';
?>
<style>
.bk-totals{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:20px}
.bk-tcard{background:var(--surface);border-radius:16px;padding:15px 18px;box-shadow:var(--shadow);border:1px solid var(--border);position:relative;overflow:hidden}
.bk-tcard .lbl{font-size:11px;color:var(--muted);font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.bk-tcard .amt{font-size:23px;font-weight:900;margin-top:3px;font-variant-numeric:tabular-nums}
.bk-tcard .meta{font-size:11px;color:var(--muted);margin-top:2px}
.bk-tcard .ic{position:absolute;right:14px;top:13px;font-size:21px;opacity:.8}
.bk-tcard.big{background:linear-gradient(135deg,#1f2740,#2b3556);color:#fff;border:0}
.bk-tcard.big .lbl{color:#aeb8d4}.bk-tcard.big .meta{color:#8b95b8}

.bk-banks{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-bottom:22px}
.bk-card{background:var(--surface);border-radius:16px;padding:16px;box-shadow:var(--shadow);border:1px solid var(--border);border-top:4px solid var(--brand)}
.bk-card.k-wallet{border-top-color:#e0851b}.bk-card.k-cash{border-top-color:#8a94a6}
.bk-card .bn{font-weight:900;font-size:15px;display:flex;justify-content:space-between;align-items:center;gap:6px}
.bk-card .acct{font-size:11px;color:var(--muted);font-family:ui-monospace,monospace;margin-top:1px}
.bk-card .bal-lbl{font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:800;margin-top:10px}
.bk-card .bal{font-size:27px;font-weight:900;margin:1px 0 2px;font-variant-numeric:tabular-nums;color:var(--ink);line-height:1.1}
.bk-card.pos .bal{color:#12a06a}.bk-card.neg .bal{color:#dc4437}
.bk-card .rmk{font-size:11.5px;color:var(--muted);font-style:italic;margin-top:5px;padding-top:8px;border-top:1px dashed var(--border)}
.bk-card .acts{display:flex;gap:6px;margin-top:12px;flex-wrap:wrap}
.bk-card .acts .btn{padding:6px 10px;font-size:11px}
.bk-card.sel{outline:2px solid var(--brand);outline-offset:1px}

.bk-forms{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:22px}
@media(max-width:820px){.bk-forms{grid-template-columns:1fr}}
.bk-form{background:var(--surface);border-radius:16px;box-shadow:var(--shadow);border:1px solid var(--border);padding:15px 17px}
.bk-form h3{font-size:13px;font-weight:900;margin:0 0 12px}
.bk-fg{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.bk-fg .full{grid-column:1/-1}
.bk-fg label{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:800;display:block;margin-bottom:3px}
.bk-fg input,.bk-fg select{width:100%;border:1px solid var(--border);border-radius:9px;padding:8px 10px;font:inherit;font-size:12.5px;background:var(--surface-2);color:var(--ink)}
.seg{display:inline-flex;border:1px solid var(--border);border-radius:9px;overflow:hidden;width:100%}
.seg label{flex:1;text-align:center;cursor:pointer;padding:8px 6px;font-size:12px;font-weight:800;color:var(--muted);background:var(--surface-2)}
.seg input{display:none}
.seg input:checked + label.in{background:#12a06a;color:#fff}
.seg input:checked + label.out{background:#dc4437;color:#fff}

.bk-panel{background:var(--surface);border-radius:16px;box-shadow:var(--shadow);border:1px solid var(--border);overflow:hidden}
.bk-panel-h{padding:13px 17px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.bk-panel-h b{font-size:14px}
.bk-filters{display:flex;gap:6px;flex-wrap:wrap}
.bk-chip{border:1px solid var(--border);background:var(--surface-2);border-radius:999px;padding:5px 12px;font-size:11.5px;font-weight:700;cursor:pointer;color:var(--ink);text-decoration:none}
.bk-chip.on{background:var(--brand);color:#fff;border-color:var(--brand)}
.bk-table{width:100%;border-collapse:collapse;font-size:12.5px}
.bk-table th{font-size:9.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);text-align:left;padding:10px 14px;border-bottom:1px solid var(--border);font-weight:800}
.bk-table th.num,.bk-table td.num{text-align:right;font-variant-numeric:tabular-nums}
.bk-table td{padding:10px 14px;border-bottom:1px solid var(--border)}
.bk-table tr:last-child td{border-bottom:0}
.t-in{color:#12a06a;font-weight:800}.t-out{color:#dc4437;font-weight:800}
.bk-cat{font-size:10px;font-weight:800;border-radius:99px;padding:2px 8px;background:var(--surface-2);color:var(--muted)}
.bk-rmk{color:var(--muted);font-style:italic;font-size:11.5px}
.bk-empty{padding:34px;text-align:center;color:var(--muted)}
.bk-del{border:0;background:none;color:var(--red);cursor:pointer;opacity:.5;font-size:13px}
.bk-del:hover{opacity:1}
</style>

<div class="page-head">
  <div><h1>🏦 Bank Accounts</h1><p>Your account balances, every transaction, and a place to keep records — with remarks</p></div>
</div>

<?php if (!$accounts): ?>
  <div class="bk-panel" style="margin-bottom:22px"><div class="bk-empty">
    <div style="font-size:30px">🏦</div>
    <p style="margin:8px 0;font-weight:700">No accounts yet</p>
    <p style="font-size:12px">Add your first bank, wallet, or cash box below to start tracking money.</p>
  </div></div>
<?php else: ?>
  <!-- TOTALS -->
  <div class="bk-totals">
    <div class="bk-tcard big"><div class="lbl">Total Available</div><div class="amt"><?= money($totAll) ?></div><div class="meta"><?= count($accounts) ?> account<?= count($accounts)>1?'s':'' ?></div><div class="ic">💰</div></div>
    <div class="bk-tcard"><div class="lbl">🏦 In Banks &amp; Wallets</div><div class="amt"><?= money($totBank) ?></div><div class="ic">🏦</div></div>
    <div class="bk-tcard"><div class="lbl">💵 Cash in Hand</div><div class="amt"><?= money($totCash) ?></div><div class="ic">💵</div></div>
    <div class="bk-tcard"><div class="lbl">This month</div><div class="amt" style="color:<?= $mNet>=0?'#12a06a':'#dc4437' ?>"><?= ($mNet>=0?'+ ':'− ').money(abs($mNet)) ?></div><div class="meta">in <?= money($mIn) ?> · out <?= money($mOut) ?></div><div class="ic">📈</div></div>
  </div>

  <!-- BANK CARDS -->
  <div class="bk-banks">
  <?php foreach ($accounts as $a): $ic=$kindIcon[$a['kind']] ?? '🏦'; ?>
    <div class="bk-card k-<?= e($a['kind']) ?><?= $a['balance']>0?' pos':($a['balance']<0?' neg':'') ?><?= $view===$a['id']?' sel':'' ?>">
      <div class="bn"><span><?= $ic ?> <?= e($a['name']) ?></span></div>
      <?php if ($a['acct_no']!==''): ?><div class="acct">A/C <?= e($a['acct_no']) ?></div><?php endif; ?>
      <div class="bal-lbl">Balance</div>
      <div class="bal"><?= money($a['balance']) ?></div>
      <?php if ($a['remarks']!==''): ?><div class="rmk">✎ <?= e($a['remarks']) ?></div><?php endif; ?>
      <div class="acts">
        <?php $cf=$codFlow[(int)$a['id']] ?? null; if($cf): ?>
        <span class="pill p-blue" style="font-size:10px" title="money that arrived from the COD Ledger">🔗 COD in: <?= money($cf['in'] ?? 0) ?></span>
        <?php if(($cf['out']??0)>0): ?><span class="pill p-red" style="font-size:10px" title="COD-related money out, linked with the COD Ledger">🔗 COD out: <?= money($cf['out']) ?></span><?php endif; ?>
        <?php if(($cf['out'] ?? 0)>0): ?><span class="pill p-red" style="font-size:10px" title="money that left via the COD Ledger">out: <?= money($cf['out']) ?></span><?php endif; ?>
        <?php endif; ?>
        <a class="btn btn-sm<?= $view===$a['id']?' btn-primary':'' ?>" href="banks.php?a=<?= $a['id'] ?>">Ledger</a>
        <button class="btn btn-sm" onclick='txnFor(<?= (int)$a['id'] ?>,<?= json_encode($a['name']) ?>)'>＋ Txn</button>
        <button class="btn btn-sm" onclick='editBank(<?= json_encode($a) ?>)'>✎ Edit</button>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- FORMS -->
<div class="bk-forms">
  <!-- add bank -->
  <div class="bk-form">
    <h3>＋ Add a Bank / Wallet / Cash</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="add_bank">
      <div class="bk-fg">
        <div class="full"><label>Account Name *</label><input name="name" required placeholder="e.g. NIC Asia Bank"></div>
        <div><label>Account Number (optional)</label><input name="acct_no" placeholder="••••1234"></div>
        <div><label>Type</label><select name="kind"><option value="bank">🏦 Bank</option><option value="wallet">📱 Wallet (eSewa/Khalti)</option><option value="cash">💵 Cash</option></select></div>
        <div><label>Opening Balance (Rs.)</label><input name="opening" type="number" step="any" value="0"></div>
        <div class="full"><label>Remarks</label><input name="remarks" placeholder="e.g. Main business account"></div>
      </div>
      <div style="margin-top:12px;text-align:right"><button class="btn btn-primary">Save Account</button></div>
    </form>
  </div>

  <!-- record transaction -->
  <div class="bk-form">
    <h3>＋ Record a Transaction</h3>
    <?php if (!$accounts): ?>
      <p class="muted" style="font-size:12px">Add an account first, then you can record money in and out here.</p>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="add_txn">
      <div class="bk-fg">
        <div><label>Account</label><select name="account_id" id="txnAcct" required>
          <?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>"<?= $view===$a['id']?' selected':'' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div><label>Direction</label>
          <div class="seg">
            <input type="radio" name="direction" id="dir_in" value="in" checked><label class="in" for="dir_in">＋ In</label>
            <input type="radio" name="direction" id="dir_out" value="out"><label class="out" for="dir_out">− Out</label>
          </div>
        </div>
        <div><label>Amount (Rs.) *</label><input name="amount" id="txnAmt" type="number" step="any" required placeholder="0"></div>
        <div><label>Date</label><input name="txn_date" type="date" value="<?= date('Y-m-d') ?>"></div>
        <div class="full"><label>Category</label><select name="category">
          <?php foreach ($catList as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
        </select></div>
        <div class="full"><label>Remarks</label><input name="remarks" placeholder="e.g. Deposited NCM COD for 20 Jul"></div>
      </div>
      <div style="margin-top:12px;text-align:right"><button class="btn btn-primary">Save Transaction</button></div>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- TRANSACTIONS -->
<div class="bk-panel">
  <div class="bk-panel-h">
    <b>📒 <?= $view ? 'Ledger — '.e((function() use($accounts,$view){foreach($accounts as $a)if($a['id']===$view)return $a['name'];return '';})()) : 'All Transactions' ?></b>
    <div class="bk-filters">
      <a class="bk-chip<?= $view===0?' on':'' ?>" href="banks.php">All accounts</a>
      <?php foreach ($accounts as $a): ?>
        <a class="bk-chip<?= $view===$a['id']?' on':'' ?>" href="banks.php?a=<?= $a['id'] ?>"><?= e($a['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!$txns): ?>
    <div class="bk-empty">No transactions yet<?= $view?' for this account':'' ?>. Use “Record a Transaction” above to add one.</div>
  <?php else: ?>
    <div style="overflow-x:auto">
    <table class="bk-table">
      <thead><tr>
        <th>Date</th><?php if(!$view): ?><th>Account</th><?php endif; ?>
        <th>Category</th><th>Remarks</th>
        <th class="num">In</th><th class="num">Out</th><th class="num">Balance</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($txns as $t): ?>
        <tr>
          <td><?= e(date('d M', strtotime($t['txn_date']))) ?></td>
          <?php if(!$view): ?><td><?= ($kindIcon[$t['kind']]??'') ?> <?= e($t['acct_name']) ?></td><?php endif; ?>
          <td><?php if($t['category']!==''): ?><span class="bk-cat"><?= (strpos($t['category'],'COD ')===0?'🔗 ':'') ?><?= e($t['category']) ?></span><?php else: ?>—<?php endif; ?></td>
          <td class="bk-rmk"><?= $t['remarks']!==''?e($t['remarks']):'—' ?></td>
          <td class="num"><?= $t['direction']==='in' ? '<span class="t-in">+'.number_format($t['amount']).'</span>' : '—' ?></td>
          <td class="num"><?= $t['direction']==='out' ? '<span class="t-out">−'.number_format($t['amount']).'</span>' : '—' ?></td>
          <td class="num"><?= number_format($t['balance_after']) ?></td>
          <td class="num" style="white-space:nowrap">
            <button class="iact" title="Edit" onclick='editTxn(<?= json_encode($t, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✏️</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this transaction?')">
              <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="del_txn">
              <input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="a" value="<?= $view ?>">
              <button class="bk-del" title="Delete">🗑</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
<p style="font-size:11.5px;color:var(--muted);margin-top:10px">💡 The Balance column is the running balance for that account after each entry. This page keeps your own records — later we can auto-pull COD, expenses and payee payments in too.</p>

<!-- edit bank modal -->
<div class="modal-bg" id="ebBg" style="z-index:99990">
  <div class="modal" style="width:460px;max-width:94vw">
    <div class="modal-head"><span>✎ Edit Account</span><span class="mx" onclick="closeM('ebBg')">✕</span></div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="edit_bank"><input type="hidden" name="id" id="eb_id">
      <div style="padding:16px 20px">
        <div class="bk-fg">
          <div class="full"><label>Account Name *</label><input name="name" id="eb_name" required></div>
          <div><label>Account Number</label><input name="acct_no" id="eb_acct"></div>
          <div><label>Type</label><select name="kind" id="eb_kind"><option value="bank">🏦 Bank</option><option value="wallet">📱 Wallet</option><option value="cash">💵 Cash</option></select></div>
          <div class="full"><label>Opening Balance (Rs.)</label><input name="opening" id="eb_open" type="number" step="any"></div>
          <div class="full"><label>Remarks</label><input name="remarks" id="eb_rmk"></div>
        </div>
      </div>
      <div class="modal-foot">
        <?php if ($isAdmin): ?>
        <button type="button" class="btn" style="color:var(--red);margin-right:auto" onclick="archiveBank()">Archive</button>
        <?php endif; ?>
        <button type="button" class="btn" onclick="closeM('ebBg')">Cancel</button>
        <button class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<form method="post" id="archiveForm" style="display:none"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="archive_bank"><input type="hidden" name="id" id="ar_id"></form>

<!-- edit transaction modal -->
<div class="modal-bg" id="etBg" style="z-index:99990">
  <div class="modal" style="width:460px;max-width:94vw">
    <div class="modal-head"><span>✎ Edit Transaction</span><span class="mx" onclick="closeM('etBg')">✕</span></div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="edit_txn"><input type="hidden" name="id" id="et_id"><input type="hidden" name="a" value="<?= $view ?>">
      <div style="padding:16px 20px">
        <p class="muted" style="font-size:11.5px;margin-bottom:12px">Account and direction can't be changed here — delete and re-add if those need to change. Changing the category to/from "COD Deposit" or "COD Money Out" automatically links or unlinks it with COD Ledger.</p>
        <div class="bk-fg">
          <div><label>Amount (Rs.)</label><input name="amount" id="et_amt" type="number" step="any"></div>
          <div><label>Date</label><input name="txn_date" id="et_date" type="date"></div>
          <div class="full"><label>Category</label><select name="category" id="et_cat">
            <?php foreach ($catList as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
          </select></div>
          <div class="full"><label>Remarks</label><input name="remarks" id="et_rmk"></div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" onclick="closeM('etBg')">Cancel</button>
        <button class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openM(id){document.getElementById(id).classList.add('open');document.body.classList.add('modal-open');}
function closeM(id){document.getElementById(id).classList.remove('open');document.body.classList.remove('modal-open');}
function txnFor(id,name){
  var sel=document.getElementById('txnAcct'); if(sel){sel.value=id;}
  var amt=document.getElementById('txnAmt'); if(amt){amt.focus();amt.scrollIntoView({behavior:'smooth',block:'center'});}
}
function editBank(a){
  document.getElementById('eb_id').value=a.id;
  document.getElementById('eb_name').value=a.name||'';
  document.getElementById('eb_acct').value=a.acct_no||'';
  document.getElementById('eb_kind').value=a.kind||'bank';
  document.getElementById('eb_open').value=a.opening||0;
  document.getElementById('eb_rmk').value=a.remarks||'';
  openM('ebBg');
}
function archiveBank(){
  if(!confirm('Archive this account? Its transactions are kept, but it will be hidden from the list.'))return;
  document.getElementById('ar_id').value=document.getElementById('eb_id').value;
  document.getElementById('archiveForm').submit();
}
function editTxn(t){
  document.getElementById('et_id').value=t.id;
  document.getElementById('et_amt').value=t.amount||0;
  document.getElementById('et_date').value=t.txn_date||'';
  document.getElementById('et_cat').value=t.category||'Other';
  document.getElementById('et_rmk').value=t.remarks||'';
  openM('etBg');
}
var _et=document.getElementById('etBg'); if(_et)_et.addEventListener('click',function(e){if(e.target===_et)closeM('etBg');});
var _eb=document.getElementById('ebBg'); if(_eb)_eb.addEventListener('click',function(e){if(e.target===_eb)closeM('ebBg');});
</script>
<?php require __DIR__.'/includes/footer.php'; ?>