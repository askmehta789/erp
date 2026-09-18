<?php
/* ============================================================
   NCM PENDING ORDERS — dedicated dispatch + notify page.
   Scope is deliberately narrow, per spec: book orders on NCM,
   then send the customer their tracking link. That's it.
   - Booked orders STAY on this page (never move to ncm_action.php).
   - This page does NOT run the status-sync/heal logic that lives
     on ncm.php/cron.php — it only READS the stored status, so it
     can never interfere with the return-tracking system built
     there. Reuses the same booking helpers as ncm.php (ncm_api.php)
     so there is exactly one booking code path in the whole app.
   ============================================================ */
require_once __DIR__.'/ncm_api.php';
require_login(); require_page_access();
$PAGE_TITLE='NCM Pending Orders';

/* portable columns this page needs */
try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_sent_at DATETIME NULL"); } catch (Exception $e) {}

/* customer-facing message + tracking URL are settings, not hardcoded —
   NOTE: NCM's official public tracker (portal.nepalcanmove.com/track/) is
   marked "Coming Soon" on their own site as of this build. The link still
   goes out (so nothing breaks once NCM finishes it), but say so plainly in
   the UI rather than pretending it works today. Update the setting the day
   NCM ships it — no code change needed. */
$trackUrl = trim((string)setting('ncm_customer_track_url','')) ?: 'https://portal.nepalcanmove.com/track/';
$msgTpl   = trim((string)setting('ncm_tracking_msg_tpl','')) ?: "Namaste {name}! Your order has been dispatched via NCM.\n📦 NCM Tracking No: {ncm_id}{hub_line}\nTrack here: {link}";

if(!function_exists('wa_phone')){ function wa_phone($p){ $ph=preg_replace('/[^0-9]/','',(string)$p); $ph=ltrim($ph,'0'); if(strpos($ph,'977')!==0)$ph='977'.$ph; return $ph; } }
function sms_phone($p){ $ph=preg_replace('/[^0-9]/','',(string)$p); $ph=ltrim($ph,'0'); if(strpos($ph,'977')!==0)$ph='+977'.$ph; return $ph; }

if (!function_exists('bp_match_branch')) {
  function bp_match_branch($name,$names){
    $t = mb_strtoupper(trim((string)$name)); if ($t==='') return '';
    foreach ($names as $b) if (mb_strtoupper($b)===$t) return $b;
    foreach ($names as $b) if (mb_strpos(mb_strtoupper($b),$t)!==false || mb_strpos($t,mb_strtoupper($b))!==false) return $b;
    return '';
  }
  function bp_guess_branch($addr,$names){
    $A = mb_strtoupper((string)$addr); if ($A==='') return '';
    $best=''; $bestLen=0;
    foreach ($names as $b) { $B=mb_strtoupper($b); if ($B!=='' && mb_strpos($A,$B)!==false && mb_strlen($B)>$bestLen) { $best=$b; $bestLen=mb_strlen($B); } }
    return $best;
  }
  /* branch → phone map, cached 24h — same idea as the Action Center's hub-calling feature,
     kept as its own local copy here so this page never breaks over what state another file is in */
  function bp_branch_phones(){
    $c=kv_get('bp_branch_phones');
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
      if($map) kv_set('bp_branch_phones',$map);
    } catch (Exception $e) {}
    return $map;
  }
}
function tracking_message($tpl,$code,$link,$ncmId,$name,$hubLine=''){
  $greetName = trim((string)$name)!=='' ? trim((string)$name).' ji' : 'there';
  return strtr($tpl,['{code}'=>$code,'{link}'=>$link,'{ncm_id}'=>$ncmId,'{name}'=>$greetName,'{hub_line}'=>$hubLine]);
}
/* "collect from here" line in Nepali-English mix — same tone as the Action Center's hub message */
function hub_collect_line($branch,$phone){
  if ($branch==='') return '';
  $t = "\n🏢 Parcel " . $branch . " branch ma cha";
  if ($phone!=='') $t .= " — yahi bata collect pani garna sakinuhuncha, yo number ma sampark garnus: " . $phone;
  return $t;
}

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
      log_activity('Booked NCM order '.$ncmId.' (Pending Orders page)','NCM');
      flash("Booked ✓ NCM #$ncmId — now shown below, ready to notify the customer.");
    }
    elseif ($act==='send_tracking') {
      $oid=(int)($_POST['id']??0);
      q("UPDATE orders SET tracking_sent_at=NOW() WHERE id=?",[$oid]);
      $oc=(string)val("SELECT code FROM orders WHERE id=?",[$oid]);
      log_activity('Tracking link sent to customer: '.$oc,'NCM');
      flash('📮 Marked tracking as sent for '.$oc.'.');
    }
  } catch (Exception $ex) { flash('Error: '.$ex->getMessage()); }
  header('Location: ncm_pending.php'); exit;
}

require __DIR__.'/includes/header.php';

/* branches for the booking modal + hub phone numbers for the tracking message */
$connected=false; $branchNames=[]; $branchPhones=[];
if (ncm()->configured()) { try { $bs=ncm()->branches(); $connected=is_array($bs); foreach($bs as $b) if(!empty($b['name'])) $branchNames[]=$b['name']; sort($branchNames); $branchPhones=bp_branch_phones(); } catch (Exception $e) {} }

/* ---- Section 1: Awaiting Dispatch — unbooked, active, NCM-bound (or not yet assigned any courier) ---- */
$awaiting = rows("SELECT o.*, p.name AS product_name FROM orders o
  LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id
  WHERE (c.name LIKE '%NCM%' OR o.courier_id IS NULL)
    AND COALESCE(o.ncm_order_id,'')=''
    AND o.status NOT IN ('delivered','cancelled','returned')
  ORDER BY o.order_date, o.id");

/* ---- Section 2: Booked & still active — THIS is what "stays on the page" means.
   No status filtering beyond "not finalized" — booking here never removes an order
   from view, it only adds the Send Tracking action. ---- */
$booked = rows("SELECT o.*, p.name AS product_name FROM orders o
  LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id
  WHERE c.name LIKE '%NCM%' AND COALESCE(o.ncm_order_id,'')<>''
    AND o.status NOT IN ('delivered','cancelled','returned')
  ORDER BY (o.tracking_sent_at IS NOT NULL), o.id DESC");
?>
<style>
.np-hero{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:linear-gradient(120deg,#1d4ed8,#4f46e5);border-radius:18px;padding:20px 24px;color:#fff;margin-bottom:16px}
.np-hero h1{font-size:21px;font-weight:900;margin:0}
.np-hero p{opacity:.85;font-size:12.5px;margin:2px 0 0}
.np-warn{background:#fff8e8;border:1.5px solid #f0d9ad;border-radius:12px;padding:10px 16px;font-size:12.3px;margin-bottom:14px}
.np-row{display:flex;gap:10px;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border)}
.np-row:last-child{border-bottom:0}
.np-av{width:34px;height:34px;border-radius:99px;background:var(--blue-bg);color:var(--blue);display:flex;align-items:center;justify-content:center;font-weight:900;flex:none}
.np-nm{font-weight:800;font-size:13px}
.np-sub{font-size:11px;color:var(--muted)}
.np-act{display:flex;gap:6px;margin-left:auto;flex-wrap:wrap}
.np-sent{font-size:10.5px;color:var(--green);font-weight:700;background:var(--green-bg);border-radius:99px;padding:2px 9px;white-space:nowrap}
@media(max-width:760px){ .np-row{flex-wrap:wrap} .np-act{width:100%;margin-left:0} .np-act .btn{flex:1;text-align:center} }
@media(max-width:640px){ .np-hero{padding:16px 18px} .np-hero h1{font-size:18px} }
</style>

<div class="np-hero">
  <span style="font-size:30px">📮</span>
  <div style="flex:1;min-width:220px">
    <h1>NCM Pending Orders</h1>
    <p>Book on NCM, then send the tracking link — nothing here touches the Action Center's follow-up queue.</p>
  </div>
  <a class="btn" style="background:rgba(255,255,255,.16);color:#fff" href="ncm.php">📮 NCM Courier</a>
  <a class="btn" style="background:rgba(255,255,255,.16);color:#fff" href="ncm_action.php">🚨 Action Center</a>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="np-warn">ℹ️ NCM's own public tracking page (<?= e($trackUrl) ?>) currently shows <b>"Coming Soon"</b> on their site — the link below is wired up and will work the moment NCM turns it on. If they publish a different tracking URL format, update it in <a href="settings.php"><b>Settings</b></a> (or tell me and I'll do it) — no need to touch this page again.</div>

<div class="panel">
  <div class="panel-head"><h2>🚚 Awaiting Dispatch <span class="pill p-amber" style="font-size:11px"><?= count($awaiting) ?></span></h2></div>
  <?php if(!$awaiting): ?>
    <div class="empty" style="padding:18px">🎉 Nothing waiting — every NCM order has been booked.</div>
  <?php else: foreach($awaiting as $o): ?>
    <div class="np-row">
      <span class="np-av"><?= e(strtoupper(mb_substr(trim((string)$o['customer'])?:'?',0,1))) ?></span>
      <div>
        <div class="np-nm"><?= e($o['code']) ?> · <?= e($o['customer']?:'—') ?></div>
        <div class="np-sub"><?= e($o['product_name']?:'Goods') ?> ×<?= (int)$o['qty'] ?> · <?= e(mb_strimwidth((string)$o['address'],0,44,'…')) ?></div>
      </div>
      <div class="np-act">
        <?php if($connected): ?>
        <button class="btn btn-sm btn-primary" onclick='bookFor(<?= json_encode(["id"=>$o["id"],"name"=>$o["customer"],"phone"=>$o["phone"],"address"=>$o["address"],"cod"=>(strtolower((string)$o["payment_type"])==="cod"?(float)$o["sell_price"]*(int)$o["qty"]:0),"ref"=>$o["code"],"package"=>trim((($o["product_name"]??"")?:"Goods")." x".(int)$o["qty"])], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)">🚚 Book on NCM</button>
        <?php else: ?><span class="muted" style="font-size:11.5px">connect NCM in Settings to book</span><?php endif; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<div class="panel" style="margin-top:18px">
  <div class="panel-head"><h2>✅ Booked — Send Tracking <span class="pill p-blue" style="font-size:11px"><?= count($booked) ?></span></h2></div>
  <?php if(!$booked): ?>
    <div class="empty" style="padding:18px">No booked orders yet — book something above first.</div>
  <?php else: foreach($booked as $o):
    $branch = $o['ncm_status'] ? bp_match_branch($o['ncm_status'], $branchNames) : '';
    if ($branch==='') $branch = bp_guess_branch($o['address'], $branchNames);
    $bphone = $branch!=='' ? ($branchPhones[mb_strtoupper($branch)] ?? '') : '';
    $link = $trackUrl; $msg = tracking_message($msgTpl,$o['code'],$link,$o['ncm_order_id'],$o['customer'],hub_collect_line($branch,$bphone));
    $sent = !empty($o['tracking_sent_at']);
  ?>
    <div class="np-row">
      <span class="np-av"><?= e(strtoupper(mb_substr(trim((string)$o['customer'])?:'?',0,1))) ?></span>
      <div>
        <div class="np-nm"><?= e($o['code']) ?> · <?= e($o['customer']?:'—') ?>
          <a href="<?= e(ncm_portal_url($o['ncm_order_id'])) ?>" target="_blank" rel="noopener" class="pill p-blue" style="font-size:10px;text-decoration:none">NCM <?= e($o['ncm_order_id']) ?> ↗</a>
          <?php if($sent): ?><span class="np-sent">✓ sent <?= e(date('d M, H:i',strtotime($o['tracking_sent_at']))) ?></span><?php endif; ?>
        </div>
        <div class="np-sub"><?= e($o['phone']?:'—') ?> · <span class="pill <?= ncm_status_class($o['ncm_status']??'') ?>" style="font-size:9.5px"><?= e($o['ncm_status']?:'Booked') ?></span></div>
      </div>
      <div class="np-act">
        <?php if($o['phone']): ?>
        <a class="btn btn-sm" href="tel:<?= e($o['phone']) ?>" title="Call customer">📞 Call</a>
        <a class="btn btn-sm np-send" style="background:#25d366;color:#fff" target="_blank" rel="noopener"
           data-oid="<?= (int)$o['id'] ?>" data-csrf="<?= csrf() ?>"
           href="https://wa.me/<?= e(wa_phone($o['phone'])) ?>?text=<?= rawurlencode($msg) ?>" title="Send tracking link via WhatsApp — marks as sent">📮 Send Tracking</a>
        <a class="btn btn-sm" href="https://wa.me/<?= e(wa_phone($o['phone'])) ?>" target="_blank" rel="noopener" title="Open WhatsApp chat (no message)">💬 WhatsApp</a>
        <a class="btn btn-sm" href="sms:<?= e(sms_phone($o['phone'])) ?>?body=<?= rawurlencode($msg) ?>" title="No WhatsApp on this number? Send SMS with the same message">📩 SMS</a>
        <?php else: ?><span class="muted" style="font-size:11px">no phone on file</span><?php endif; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>
  <div class="legend" style="padding:10px 18px 14px;font-size:11px;color:var(--muted)">📮 Send Tracking opens WhatsApp with the message ready and marks it sent · use 📩 SMS if the customer's number doesn't have WhatsApp — there's no automatic way to detect that yet (needs NCM's Meta WhatsApp Business API connected — see the 💬 WhatsApp Center design when that's ready).</div>
</div>

<datalist id="brlist"><?php foreach($branchNames as $bn) echo '<option value="'.e($bn).'">'; ?></datalist>

<!-- booking modal (same fields/flow as the main NCM page) -->
<div class="modal-bg" id="bookModal"><form class="modal" method="post">
  <div class="modal-head"><span>Book Order on NCM</span><span class="mx" onclick="closeBook()">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="book"><input type="hidden" name="order_id" id="bk_order_id" value="">
  <div class="modal-body">
    <div><label>Customer Name</label><input name="name" id="bk_name" required></div>
    <div><label>Phone</label><input name="phone" id="bk_phone" required></div>
    <div><label>COD Amount (Rs.)</label><input name="cod_charge" id="bk_cod" type="number" step="any"></div>
    <div class="full"><label>Delivery Address</label><input name="address" id="bk_address"></div>
    <div><label>From Branch (pickup)</label><input name="fbranch" list="brlist" value="<?= e(setting('ncm_from_branch','TINKUNE')) ?>"></div>
    <div><label>To Branch (destination)</label><input name="branch" id="bk_branch" list="brlist" required></div>
    <div><label>Package / Contents</label><input name="package" id="bk_pkg"></div>
    <div><label>Your Ref (order code)</label><input name="vref_id" id="bk_ref"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="closeBook()">Cancel</button><button class="btn btn-primary">Book on NCM</button></div>
</form></div>

<script>
var BRANCHES=<?= json_encode($branchNames) ?>;
function bestBranch(addr){ addr=(''+(addr||'')).toLowerCase(); if(!addr) return '';
  for(var i=0;i<BRANCHES.length;i++){ var b=BRANCHES[i]; if(b && addr.indexOf((''+b).toLowerCase())>-1) return b; } return ''; }
function bookFor(o){
  document.getElementById('bk_order_id').value=o.id;
  document.getElementById('bk_name').value=o.name||'';
  document.getElementById('bk_phone').value=o.phone||'';
  document.getElementById('bk_address').value=o.address||'';
  document.getElementById('bk_cod').value=o.cod||0;
  document.getElementById('bk_ref').value=o.ref||'';
  document.getElementById('bk_pkg').value=o.package||'';
  document.getElementById('bk_branch').value=bestBranch(o.address);
  document.getElementById('bookModal').classList.add('open');
}
function closeBook(){ document.getElementById('bookModal').classList.remove('open'); }
document.getElementById('bookModal').addEventListener('click',function(e){ if(e.target===this) closeBook(); });

/* clicking Send Tracking opens WhatsApp (the href does that natively) AND marks it sent in the background */
document.querySelectorAll('.np-send').forEach(function(a){
  a.addEventListener('click', function(){
    var body='csrf='+encodeURIComponent(a.getAttribute('data-csrf'))+'&_action=send_tracking&id='+encodeURIComponent(a.getAttribute('data-oid'));
    fetch('ncm_pending.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body:body})
      .then(function(){ setTimeout(function(){ location.reload(); }, 600); });
  });
});
</script>
<?php require __DIR__.'/includes/footer.php'; ?>