<?php
/* ============================================================
   NCM ACTION CENTER 2.0  —  return-prevention command page
   Linked from ncm.php (not in the nav menu on purpose).
   Everything here exists to answer one question:
   "who do I call RIGHT NOW so this parcel doesn't come back?"
   ============================================================ */
require_once __DIR__.'/ncm_api.php';
require_login(); require_page_access();
$PAGE_TITLE='NCM Action Center';

/* last-contact tracking column (plain ALTER inside try = portable on MySQL & MariaDB) */
try { q("ALTER TABLE orders ADD COLUMN ncm_last_contact DATE NULL"); } catch (Exception $e) {}

/* Nepal phone → wa.me format */
if(!function_exists('wa_phone')){ function wa_phone($p){ $ph=preg_replace('/[^0-9]/','',(string)$p); $ph=ltrim($ph,'0'); if(strpos($ph,'977')!==0)$ph='977'.$ph; return $ph; } }

/* ---- POST actions ---- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act=$_POST['_action'] ?? '';
  try {
    if ($act==='mark_called') {
      $id=(int)($_POST['id']??0);
      q("UPDATE orders SET ncm_last_contact=CURDATE() WHERE id=?",[$id]);
      $oc=(string)val("SELECT code FROM orders WHERE id=?",[$id]);
      log_activity('Marked customer contacted (Action Center): '.$oc,'NCM');
      flash('✓ '.$oc.' marked as contacted today.');
    }
    elseif ($act==='mark_called_bulk') {
      $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
      $ids = array_slice($ids, 0, 100);
      $n=0;
      foreach ($ids as $id) { q("UPDATE orders SET ncm_last_contact=CURDATE() WHERE id=?",[$id]); $n++; }
      if ($n) log_activity("Bulk-marked $n order(s) contacted (Action Center)",'NCM');
      flash($n ? "✓ $n order(s) marked as contacted today." : 'Nothing selected.');
    }
    elseif ($act==='unmark_called') {
      $id=(int)($_POST['id']??0);
      q("UPDATE orders SET ncm_last_contact=NULL WHERE id=?",[$id]);
      flash('Contact mark removed.');
    }
    elseif ($act==='refresh_cmts') {
      try { kv_set('ac_lastcmt2', []); } catch (Exception $e) {}
      flash('🔄 Comments refreshed from NCM.');
    }
    elseif ($act==='comment') {
      ncm()->addComment((int)$_POST['ncm_id'], trim($_POST['comment']??''));
      log_activity('Commented on NCM #'.$_POST['ncm_id'].' (Action Center)','NCM');
      flash('Message sent to NCM.');
    }
  } catch (Exception $ex) { flash('Error: '.$ex->getMessage()); }
  header('Location: ncm_action.php'); exit;
}

if (!function_exists('ac_wa_text')) {
function ac_wa_text($o,$branch,$bphone){
  $t='Namaste '.(($o['customer']??'')?:'').' ji! Luprah Trading bata. Tapaiko order '.($o['code']??'').' NCM courier ma cha — kripaya phone uthaidinus, delivery re-attempt garna lagirachhau.';
  if($branch!==''){
    $t.=' Parcel NCM '.$branch.' branch ma cha.';
    if($bphone!=='') $t.=' Branch number: '.$bphone.' — yo number bata call auna sakcha, kripaya uthaidinus. Aafai pani yo number ma call garna saknu huncha.';
  }
  return $t.' Dhanyabad! 🙏';
}
}

require __DIR__.'/includes/header.php';

/* ---- settings & data (reads the statuses cron/ncm.php already keep fresh — no heavy API work) ---- */
$agingDays  = max(1,(int)setting('ncm_aging_days',5));
$atriskDays = max(1,(int)setting('ncm_atrisk_days',4));
$midDays    = max(1,(int)round($agingDays*0.6));
$today=new DateTime('today');

$orders = rows("SELECT o.*, p.name AS product_name FROM orders o
                LEFT JOIN products p ON p.id=o.product_id
                LEFT JOIN couriers c ON c.id=o.courier_id
                WHERE c.name LIKE '%NCM%'
                  AND o.status NOT IN ('delivered','cancelled','returned')
                ORDER BY o.id DESC");

/* latest NCM comment per order → no-response detection (best-effort, page still works offline) */
$commentByOrder=[];
if (ncm()->configured()) {
  try {
    foreach (ncm()->bulkComments() as $c) {
      $oid=(string)($c['orderid']??($c['order']??'')); if($oid==='') continue;
      if(!isset($commentByOrder[$oid]))
        $commentByOrder[$oid]=['text'=>(string)($c['comments']??($c['comment']??'')),'time'=>(string)($c['added_time']??($c['addedTime']??''))];
    }
  } catch (Exception $e) {}
}

/* ---- score every open order ---- */
$list=[]; $calledToday=[];
$nNoResp=0; $nAging=0; $nStuck=0; $codAtRisk=0.0;
foreach ($orders as $o) {
  $nid=(string)($o['ncm_order_id']??'');
  $status = trim((string)($o['ncm_status']??'')) ?: ucfirst((string)$o['status']);
  $sl=strtolower($status);
  $age = $o['order_date'] ? (int)$today->diff(new DateTime($o['order_date']))->days : 0;
  $inDelivery = strpos($sl,'dispatch')!==false||strpos($sl,'sent for delivery')!==false||strpos($sl,'arrived')!==false||strpos($sl,'out for')!==false||strpos($sl,'ship')!==false;
  $cmt = $nid!=='' ? ($commentByOrder[$nid]??null) : null;
  $noResp = $cmt ? ncm_no_response($cmt['text']) : false;
  $aging  = $age>=$agingDays;
  $stuck  = $inDelivery && $age>=$atriskDays && !$noResp;
  if (!$noResp && !$aging && !$stuck) continue;            /* healthy — not this page's business */

  $cod = strtolower((string)$o['payment_type'])==='cod' ? (float)$o['sell_price']*(int)$o['qty'] : 0;
  /* priority: who to call FIRST */
  $score = ($noResp?45:0) + min($age,10)*6 + ($stuck?12:0) + (int)min(20, $cod/1000);
  $why=[];
  if($noResp)$why[]='noresp'; if($aging)$why[]='aging'; if($stuck)$why[]='stuck';

  $row = compact('o','nid','status','sl','age','cmt','noResp','aging','stuck','cod','score','why');
  $lc = (string)($o['ncm_last_contact']??'');
  if ($lc!=='' && $lc===date('Y-m-d')) { $calledToday[]=$row; continue; }
  $list[]=$row;
  if($noResp)$nNoResp++; if($aging)$nAging++; if($stuck)$nStuck++;
  $codAtRisk += $cod;
}
usort($list, fn($a,$b)=> $b['score']<=>$a['score']);
$nAll=count($list);

/* ---- enrich the queue with LIVE data ----
   a) one ordersStatuses call → current status ("Returned to Warehouse", "Sent for Delivery"…)
   b) each order's LATEST NCM comment (per-order fetch, cached 10 min) — the information you
      follow up on. The bulk feed only shows ~25 recent comments across ALL orders; this asks
      NCM for each queue order specifically, so every card carries its last known word. */
$qNids=[]; foreach(array_merge($list,$calledToday) as $r){ if($r['nid']!=='') $qNids[$r['nid']]=1; }
$qNids=array_slice(array_keys($qNids),0,60);
$liveNow=[];
if ($qNids && ncm()->configured()) {
  try { $lr=ncm()->ordersStatuses(array_map('intval',$qNids)); if(isset($lr['result'])&&is_array($lr['result'])) $liveNow=$lr['result']; } catch (Exception $e) {}
}
/* NCM branch → phone map, cached 24h (branch numbers rarely change) */
if (!function_exists('ac_branch_phones')) {
function ac_branch_phones(){
  $c=kv_get('ac_branch_phones');
  if($c && (time()-strtotime($c['at']??'1970-01-01'))<86400 && is_array($c['v']??null) && count($c['v'])) return $c['v'];
  $map=[];
  try {
    foreach (ncm()->branches() as $b) {
      if(empty($b['name'])||!is_array($b)) continue;
      $ph='';
      foreach ($b as $k=>$v){
        if(!is_string($v) && !is_numeric($v)) continue;
        if(preg_match('/phone|contact|tel|mobile/i',(string)$k) && trim((string)$v)!==''){ $ph=trim((string)$v); break; }
      }
      if($ph==='') foreach ($b as $v){ if((is_string($v)||is_numeric($v)) && preg_match('/^[0-9+\-\s]{7,}$/',trim((string)$v))){ $ph=trim((string)$v); break; } }
      $map[mb_strtoupper((string)$b['name'])]=$ph;
    }
    if($map) kv_set('ac_branch_phones',$map);
  } catch (Exception $e) {}
  return $map;
}
/* which branch is this parcel at / heading to? 1) branch named inside the live status
   ("Arrived at ITAHARI") 2) longest branch-name match in the address */
function ac_detect_branch($status,$address,$branchNames){
  $S=mb_strtoupper((string)$status); $best=''; $bl=0;
  foreach($branchNames as $b){ $B=mb_strtoupper($b); if($B!=='' && mb_strpos($S,$B)!==false && mb_strlen($B)>$bl){ $best=$b;$bl=mb_strlen($B);} }
  if($best!=='') return $best;
  $A=mb_strtoupper((string)$address); $bl=0;
  foreach($branchNames as $b){ $B=mb_strtoupper($b); if($B!=='' && mb_strpos($A,$B)!==false && mb_strlen($B)>$bl){ $best=$b;$bl=mb_strlen($B);} }
  return $best;
}

/* pick the NEWEST comment by its own timestamp — NCM's array order is not trusted
   (some endpoints return newest-first, others oldest-first) */
function ac_newest_comment($th){
  if(!is_array($th)||!$th) return null;
  $best=null; $bestTs=-1; $anyTs=false;
  foreach($th as $c){
    if(!is_array($c)) continue;
    $ts=strtotime((string)($c['added_time']??($c['addedTime']??'')));
    if($ts!==false){ $anyTs=true; if($ts>=$bestTs){ $bestTs=$ts; $best=$c; } }
  }
  if(!$anyTs){ $best=end($th); }                       /* no parsable times → last element */
  return $best ? ['text'=>(string)($best['comments']??($best['comment']??'')),'by'=>(string)($best['addedBy']??''),'time'=>(string)($best['added_time']??($best['addedTime']??''))] : null;
}
}
$lastCmt=[]; $cmtFetchedAt='';
if ($qNids && ncm()->configured()) {
  $cache=kv_get('ac_lastcmt2');
  $fresh = $cache && (time()-strtotime($cache['at'] ?? '1970-01-01')) < 180 && is_array($cache['v'] ?? null) && count($cache['v']);
  $lastCmt = $fresh ? $cache['v'] : [];
  $miss=array_values(array_filter($qNids, fn($n)=>!array_key_exists($n,$lastCmt)));
  if ($miss) {
    if(function_exists('set_time_limit')) @set_time_limit(120);
    foreach (array_slice($miss,0,40) as $n) {
      try { $lastCmt[$n]=ac_newest_comment(ncm()->comments((int)$n)); }
      catch (Exception $e) { $lastCmt[$n]=null; }
      usleep(80000);
    }
    try { kv_set('ac_lastcmt2',$lastCmt); } catch (Exception $e) {}
  }
  $cmtFetchedAt = $fresh ? date('H:i',strtotime($cache['at'])) : date('H:i');
}
/* branch phone book (name → hub number) */
$bp = ncm()->configured() ? ac_branch_phones() : [];
$bNames = array_keys($bp);
/* fold live status + last comment + destination hub into each row */
$enrich=function(&$arr) use($liveNow,$lastCmt,$bp,$bNames){
  foreach($arr as &$r){
    if($r['nid']!=='' && isset($liveNow[$r['nid']]) && trim((string)$liveNow[$r['nid']])!=='')
      $r['status']=(string)$liveNow[$r['nid']];
    $r['lastc']=$r['nid']!=='' ? ($lastCmt[$r['nid']] ?? null) : null;
    if($r['lastc'] && ncm_no_response($r['lastc']['text'])) $r['noResp']=true;
    $r['branch']=ac_detect_branch($r['status'], $r['o']['address']??'', $bNames);
    $r['bphone']=$r['branch']!=='' ? (string)($bp[mb_strtoupper($r['branch'])]??'') : '';
  } unset($r);
};
$enrich($list); $enrich($calledToday);

/* ---- orders already ON a return journey don't need a phone call — the parcel is
   already heading back, so "call the customer to confirm delivery" doesn't apply.
   The real action for these lives on the main NCM Courier page's Return? tab
   (✔ Returned / ✔ Delivered) — this page just gets them OFF the active call list
   and points staff to the right place, instead of pretending a call will help. */
$returning=[];
$list = array_values(array_filter($list, function($r) use(&$returning){
  $oFlag = (int)($r['o']['ncm_return_flag'] ?? 0);
  $stg   = ncm_return_stage($r['status']);
  if ($oFlag===1 || $stg==='progress' || $stg==='ambiguous') { $returning[]=$r; return false; }
  return true;
}));
/* recompute the alarm counts from the FINAL call queue only — a returning order
   shouldn't inflate "not responding" / "aging" / "stuck" now that it's not something
   staff need to act on by phone */
$nNoResp   = count(array_filter($list, fn($r)=>$r['noResp']));
$nAging    = count(array_filter($list, fn($r)=>$r['aging']));
$nStuck    = count(array_filter($list, fn($r)=>$r['stuck']));
$codAtRisk = array_sum(array_map(fn($r)=>$r['cod'], $list));
$nAll      = count($list);
$nReturning= count($returning);
?>
<style>
.ac-hero{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:linear-gradient(120deg,#7f1d1d,#b45309);border-radius:18px;padding:20px 24px;color:#fff;margin-bottom:16px}
.ac-hero h1{font-size:21px;font-weight:900;margin:0}
.ac-hero p{opacity:.85;font-size:12.5px;margin:2px 0 0}
.ac-bulkbar{display:none;align-items:center;gap:12px;background:#eefdf4;border:1.5px solid #86efac;border-radius:13px;padding:10px 16px;margin:0 18px 12px;position:sticky;top:8px;z-index:20;box-shadow:0 6px 18px rgba(34,197,94,.15)}
.ac-bulkbar.on{display:flex}
.acSel{width:17px;height:17px;cursor:pointer}
.ac-score{display:inline-block;min-width:40px;text-align:center;border-radius:99px;padding:3px 9px;font-weight:900;font-size:11.5px}
.sc-hot{background:var(--red-bg);color:var(--red)}.sc-warm{background:var(--amber-bg);color:var(--amber)}.sc-mild{background:var(--surface-2);color:var(--muted)}
.age-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800}
.age-old{background:var(--red-bg);color:var(--red)}.age-mid{background:var(--amber-bg);color:var(--amber)}.age-ok{background:var(--surface-2);color:var(--muted)}
.ac-chips{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
.acchip{border:1.5px solid var(--line,#e4e8f2);background:var(--surface,#fff);border-radius:99px;padding:5px 13px;font-size:11.5px;font-weight:800;cursor:pointer}
.acchip.on{background:#b45309;border-color:#b45309;color:#fff}
.row-called td{opacity:.55}
.ac-cmt{font-size:11px;color:var(--muted);margin-top:3px;font-style:italic}
.ac-lastc{margin-top:5px;background:var(--surface-2,#f4f6fb);border-left:3px solid #4f7cf7;border-radius:0 9px 9px 0;padding:5px 9px;font-size:11.5px;line-height:1.5}
.ac-lcby{display:block;font-size:10px;color:var(--muted);font-weight:700;margin-top:1px}
.ac-hub{font-size:10.5px;color:var(--muted);margin-top:3px;font-weight:700}
.ac-hub a{color:var(--brand);text-decoration:none;font-weight:800}
/* ---- mobile: card view, thumb-sized actions ---- */
.ac-cards{display:none}
@media(max-width:760px){
  .ac-desktop{display:none}
  .ac-cards{display:flex;flex-direction:column;gap:10px;padding:12px 14px 16px}
  .acc{background:var(--surface,#fff);border:1px solid var(--line,#e7ebf4);border-radius:15px;padding:12px 13px;box-shadow:0 4px 14px rgba(30,40,80,.06)}
  .acc.hot{border-left:4px solid var(--red)}
  .acc-top{display:flex;align-items:center;gap:8px;margin-bottom:6px}
  .acc-top b{font-size:13.5px}
  .acc-nm{font-size:15px;font-weight:900;margin:2px 0}
  .acc-sub{font-size:11.5px;color:var(--muted)}
  .acc-mid{display:flex;gap:6px;flex-wrap:wrap;margin:7px 0}
  .acc-cod{margin-left:auto;font-weight:900;font-size:14px}
  .acc-act{display:flex;gap:8px;margin-top:9px}
  .acc-act .btn{flex:1;text-align:center;padding:11px 8px;font-size:13px;border-radius:11px}
  .acc-act2{display:flex;gap:8px;margin-top:7px}
  .acc-act2 .btn, .acc-act2 form{flex:1}
  .acc-act2 .btn{width:100%;text-align:center;padding:9px 6px;font-size:11.5px;border-radius:10px}
  .ac-hero{padding:16px 16px}
  .acchip{padding:8px 14px;font-size:12px}
  #acSearch{max-width:100% !important;width:100%;padding:10px 14px}
  .ac-chips{width:100%}
}
</style>

<div class="ac-hero">
  <span style="font-size:34px">🚨</span>
  <div style="flex:1;min-width:220px">
    <h1>Action Center <span style="font-size:12px;background:rgba(255,255,255,.2);border-radius:99px;padding:2px 10px;vertical-align:middle">v2.0</span></h1>
    <p>Call these customers now — every call here is a parcel that doesn't come back with a Rs. 170+ return charge.</p>
  </div>
  <a class="btn" style="background:rgba(255,255,255,.15);color:#fff" href="ncm_followup.php">🛵 Follow-Up</a>
  <a class="btn" style="background:rgba(255,255,255,.15);color:#fff" href="ncm.php">← NCM Courier</a>
  <button class="btn" style="background:rgba(255,255,255,.9);color:#7f1d1d" onclick="acCSV()">⬇ Call list CSV</button>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>
<a href="ncm_followup.php" class="panel" style="display:flex;align-items:center;gap:14px;padding:14px 18px;margin-bottom:16px;text-decoration:none;color:inherit;border:1px solid #a5d8ff">
  <span style="font-size:24px">🛵</span>
  <div style="flex:1;min-width:220px"><b style="font-size:13.5px">NCM Customer Follow-Up</b>
    <div class="muted" style="font-size:11.5px;margin-top:2px">Every order's delivery stage + WhatsApp message + NCM's replies, in one place — separate from this call queue</div></div>
  <span class="btn btn-primary" style="white-space:nowrap;background:linear-gradient(160deg,#38bdf8,#0369a1)!important">Open →</span>
</a>

<div class="mgrid" style="grid-template-columns:repeat(auto-fit,minmax(185px,1fr))">
  <div class="metric red"><span class="g">📞</span><div class="mv"><?= $nAll ?></div><div class="ml">To call now</div></div>
  <div class="metric purple"><span class="g">🔇</span><div class="mv"><?= $nNoResp ?></div><div class="ml">Not responding</div></div>
  <div class="metric orange"><span class="g">⏳</span><div class="mv"><?= $nAging ?></div><div class="ml">Aging <?= $agingDays ?>+ days</div></div>
  <div class="metric amber"><span class="g">🚚</span><div class="mv"><?= $nStuck ?></div><div class="ml">Stuck in delivery</div></div>
  <div class="metric teal"><span class="g">💰</span><div class="mv" style="font-size:19px"><?= money($codAtRisk) ?></div><div class="ml">COD at risk</div></div>
  <div class="metric green"><span class="g">✅</span><div class="mv"><?= count($calledToday) ?></div><div class="ml">Contacted today</div></div>
  <div class="metric indigo"><span class="g">↩</span><div class="mv"><?= $nReturning ?></div><div class="ml" title="The parcel is already heading back — a call won't change that. Confirm it on the NCM Courier page instead.">Already returning</div></div>
</div>

<div class="panel" style="margin-top:18px">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px">
    <h2>📋 Call queue <span class="muted" style="font-size:12px;font-weight:600" id="acSortLbl">highest priority first<?= $cmtFetchedAt?' · comments as of '.$cmtFetchedAt:'' ?></span>
      <form method="post" style="display:inline;margin-left:6px" onsubmit="this.querySelector('button').textContent='⏳'">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="refresh_cmts">
        <button class="btn btn-sm" title="Fetch the very latest comments from NCM now">🔄</button></form></h2>
    <div class="ac-chips">
      <span class="acchip sortBtn on" data-sort="priority" onclick="acSort(this)" title="Oldest / most urgent first">🔥 Priority</span>
      <span class="acchip sortBtn" data-sort="newest" onclick="acSort(this)" title="Most recent order first — no scrolling past old ones">🕐 Newest First</span>
      <input id="acSearch" class="ncm-search" placeholder="🔍 name, phone, order…" oninput="acFilter()" style="max-width:210px">
      <span class="acchip on" data-f="all" onclick="acTab(this)">All <?= $nAll ?></span>
      <span class="acchip" data-f="noresp" onclick="acTab(this)">🔇 No response <?= $nNoResp ?></span>
      <span class="acchip" data-f="aging" onclick="acTab(this)">⏳ Aging <?= $nAging ?></span>
      <span class="acchip" data-f="stuck" onclick="acTab(this)">🚚 Stuck <?= $nStuck ?></span>
    </div>
  </div>
  <div class="ac-bulkbar" id="acBulkBar">
    <span><b id="acSelN">0</b> selected</span>
    <form method="post" id="acBulkForm" style="display:inline">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="mark_called_bulk"><input type="hidden" name="ids" id="acBulkIds">
      <button class="btn btn-sm" style="color:var(--green)" type="submit" onclick="return confirm('Mark all selected orders as contacted today?')">✓ Mark Called</button>
    </form>
    <button class="btn btn-sm" onclick="acSelClear()">✕ Clear</button>
  </div>
  <div class="ac-desktop"><div class="table-wrap"><table class="tbl">
    <thead><tr><th style="width:26px"><input type="checkbox" id="acSelAll" onchange="acSelAll(this)" title="Select all visible"></th><th>🔥</th><th>Order</th><th>Customer</th><th>Phone</th><th>Age</th><th>NCM Status</th><th>Why</th><th class="right">COD</th><th></th></tr></thead>
    <tbody id="acRows">
    <?php foreach($list as $__i=>$r): $o=$r['o'];
      $sc=$r['score']>=60?'sc-hot':($r['score']>=35?'sc-warm':'sc-mild');
      $ap=$r['age']>=$agingDays?'age-old':($r['age']>=$midDays?'age-mid':'age-ok'); ?>
      <tr data-f="<?= e(implode(' ',$r['why'])) ?>" data-s="<?= e(strtolower($o['code'].' '.$o['customer'].' '.$o['phone'].' '.$r['nid'])) ?>" data-age="<?= $r['age'] ?>" data-idx="<?= $__i ?>"
          data-csv='<?= e(json_encode([$o['code'],$o['customer'],$o['phone'],$r['age'].'d',$r['status'],implode('+',$r['why']),$r['cod']])) ?>'>
        <td><input type="checkbox" class="acSel" value="<?= (int)$o['id'] ?>" onchange="acSelCount()"></td>
        <td><span class="ac-score <?= $sc ?>"><?= (int)$r['score'] ?></span></td>
        <td><b><?= e($o['code']) ?></b><?php if($r['nid']): ?><div class="muted" style="font-size:10px">NCM <?= e($r['nid']) ?></div><?php endif; ?></td>
        <td><b><?= e($o['customer']?:'—') ?></b>
          <div class="muted" style="font-size:11px"><?= e(mb_strimwidth((string)$o['address'],0,40,'…')) ?></div>
          <div class="muted" style="font-size:10.5px"><?= e($o['product_name']?:'Goods') ?> ×<?= (int)$o['qty'] ?></div></td>
        <td class="num nowrap"><?= e($o['phone']?:'—') ?></td>
        <td><span class="age-pill <?= $ap ?>"><?= $r['age'] ?>d</span></td>
        <td><span class="pill <?= ncm_status_class($r['status']) ?>"><?= e($r['status']) ?></span>
          <?php if($r['branch']!==''): ?><div class="ac-hub">🏢 <?= e($r['branch']) ?><?= $r['bphone']!==''?' · <a href="tel:'.e($r['bphone']).'">☎ '.e($r['bphone']).'</a>':'' ?></div><?php endif; ?></td>
        <td>
          <?php if($r['noResp']): ?><span class="pill p-red">🔇 No response</span><?php endif; ?>
          <?php if($r['aging']): ?><span class="pill p-yellow"><?= $agingDays ?>+ days</span><?php endif; ?>
          <?php if($r['stuck']): ?><span class="pill p-yellow">stuck</span><?php endif; ?>
          <?php if($r['lastc']): ?>
            <div class="ac-lastc">💬 "<?= e(mb_strimwidth($r['lastc']['text'],0,90,'…')) ?>"
              <span class="ac-lcby">— <?= e($r['lastc']['by']?:'NCM') ?><?= $r['lastc']['time']?' · '.e(date('d M H:i',strtotime($r['lastc']['time']))):'' ?></span></div>
          <?php elseif($r['nid']!==''): ?><div class="ac-cmt muted">no comments yet</div><?php endif; ?>
        </td>
        <td class="num right"><?= $r['cod']>0?money($r['cod']):'<span class="muted">prepaid</span>' ?></td>
        <td class="right nowrap">
          <?php if($o['phone']): ?>
            <a class="btn btn-sm btn-primary" style="background:#25d366!important;border-color:#25d366!important" target="_blank" rel="noopener" title="WhatsApp — includes the NCM hub number"
               href="https://wa.me/<?= e(wa_phone($o['phone'])) ?>?text=<?= rawurlencode(ac_wa_text($o,$r['branch'],$r['bphone'])) ?>">💬 WhatsApp</a>
            <a class="btn btn-sm" href="tel:<?= e($o['phone']) ?>" title="Call (secondary — most follow-up happens on WhatsApp)">📞</a>
          <?php endif; ?>
          <?php if($r['nid']): ?>
            <button class="btn btn-sm" onclick='acReply(<?= json_encode($r['nid']) ?>)'>✉ NCM</button>
            <a class="btn btn-sm" href="ncm.php?comments=<?= e($r['nid']) ?>">Thread</a>
            <a class="btn btn-sm" href="<?= e(ncm_portal_url($r['nid'])) ?>" target="_blank" rel="noopener">↗</a>
          <?php endif; ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="mark_called"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
            <button class="btn btn-sm" style="color:var(--green)" title="I talked to this customer — move them off the queue for today">✓ Called</button>
          </form>
        </td>
      </tr>
    <?php endforeach; if(!$list) echo '<tr><td colspan="9"><div class="empty">🎉 Nothing needs action right now — no at-risk parcels.</div></td></tr>'; ?>
    </tbody>
  </table></div></div>

  <!-- ===== mobile card view (same data, thumb-first) ===== -->
  <div class="ac-cards" id="acCards">
  <?php foreach($list as $__i=>$r): $o=$r['o'];
    $sc=$r['score']>=60?'sc-hot':($r['score']>=35?'sc-warm':'sc-mild');
    $ap=$r['age']>=$agingDays?'age-old':($r['age']>=$midDays?'age-mid':'age-ok'); ?>
    <div class="acc <?= $r['score']>=60?'hot':'' ?>" data-f="<?= e(implode(' ',$r['why'])) ?>" data-s="<?= e(strtolower($o['code'].' '.$o['customer'].' '.$o['phone'].' '.$r['nid'])) ?>" data-age="<?= $r['age'] ?>" data-idx="<?= $__i ?>">
      <div class="acc-top">
        <input type="checkbox" class="acSel" value="<?= (int)$o['id'] ?>" onchange="acSelCount()" style="margin-right:2px">
        <span class="ac-score <?= $sc ?>"><?= (int)$r['score'] ?></span>
        <b><?= e($o['code']) ?></b>
        <span class="age-pill <?= $ap ?>"><?= $r['age'] ?>d</span>
        <span class="acc-cod"><?= $r['cod']>0?money($r['cod']):'prepaid' ?></span>
      </div>
      <div class="acc-nm"><?= e($o['customer']?:'—') ?></div>
      <div class="acc-sub"><?= e(mb_strimwidth((string)$o['address'],0,52,'…')) ?> · <?= e($o['product_name']?:'Goods') ?> ×<?= (int)$o['qty'] ?><?= $r['nid']?' · NCM '.e($r['nid']):'' ?></div>
      <?php if($r['branch']!==''): ?><div class="ac-hub">🏢 NCM <?= e($r['branch']) ?> hub<?= $r['bphone']!==''?' · <a href="tel:'.e($r['bphone']).'">☎ '.e($r['bphone']).'</a>':'' ?></div><?php endif; ?>
      <div class="acc-mid">
        <span class="pill <?= ncm_status_class($r['status']) ?>"><?= e($r['status']) ?></span>
        <?php if($r['noResp']): ?><span class="pill p-red">🔇 No response</span><?php endif; ?>
        <?php if($r['aging']): ?><span class="pill p-yellow"><?= $agingDays ?>+ days</span><?php endif; ?>
        <?php if($r['stuck']): ?><span class="pill p-yellow">stuck</span><?php endif; ?>
      </div>
      <?php if($r['lastc']): ?>
        <div class="ac-lastc">💬 "<?= e(mb_strimwidth($r['lastc']['text'],0,110,'…')) ?>"
          <span class="ac-lcby">— <?= e($r['lastc']['by']?:'NCM') ?><?= $r['lastc']['time']?' · '.e(date('d M H:i',strtotime($r['lastc']['time']))):'' ?></span></div>
      <?php elseif($r['nid']!==''): ?><div class="ac-cmt muted">no comments yet</div><?php endif; ?>
      <?php if($o['phone']): ?>
      <div class="acc-act">
        <a class="btn btn-primary" style="flex:2;background:#25d366!important;border-color:#25d366!important" target="_blank" rel="noopener"
           href="https://wa.me/<?= e(wa_phone($o['phone'])) ?>?text=<?= rawurlencode(ac_wa_text($o,$r['branch'],$r['bphone'])) ?>">💬 WhatsApp</a>
        <a class="btn" style="flex:1" href="tel:<?= e($o['phone']) ?>" title="Call">📞</a>
      </div>
      <?php endif; ?>
      <div class="acc-act2">
        <?php if($r['nid']): ?><button class="btn" onclick='acReply(<?= json_encode($r['nid']) ?>)'>✉ NCM</button>
        <a class="btn" href="ncm.php?comments=<?= e($r['nid']) ?>">🧵 Thread</a><?php endif; ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="mark_called"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
          <button class="btn" style="color:var(--green)">✓ Called</button></form>
      </div>
    </div>
  <?php endforeach; if(!$list) echo '<div class="empty" style="padding:20px">🎉 Nothing needs action right now.</div>'; ?>
  </div>
  <div class="legend" style="display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--muted);padding:0 18px 14px">
    <span>🔥 priority = no-response + age + stuck + COD value — call top-down</span>
    <span>💬 opens WhatsApp with a ready Nepali message</span>
    <span>✓ Called hides them until tomorrow</span>
  </div>
</div>

<?php if($returning): ?>
<div class="panel" style="margin-top:18px;border:1px solid #e4dbff">
  <div class="panel-head"><h2>↩ Already Returning <span class="muted" style="font-size:12px;font-weight:600"><?= count($returning) ?> — the parcel is already heading back, no follow-up call needed</span></h2></div>
  <div class="ac-desktop"><div class="table-wrap"><table class="tbl">
    <thead><tr><th>Order</th><th>Customer</th><th>Age</th><th>NCM Status</th><th></th></tr></thead><tbody>
    <?php foreach($returning as $r): $o=$r['o']; ?>
      <tr>
        <td><b><?= e($o['code']) ?></b></td>
        <td><?= e($o['customer']?:'—') ?></td>
        <td><span class="age-pill age-mid"><?= $r['age'] ?>d</span></td>
        <td><span class="pill <?= ncm_status_class($r['status']) ?>"><?= e($r['status']) ?></span></td>
        <td class="right"><a class="btn btn-sm btn-primary" href="ncm.php?f=retcheck#orders">↩ Confirm on NCM Courier →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
  <div class="ac-cards">
  <?php foreach($returning as $r): $o=$r['o']; ?>
    <div class="acc" style="border-left:4px solid #7c5cf7">
      <div class="acc-top"><b><?= e($o['code']) ?></b><span class="age-pill age-mid"><?= $r['age'] ?>d</span>
        <span class="pill <?= ncm_status_class($r['status']) ?>" style="margin-left:auto"><?= e($r['status']) ?></span></div>
      <div class="acc-nm" style="font-size:13.5px"><?= e($o['customer']?:'—') ?></div>
      <div class="acc-act2"><a class="btn btn-primary" href="ncm.php?f=retcheck#orders">↩ Confirm on NCM Courier →</a></div>
    </div>
  <?php endforeach; ?>
  </div>
  <div class="legend" style="padding:10px 18px 14px;font-size:11.5px;color:var(--muted)">These are held on the NCM Courier page's Return? tab — go there to mark ✔ Returned or ✔ Delivered once you know which.</div>
</div>
<?php endif; ?>

<?php if($calledToday): ?>
<div class="panel" style="margin-top:18px">
  <div class="panel-head"><h2>✅ Contacted today <span class="muted" style="font-size:12px;font-weight:600"><?= count($calledToday) ?> — back in the queue tomorrow if still undelivered</span></h2></div>
  <div class="ac-desktop"><div class="table-wrap"><table class="tbl">
    <thead><tr><th>Order</th><th>Customer</th><th>Phone</th><th>Age</th><th>NCM Status</th><th></th></tr></thead><tbody>
    <?php foreach($calledToday as $r): $o=$r['o']; ?>
      <tr class="row-called">
        <td><b><?= e($o['code']) ?></b></td>
        <td><?= e($o['customer']?:'—') ?></td>
        <td class="num"><?= e($o['phone']?:'—') ?></td>
        <td><span class="age-pill age-ok"><?= $r['age'] ?>d</span></td>
        <td><span class="pill <?= ncm_status_class($r['status']) ?>"><?= e($r['status']) ?></span></td>
        <td class="right"><form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="unmark_called"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
          <button class="btn btn-sm">↩ Back to queue</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
  <div class="ac-cards">
  <?php foreach($calledToday as $r): $o=$r['o']; ?>
    <div class="acc" style="opacity:.65">
      <div class="acc-top"><b><?= e($o['code']) ?></b><span class="age-pill age-ok"><?= $r['age'] ?>d</span>
        <span class="pill <?= ncm_status_class($r['status']) ?>" style="margin-left:auto"><?= e($r['status']) ?></span></div>
      <div class="acc-nm" style="font-size:13.5px"><?= e($o['customer']?:'—') ?> <span class="muted" style="font-weight:400;font-size:11px"><?= e($o['phone']) ?></span></div>
      <div class="acc-act2">
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="unmark_called"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
          <button class="btn">↩ Back to queue</button></form>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- notify NCM modal -->
<div class="modal-bg" id="acReplyModal"><form class="modal" method="post" style="width:460px;max-width:94vw">
  <div class="modal-head"><span>✉ Message NCM</span><span class="mx" onclick="document.getElementById('acReplyModal').classList.remove('open');document.body.classList.remove('modal-open')">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="comment"><input type="hidden" name="ncm_id" id="ac_nid">
  <div class="modal-body">
    <div class="full"><label>Message for NCM Order #<span id="ac_lbl"></span></label>
      <input name="comment" id="ac_text" required placeholder="e.g. Customer confirmed — please re-attempt delivery"></div>
    <div class="full"><div class="muted" style="font-size:12px">Quick replies:</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
        <button type="button" class="btn btn-sm" onclick="acQ('Customer confirmed available — please re-attempt delivery.')">Re-attempt</button>
        <button type="button" class="btn btn-sm" onclick="acQ('Please call the customer again, phone was off earlier.')">Call again</button>
        <button type="button" class="btn btn-sm" onclick="acQ('Customer will collect from branch — please hold.')">Hold at branch</button>
        <button type="button" class="btn btn-sm" onclick="acQ('Customer not responding to us either — please hold 1 day.')">Hold 1 day</button>
      </div></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="document.getElementById('acReplyModal').classList.remove('open');document.body.classList.remove('modal-open')">Cancel</button><button class="btn btn-primary">Send ➤</button></div>
</form></div>

<script>
var ACF='all'; var ASORT='priority';
function acSelCount(){
  var boxes=Array.prototype.slice.call(document.querySelectorAll('.acSel:checked'));
  var n=boxes.length;
  document.getElementById('acSelN').textContent=n;
  document.getElementById('acBulkBar').classList.toggle('on',n>0);
  document.getElementById('acBulkIds').value=boxes.map(function(b){return b.value;}).join(',');
}
function acSelAll(master){
  document.querySelectorAll('#acRows tr .acSel, #acCards .acc .acSel').forEach(function(b){
    var host = b.closest('tr, .acc');
    if (host && host.style.display !== 'none') b.checked = master.checked;
  });
  acSelCount();
}
function acSelClear(){
  document.querySelectorAll('.acSel').forEach(function(b){ b.checked=false; });
  var all=document.getElementById('acSelAll'); if(all) all.checked=false;
  acSelCount();
}
function acTab(el){
  ACF=el.getAttribute('data-f');
  document.querySelectorAll('.acchip:not(.sortBtn)').forEach(function(c){c.classList.toggle('on',c===el);});
  acFilter();
}
function acSort(el){
  ASORT=el.getAttribute('data-sort');
  document.querySelectorAll('.sortBtn').forEach(function(c){c.classList.toggle('on',c===el);});
  ['acRows','acCards'].forEach(function(id){
    var box=document.getElementById(id); if(!box) return;
    var items=Array.prototype.slice.call(box.children);
    items.sort(function(a,b){
      var av=parseInt(a.getAttribute(ASORT==='newest'?'data-age':'data-idx'))||0;
      var bv=parseInt(b.getAttribute(ASORT==='newest'?'data-age':'data-idx'))||0;
      return av-bv;   /* newest: smallest age first · priority: smallest original index first (already highest-score-first from PHP) */
    });
    items.forEach(function(it){ box.appendChild(it); });
  });
}
function acFilter(){
  var q=(document.getElementById('acSearch').value||'').toLowerCase().trim();
  document.querySelectorAll('#acRows tr[data-f], #acCards .acc[data-f]').forEach(function(tr){
    var okF=(ACF==='all')||((tr.getAttribute('data-f')||'').indexOf(ACF)!==-1);
    var okQ=(!q)||((tr.getAttribute('data-s')||'').indexOf(q)!==-1);
    tr.style.display=(okF&&okQ)?'':'none';
  });
}
function acReply(nid){
  document.getElementById('ac_nid').value=nid;
  document.getElementById('ac_lbl').textContent=nid;
  document.getElementById('ac_text').value='';
  document.getElementById('acReplyModal').classList.add('open');document.body.classList.add('modal-open');
  setTimeout(function(){document.getElementById('ac_text').focus();},60);
}
function acQ(t){document.getElementById('ac_text').value=t;}
function acCSV(){
  var lines=[['Order','Customer','Phone','Age','NCM Status','Why','COD'].join(',')];
  document.querySelectorAll('#acRows tr[data-csv]').forEach(function(tr){
    if(tr.style.display==='none')return;
    try{ lines.push(JSON.parse(tr.getAttribute('data-csv')).map(function(v){return '"'+String(v==null?'':v).replace(/"/g,'""')+'"';}).join(',')); }catch(e){}
  });
  var blob=new Blob([lines.join('\n')],{type:'text/csv'});
  var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='ncm_call_list.csv';a.click();
}
</script>
<?php require __DIR__.'/includes/footer.php'; ?>