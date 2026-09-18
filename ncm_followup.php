<?php
/* ============================================================
   NCM CUSTOMER FOLLOW-UP — one place for the whole delivery
   lifecycle's customer communication.
   Reached via a link from ncm_action.php. NOT in the main nav —
   same pattern as the Action Center itself.

   Deliberately self-contained: its own branch/hub/message helpers,
   not borrowed from ncm_pending.php or ncm_action.php, so this page
   can never break because of what state some OTHER file is in.

   Return-suspect orders are NEVER touched here — that lock stays
   owned by ncm.php/cron.php, exactly as before. This page only
   reads ncm_return_flag to know when to STOP offering stage
   messages and point to the confirm tab instead.
   ============================================================ */
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
require_once __DIR__.'/ncm_api.php';
ncm_ensure_cols();
try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS ncm_stage_msg VARCHAR(20) NULL"); } catch (Exception $e) {}
try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS ncm_stage_msg_at DATETIME NULL"); } catch (Exception $e) {}
$PAGE_TITLE='NCM Customer Follow-Up';

if(!function_exists('wa_phone')){ function wa_phone($p){ $ph=preg_replace('/[^0-9]/','',(string)$p); $ph=ltrim($ph,'0'); if(strpos($ph,'977')!==0)$ph='977'.$ph; return $ph; } }

if (!function_exists('fu_match_branch')) {
  function fu_match_branch($name,$names){
    $t=mb_strtoupper(trim((string)$name)); if($t==='') return '';
    foreach($names as $b) if(mb_strtoupper($b)===$t) return $b;
    foreach($names as $b) if(mb_strpos(mb_strtoupper($b),$t)!==false || mb_strpos($t,mb_strtoupper($b))!==false) return $b;
    return '';
  }
  function fu_guess_branch($addr,$names){
    $A=mb_strtoupper((string)$addr); if($A==='') return '';
    $best=''; $bl=0;
    foreach($names as $b){ $B=mb_strtoupper($b); if($B!=='' && mb_strpos($A,$B)!==false && mb_strlen($B)>$bl){ $best=$b; $bl=mb_strlen($B); } }
    return $best;
  }
  function fu_branch_phones(){
    $c=kv_get('fu_branch_phones');
    if($c && (time()-strtotime($c['at']??'1970-01-01'))<86400 && is_array($c['v']??null) && count($c['v'])) return $c['v'];
    $map=[];
    try {
      foreach(ncm()->branches() as $b){
        if(empty($b['name'])||!is_array($b)) continue;
        $ph='';
        foreach($b as $k=>$v){ if(!is_string($v)&&!is_numeric($v)) continue; if(preg_match('/phone|contact|tel|mobile/i',(string)$k)&&trim((string)$v)!==''){ $ph=trim((string)$v); break; } }
        if($ph==='') foreach($b as $v){ if((is_string($v)||is_numeric($v)) && preg_match('/^[0-9+\-\s]{7,}$/',trim((string)$v))){ $ph=trim((string)$v); break; } }
        $map[mb_strtoupper((string)$b['name'])]=$ph;
      }
      if($map) kv_set('fu_branch_phones',$map);
    } catch (Exception $e) {}
    return $map;
  }
  function fu_newest_comment($th){
    if(!is_array($th)||!$th) return null;
    $best=null; $bestTs=-1; $any=false;
    foreach($th as $c){ if(!is_array($c)) continue; $ts=strtotime((string)($c['added_time']??($c['addedTime']??''))); if($ts!==false){ $any=true; if($ts>=$bestTs){ $bestTs=$ts; $best=$c; } } }
    if(!$any) $best=end($th);
    return $best ? ['text'=>(string)($best['comments']??($best['comment']??'')),'by'=>(string)($best['addedBy']??''),'time'=>(string)($best['added_time']??($best['addedTime']??''))] : null;
  }
}

/* stage detection: same signal ncm_action.php already uses for "in delivery",
   layered with the order's OWN authoritative return_flag/status (never re-derived) */
if (!function_exists('fu_detect_stage')) {
function fu_detect_stage($liveStatus, $order){
  if (($order['status']??'')==='returned') return 'returned';
  $flag = (int)($order['ncm_return_flag'] ?? 0);
  if ($flag===1 || $flag===2) return 'holding';   /* return-suspect — handled entirely on the NCM page, not here */
  $sl = strtolower($liveStatus);
  if (($order['status']??'')==='delivered') return 'delivered';
  $inTransit = strpos($sl,'dispatch')!==false||strpos($sl,'sent for delivery')!==false||strpos($sl,'arrived')!==false||strpos($sl,'out for')!==false||strpos($sl,'ship')!==false;
  return $inTransit ? 'transit' : 'booked';
}
function fu_hub_line($branch,$phone){
  if($branch==='') return '';
  $t="\n🏢 Parcel ".$branch." branch ma cha";
  if($phone!=='') $t.=" — sampark garna sakinuhuncha: ".$phone;
  return $t;
}
function fu_message($stage,$tpl,$o,$ncmId,$branch,$phone,$link){
  $name = trim((string)($o['customer']??'')) !== '' ? trim($o['customer']).' ji' : 'there';
  return strtr($tpl,[
    '{name}'=>$name,'{code}'=>$o['code']??'','{ncm_id}'=>$ncmId,'{link}'=>$link,
    '{hub_line}'=>fu_hub_line($branch,$phone),
  ]);
}
}

$stageMsgTpl = [
  'booked'    => trim((string)setting('ncm_msg_booked_tpl',''))    ?: "Namaste {name}! Your order has been dispatched via NCM.\n📦 NCM Tracking No: {ncm_id}{hub_line}\nTrack here: {link}",
  'transit'   => trim((string)setting('ncm_msg_transit_tpl',''))   ?: "Namaste {name}! Your parcel is out for delivery today.\n🛵 The NCM rider will call you shortly — kripaya phone uthaidinus (please answer the call) so they can hand over your order.{hub_line}",
  'delivered' => trim((string)setting('ncm_msg_delivered_tpl','')) ?: "Dhanyabad {name} ji! 🙏 Your order has been delivered. We hope you love it — feel free to reach out if there's any issue at all.",
  'returned'  => trim((string)setting('ncm_msg_returned_tpl',''))  ?: "Namaste {name}, your order {code} is being returned to us. If there's a reason we should know, please reply here — we'd like to make it right next time.",
];
$stageLabel = ['booked'=>'📮 Send Tracking','transit'=>'🛵 Notify: Rider Coming','delivered'=>'✅ Send Thank You','returned'=>'↩ Send Return Notice'];
$stageBadge = ['booked'=>'Booked','transit'=>'Out for Delivery','delivered'=>'Delivered','returned'=>'Returned'];

$trackUrl = trim((string)setting('ncm_customer_track_url','')) ?: 'https://portal.nepalcanmove.com/track/';

/* ---- POST: send (mark) / reply to NCM ---- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act = $_POST['_action'] ?? '';
  try {
    if ($act==='mark_sent') {
      $id=(int)($_POST['id']??0); $stage=(string)($_POST['stage']??'');
      q("UPDATE orders SET ncm_stage_msg=?, ncm_stage_msg_at=NOW() WHERE id=?",[$stage,$id]);
      $oc=(string)val("SELECT code FROM orders WHERE id=?",[$id]);
      log_activity("Follow-up sent ($stage) to customer: $oc",'NCM');
      flash("✓ $oc — $stage message marked as sent.");
    }
    elseif ($act==='reply_ncm') {
      $nid=(string)($_POST['ncm_id']??''); $msg=trim((string)($_POST['comment']??''));
      if($nid!=='' && $msg!=='') { ncm()->addComment((int)$nid,$msg); log_activity('Replied to NCM #'.$nid.' (Follow-Up)','NCM'); flash('Reply sent to NCM.'); }
    }
  } catch (Exception $ex) { flash('Error: '.$ex->getMessage()); }
  header('Location: ncm_followup.php'); exit;
}

require __DIR__.'/includes/header.php';

$connected=false; $branchNames=[]; $branchPhones=[];
if (ncm()->configured()) { try { $bs=ncm()->branches(); $connected=is_array($bs); foreach($bs as $b) if(!empty($b['name'])) $branchNames[]=$b['name']; sort($branchNames); $branchPhones=fu_branch_phones(); } catch (Exception $e) {} }

$orders = rows("SELECT o.*, p.name AS product_name FROM orders o
  LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id
  WHERE c.name LIKE '%NCM%' AND COALESCE(o.ncm_order_id,'')<>''
    AND o.status NOT IN ('cancelled')
  ORDER BY o.id DESC");

/* live status (display only — never writes back; the sync stays owned by ncm.php/cron.php) */
$liveStatus=[]; $bookedIds=array_values(array_filter(array_map(fn($o)=>$o['ncm_order_id']??null,$orders)));
if ($connected && $bookedIds) { try { $r=ncm()->ordersStatuses(array_map('intval',$bookedIds)); if(isset($r['result'])&&is_array($r['result'])) $liveStatus=$r['result']; } catch (Exception $e) {} }

$rows=[]; $holding=[]; $counts=['booked'=>0,'transit'=>0,'delivered'=>0];
foreach($orders as $o){
  $nid=(string)($o['ncm_order_id']??'');
  $live = $nid!=='' && isset($liveStatus[$nid]) ? (string)$liveStatus[$nid] : (string)($o['ncm_status']??'');
  $stage = fu_detect_stage($live, $o);
  if ($stage==='holding') { $holding[]=['o'=>$o,'status'=>$live?:$o['status']]; continue; }
  if ($stage==='returned') continue;  /* fully resolved, nothing to follow up */

  $branch = fu_match_branch($live, $branchNames) ?: fu_guess_branch($o['address'] ?? '', $branchNames);
  $phone  = $branch!=='' ? ($branchPhones[mb_strtoupper($branch)] ?? '') : '';
  $msg = fu_message($stage, $stageMsgTpl[$stage], $o, $nid, $branch, $phone, $trackUrl);
  $alreadySent = ($o['ncm_stage_msg'] ?? '') === $stage;

  $rows[] = compact('o','nid','live','stage','branch','phone','msg','alreadySent');
  if (!$alreadySent) $counts[$stage] = ($counts[$stage] ?? 0) + 1;
}

/* newest NCM comment per order, cached 3 min — same approach proven in the Action Center */
$qNids = array_values(array_unique(array_filter(array_map(fn($r)=>$r['nid'], $rows))));
$lastCmt = [];
if ($qNids && $connected) {
  $cache = kv_get('fu_lastcmt');
  $fresh = $cache && (time()-strtotime($cache['at']??'1970-01-01'))<180 && is_array($cache['v']??null);
  $lastCmt = $fresh ? $cache['v'] : [];
  $miss = array_values(array_filter($qNids, fn($n)=>!array_key_exists($n,$lastCmt)));
  if ($miss) { foreach(array_slice($miss,0,40) as $n){ try{ $lastCmt[$n]=fu_newest_comment(ncm()->comments((int)$n)); }catch(Exception $e){ $lastCmt[$n]=null; } usleep(80000); } try{ kv_set('fu_lastcmt',$lastCmt); }catch(Exception $e){} }
}
$needsReply = 0;
foreach($rows as &$r){ $r['lastc']=$lastCmt[$r['nid']]??null; if($r['lastc'] && stripos($r['lastc']['by'],'vendor')===false) $needsReply++; } unset($r);

/* sort: unsent stage messages first, then by recency */
usort($rows, function($a,$b){
  if ($a['alreadySent']!==$b['alreadySent']) return $a['alreadySent']?1:-1;
  return $b['o']['id']<=>$a['o']['id'];
});
?>
<style>
.fu-hero{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:linear-gradient(120deg,#0369a1,#0e7490);border-radius:18px;padding:20px 24px;color:#fff;margin-bottom:16px;box-shadow:0 14px 34px rgba(3,105,161,.25)}
.fu-hero h1{font-size:21px;font-weight:900;margin:0}
.fu-hero p{opacity:.85;font-size:12.5px;margin:2px 0 0}
.fu-row{background:rgba(255,255,255,.72);backdrop-filter:blur(16px) saturate(1.5);border:1px solid rgba(255,255,255,.7);border-radius:14px;padding:13px 16px;margin-bottom:10px;box-shadow:0 6px 18px rgba(30,41,80,.06)}
.fu-row.sent{opacity:.6}
.fu-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.fu-name{font-weight:800;font-size:13.5px}
.fu-sub{font-size:11px;color:var(--muted)}
.fu-stage{border-radius:99px;padding:3px 11px;font-size:10.5px;font-weight:800}
.fu-stage.booked{background:#dbeafe;color:#1d4ed8}
.fu-stage.transit{background:#fef3c7;color:#b45309}
.fu-stage.delivered{background:#dcfce7;color:#12a06a}
.fu-cmt{margin-top:8px;background:#f4f6fb;border-left:3px solid #0e7490;border-radius:0 9px 9px 0;padding:7px 11px;font-size:11.5px;line-height:1.5}
.fu-cmt .by{display:block;font-size:10px;color:var(--muted);font-weight:700;margin-top:1px}
.fu-act{display:flex;gap:7px;margin-top:9px;flex-wrap:wrap}
.fu-replybox{display:flex;gap:6px;margin-top:7px}
.fu-replybox input{flex:1;border:1px solid var(--border);border-radius:9px;padding:7px 10px;font:inherit;font-size:12px}
@media(max-width:640px){ .fu-hero{padding:16px 18px} .fu-hero h1{font-size:18px} .fu-act .btn{flex:1;text-align:center} }
</style>

<div class="fu-hero">
  <span style="font-size:30px">🛵</span>
  <div style="flex:1;min-width:220px">
    <h1>NCM Customer Follow-Up</h1>
    <p>Every order's delivery stage, its message, and NCM's replies — one place, no switching</p>
  </div>
  <a class="btn" style="background:rgba(255,255,255,.16);color:#fff" href="ncm_action.php">🚨 Action Center</a>
  <a class="btn" style="background:rgba(255,255,255,.16);color:#fff" href="ncm.php">📮 NCM Courier</a>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>
<?php if(!$connected): ?><div class="flash" style="background:var(--amber-bg,#fef3c7);color:#92610a">⚠ NCM isn't connected — connect it in Settings to see live stages and comments.</div><?php endif; ?>

<div class="mgrid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
  <div class="metric blue"><span class="g">📮</span><div class="mv"><?= $counts['booked'] ?></div><div class="ml">Tracking not sent</div></div>
  <div class="metric amber"><span class="g">🛵</span><div class="mv"><?= $counts['transit'] ?></div><div class="ml">Rider-coming not sent</div></div>
  <div class="metric green"><span class="g">✅</span><div class="mv"><?= $counts['delivered'] ?></div><div class="ml">Thank-you not sent</div></div>
  <div class="metric purple"><span class="g">💬</span><div class="mv"><?= $needsReply ?></div><div class="ml">Needs your reply</div></div>
  <div class="metric indigo"><span class="g">↩</span><div class="mv"><?= count($holding) ?></div><div class="ml">Return-suspect — on NCM page</div></div>
</div>

<?php if($holding): ?>
<div class="panel" style="margin-top:16px;border:1px solid #ddd6fe">
  <div class="panel-head"><h2>↩ Return-Suspect — decide on the NCM Courier page</h2></div>
  <div class="panel-body">
    <?php foreach($holding as $h): $o=$h['o']; ?>
    <div class="fu-row"><div class="fu-top">
      <b><?= e($o['code']) ?></b> <span class="fu-sub"><?= e($o['customer']?:'—') ?></span>
      <span class="pill <?= ncm_status_class($h['status']) ?>" style="margin-left:auto"><?= e($h['status']) ?></span>
      <a class="btn btn-sm btn-primary" href="ncm.php?f=retcheck#orders">Confirm on NCM →</a>
    </div></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:16px">
  <div class="panel-head"><h2>📋 Follow-Up Queue <span class="muted" style="font-size:12px;font-weight:600"><?= count($rows) ?> active orders</span></h2></div>
  <div class="panel-body">
    <?php foreach($rows as $r): $o=$r['o']; ?>
    <div class="fu-row <?= $r['alreadySent']?'sent':'' ?>">
      <div class="fu-top">
        <span class="fu-stage <?= $r['stage'] ?>"><?= e($stageBadge[$r['stage']]) ?></span>
        <span class="fu-name"><?= e($o['code']) ?> · <?= e($o['customer']?:'—') ?></span>
        <span class="fu-sub"><?= e($o['phone']?:'') ?></span>
        <?php if($r['alreadySent']): ?><span class="pill p-green" style="font-size:10px;margin-left:auto"><?= e($stageBadge[$r['stage']]) ?> msg sent <?= e(date('d M H:i',strtotime($o['ncm_stage_msg_at']))) ?></span><?php endif; ?>
      </div>
      <div class="fu-sub"><?= e($o['product_name']?:'Goods') ?> ×<?= (int)$o['qty'] ?> · NCM <?= e($r['nid']) ?> · <?= e($r['live']) ?><?= $r['branch']!==''?' · '.e($r['branch']).' branch':'' ?></div>

      <?php if($r['lastc']): ?>
        <div class="fu-cmt">💬 "<?= e(mb_strimwidth($r['lastc']['text'],0,110,'…')) ?>"<span class="by">— <?= e($r['lastc']['by']?:'NCM') ?><?= $r['lastc']['time']?' · '.e(date('d M H:i',strtotime($r['lastc']['time']))):'' ?></span></div>
      <?php endif; ?>

      <?php if($o['phone']): ?>
      <div class="fu-act">
        <a class="btn btn-sm btn-primary fu-send" style="background:#25d366!important;border-color:#25d366!important" target="_blank" rel="noopener"
           data-oid="<?= (int)$o['id'] ?>" data-stage="<?= e($r['stage']) ?>" data-csrf="<?= csrf() ?>"
           href="https://wa.me/<?= e(wa_phone($o['phone'])) ?>?text=<?= rawurlencode($r['msg']) ?>"><?= e($stageLabel[$r['stage']]) ?></a>
        <a class="btn btn-sm" href="tel:<?= e($o['phone']) ?>">📞</a>
        <?php if($r['nid']): ?><a class="btn btn-sm" href="ncm.php?comments=<?= e($r['nid']) ?>">🧵 Thread</a><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if($r['nid']): ?>
      <form method="post" class="fu-replybox">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="reply_ncm"><input type="hidden" name="ncm_id" value="<?= e($r['nid']) ?>">
        <input type="text" name="comment" placeholder="Reply to NCM…">
        <button class="btn btn-sm">Send</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; if(!$rows): ?><div class="empty">🎉 Nothing needs a follow-up message right now.</div><?php endif; ?>
  </div>
</div>

<script>
document.querySelectorAll('.fu-send').forEach(function(a){
  a.addEventListener('click', function(){
    var body='csrf='+encodeURIComponent(a.getAttribute('data-csrf'))+'&_action=mark_sent&id='+encodeURIComponent(a.getAttribute('data-oid'))+'&stage='+encodeURIComponent(a.getAttribute('data-stage'));
    fetch('ncm_followup.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body:body})
      .then(function(){ setTimeout(function(){ location.reload(); }, 600); });
  });
});
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
