<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
$PAGE_TITLE='COD Ledger';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;

/* ---- ledger table ---- */
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

$couriers = rows("SELECT id,name FROM couriers ORDER BY name");
$codThr = (float)setting('cod_alert_threshold', 100000);

/* ---- actions ---- */
/* ---- Bank link: every COD rupee can land in a real account ---- */
try {
  foreach (['account_id'=>"ALTER TABLE cod_ledger ADD COLUMN account_id INT NULL",
            'bank_txn_id'=>"ALTER TABLE cod_ledger ADD COLUMN bank_txn_id INT NULL"] as $col=>$ddl) {
    $has=(int)val("SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cod_ledger' AND COLUMN_NAME=?",[$col]);
    if(!$has) q($ddl);
  }
  /* make sure a Cash in Hand account exists on the Bank page (Q1: yes) */
  $cashId=(int)val("SELECT COALESCE(MIN(id),0) FROM bank_accounts WHERE kind='cash' AND archived=0");
  if(!$cashId){ q("INSERT INTO bank_accounts(name,acct_no,kind,opening,remarks) VALUES('Cash in Hand','','cash',0,'auto-created for COD ledger link')"); }
} catch (Exception $e) {}
$linkAccounts = [];
try { $linkAccounts = rows("SELECT id,name,kind,opening FROM bank_accounts WHERE archived=0 ORDER BY kind='cash' DESC, name"); } catch (Exception $e) {}
$acctBal = [];
foreach($linkAccounts as $A){
  $in =(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='in'",[$A['id']]);
  $out=(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='out'",[$A['id']]);
  $acctBal[$A['id']]=(float)$A['opening']+$in-$out;
}
/* create/refresh the linked bank transaction for one COD entry; returns txn id or null */
function cod_bank_sync(array $e, ?int $accountId): ?int {
  $old=(int)($e['bank_txn_id'] ?? 0);
  if(!$accountId){ if($old) q("DELETE FROM bank_txns WHERE id=?",[$old]); return null; }
  $dir = $e['type']==='out' ? 'out' : 'in';
  $cName = $e['courier_id'] ? (string)val("SELECT name FROM couriers WHERE id=?",[$e['courier_id']]) : '';
  $cat = $e['type']==='out' ? 'COD Money Out' : trim('COD Release'.($cName?' — '.$cName:''));
  $rem = trim(($e['note']??'').(($e['reference']??'')!==''?' · ref '.$e['reference']:''));
  if($old){
    q("UPDATE bank_txns SET account_id=?, txn_date=?, direction=?, amount=?, category=?, remarks=? WHERE id=?",
      [$accountId,$e['entry_date'],$dir,(float)$e['amount'],$cat,$rem,$old]);
    return $old;
  }
  q("INSERT INTO bank_txns(account_id,txn_date,direction,amount,category,remarks) VALUES(?,?,?,?,?,?)",
    [$accountId,$e['entry_date'],$dir,(float)$e['amount'],$cat,$rem]);
  return (int)db()->lastInsertId();
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $act = $_POST['_action'] ?? '';
  check_csrf();

  if ($act==='upd' && $isAdmin) {
    $id=(int)($_POST['id'] ?? 0);
    $amt=(float)($_POST['amount'] ?? 0);
    if ($id && $amt>0) {
      q("UPDATE cod_ledger SET entry_date=?, type=?, courier_id=?, amount=?, method=?, reference=?, note=? WHERE id=?",[
        ($_POST['entry_date'] ?? '') ?: date('Y-m-d'),
        ($_POST['type'] ?? 'in')==='out' ? 'out' : 'in',
        (int)($_POST['courier_id'] ?? 0) ?: null,
        $amt,
        in_array($_POST['method'] ?? '', ['cash','bank','wallet'], true) ? $_POST['method'] : 'cash',
        trim($_POST['reference'] ?? ''),
        trim($_POST['note'] ?? ''),
        $id]);
      try{
        $e=row("SELECT * FROM cod_ledger WHERE id=?",[$id]);
        $acc=(int)($_POST['account_id'] ?? 0) ?: null;
        $tid=cod_bank_sync($e,$acc);
        q("UPDATE cod_ledger SET account_id=?, bank_txn_id=? WHERE id=?",[$acc,$tid,$id]);
      }catch(Exception $e){}
      log_activity("COD ledger entry #$id edited",'COD'); flash('Entry updated.');
    } else flash('Amount must be greater than 0.');
    header('Location: cod.php'); exit;
  }
  if ($act==='add') {
    $type = ($_POST['type'] ?? 'in')==='out' ? 'out' : 'in';
    $amt  = (float)($_POST['amount'] ?? 0);
    if ($amt<=0) { flash('Amount must be greater than 0.'); header('Location: cod.php'); exit; }
    q("INSERT INTO cod_ledger(entry_date,type,courier_id,amount,method,reference,note,created_by) VALUES(?,?,?,?,?,?,?,?)",[
      ($_POST['entry_date'] ?? '') ?: date('Y-m-d'),
      $type,
      (int)($_POST['courier_id'] ?? 0) ?: null,
      $amt,
      in_array($_POST['method'] ?? '', ['cash','bank','wallet'], true) ? $_POST['method'] : 'cash',
      trim($_POST['reference'] ?? ''),
      trim($_POST['note'] ?? ''),
      $u['name'] ?? ''
    ]);
    $newId=(int)db()->lastInsertId();
    $acc=(int)($_POST['account_id'] ?? 0);
    $acctNote='';
    if($acc>0){
      try{
        $e=row("SELECT * FROM cod_ledger WHERE id=?",[$newId]);
        $tid=cod_bank_sync($e,$acc);
        if($tid){ q("UPDATE cod_ledger SET account_id=?, bank_txn_id=? WHERE id=?",[$acc,$tid,$newId]);
          $an=(string)val("SELECT name FROM bank_accounts WHERE id=?",[$acc]);
          $acctNote=' → '.($type==='in'?'into ':'from ').$an;
        }
      }catch(Exception $e){}
    }
    log_activity(($type==='in'?'COD release received ':'Money out ').money($amt).$acctNote,'COD');
    flash(($type==='in'?'COD release recorded: ':'Money-out recorded: ').money($amt).$acctNote);
    header('Location: cod.php'); exit;
  }
  if ($act==='del' && $isAdmin) {
    try{ $tid=(int)val("SELECT COALESCE(bank_txn_id,0) FROM cod_ledger WHERE id=?",[(int)($_POST['id'] ?? 0)]);
         if($tid) q("DELETE FROM bank_txns WHERE id=?",[$tid]); }catch(Exception $e){}
    q("DELETE FROM cod_ledger WHERE id=?",[(int)($_POST['id'] ?? 0)]);
    flash('Entry deleted.'); header('Location: cod.php'); exit;
  }
}

/* ---- filters ---- */
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$tf   = in_array($_GET['t'] ?? '', ['in','out'], true) ? $_GET['t'] : 'all';

/* ---- all-time reconciliation (couriers pay NET of their charges) ---- */
$holdings = cod_holdings();   /* per courier: collected, charges, net, released, held */
$codOrderCount = [];
foreach (rows("SELECT courier_id cid, COUNT(*) n FROM orders WHERE status='delivered' AND payment_type='cod' AND courier_id IS NOT NULL GROUP BY courier_id") as $r)
  $codOrderCount[(int)$r['cid']]=(int)$r['n'];

$totCollected=0; $totCharges=0; $totNet=0; $totReleased=(float)val("SELECT COALESCE(SUM(amount),0) FROM cod_ledger WHERE type='in'");
foreach($holdings as $h){ $totCollected+=$h['collected']; $totCharges+=$h['charges']; $totNet+=$h['net']; }
$totOut = (float)val("SELECT COALESCE(SUM(amount),0) FROM cod_ledger WHERE type='out'");
$totOutUnlinked = (float)val("SELECT COALESCE(SUM(amount),0) FROM cod_ledger WHERE type='out' AND (account_id IS NULL OR account_id=0)");
$totOutLinked = $totOut - $totOutUnlinked;
$withCouriers = $totNet - $totReleased;
$inHand = $totReleased - $totOut;

/* ---- range totals + ledger list ---- */
$rangeIn  = (float)val("SELECT COALESCE(SUM(amount),0) FROM cod_ledger WHERE type='in'  AND entry_date BETWEEN ? AND ?",[$from,$to]);
$rangeOut = (float)val("SELECT COALESCE(SUM(amount),0) FROM cod_ledger WHERE type='out' AND entry_date BETWEEN ? AND ?",[$from,$to]);
$w = "entry_date BETWEEN ? AND ?"; $p=[$from,$to];
if ($tf!=='all'){ $w.=" AND type=?"; $p[]=$tf; }
$ledger = rows("SELECT l.*, c.name AS courier_name, b.name AS acct_name, b.kind AS acct_kind
                FROM cod_ledger l LEFT JOIN couriers c ON c.id=l.courier_id
                LEFT JOIN bank_accounts b ON b.id=l.account_id
                WHERE $w ORDER BY l.entry_date DESC, l.id DESC", $p);

require __DIR__.'/includes/header.php';
?>
<div class="page-head">
  <div><h1>💵 COD Ledger</h1><p>Courier COD releases in · money out · what's still with each courier</p></div>
  <button class="btn btn-primary" onclick="document.getElementById('addPanel').scrollIntoView({behavior:'smooth'});document.getElementById('l_amount').focus()">＋ Add Entry</button>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<!-- all-time money position -->
<div class="mgrid">
  <div class="metric blue"><div><div class="mv" style="font-size:20px"><?= money($totCollected) ?></div><div class="ml">COD Collected (gross)</div><div class="ms">before courier charges</div></div><div class="mi">📦</div></div>
  <div class="metric red"><div><div class="mv" style="font-size:20px">− <?= money($totCharges) ?></div><div class="ml">Courier Charges</div><div class="ms">delivery + cancel fees they keep</div></div><div class="mi">✂️</div></div>
  <div class="metric indigo"><div><div class="mv" style="font-size:20px"><?= money($totNet) ?></div><div class="ml">Net Payable to You</div><div class="ms">gross − their charges</div></div><div class="mi">🧮</div></div>
  <div class="metric green"><div><div class="mv" style="font-size:20px"><?= money($totReleased) ?></div><div class="ml">Released to Us</div><div class="ms">all COD releases recorded</div></div><div class="mi">✅</div></div>
  <div class="metric <?= $withCouriers>0.5?'amber':'teal' ?>"><div><div class="mv" style="font-size:20px"><?= money($withCouriers) ?></div><div class="ml">Still With Couriers</div><div class="ms">net payable − released</div></div><div class="mi">🚚</div></div>
  <div class="metric red"><div><div class="mv" style="font-size:20px"><?= money($totOut) ?></div><div class="ml">Money Out</div><div class="ms">all time<?= $totOutUnlinked>0 ? ' · <b style="color:var(--amber,#b45309)">'.money($totOutUnlinked).' not yet linked to a bank account</b>' : ' · fully linked to Bank Accounts ✓' ?></div></div><div class="mi">💸</div></div>
  <div class="metric <?= $inHand>=0?'indigo':'red' ?>"><div><div class="mv" style="font-size:20px"><?= money($inHand) ?></div><div class="ml">Cash In Hand</div><div class="ms">released − out</div></div><div class="mi">🧾</div></div>
</div>

<?php if($codThr>0): $hotOnes=array_filter($holdings,fn($h)=>$h['held']>$codThr); if($hotOnes): ?>
<div class="flash" style="margin-top:16px;background:rgba(254,226,226,.75);border-color:rgba(239,68,68,.4);color:#991b1b;font-weight:700">
  🔔 <?php $names=[]; foreach($hotOnes as $h){$names[]=e($h['courier']).' ('.money($h['held']).')';} echo implode(' · ',$names); ?> — above your <?= money($codThr) ?> alert limit (net of courier charges). Chase the release!
</div>
<?php endif; endif; ?>

<!-- per-courier reconciliation -->
<div class="panel" style="margin-top:20px">
  <div class="panel-head"><h2>🚚 Courier Reconciliation (all time)</h2><span class="muted" style="font-size:12px">chase whoever holds your money</span></div>
  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr>
    <th>Courier</th><th>COD Orders</th><th>Collected (gross)</th><th>Their Charges</th><th>Net Payable</th><th>Released</th><th>Still Holding</th>
  </tr></thead><tbody>
  <?php foreach($holdings as $h): $hold=$h['held']; ?>
    <tr<?= ($codThr>0&&$hold>$codThr)?' style="background:rgba(239,68,68,.10)"':($hold>0.5?' style="background:rgba(245,158,11,.08)"':'') ?>>
      <td><?= e($h['courier']) ?></td>
      <td><?= (int)($codOrderCount[$h['cid']] ?? 0) ?></td>
      <td><?= money($h['collected']) ?></td>
      <td style="color:var(--red)">− <?= money($h['charges']) ?></td>
      <td style="font-weight:700"><?= money($h['net']) ?></td>
      <td style="color:var(--green)"><?= money($h['released']) ?></td>
      <td style="font-weight:800;color:<?= ($codThr>0&&$hold>$codThr)?'var(--red)':($hold>0.5?'var(--amber)':'var(--green)') ?>"><?= money($hold) ?><?= ($codThr>0&&$hold>$codThr)?' 🔥':'' ?></td>
    </tr>
  <?php endforeach; if(!$holdings) echo '<tr><td colspan="7"><div class="empty">No delivered COD orders yet.</div></td></tr>'; ?>
  </tbody></table></div>
</div>

<!-- add entry -->
<div class="panel" style="margin-top:20px" id="addPanel">
  <div class="panel-head"><h2>➕ Add Entry</h2><span class="muted" style="font-size:12px">record a COD release from a courier, or money going out</span></div>
  <div class="panel-body"><form method="post" class="fgrid" style="grid-template-columns:repeat(4,1fr);gap:12px">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="add">
    <div><label>Type</label><select name="type" id="l_type" onchange="document.getElementById('courWrap').style.opacity=this.value==='in'?1:.45">
      <option value="in">💰 COD Release (money IN)</option><option value="out">💸 Money OUT</option></select></div>
    <div><label>Date</label><input type="date" name="entry_date" value="<?= date('Y-m-d') ?>"></div>
    <div id="courWrap"><label>Courier (for releases)</label><select name="courier_id"><option value="">—</option>
      <?php foreach($couriers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Amount (Rs.) *</label><input type="number" step="any" min="1" name="amount" id="l_amount" required placeholder="150000"></div>
    <div><label>Method</label><select name="method"><option value="cash">Cash</option><option value="bank">Bank</option><option value="wallet">Wallet</option></select></div>
    <div><label>Account (Bank page) — where the money lands / leaves</label>
      <select name="account_id">
        <option value="0">⏳ — not banked yet —</option>
        <?php foreach($linkAccounts as $A): ?>
          <option value="<?= (int)$A['id'] ?>"><?= $A['kind']==='cash'?'💵':'🏦' ?> <?= e($A['name']) ?> — <?= money($acctBal[$A['id']] ?? 0) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label>Reference #</label><input name="reference" placeholder="txn / slip no. (optional)"></div>
    <div class="full" style="grid-column:span 2"><label>Note</label><input name="note" placeholder="e.g. NCM weekly release / rent payment / supplier advance…"></div>
    <div style="display:flex;align-items:flex-end"><button class="btn btn-primary" style="width:100%">💾 Save Entry</button></div>
  </form></div>
</div>

<!-- ledger -->
<div class="panel" style="margin-top:20px">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px"><h2>📒 Ledger</h2>
    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <a class="btn btn-sm <?= $tf==='all'?'btn-primary':'' ?>" href="cod.php?from=<?= e($from) ?>&to=<?= e($to) ?>">All</a>
      <a class="btn btn-sm <?= $tf==='in'?'btn-primary':'' ?>" href="cod.php?t=in&from=<?= e($from) ?>&to=<?= e($to) ?>">IN</a>
      <a class="btn btn-sm <?= $tf==='out'?'btn-primary':'' ?>" href="cod.php?t=out&from=<?= e($from) ?>&to=<?= e($to) ?>">OUT</a>
      <input type="date" name="from" value="<?= e($from) ?>"><span class="muted">→</span><input type="date" name="to" value="<?= e($to) ?>">
      <?php if($tf!=='all'): ?><input type="hidden" name="t" value="<?= e($tf) ?>"><?php endif; ?>
      <button class="btn btn-sm">Apply</button>
      <button type="button" class="btn btn-sm" onclick="exportLedger()">⬇ CSV</button>
    </form>
  </div>
  <div class="panel-body" style="padding:10px 18px;border-bottom:1px solid rgba(148,163,184,.15);display:flex;gap:22px;flex-wrap:wrap;font-size:13px">
    <span>IN this range: <b style="color:var(--green)"><?= money($rangeIn) ?></b></span>
    <span>OUT this range: <b style="color:var(--red)"><?= money($rangeOut) ?></b></span>
    <span>Net: <b style="color:<?= ($rangeIn-$rangeOut)>=0?'var(--green)':'var(--red)' ?>"><?= money($rangeIn-$rangeOut) ?></b></span>
  </div>
  <div class="table-wrap"><table class="tbl led-tbl" id="ledTbl"><thead><tr>
    <th>Date</th><th>Type</th><th>Courier</th><th>Amount</th><th>Method</th><th>Account</th><th>Reference</th><th>Note</th><th>By</th><?php if($isAdmin): ?><th></th><?php endif; ?>
  </tr></thead><tbody>
  <?php foreach($ledger as $l): $in=$l['type']==='in'; ?>
    <tr>
      <td><?= e($l['entry_date']) ?></td>
      <td><span class="pill <?= $in?'p-green':'p-red' ?>"><?= $in?'IN · Release':'OUT' ?></span></td>
      <td><?= e($l['courier_name'] ?: '—') ?></td>
      <td class="led-amt" style="color:<?= $in?'var(--green)':'var(--red)' ?>"><?= ($in?'+':'−').' '.money($l['amount']) ?></td>
      <td><?= e(ucfirst($l['method'])) ?></td>
      <td><?php if($l['account_id']): ?><span class="pill <?= ($l['acct_kind']??'')==='cash'?'p-green':'p-blue' ?>" title="linked to Bank page"><?= ($l['acct_kind']??'')==='cash'?'💵':'🏦' ?> <?= e($l['acct_name'] ?? '') ?></span><?php else: ?><span class="pill p-amber" title="money not deposited to any account yet">⏳ not banked</span><?php endif; ?></td>
      <td class="muted"><?= e($l['reference'] ?: '—') ?></td>
      <td class="led-note"><?= e($l['note'] ?: '—') ?></td>
      <td class="muted"><?= e($l['created_by']) ?></td>
      <?php if($isAdmin): ?><td style="white-space:nowrap"><button class="iact" title="Edit" onclick='editLed(<?= json_encode($l, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✏️</button> <form method="post" onsubmit="return confirm('Delete this entry?')" style="display:inline">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="del"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
        <button class="rowdel" title="Delete">🗑</button></form></td><?php endif; ?>
    </tr>
  <?php endforeach; if(!$ledger) echo '<tr><td colspan="9"><div class="empty">No entries in this range — record your first COD release above.</div></td></tr>'; ?>
  </tbody></table></div>
</div>

<script>
function exportLedger(){
  var rows=Array.prototype.map.call(document.querySelectorAll('#ledTbl tr'),function(tr){
    return Array.prototype.map.call(tr.querySelectorAll('th,td'),function(td){return '"'+td.textContent.trim().replace(/"/g,'""')+'"';}).join(',');
  }).join('\n');
  var a=document.createElement('a');a.href=URL.createObjectURL(new Blob([rows],{type:'text/csv'}));a.download='cod_ledger.csv';a.click();
}
</script>
<!-- edit ledger entry -->
<div class="modal-bg" id="ledEdit" style="z-index:99991"><form class="modal" method="post" style="width:520px;max-width:94vw">
  <div class="modal-head"><span id="leTitle">✏️ Edit Entry</span><span class="mx" onclick="closeLed()">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="upd"><input type="hidden" name="id" id="le_id">
  <div class="modal-body">
    <div><label>Date</label><input type="date" name="entry_date" id="le_date"></div>
    <div><label>Type</label><select name="type" id="le_type"><option value="in">IN · COD Release</option><option value="out">OUT · Money Out</option></select></div>
    <div><label>Courier</label><select name="courier_id" id="le_cour"><option value="">—</option>
      <?php foreach($couriers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Amount (Rs.) *</label><input type="number" step="any" min="1" name="amount" id="le_amt" required></div>
    <div><label>Method</label><select name="method" id="le_meth"><option value="cash">Cash</option><option value="bank">Bank</option><option value="wallet">Wallet</option></select></div>
    <div><label>Account (Bank page)</label>
      <select name="account_id" id="le_acct">
        <option value="0">⏳ — not banked yet —</option>
        <?php foreach($linkAccounts as $A): ?>
          <option value="<?= (int)$A['id'] ?>"><?= $A['kind']==='cash'?'💵':'🏦' ?> <?= e($A['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label>Reference</label><input name="reference" id="le_ref" placeholder="txn / slip no."></div>
    <div class="full"><label>Note</label><input name="note" id="le_note"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="closeLed()">Cancel</button><button class="btn btn-primary">💾 Save</button></div>
</form></div>
<script>
function editLed(l){
  document.getElementById('leTitle').textContent='✏️ Edit Entry #'+l.id;
  document.getElementById('le_id').value=l.id;
  document.getElementById('le_date').value=String(l.entry_date).slice(0,10);
  document.getElementById('le_type').value=l.type||'in';
  document.getElementById('le_cour').value=l.courier_id||'';
  document.getElementById('le_amt').value=l.amount||'';
  document.getElementById('le_meth').value=l.method||'cash';
  document.getElementById('le_acct').value=l.account_id||0;
  document.getElementById('le_ref').value=l.reference||'';
  document.getElementById('le_note').value=l.note||'';
  document.getElementById('ledEdit').classList.add('open');document.body.classList.add('modal-open');
}
function closeLed(){document.getElementById('ledEdit').classList.remove('open');document.body.classList.remove('modal-open');}
(function(){var mm=document.getElementById('ledEdit');mm.addEventListener('click',function(e){if(e.target===mm)closeLed();});})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>