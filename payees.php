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
$grandPaid=0; $grandRemain=0;
foreach($payees as $p){ $grandPaid+=(float)$p['tpaid']; $grandRemain+=max(0,(float)$p['tdue']-(float)$p['tpaid']); }

$view = (int)($_GET['id'] ?? 0);
$linkAccounts = rows("SELECT * FROM bank_accounts WHERE archived=0 ORDER BY kind='cash' DESC, name");
$acctBal = [];
foreach($linkAccounts as $A){
  $in =(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='in'",[$A['id']]);
  $out=(float)val("SELECT COALESCE(SUM(amount),0) FROM bank_txns WHERE account_id=? AND direction='out'",[$A['id']]);
  $acctBal[$A['id']]=(float)$A['opening']+$in-$out;
}
$vp = $view ? row("SELECT * FROM payees WHERE id=?",[$view]) : null;
$vm = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : '';
if ($vp) {
  $w = "payee_id=?"; $args=[$view];
  if ($vm){ $w.=" AND DATE_FORMAT(entry_date,'%Y-%m')=?"; $args[]=$vm; }
  $ledger = rows("SELECT * FROM payee_ledger WHERE $w ORDER BY entry_date DESC, id DESC LIMIT 500",$args);
  $vDue=(float)val("SELECT COALESCE(SUM(amount),0) FROM payee_ledger WHERE payee_id=? AND type='due'",[$view]);
  $vPaid=(float)val("SELECT COALESCE(SUM(amount),0) FROM payee_ledger WHERE payee_id=? AND type='paid'",[$view]);
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
  <div class="metric purple"><div><div class="mv"><?= count($payees) ?></div><div class="ml">Payees</div><div class="ms">people & vendors</div></div><div class="mi">👥</div></div>
</div>

<div class="panel" style="margin-top:18px">
  <?php $adsPid=(int)setting('ads_payee_id',0); ?>
  <div class="panel-head" style="flex-wrap:wrap;gap:10px"><h2>👥 Payee Summary</h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <span class="muted" style="font-size:11.5px"><?= $adsPid?'⚡ Ads auto-bill: ON':'⚡ tap the lightning on a payee to auto-bill new Ads expenses to them' ?></span>
      <?php if($isAdmin && $adsPid): ?><form method="post" style="display:inline" onsubmit="return confirm('Import ALL past Ads expenses as dues to the ads payee? Safe to run twice — never duplicates.')">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="backfill_ads">
        <button class="btn btn-sm">⚡ Import past Ads expenses</button></form>
        <form method="post" style="display:inline" title="Re-sync ad dues to their rupee amount — fixes dollars shown as Rs.">
          <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="repair_ads">
          <button class="btn btn-sm">🔧 Fix $→Rs on Ads dues</button></form><?php endif; ?>
    </div></div>
  <div style="padding:10px 14px 0"><input id="paySearch" type="search" placeholder="🔍 Search payee…" style="width:100%;max-width:340px;border:1px solid #dfe4f2;border-radius:10px;padding:8px 12px;font:inherit" oninput="payFilter(this.value)"></div>
  <div class="table-wrap"><table class="tbl num-tbl" id="payTbl"><thead><tr>
    <th>Name</th><th>Status</th><th class="right">Total</th><th class="right">Total Paid</th><th class="right">Remaining</th><th class="right">This Month</th><th>Last Payment</th><th></th>
  </tr></thead><tbody>
  <?php foreach($payees as $p): $rem=(float)$p['tdue']-(float)$p['tpaid'];
        $days = $p['lastpay'] ? (int)floor((time()-strtotime($p['lastpay']))/86400) : null; ?>
    <tr class="payrow" data-name="<?= e(mb_strtolower($p['name'].' '.$p['note'])) ?>">
      <td><a href="payees.php?id=<?= (int)$p['id'] ?>" style="font-weight:800"><?= e($p['name']) ?></a><?= ((int)$p['id']===$adsPid)?' <span class="pill p-amber" style="font-size:9px">⚡ ADS PAYEE</span>':'' ?><?= $p['note']?'<div class="muted" style="font-size:11px">'.e($p['note']).'</div>':'' ?>
        <div class="muted" style="font-size:10.5px"><?= (int)$p['nentries'] ?> entries</div></td>
      <td><?php if($rem>0.5): ?><span class="pill p-amber">🟠 Due</span><?php elseif($rem<-0.5): ?><span class="pill p-blue">🔵 Advance</span><?php else: ?><span class="pill p-green">🟢 Settled</span><?php endif; ?></td>
      <td class="num right"><?= money($p['tdue']) ?></td>
      <td class="num right" style="color:var(--green)"><?= money($p['tpaid']) ?></td>
      <td class="num right" style="font-weight:800;color:<?= $rem>0.5?'var(--amber)':($rem< -0.5?'var(--blue)':'var(--green)') ?>">
        <?= $rem>0.5?money($rem):($rem< -0.5?money(-$rem).' advance':'✓ settled') ?></td>
      <td class="num right" title="paid to them this month"><?= (float)$p['mpaid']>0?money($p['mpaid']):'—' ?></td>
      <td><?php if($p['lastpay']): ?><span style="font-size:12px"><?= e(date('d M',strtotime($p['lastpay']))) ?></span>
            <span class="muted" style="font-size:10.5px;<?= ($rem>0.5 && $days>30)?'color:var(--red);font-weight:800':'' ?>"><?= $days===0?'today':$days.'d ago' ?></span>
          <?php else: ?><span class="muted">never</span><?php endif; ?></td>
      <td class="right nowrap">
        <button class="pbtn pbtn-pay" onclick="payQuick(<?= (int)$p['id'] ?>,'paid')" title="Record a payment to this payee">💸 Pay</button>
        <button class="pbtn pbtn-due" onclick="payQuick(<?= (int)$p['id'] ?>,'due')" title="Record a new due/bill">🧾 Due</button>
        <a class="pbtn pbtn-ghost" href="payees.php?id=<?= (int)$p['id'] ?>">📜 Ledger</a>
        <?php if($isAdmin && (int)$p['id']!==$adsPid): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="set_ads_payee"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="iact" title="Auto-bill new Ads expenses to this payee">⚡</button></form><?php endif; ?>
        <?php if($isAdmin): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete this payee AND all their entries?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="del_payee"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="iact del">🗑</button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; if(!$payees) echo '<tr><td colspan="8"><div class="empty">No payees yet — add Rohit, Raushan, Vedas Store… then record dues & payments.</div></td></tr>'; ?>
  </tbody></table></div>
</div>

<?php if($vp): ?>
<div class="panel" style="margin-top:18px">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px">
    <h2>📜 <?= e($vp['name']) ?> — Ledger</h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <span class="pill p-blue">Total <?= money($vDue) ?></span>
      <span class="pill p-green">Paid <?= money($vPaid) ?></span>
      <span class="pill <?= ($vDue-$vPaid)>0.5?'p-amber':'p-green' ?>" style="font-weight:800">Remaining <?= money(max(0,$vDue-$vPaid)) ?></span>
      <form method="get" style="display:flex;gap:6px"><input type="hidden" name="id" value="<?= $view ?>"><input type="month" name="m" value="<?= e($vm) ?>"><button class="btn btn-sm">Filter</button><?= $vm?'<a class="btn btn-sm" href="payees.php?id='.$view.'">✕</a>':'' ?></form>
    </div>
  </div>
  <div class="table-wrap"><table class="tbl num-tbl"><thead><tr>
    <th>Date</th><th>Type</th><th>Expense / Note</th><th class="right">Amount</th><th class="right">Running Remaining</th><th></th>
  </tr></thead><tbody>
  <?php
    /* running balance computed oldest→newest, displayed newest-first */
    $asc=array_reverse($ledger); $run=0; $runMap=[];
    /* if month filter on, seed with balance before the month */
    if($vm){ $run=(float)val("SELECT COALESCE(SUM(CASE WHEN type='due' THEN amount ELSE -amount END),0) FROM payee_ledger WHERE payee_id=? AND DATE_FORMAT(entry_date,'%Y-%m')<?",[$view,$vm]); }
    foreach($asc as $l){ $run += $l['type']==='due' ? (float)$l['amount'] : -(float)$l['amount']; $runMap[$l['id']]=$run; }
  ?>
  <?php foreach($ledger as $l): ?>
    <tr>
      <td class="num"><?= e($l['entry_date']) ?></td>
      <td><?= $l['type']==='due' ? '<span class="pill p-blue">🧾 Due</span>' : '<span class="pill p-green">💸 Paid</span>' ?></td>
      <td><?= e($l['label'] ?: '—') ?></td>
      <td class="num right" style="font-weight:700;color:<?= $l['type']==='due'?'var(--ink)':'var(--green)' ?>"><?= money($l['amount']) ?></td>
      <td class="num right" style="color:<?= $runMap[$l['id']]>0.5?'var(--amber)':'var(--green)' ?>"><?= money(max(0,$runMap[$l['id']])) ?></td>
      <td class="right"><?php if($isAdmin): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete entry?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="del_entry"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="pid" value="<?= $view ?>"><button class="iact del">🗑</button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; if(!$ledger) echo '<tr><td colspan="6"><div class="empty">No entries'.($vm?' in this month':'').'.</div></td></tr>'; ?>
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
function payFilter(q){
  q=(q||'').toLowerCase();
  document.querySelectorAll('#payTbl tbody tr.payrow').forEach(function(tr){
    tr.style.display = (!q || (tr.dataset.name||'').indexOf(q)!==-1) ? '' : 'none';
  });
}
function payQuick(pid,type){
  openEntry(type);
  var sel=document.querySelector('#enBg select[name=payee_id]'); if(sel) sel.value=String(pid);
  var amt=document.querySelector('#enBg input[name=amount]'); if(amt) setTimeout(function(){amt.focus();},50);
}
['npBg','enBg'].forEach(function(id){var m=document.getElementById(id);m.addEventListener('click',function(e){if(e.target===m)closeM(id);});});
</script>

<?php require __DIR__.'/includes/footer.php'; ?>