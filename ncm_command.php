<?php
/* ============================================================
   NCM COMMAND CENTER
   Replaces: ncm_pending.php, ncm_action.php, ncm_followup.php.
   One page, urgency-ordered. Delivered/returned/cancelled orders
   are OUT OF SCOPE entirely.

   Self-contained by design — branch/hub/comment/message helpers
   are local copies, not borrowed from other files. A lesson
   learned the hard way earlier in this project: a page should
   never break because of what state some OTHER file is in.

   HONEST NOTE on the "morning digest": there is no Meta WhatsApp
   Business API configured, so nothing can be silently auto-sent
   at 8am. What this page actually does: (1) a real ERP notification
   bell entry every morning via cron, (2) an email if a report email
   is configured, and (3) a one-tap "Send via WhatsApp" button on
   this page that opens the chat with the digest pre-filled, ready
   for a human tap. That is the honest ceiling without the API.
   ============================================================ */
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
require_once __DIR__.'/ncm_api.php';
ncm_ensure_cols();
foreach ([
  "ALTER TABLE orders ADD COLUMN IF NOT EXISTS ncm_stage_msg VARCHAR(20) NULL",
  "ALTER TABLE orders ADD COLUMN IF NOT EXISTS ncm_stage_msg_at DATETIME NULL",
  "ALTER TABLE orders ADD COLUMN IF NOT EXISTS ncm_last_contact DATE NULL",
  "ALTER TABLE orders ADD COLUMN IF NOT EXISTS ncm_snooze_until DATE NULL",
  "ALTER TABLE orders ADD COLUMN IF NOT EXISTS ncm_snooze_reason VARCHAR(255) NULL",
  "ALTER TABLE orders ADD COLUMN IF NOT EXISTS ncm_note VARCHAR(500) NULL",
  "ALTER TABLE orders ADD COLUMN IF NOT EXISTS ncm_outcome VARCHAR(20) NULL",
  "ALTER TABLE orders ADD COLUMN IF NOT EXISTS ncm_outcome_at DATETIME NULL",
] as $ddl) { try { q($ddl); } catch (Exception $e) {} }
repair_zero_cost_profit();
$PAGE_TITLE='NCM Command Center';

if(!function_exists('wa_phone')){ function wa_phone($p){ $ph=preg_replace('/[^0-9]/','',(string)$p); $ph=ltrim($ph,'0'); if(strpos($ph,'977')!==0)$ph='977'.$ph; return $ph; } }

if (!function_exists('cc_match_branch')) {
  function cc_match_branch($name,$names){
    $t=mb_strtoupper(trim((string)$name)); if($t==='') return '';
    foreach($names as $b) if(mb_strtoupper($b)===$t) return $b;
    foreach($names as $b) if(mb_strpos(mb_strtoupper($b),$t)!==false || mb_strpos($t,mb_strtoupper($b))!==false) return $b;
    return '';
  }
  function cc_guess_branch($addr,$names){
    $A=mb_strtoupper((string)$addr); if($A==='') return '';
    $best=''; $bl=0;
    foreach($names as $b){ $B=mb_strtoupper($b); if($B!=='' && mb_strpos($A,$B)!==false && mb_strlen($B)>$bl){ $best=$b; $bl=mb_strlen($B); } }
    return $best;
  }
  function cc_branch_phones(){
    $c=kv_get('cc_branch_phones');
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
      if($map) kv_set('cc_branch_phones',$map);
    } catch (Exception $e) {}
    return $map;
  }
  function cc_newest_comment($th){
    if(!is_array($th)||!$th) return null;
    $best=null; $bestTs=-1; $any=false;
    foreach($th as $c){ if(!is_array($c)) continue; $ts=strtotime((string)($c['added_time']??($c['addedTime']??''))); if($ts!==false){ $any=true; if($ts>=$bestTs){ $bestTs=$ts; $best=$c; } } }
    if(!$any) $best=end($th);
    return $best ? ['text'=>(string)($best['comments']??($best['comment']??'')),'by'=>(string)($best['addedBy']??''),'time'=>(string)($best['added_time']??($best['addedTime']??''))] : null;
  }
  function cc_hub_line($branch,$phone){
    if($branch==='') return '';
    $t="\n🏢 Parcel ".$branch." branch ma cha";
    if($phone!=='') $t.=" — sampark garna sakinuhuncha: ".$phone;
    return $t;
  }
  function cc_smart_message($stage, $commentText, $o, $branch, $phone, $link, $ncmId, $stageMsgTpl){
    $name = trim((string)($o['customer']??'')) !== '' ? trim($o['customer']).' ji' : 'there';
    $hub = cc_hub_line($branch,$phone);
    $c = mb_strtolower((string)$commentText);

    if ($c!=='' && (mb_strpos($c,'evening')!==false || mb_strpos($c,'tomorrow')!==false || mb_strpos($c,'later')!==false || mb_strpos($c,'reschedul')!==false)) {
      return "Namaste $name! We saw your note about the delivery timing — NCM has it noted and will retry as requested.$hub Please stay reachable on this number. 🙏";
    }
    if ($c!=='' && (mb_strpos($c,'not receiv')!==false || mb_strpos($c,'not answer')!==false || mb_strpos($c,'no response')!==false || mb_strpos($c,'switch off')!==false || mb_strpos($c,'phone not')!==false)) {
      return "Namaste $name! We've been trying to reach you about your order — the NCM rider couldn't get through. 🙏 Kripaya phone khulla rakhnus (please keep your phone reachable), we'd hate for this to go back over a missed call.$hub";
    }
    if ($c!=='' && mb_strpos($c,'wrong')!==false && (mb_strpos($c,'address')!==false || mb_strpos($c,'number')!==false)) {
      return "Namaste $name! NCM flagged a possible issue with your delivery address or number for order {$o['code']}. Please reply here with the correct details so we can get this to you without delay.";
    }
    return strtr($stageMsgTpl,['{name}'=>$name,'{code}'=>$o['code']??'','{ncm_id}'=>$ncmId,'{link}'=>$link,'{hub_line}'=>$hub]);
  }
}

$stageMsgTpl = [
  'booked'  => trim((string)setting('ncm_msg_booked_tpl',''))  ?: "Namaste {name}! Your order has been dispatched via NCM.\n📦 NCM Tracking No: {ncm_id}{hub_line}\nTrack here: {link}",
  'transit' => trim((string)setting('ncm_msg_transit_tpl','')) ?: "Namaste {name}! Your parcel is out for delivery today.\n🛵 The NCM rider will call you shortly — kripaya phone uthaidinus (please answer the call) so they can hand over your order.{hub_line}",
];
$stageBtn = ['booked'=>'📮 Send Tracking','transit'=>'🛵 Rider Coming — Send'];
$trackUrl = trim((string)setting('ncm_customer_track_url','')) ?: 'https://portal.nepalcanmove.com/track/';
$agingDays  = (int)setting('ncm_aging_days',5);
$atriskDays = (int)setting('ncm_atrisk_days',4);
$digestOn   = (string)setting('ncm_digest_enabled','1')==='1';
$myPhone    = trim((string)setting('digest_whatsapp_number', setting('store_phone','')));

$OUTCOMES = ['confirmed'=>['✓ Confirmed','confirmed'],'resched'=>['📅 Rescheduled','resched'],'noreply'=>['… No Reply Yet','noreply'],'cancel'=>['✕ Wants to Cancel','cancel']];

/* ---- POST actions ---- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act = $_POST['_action'] ?? '';
  try {
    if ($act==='book') {
      $bn=[]; foreach (ncm()->branches() as $b) if(!empty($b['name'])) $bn[]=$b['name'];
      [$ncmId,$ncmCharge] = ncm_book_one([
        'name'=>$_POST['name']??'','phone'=>$_POST['phone']??'',
        'cod'=>$_POST['cod_charge']??0,'address'=>$_POST['address']??'',
        'fbranch'=>$_POST['fbranch']??'','branch'=>$_POST['branch']??'',
        'package'=>$_POST['package']??'','vref_id'=>$_POST['vref_id']??'',
        'delivery_type'=>$_POST['delivery_type']??'Door2Door',
      ], $bn);
      $oid=(int)($_POST['order_id']??0);
      if ($oid) {
        q("UPDATE orders SET ncm_order_id=?, courier_id=(SELECT id FROM couriers WHERE name LIKE '%NCM%' LIMIT 1) WHERE id=?", [$ncmId,$oid]);
        if ($ncmCharge!==null) q("UPDATE orders SET delivery_charge=? WHERE id=?", [$ncmCharge,$oid]);
      }
      log_activity('Booked NCM order '.$ncmId.' (Command Center)','NCM');
      flash("Booked ✓ NCM #$ncmId — ready to notify below.");
    }
    elseif ($act==='mark_sent') {
      $id=(int)($_POST['id']??0); $stage=(string)($_POST['stage']??'');
      q("UPDATE orders SET ncm_stage_msg=?, ncm_stage_msg_at=NOW(), ncm_outcome=NULL, ncm_outcome_at=NULL WHERE id=?",[$stage,$id]);
      flash('✓ Message marked as sent.');
    }
    elseif ($act==='mark_called') {
      $id=(int)($_POST['id']??0);
      q("UPDATE orders SET ncm_last_contact=CURDATE() WHERE id=?",[$id]);
      flash('✓ Marked as contacted today.');
    }
    elseif ($act==='mark_called_bulk') {
      $ids = array_slice(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))), 0, 100);
      $n=0; foreach ($ids as $id) { q("UPDATE orders SET ncm_last_contact=CURDATE() WHERE id=?",[$id]); $n++; }
      flash($n ? "✓ $n order(s) marked as contacted today." : 'Nothing selected.');
    }
    elseif ($act==='reply_ncm') {
      $nid=(string)($_POST['ncm_id']??''); $msg=trim((string)($_POST['comment']??''));
      if($nid!=='' && $msg!=='') { ncm()->addComment((int)$nid,$msg); flash('Reply sent to NCM.'); }
    }
    elseif ($act==='save_note') {
      $id=(int)($_POST['id']??0); $note=mb_substr(trim((string)($_POST['note']??'')),0,500);
      q("UPDATE orders SET ncm_note=? WHERE id=?",[$note!==''?$note:null,$id]);
      flash('Note saved.');
    }
    elseif ($act==='set_outcome') {
      $id=(int)($_POST['id']??0); $oc=(string)($_POST['outcome']??'');
      if (isset($OUTCOMES[$oc])) { q("UPDATE orders SET ncm_outcome=?, ncm_outcome_at=NOW() WHERE id=?",[$oc,$id]); flash('Outcome recorded.'); }
    }
    elseif ($act==='snooze') {
      $id=(int)($_POST['id']??0); $days=max(1,min(30,(int)($_POST['days']??1))); $reason=mb_substr(trim((string)($_POST['reason']??'')),0,255);
      q("UPDATE orders SET ncm_snooze_until=DATE_ADD(CURDATE(), INTERVAL ? DAY), ncm_snooze_reason=? WHERE id=?",[$days,$reason!==''?$reason:null,$id]);
      flash("Snoozed for $days day(s).");
    }
    elseif ($act==='wake') {
      $id=(int)($_POST['id']??0);
      q("UPDATE orders SET ncm_snooze_until=NULL, ncm_snooze_reason=NULL WHERE id=?",[$id]);
      flash('Woken up — back in the active queue.');
    }
    elseif ($act==='save_digest_settings') {
      set_setting('ncm_digest_enabled', !empty($_POST['enabled']) ? '1' : '0');
      set_setting('digest_whatsapp_number', trim((string)($_POST['digest_phone']??'')));
      flash('Digest settings saved.');
    }
  } catch (Exception $ex) { flash('Error: '.$ex->getMessage()); }
  header('Location: ncm_command.php'); exit;
}

require __DIR__.'/includes/header.php';

$connected=false; $branchNames=[]; $branchPhones=[];
if (ncm()->configured()) { try { $bs=ncm()->branches(); $connected=is_array($bs); foreach($bs as $b) if(!empty($b['name'])) $branchNames[]=$b['name']; sort($branchNames); $branchPhones=cc_branch_phones(); } catch (Exception $e) {} }

/* ---- scope: NCM-bound, active, never delivered/returned/cancelled ---- */
$orders = rows("SELECT o.*, p.name AS product_name FROM orders o
  LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id
  WHERE (c.name LIKE '%NCM%' OR o.courier_id IS NULL)
    AND o.status NOT IN ('delivered','cancelled','returned')
  ORDER BY o.id DESC");

$bookedIds = array_values(array_filter(array_map(fn($o)=>$o['ncm_order_id']??null, $orders)));
$liveStatus=[];
if ($connected && $bookedIds) { try { $r=ncm()->ordersStatuses(array_map('intval',$bookedIds)); if(isset($r['result'])&&is_array($r['result'])) $liveStatus=$r['result']; } catch (Exception $e) {} }

$qNids = array_values(array_unique(array_filter(array_map(fn($o)=>(string)($o['ncm_order_id']??''), $orders))));
$lastCmt = [];
if ($qNids && $connected) {
  $cache = kv_get('cc_lastcmt');
  $lastCmt = ($cache && (time()-strtotime($cache['at']??'1970-01-01'))<180 && is_array($cache['v']??null)) ? $cache['v'] : [];
  $miss = array_values(array_filter($qNids, fn($n)=>!array_key_exists($n,$lastCmt)));
  if ($miss) { foreach(array_slice($miss,0,40) as $n){ try{ $lastCmt[$n]=cc_newest_comment(ncm()->comments((int)$n)); }catch(Exception $e){ $lastCmt[$n]=null; } usleep(80000); } try{ kv_set('cc_lastcmt',$lastCmt); }catch(Exception $e){} }
}

/* repeat-customer counts — one batched query, not one per row */
$phones = array_values(array_unique(array_filter(array_map(fn($o)=>trim((string)($o['phone']??'')), $orders))));
$repeatCounts = [];
if ($phones) {
  $ph_in = implode(',', array_fill(0, count($phones), '?'));
  foreach (rows("SELECT phone, COUNT(*) n FROM orders WHERE phone IN ($ph_in) AND status IN ('returned','cancelled')", $phones) as $rr) {
    $repeatCounts[$rr['phone']] = (int)$rr['n'];
  }
}

$today = new DateTime(); $todayStr = date('Y-m-d');
$urgent=[]; $outcome=[]; $needsMsg=[]; $awaiting=[]; $calledToday=[]; $snoozed=[];

foreach ($orders as $o) {
  $nid = (string)($o['ncm_order_id']??'');
  if ($nid==='') { $awaiting[]=$o; continue; }

  if (!empty($o['ncm_snooze_until']) && $o['ncm_snooze_until'] >= $todayStr) { $snoozed[]=$o; continue; }

  $flag = (int)($o['ncm_return_flag'] ?? 0);
  $live = isset($liveStatus[$nid]) ? (string)$liveStatus[$nid] : (string)($o['ncm_status']??'');
  $sl = strtolower($live);
  $age = $o['order_date'] ? (int)$today->diff(new DateTime($o['order_date']))->days : 0;
  $inDelivery = strpos($sl,'dispatch')!==false||strpos($sl,'sent for delivery')!==false||strpos($sl,'arrived')!==false||strpos($sl,'out for')!==false||strpos($sl,'ship')!==false;
  $cmt = $lastCmt[$nid] ?? null;
  $noResp = $cmt ? ncm_no_response($cmt['text']) : false;
  $aging  = $age>=$agingDays;
  $stuck  = $inDelivery && $age>=$atriskDays && !$noResp;
  $holding = ($flag===1 || $flag===2);
  $repeatN = $repeatCounts[trim((string)($o['phone']??''))] ?? 0;

  $branch = cc_match_branch($live,$branchNames) ?: cc_guess_branch($o['address']??'',$branchNames);
  $phone  = $branch!=='' ? ($branchPhones[mb_strtoupper($branch)]??'') : '';

  if ($holding || $noResp || $aging || $stuck) {
    $score = ($noResp?45:0) + min($age,10)*6 + ($stuck?12:0) + ($holding?60:0);
    $isCalledToday = !empty($o['ncm_last_contact']) && $o['ncm_last_contact']===$todayStr;
    $row = compact('o','nid','live','age','holding','noResp','aging','stuck','score','cmt','branch','phone','repeatN');
    if ($isCalledToday) $calledToday[]=$row; else $urgent[]=$row;
    continue;
  }

  $stage = $inDelivery ? 'transit' : 'booked';
  $sentForStage = ($o['ncm_stage_msg']??'')===$stage;
  if ($sentForStage) {
    if (empty($o['ncm_outcome'])) { $outcome[] = compact('o','nid','live','stage','repeatN'); }
    continue;
  }
  $msg = cc_smart_message($stage, $cmt['text']??'', $o, $branch, $phone, $trackUrl, $nid, $stageMsgTpl[$stage]);
  $needsMsg[] = compact('o','nid','live','stage','cmt','branch','phone','msg','repeatN');
}
usort($urgent, fn($a,$b)=>$b['score']<=>$a['score']);

$initial = function($name){ $name = trim((string)$name); return $name!=='' ? mb_strtoupper(mb_substr($name,0,1)) : '?'; };
$codOf = function($o){ return strtolower((string)($o['payment_type']??''))==='cod' ? (float)$o['sell_price']*(int)$o['qty'] : 0; };
$codSum = function($list){ $s=0; foreach($list as $r) $s+=(strtolower((string)($r['o']['payment_type']??''))==='cod' ? (float)$r['o']['sell_price']*(int)$r['o']['qty'] : 0); return $s; };
?>
<style>
.cc-hero{background:linear-gradient(120deg,#7f1d1d,#0369a1);border-radius:22px;padding:22px 26px;color:#fff;margin-bottom:14px;box-shadow:0 18px 40px rgba(3,105,161,.3)}
.cc-hero-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.cc-hero h1{font-size:21px;font-weight:900;margin:0}
.cc-hero p{opacity:.85;font-size:12px;margin:2px 0 0}
.cc-hero-links{display:flex;gap:8px;margin-left:auto;flex-wrap:wrap}
.cc-hlink{background:rgba(255,255,255,.9);color:#0369a1;border-radius:10px;padding:8px 13px;font-size:11.5px;font-weight:800;text-decoration:none}
.cc-hlink.ghost{background:rgba(255,255,255,.18);color:#fff}
.cc-digest{margin-top:14px;background:rgba(255,255,255,.14);border-radius:12px;padding:10px 15px;display:flex;align-items:center;gap:10px;font-size:11.5px;flex-wrap:wrap}
.cc-digest .dot{width:8px;height:8px;border-radius:99px;background:#4ade80;flex:none}
.cc-digest .dot.off{background:#f87171}
.cc-digest-btn{margin-left:auto;background:rgba(255,255,255,.9);color:#0369a1;border:0;border-radius:99px;padding:6px 13px;font-weight:800;font-size:10.5px;cursor:pointer}

.cc-scope{background:rgba(255,255,255,.75);backdrop-filter:blur(14px);border:1px solid #bae6fd;color:#0369a1;border-radius:12px;padding:9px 15px;font-size:11.5px;margin-bottom:14px}
.cc-megasearch{display:flex;align-items:center;gap:10px;background:#fff;border-radius:16px;padding:13px 18px;box-shadow:0 10px 26px rgba(30,41,80,.09);margin-bottom:12px;border:2px solid transparent;position:sticky;top:8px;z-index:15}
.cc-megasearch:focus-within{border-color:#0369a1}
.cc-megasearch input{flex:1;border:0;outline:0;font-size:14px;font-weight:600}
.cc-megasearch .cnt{font-size:10.5px;color:#8a93a8;font-weight:700;background:#f1f4fa;border-radius:99px;padding:4px 11px;white-space:nowrap}
.cc-megasearch .collapseall{background:#f1f4fa;border:0;border-radius:99px;padding:6px 13px;font-weight:800;font-size:10.5px;color:#334;cursor:pointer;white-space:nowrap}

.cc-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px}
.cc-tile{background:rgba(255,255,255,.85);backdrop-filter:blur(16px);border-radius:15px;padding:12px 10px;text-align:center;box-shadow:0 6px 18px rgba(30,41,80,.07);border-top:3px solid #ddd;text-decoration:none;color:inherit;display:block;transition:transform .12s}
.cc-tile:hover{transform:translateY(-2px)}
.cc-tile.red{border-top-color:#dc2626}.cc-tile.purple{border-top-color:#7c3aed}.cc-tile.amber{border-top-color:#d97706}.cc-tile.grey{border-top-color:#94a3b8}.cc-tile.green{border-top-color:#16a34a}.cc-tile.slate{border-top-color:#64748b}
.cc-tile b{font-size:19px;display:block;font-weight:900}
.cc-tile span{font-size:10px;color:#5a6580;font-weight:700}

.cc-section-h{display:flex;align-items:center;gap:9px;margin:22px 0 10px;padding:0 2px;flex-wrap:wrap}
.cc-section-h .tag{font-size:13px;font-weight:900}
.cc-section-h .cnt2{font-size:10.5px;font-weight:800;border-radius:99px;padding:2px 9px}
.cc-section-h .codsum{margin-left:auto;font-size:11px;font-weight:800;background:#fff;border-radius:99px;padding:4px 12px;box-shadow:0 4px 10px rgba(30,41,80,.06)}

.cc-bulkbar{display:none;align-items:center;gap:12px;background:#eefdf4;border:1.5px solid #86efac;border-radius:13px;padding:9px 15px;margin-bottom:10px;box-shadow:0 6px 18px rgba(34,197,94,.15)}
.cc-bulkbar.on{display:flex}
.cc-bulkbar button{border:0;border-radius:9px;padding:7px 13px;font-weight:800;font-size:11px;cursor:pointer}

.cc-card{background:#fff;border-radius:16px;padding:14px 16px;margin-bottom:11px;box-shadow:0 6px 20px rgba(30,41,80,.07);border-left:5px solid #dc2626;display:flex;gap:11px}
.cc-card.amber-b{border-left-color:#d97706}
.cc-card.purple-b{border-left-color:#7c3aed}
.cc-card.slate-b{border-left-color:#94a3b8;opacity:.85}
.cc-card.grey-b{border-left-color:#94a3b8}
.cc-check{margin-top:3px;width:16px;height:16px}
.cc-card-body{flex:1;min-width:0}
.cc-card-top{display:flex;align-items:flex-start;gap:11px}
.cc-avatar{width:40px;height:40px;border-radius:99px;background:linear-gradient(135deg,#fca5a5,#dc2626);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14.5px;flex:none;box-shadow:0 3px 9px rgba(220,38,38,.3)}
.cc-avatar.blue{background:linear-gradient(135deg,#93c5fd,#0369a1);box-shadow:0 3px 9px rgba(3,105,161,.3)}
.cc-avatar.purple{background:linear-gradient(135deg,#c4b5fd,#7c3aed);box-shadow:0 3px 9px rgba(124,58,237,.3)}
.cc-avatar.slate{background:linear-gradient(135deg,#cbd5e1,#64748b);box-shadow:none}
.cc-info{flex:1;min-width:0}
.cc-nm{font-weight:900;font-size:13.5px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.cc-sub{font-size:11px;color:var(--muted);margin-top:2px}
.cc-badge{font-size:9.5px;font-weight:800;border-radius:99px;padding:2px 8px}
.cc-cod{font-weight:900;font-size:12.5px;color:#334;text-align:right;flex:none}
.cc-cod .l{font-size:9px;color:#8a93a8;font-weight:700;display:block}
.cc-repeat{background:linear-gradient(90deg,#fff7ed,#ffedd5);border:1px solid #fed7aa;color:#c2410c;font-size:10.5px;font-weight:800;border-radius:9px;padding:6px 10px;margin-top:8px}
.cc-cmt{margin-top:8px;background:linear-gradient(90deg,#f0f9ff,#f8fafc);border-radius:10px;padding:7px 12px;font-size:11.5px;line-height:1.5;border-left:3px solid #0369a1}
.cc-lastc{font-size:10px;color:#8a93a8;margin-top:6px;font-weight:700}
.cc-msgnote{font-size:9.5px;color:#7c3aed;background:#f3ecff;border-radius:99px;padding:2px 9px;margin-top:5px;display:inline-block;font-weight:700}
.cc-note{margin-top:8px;background:#fefce8;border:1px dashed #fde047;border-radius:9px;padding:6px 10px;display:flex;gap:6px;align-items:center}
.cc-note input{flex:1;border:0;background:transparent;font-size:11px;outline:0;font-style:italic;color:#78350f}
.cc-note button{border:0;background:transparent;color:#a16207;font-size:11px;font-weight:800;cursor:pointer}
.cc-tonerow{display:flex;gap:6px;margin-top:9px}
.cc-tonechip{border:1.5px solid #e4e8f2;border-radius:99px;padding:4px 11px;font-size:10px;font-weight:800;color:#334;cursor:pointer;background:#fff}
.cc-tonechip.on{background:#0369a1;border-color:#0369a1;color:#fff}
.cc-act{display:flex;gap:7px;margin-top:9px;flex-wrap:wrap;align-items:center}
.cc-wa{flex:1;min-width:130px;background:linear-gradient(135deg,#25d366,#128c7e);color:#fff;border:0;border-radius:11px;padding:10px 14px;font-weight:900;font-size:12px;display:flex;align-items:center;justify-content:center;gap:7px;text-decoration:none;box-shadow:0 5px 14px rgba(37,211,102,.3)}
.cc-icon{width:38px;height:38px;border-radius:11px;background:#f1f4fa;color:#334;display:flex;align-items:center;justify-content:center;font-size:15px;flex:none;text-decoration:none;border:0;cursor:pointer}
.cc-icon.portal{background:#e0f2fe;color:#0369a1}
.cc-icon.snooze{background:#f1f5f9;color:#475569}
.cc-outrow{margin-top:9px}
.cc-outlbl{font-size:10px;color:#6b7280;font-weight:700;margin-bottom:5px}
.cc-outbtns{display:flex;gap:6px;flex-wrap:wrap}
.cc-oc{border:1.5px solid #e4e8f2;border-radius:9px;padding:6px 11px;font-size:10.5px;font-weight:800;cursor:pointer;background:#fff}
.cc-oc.confirmed{color:#16a34a;border-color:#bbf7d0}.cc-oc.resched{color:#d97706;border-color:#fde68a}.cc-oc.noreply{color:#64748b}.cc-oc.cancel{color:#dc2626;border-color:#fecaca}
.cc-snoozepick{display:none;background:#f8fafc;border-radius:9px;padding:8px 10px;margin-top:9px;font-size:10.5px;gap:6px;flex-wrap:wrap;align-items:center}
.cc-snoozepick.on{display:flex}
.cc-snoozepick select,.cc-snoozepick input[type=text]{border:1px solid var(--border);border-radius:7px;padding:5px 8px;font-size:10.5px}
.cc-snoozeinfo{background:#f8fafc;border-radius:9px;padding:8px 12px;font-size:11px;margin-top:2px}
.cc-empty{padding:22px 18px;text-align:center;color:var(--muted);font-size:12px}
.cc-showmore{text-align:center;background:#fff;border-radius:13px;padding:11px;font-size:11.5px;font-weight:800;color:#0369a1;box-shadow:0 4px 14px rgba(30,41,80,.06);margin-bottom:12px;cursor:pointer}
@media(max-width:640px){
  .cc-hero{padding:17px 19px}.cc-hero h1{font-size:18px}
  .cc-megasearch{position:static}
  .cc-act .cc-wa{flex:1 1 100%}
}
</style>

<div class="cc-hero">
  <div class="cc-hero-top">
    <span style="font-size:28px">🚚</span>
    <div><h1>NCM Command Center</h1><p>Urgent first, always — every order still in motion</p></div>
    <div class="cc-hero-links">
      <a class="cc-hlink" href="https://portal.nepalcanmove.com/" target="_blank" rel="noopener">🌐 NCM Portal ↗</a>
      <a class="cc-hlink ghost" href="ncm.php">📮 Internal Page</a>
    </div>
  </div>
  <div class="cc-digest">
    <span class="dot <?= $digestOn?'':'off' ?>"></span>
    <span><b>🔔 Morning digest</b> — a bell notification every day<?= $myPhone ? ' + one-tap WhatsApp below' : '' ?> <?= $digestOn?'(on)':'(off)' ?></span>
    <button type="button" class="cc-digest-btn" onclick="ccDigestSettings()">⚙ Settings</button>
    <?php if ($myPhone): $digestText = "Today: ".count($urgent)." need attention, ".count($needsMsg)." need a message, ".count($awaiting)." awaiting dispatch."; ?>
    <a class="cc-digest-btn" style="background:#25d366;color:#fff" target="_blank" rel="noopener"
       href="https://wa.me/<?= e(wa_phone($myPhone)) ?>?text=<?= rawurlencode($digestText) ?>">💬 Send Today's Digest</a>
    <?php endif; ?>
  </div>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>
<?php if(!$connected): ?><div class="flash" style="background:var(--amber-bg,#fef3c7);color:#92610a">⚠ NCM isn't connected — connect it in Settings to see live stages, comments, and booking.</div><?php endif; ?>

<div class="cc-scope">ℹ️ Shows orders <b>booked but not yet delivered, returned, or cancelled</b>, plus anything still waiting to be booked. Delivered orders drop off automatically.</div>

<div class="cc-megasearch">🔍 <input id="ccSearch" placeholder="Search name, phone, order code, or branch — filters everything at once">
  <span class="cnt" id="ccCount"><?= count($orders) ?> orders</span>
  <button type="button" class="collapseall" onclick="ccCollapseAll()">⤢ All</button>
</div>

<div class="cc-tiles">
  <a href="#band-urgent" class="cc-tile red"><b><?= count($urgent) ?></b><span>🚨 Attention</span></a>
  <a href="#band-outcome" class="cc-tile purple"><b><?= count($outcome) ?></b><span>🎯 Outcome</span></a>
  <a href="#band-msg" class="cc-tile amber"><b><?= count($needsMsg) ?></b><span>🛵 Message</span></a>
  <a href="#band-snoozed" class="cc-tile slate"><b><?= count($snoozed) ?></b><span>⏰ Snoozed</span></a>
  <a href="#band-await" class="cc-tile grey"><b><?= count($awaiting) ?></b><span>🚚 Dispatch</span></a>
  <a href="#band-called" class="cc-tile green"><b><?= count($calledToday) ?></b><span>✓ Done</span></a>
</div>

<div class="cc-section-h" id="band-urgent"><span class="tag" style="color:#7f1d1d">🚨 NEEDS ATTENTION NOW</span><span class="cnt2" style="background:#fee2e2;color:#c0392b"><?= count($urgent) ?></span><span class="codsum" style="color:#7f1d1d">💰 <?= money($codSum($urgent)) ?> at risk</span></div>
<div class="cc-bulkbar" id="bulkbar-urgent"><span><b class="bn">0</b> selected</span><button type="button" class="bulkMark" data-band="urgent" style="background:#16a34a;color:#fff">✓ Mark Called</button><button type="button" class="bulkClear" data-band="urgent" style="background:#64748b;color:#fff">✕ Clear</button></div>
<?php foreach ($urgent as $i=>$r): $o=$r['o']; $hideStyle = $i>=8 ? ' style="display:none"' : ''; ?>
<div class="cc-card cc-extra-urgent" data-band="urgent" data-s="<?= e(mb_strtolower($o['code'].' '.$o['customer'].' '.$o['phone'].' '.$r['branch'])) ?>"<?= $hideStyle ?>>
  <?php if(!$r['holding']): ?><input type="checkbox" class="cc-check ccSel" data-band="urgent" value="<?= (int)$o['id'] ?>"><?php endif; ?>
  <div class="cc-card-body">
    <div class="cc-card-top">
      <div class="cc-avatar"><?= e($initial($o['customer'])) ?></div>
      <div class="cc-info">
        <div class="cc-nm"><?= e($o['customer']?:'—') ?>
          <?php if($r['holding']): ?><span class="cc-badge" style="background:#ede7fe;color:#7c3aed">↩ Return-Suspect</span>
          <?php elseif($r['noResp']): ?><span class="cc-badge" style="background:#fee2e2;color:#c0392b">No Response</span>
          <?php elseif($r['aging']): ?><span class="cc-badge" style="background:#fef3c7;color:#b45309">Aging <?= $r['age'] ?>d</span>
          <?php else: ?><span class="cc-badge" style="background:#fef3c7;color:#b45309">Stuck</span><?php endif; ?>
        </div>
        <div class="cc-sub"><?= e($o['code']) ?> · <?= e($o['phone']) ?><?= $r['branch']!==''?' · '.e($r['branch']):'' ?></div>
      </div>
      <div class="cc-cod"><span class="l">COD</span><?= money($codOf($o)) ?></div>
    </div>
    <?php if($r['repeatN']>=2): ?><div class="cc-repeat">⚠️ Repeat concern — <?= $r['repeatN'] ?> prior returns from this customer</div><?php endif; ?>
    <?php if($r['cmt']): ?><div class="cc-cmt">💬 "<?= e(mb_strimwidth($r['cmt']['text'],0,110,'…')) ?>" — <?= e($r['cmt']['by']?:'NCM') ?></div><?php endif; ?>
    <?php if(!empty($o['ncm_note'])): ?>
    <div class="cc-note">📝 <input value="<?= e($o['ncm_note']) ?>" data-oid="<?= (int)$o['id'] ?>" class="ccNoteInput"><button type="button" class="ccNoteSave" data-oid="<?= (int)$o['id'] ?>">Save</button></div>
    <?php else: ?>
    <div class="cc-note">📝 <input placeholder="Add a note visible to the team…" data-oid="<?= (int)$o['id'] ?>" class="ccNoteInput"><button type="button" class="ccNoteSave" data-oid="<?= (int)$o['id'] ?>">Save</button></div>
    <?php endif; ?>
    <div class="cc-act">
      <?php if($r['holding']): ?>
        <a class="cc-wa" style="background:#7c3aed" href="ncm.php?f=retcheck#orders">↩ Confirm on NCM →</a>
      <?php elseif($o['phone']): ?>
        <a class="cc-wa ccWaBtn" data-msgbase="<?= e(cc_smart_message('transit',$r['cmt']['text']??'',$o,$r['branch'],$r['phone'],$trackUrl,$r['nid'],$stageMsgTpl['transit'])) ?>" data-name="<?= e($o['customer']) ?>"
           target="_blank" rel="noopener" href="https://wa.me/<?= e(wa_phone($o['phone'])) ?>?text=<?= rawurlencode(cc_smart_message('transit',$r['cmt']['text']??'',$o,$r['branch'],$r['phone'],$trackUrl,$r['nid'],$stageMsgTpl['transit'])) ?>">💬 Send WhatsApp</a>
        <a class="cc-icon" href="tel:<?= e($o['phone']) ?>">📞</a>
      <?php endif; ?>
      <a class="cc-icon portal" href="<?= e(ncm_portal_url($r['nid'])) ?>" target="_blank" rel="noopener" title="Open on the real NCM portal">🌐</a>
      <?php if(!$r['holding']): ?>
      <button type="button" class="cc-icon snooze ccSnoozeToggle">⏰</button>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="mark_called"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="cc-icon" style="color:var(--green)">✓</button></form>
      <?php endif; ?>
    </div>
    <?php if(!$r['holding']): ?>
    <div class="cc-snoozepick">
      <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="snooze"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
        ⏰ Snooze <select name="days"><option value="1">1 day</option><option value="2">2 days</option><option value="3" selected>3 days</option><option value="7">1 week</option></select>
        <input type="text" name="reason" placeholder="why? (optional)" style="width:130px">
        <button class="btn btn-sm">Snooze</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; if(count($urgent)>8): ?><button type="button" class="cc-showmore" data-band="urgent">⌄ Show <?= count($urgent)-8 ?> more (<?= count($urgent) ?> total)</button><?php endif; if(!$urgent): ?><div class="cc-empty">🎉 Nothing urgent right now.</div><?php endif; ?>

<div class="cc-section-h" id="band-outcome"><span class="tag" style="color:#5b21b6">🎯 AWAITING OUTCOME</span><span class="cnt2" style="background:#ede7fe;color:#7c3aed"><?= count($outcome) ?></span></div>
<?php foreach ($outcome as $i=>$r): $o=$r['o']; $hideStyle = $i>=8 ? ' style="display:none"' : ''; ?>
<div class="cc-card purple-b cc-extra-outcome" data-band="outcome" data-s="<?= e(mb_strtolower($o['code'].' '.$o['customer'].' '.$o['phone'])) ?>"<?= $hideStyle ?>>
  <div class="cc-card-body">
    <div class="cc-card-top">
      <div class="cc-avatar purple"><?= e($initial($o['customer'])) ?></div>
      <div class="cc-info">
        <div class="cc-nm"><?= e($o['customer']?:'—') ?> <span class="cc-badge" style="background:#ede7fe;color:#7c3aed">Message Sent</span></div>
        <div class="cc-sub"><?= e($o['code']) ?> · sent <?= e(date('d M, H:i',strtotime($o['ncm_stage_msg_at']))) ?></div>
      </div>
      <div class="cc-cod"><span class="l">COD</span><?= money($codOf($o)) ?></div>
    </div>
    <div class="cc-outrow">
      <div class="cc-outlbl">What happened?</div>
      <div class="cc-outbtns">
        <?php foreach($OUTCOMES as $k=>$pair): [$lbl,$cls]=$pair; ?>
        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="set_outcome"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="outcome" value="<?= e($k) ?>">
          <button class="cc-oc <?= e($cls) ?>"><?= e($lbl) ?></button></form>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; if(count($outcome)>8): ?><button type="button" class="cc-showmore" data-band="outcome">⌄ Show <?= count($outcome)-8 ?> more</button><?php endif; if(!$outcome): ?><div class="cc-empty">Nothing awaiting an outcome.</div><?php endif; ?>

<div class="cc-section-h" id="band-msg"><span class="tag" style="color:#78350f">🛵 NEEDS A MESSAGE</span><span class="cnt2" style="background:#fef3c7;color:#b45309"><?= count($needsMsg) ?></span></div>
<div class="cc-bulkbar" id="bulkbar-msg"><span><b class="bn">0</b> selected</span><button type="button" class="bulkMark" data-band="msg" style="background:#16a34a;color:#fff">✓ Mark Called</button><button type="button" class="bulkClear" data-band="msg" style="background:#64748b;color:#fff">✕ Clear</button></div>
<?php foreach ($needsMsg as $i=>$r): $o=$r['o']; $hideStyle = $i>=8 ? ' style="display:none"' : ''; ?>
<div class="cc-card amber-b cc-extra-msg" data-band="msg" data-s="<?= e(mb_strtolower($o['code'].' '.$o['customer'].' '.$o['phone'].' '.$r['branch'])) ?>"<?= $hideStyle ?>>
  <input type="checkbox" class="cc-check ccSel" data-band="msg" value="<?= (int)$o['id'] ?>">
  <div class="cc-card-body">
    <div class="cc-card-top">
      <div class="cc-avatar blue"><?= e($initial($o['customer'])) ?></div>
      <div class="cc-info">
        <div class="cc-nm"><?= e($o['customer']?:'—') ?> <span class="cc-badge" style="background:#dbeafe;color:#1d4ed8"><?= $r['stage']==='transit'?'Out for Delivery':'Booked' ?></span></div>
        <div class="cc-sub"><?= e($o['code']) ?> · <?= e($o['phone']) ?> · NCM <?= e($r['nid']) ?></div>
      </div>
      <div class="cc-cod"><span class="l">COD</span><?= money($codOf($o)) ?></div>
    </div>
    <?php if($r['repeatN']>=2): ?><div class="cc-repeat">⚠️ Repeat concern — <?= $r['repeatN'] ?> prior returns from this customer</div><?php endif; ?>
    <?php if($r['cmt']): ?><div class="cc-cmt">💬 "<?= e(mb_strimwidth($r['cmt']['text'],0,110,'…')) ?>" — <?= e($r['cmt']['by']?:'NCM') ?></div><?php endif; ?>
    <?php if($r['cmt']): ?><span class="cc-msgnote">🧠 message reflects this comment</span><?php endif; ?>
    <div class="cc-lastc"><?= !empty($o['ncm_last_contact']) ? '🕐 Last contacted '.e(date('d M',strtotime($o['ncm_last_contact']))) : '🕐 Never contacted' ?></div>
    <div class="cc-tonerow">
      <span class="cc-tonechip on" data-tone="standard">Standard</span>
      <span class="cc-tonechip" data-tone="firm">Firmer</span>
      <span class="cc-tonechip" data-tone="polite">Extra polite</span>
    </div>
    <?php if($o['phone']): ?>
    <div class="cc-act">
      <a class="cc-wa ccWaBtn fu-send" data-msgbase="<?= e($r['msg']) ?>" data-name="<?= e($o['customer']) ?>"
         data-oid="<?= (int)$o['id'] ?>" data-stage="<?= e($r['stage']) ?>" data-csrf="<?= csrf() ?>"
         target="_blank" rel="noopener" href="https://wa.me/<?= e(wa_phone($o['phone'])) ?>?text=<?= rawurlencode($r['msg']) ?>"><?= e($stageBtn[$r['stage']]) ?></a>
      <a class="cc-icon" href="tel:<?= e($o['phone']) ?>">📞</a>
      <a class="cc-icon portal" href="<?= e(ncm_portal_url($r['nid'])) ?>" target="_blank" rel="noopener">🌐</a>
      <button type="button" class="cc-icon snooze ccSnoozeToggle">⏰</button>
    </div>
    <?php endif; ?>
    <div class="cc-snoozepick">
      <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="snooze"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
        ⏰ Snooze <select name="days"><option value="1">1 day</option><option value="2">2 days</option><option value="3" selected>3 days</option><option value="7">1 week</option></select>
        <input type="text" name="reason" placeholder="why? (optional)" style="width:130px">
        <button class="btn btn-sm">Snooze</button>
      </form>
    </div>
    <form method="post" style="display:flex;gap:6px;margin-top:8px">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="reply_ncm"><input type="hidden" name="ncm_id" value="<?= e($r['nid']) ?>">
      <input type="text" name="comment" placeholder="Reply to NCM…" style="flex:1;border:1px solid var(--border);border-radius:9px;padding:6px 10px;font-size:11px"><button class="btn btn-sm">Send</button>
    </form>
  </div>
</div>
<?php endforeach; if(count($needsMsg)>8): ?><button type="button" class="cc-showmore" data-band="msg">⌄ Show <?= count($needsMsg)-8 ?> more (<?= count($needsMsg) ?> total)</button><?php endif; if(!$needsMsg): ?><div class="cc-empty">🎉 Everyone's up to date.</div><?php endif; ?>

<div class="cc-section-h" id="band-snoozed"><span class="tag" style="color:#334">⏰ SNOOZED</span><span class="cnt2" style="background:#f1f5f9;color:#475569"><?= count($snoozed) ?></span></div>
<?php foreach ($snoozed as $o): ?>
<div class="cc-card slate-b" data-band="snoozed" data-s="<?= e(mb_strtolower($o['code'].' '.$o['customer'])) ?>">
  <div class="cc-card-body">
    <div class="cc-card-top">
      <div class="cc-avatar slate"><?= e($initial($o['customer'])) ?></div>
      <div class="cc-info">
        <div class="cc-nm"><?= e($o['customer']?:'—') ?> <span class="cc-badge" style="background:#f1f5f9;color:#475569">Snoozed</span></div>
        <div class="cc-sub"><?= e($o['code']) ?></div>
      </div>
    </div>
    <div class="cc-snoozeinfo">⏰ Snoozed until <b><?= e(date('D, d M',strtotime($o['ncm_snooze_until']))) ?></b><?= !empty($o['ncm_snooze_reason'])?' — "'.e($o['ncm_snooze_reason']).'"':'' ?>
      <form method="post" style="display:inline;float:right"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="wake"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
      <button class="btn btn-sm" style="color:#0369a1">Wake now</button></form>
    </div>
  </div>
</div>
<?php endforeach; if(!$snoozed): ?><div class="cc-empty">Nothing snoozed.</div><?php endif; ?>

<div class="cc-section-h" id="band-await"><span class="tag" style="color:#334">🚚 AWAITING DISPATCH</span><span class="cnt2" style="background:#f1f4fa;color:#5a6580"><?= count($awaiting) ?></span></div>
<?php foreach ($awaiting as $i=>$o): $hideStyle = $i>=8 ? ' style="display:none"' : ''; ?>
<div class="cc-card grey-b cc-extra-await" data-band="await" data-s="<?= e(mb_strtolower($o['code'].' '.$o['customer'].' '.$o['phone'])) ?>"<?= $hideStyle ?>>
  <div class="cc-card-body">
    <div class="cc-card-top">
      <div class="cc-avatar slate"><?= e($initial($o['customer'])) ?></div>
      <div class="cc-info">
        <div class="cc-nm"><?= e($o['customer']?:'—') ?></div>
        <div class="cc-sub"><?= e($o['code']) ?> · <?= e($o['phone']) ?> · <?= e($o['product_name']?:'Goods') ?></div>
      </div>
      <button type="button" class="btn btn-sm btn-primary" onclick="ccBook(<?= (int)$o['id'] ?>,'<?= e(addslashes($o['customer'])) ?>','<?= e(addslashes($o['phone'])) ?>','<?= e(addslashes($o['address'])) ?>',<?= (float)$o['sell_price']*(int)$o['qty'] ?>)">🚚 Book</button>
    </div>
  </div>
</div>
<?php endforeach; if(count($awaiting)>8): ?><button type="button" class="cc-showmore" data-band="await">⌄ Show <?= count($awaiting)-8 ?> more</button><?php endif; if(!$awaiting): ?><div class="cc-empty">Nothing waiting to be booked.</div><?php endif; ?>

<div class="cc-section-h" id="band-called"><span class="tag" style="color:#065f46">✓ CONTACTED TODAY</span><span class="cnt2" style="background:#dcfce7;color:#12a06a"><?= count($calledToday) ?></span></div>
<?php foreach ($calledToday as $r): $o=$r['o']; ?>
<div class="cc-card" style="border-left-color:#16a34a" data-band="called" data-s="<?= e(mb_strtolower($o['code'].' '.$o['customer'])) ?>">
  <div class="cc-card-body"><div class="cc-nm"><?= e($o['customer']?:'—') ?></div><div class="cc-sub"><?= e($o['code']) ?> · already marked contacted today</div></div>
</div>
<?php endforeach; if(!$calledToday): ?><div class="cc-empty">No one contacted yet today.</div><?php endif; ?>

<div id="ccBookModal" class="modal" style="display:none">
  <div class="modal-box">
    <h3>🚚 Book on NCM</h3>
    <form method="post" id="ccBookForm">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="book">
      <input type="hidden" name="order_id" id="cb_oid"><input type="hidden" name="vref_id" id="cb_vref">
      <label>Name</label><input name="name" id="cb_name" required>
      <label>Phone</label><input name="phone" id="cb_phone" required>
      <label>Address</label><input name="address" id="cb_address" required>
      <label>COD Amount</label><input name="cod_charge" id="cb_cod" type="number">
      <label>From Branch</label><select name="fbranch" id="cb_fbranch"><?php foreach($branchNames as $b): ?><option><?= e($b) ?></option><?php endforeach; ?></select>
      <label>Destination Branch</label><select name="branch" id="cb_branch"><?php foreach($branchNames as $b): ?><option><?= e($b) ?></option><?php endforeach; ?></select>
      <input type="hidden" name="package" value="Goods x1">
      <div style="display:flex;gap:8px;margin-top:12px"><button class="btn btn-primary">Book</button><button type="button" class="btn" onclick="document.getElementById('ccBookModal').style.display='none'">Cancel</button></div>
    </form>
  </div>
</div>

<div id="ccDigestModal" class="modal" style="display:none">
  <div class="modal-box">
    <h3>🔔 Morning Digest Settings</h3>
    <p class="muted" style="font-size:11.5px;margin-bottom:10px">Honest limit: without the WhatsApp Business API, nothing can be silently auto-sent. This gives you a bell notification every morning, plus a one-tap "Send via WhatsApp" button on this page with the day's numbers ready to go.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="save_digest_settings">
      <label><input type="checkbox" name="enabled" <?= $digestOn?'checked':'' ?>> Enable daily bell notification</label>
      <label style="margin-top:10px;display:block">Your WhatsApp number (for the one-tap send button)</label>
      <input type="text" name="digest_phone" value="<?= e($myPhone) ?>" placeholder="98XXXXXXXX">
      <div style="display:flex;gap:8px;margin-top:12px"><button class="btn btn-primary">Save</button><button type="button" class="btn" onclick="document.getElementById('ccDigestModal').style.display='none'">Cancel</button></div>
    </form>
  </div>
</div>

<script>
function ccCollapseAll(){ }
document.querySelectorAll('.cc-showmore').forEach(function(btn){
  btn.addEventListener('click', function(){
    var band = btn.getAttribute('data-band');
    document.querySelectorAll('.cc-card[data-band="'+band+'"]').forEach(function(card){
      if (card.style.display === 'none') card.style.display = '';
    });
    btn.remove();
  });
});
function ccBook(id,name,phone,addr,cod){
  document.getElementById('cb_oid').value=id; document.getElementById('cb_vref').value='CMD'+id;
  document.getElementById('cb_name').value=name; document.getElementById('cb_phone').value=phone;
  document.getElementById('cb_address').value=addr; document.getElementById('cb_cod').value=cod;
  document.getElementById('ccBookModal').style.display='flex';
}
function ccDigestSettings(){ document.getElementById('ccDigestModal').style.display='flex'; }

document.getElementById('ccSearch').addEventListener('input', function(){
  var q=this.value.toLowerCase().trim(); var shown=0;
  document.querySelectorAll('.cc-card[data-s]').forEach(function(c){
    var ok=(!q||(c.getAttribute('data-s')||'').indexOf(q)!==-1);
    c.style.display=ok?'':'none'; if(ok) shown++;
  });
  document.getElementById('ccCount').textContent = q ? (shown+' match'+(shown===1?'':'es')) : '<?= count($orders) ?> orders';
});

function ccToneWrap(base, tone, name){
  if (tone==='firm') {
    var m = base.replace(/^Namaste [^!]+!\s*/u, 'Namaste '+name+', ');
    m = m.replace(/ ?🙏/g,'');
    return m.trim() + "\n\nKripaya chaँdai jawaph dinuhos — if we don't hear back soon this will likely go back as a return.";
  }
  if (tone==='polite') {
    var m = base.replace(/^Namaste ([^!,]+)[!,]\s*/u, function(full, capturedName){
      var cleanName = capturedName.replace(/\s+ji$/i, '');
      return 'Namaste ' + cleanName + ' ji! 🙏 ';
    });
    return m.trim() + "\n\nDhanyabad ra maaf garnuhos herani ko lagi — thank you so much for your patience with us! 🙏";
  }
  return base;
}
document.querySelectorAll('.cc-tonerow').forEach(function(row){
  var card = row.closest('.cc-card');
  var waBtn = card.querySelector('.ccWaBtn');
  if(!waBtn) return;
  row.querySelectorAll('.cc-tonechip').forEach(function(chip){
    chip.addEventListener('click', function(){
      row.querySelectorAll('.cc-tonechip').forEach(function(c){ c.classList.toggle('on', c===chip); });
      var tone = chip.getAttribute('data-tone');
      var base = waBtn.getAttribute('data-msgbase');
      var name = waBtn.getAttribute('data-name') || 'there';
      var finalMsg = ccToneWrap(base, tone, name);
      var url = new URL(waBtn.href);
      url.searchParams.set('text', finalMsg);
      waBtn.href = url.toString();
    });
  });
});

document.querySelectorAll('.ccSnoozeToggle').forEach(function(btn){
  btn.addEventListener('click', function(){
    var pick = btn.closest('.cc-card-body').querySelector('.cc-snoozepick');
    if(pick) pick.classList.toggle('on');
  });
});

document.querySelectorAll('.ccNoteSave').forEach(function(btn){
  btn.addEventListener('click', function(){
    var oid=btn.getAttribute('data-oid');
    var input=document.querySelector('.ccNoteInput[data-oid="'+oid+'"]');
    var body='csrf=<?= urlencode(csrf()) ?>&_action=save_note&id='+encodeURIComponent(oid)+'&note='+encodeURIComponent(input.value);
    fetch('ncm_command.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body:body});
    btn.textContent='Saved ✓'; setTimeout(function(){ btn.textContent='Save'; },1500);
  });
});

document.querySelectorAll('.fu-send').forEach(function(a){
  a.addEventListener('click', function(){
    var body='csrf='+encodeURIComponent(a.getAttribute('data-csrf'))+'&_action=mark_sent&id='+encodeURIComponent(a.getAttribute('data-oid'))+'&stage='+encodeURIComponent(a.getAttribute('data-stage'));
    fetch('ncm_command.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body:body})
      .then(function(){ setTimeout(function(){ location.reload(); }, 600); });
  });
});

function ccBulkRefresh(band){
  var sel = document.querySelectorAll('.ccSel[data-band="'+band+'"]:checked');
  var bar = document.getElementById('bulkbar-'+band);
  if(!bar) return;
  bar.classList.toggle('on', sel.length>0);
  bar.querySelector('.bn').textContent = sel.length;
}
document.querySelectorAll('.ccSel').forEach(function(cb){
  cb.addEventListener('change', function(){ ccBulkRefresh(cb.getAttribute('data-band')); });
});
document.querySelectorAll('.bulkClear').forEach(function(btn){
  btn.addEventListener('click', function(){
    var band=btn.getAttribute('data-band');
    document.querySelectorAll('.ccSel[data-band="'+band+'"]').forEach(function(cb){ cb.checked=false; });
    ccBulkRefresh(band);
  });
});
document.querySelectorAll('.bulkMark').forEach(function(btn){
  btn.addEventListener('click', function(){
    var band=btn.getAttribute('data-band');
    var ids=Array.prototype.map.call(document.querySelectorAll('.ccSel[data-band="'+band+'"]:checked'), function(c){return c.value;});
    if(!ids.length) return;
    var body='csrf=<?= urlencode(csrf()) ?>&_action=mark_called_bulk&ids='+encodeURIComponent(ids.join(','));
    fetch('ncm_command.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body:body})
      .then(function(){ location.reload(); });
  });
});
</script>
<?php require __DIR__.'/includes/footer.php'; ?>