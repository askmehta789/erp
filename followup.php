<?php
require_once __DIR__.'/functions.php'; require_once __DIR__.'/ncm_api.php';
require_login(); require_page_access();
$PAGE_TITLE='Follow-up';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;

ensure_followup(); ensure_order_group(); ncm_ensure_cols();

/* ============ RISK ENGINE — computed live, never stored ============ */
function fu_norm10($p){ $d=preg_replace('/\D/','',(string)$p); $d=preg_replace('/^977/','',$d); $d=ltrim($d,'0'); return strlen($d)>10?substr($d,-10):$d; }

/* per-phone history across ALL orders */
function fu_phone_stats(){
  $st=[];
  foreach(rows("SELECT phone,status FROM orders") as $o){
    $k=fu_norm10($o['phone']); if(strlen($k)<7) continue;
    if(!isset($st[$k])) $st[$k]=['orders'=>0,'delivered'=>0,'returned'=>0];
    $st[$k]['orders']++;
    if($o['status']==='delivered') $st[$k]['delivered']++;
    elseif(in_array($o['status'],['returned','cancelled'],true)) $st[$k]['returned']++;
  }
  return $st;
}

/* risk for one parcel (head order + summed COD + items) -> [score, level, reasons[]] */
function fu_risk($head,$codTotal,$stats,$dupKeys){
  $score=0; $why=[];
  $k=fu_norm10($head['phone']);
  $h=$stats[$k] ?? null;
  $past=$h ? ($h['orders'] - ( in_array($head['status'],['pending','processing'],true) ? 1 : 0)) : 0;

  if($h && $h['returned']>0){ $p=min(50,$h['returned']*25); $score+=$p; $why[]=$h['returned'].' previous return'.($h['returned']>1?'s':''); }
  if($h && $h['orders']>=2 && $h['returned']/$h['orders']>=0.5){ $score+=20; $why[]='high return rate'; }
  if($past<=0){ $score+=12; $why[]='new customer'; }
  if(strlen($k)!==10 || ($k[0]??'')!=='9'){ $score+=30; $why[]='invalid phone'; }
  $addr=trim((string)$head['address']);
  if($addr==='' ){ $score+=15; $why[]='no address'; }
  elseif(str_word_count(preg_replace('/[^a-zA-Z ]/',' ',$addr))<3 && mb_strlen($addr)<15){ $score+=15; $why[]='weak address'; }
  if(trim((string)$head['customer'])===''){ $score+=15; $why[]='no name'; }
  if(isset($dupKeys[$k.'|'.(int)$head['product_id']]) && $dupKeys[$k.'|'.(int)$head['product_id']]>1){ $score+=20; $why[]='possible duplicate'; }
  $hv=(float)setting('risk_high_value','2000');
  if($codTotal>=$hv){ $score+=10; $why[]='high value'; }
  if(strtolower((string)$head['payment_type'])==='cod' && strtolower((string)$head['zone'])==='outside'){ $score+=8; $why[]='COD outside valley'; }
  if($h && $h['delivered']>0){ $t=min(40,$h['delivered']*10); $score-=$t; if($h['delivered']>=3) $why[]=$h['delivered'].' deliveries ✓ trusted'; }

  $score=max(0,min(100,$score));
  $level=$score<25?'low':($score<55?'med':'high');
  return [$score,$level,$why];
}

/* ============ POST: call outcomes & status changes (group-aware) ============ */
$STATUS_SET=['pending','contacted','not_reachable','confirmed','addr_change','date_change','cancelled_cust','ready'];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act=$_POST['_action'] ?? '';
  try {
    if($act==='outcome'){
      $oid=(int)($_POST['order_id'] ?? 0);
      $res=(string)($_POST['result'] ?? '');
      $note=trim((string)($_POST['note'] ?? ''));
      $map=[ 'no_answer'=>'not_reachable', 'contacted'=>'contacted', 'confirmed'=>'confirmed',
             'callback'=>'not_reachable', 'addr_change'=>'addr_change', 'date_change'=>'date_change',
             'cancel'=>'cancelled_cust', 'ready'=>'ready', 'note'=>null, 'reopen'=>'pending' ];
      if($oid && array_key_exists($res,$map)){
        $o=row("SELECT * FROM orders WHERE id=?",[$oid]);
        if($o){
          /* log the attempt */
          q("INSERT INTO order_calls(order_id,called_at,called_by,caller_name,result,note) VALUES(?,?,?,?,?,?)",
            [$oid,date('Y-m-d H:i:s'),(int)($u['id']??0),(string)($u['name']??''),$res,$note?:null]);
          /* address change: update the address on the whole group */
          $newAddr=trim((string)($_POST['new_address'] ?? ''));
          $cs=$map[$res];
          $grp=trim((string)($o['order_group'] ?? ''));
          $ids = $grp!=='' ? array_column(rows("SELECT id FROM orders WHERE order_group=?",[$grp]),'id') : [$oid];
          foreach($ids as $gid){
            if($cs!==null) q("UPDATE orders SET confirm_status=?, confirm_by=?, confirm_at=? WHERE id=?",
              [$cs,(int)($u['id']??0),date('Y-m-d H:i:s'),$gid]);
            if($res==='addr_change' && $newAddr!=='') q("UPDATE orders SET address=? WHERE id=?",[$newAddr,$gid]);
            if($res==='cancel') q("UPDATE orders SET status='cancelled' WHERE id=? AND status IN ('pending','processing')",[$gid]);
          }
          $lbl=['no_answer'=>'No answer','contacted'=>'Contacted','confirmed'=>'✅ Confirmed','callback'=>'Callback requested',
                'addr_change'=>'Address changed','date_change'=>'Date change','cancel'=>'Cancelled by customer','ready'=>'Ready to ship',
                'note'=>'📝 Note added','reopen'=>'↩ Reopened for confirmation'][$res];
          flash($lbl.' — '.e($o['customer']?:$o['code']).($note!==''?' · noted':''));
          log_activity('Follow-up: '.$lbl.' on '.$o['code'],'Followup');
        }
      }
      header('Location: followup.php'); exit;
    }
    if($act==='gate' && $isAdmin){
      set_setting('followup_gate', setting('followup_gate','1')==='1' ? '0' : '1');
      flash('Booking gate '.(setting('followup_gate','1')==='1'?'ON — orders must be confirmed before NCM booking':'OFF — booking open for all orders'));
      header('Location: followup.php'); exit;
    }
    if($act==='autolow' && $isAdmin){
      set_setting('followup_auto_low', setting('followup_auto_low','1')==='1' ? '0' : '1');
      flash('Low-risk fast lane '.(setting('followup_auto_low','1')==='1'?'ON':'OFF'));
      header('Location: followup.php'); exit;
    }
  } catch (Exception $e) { flash('Error: '.$e->getMessage()); header('Location: followup.php'); exit; }
}

/* ============ LOAD: active unbooked orders, collapsed to parcels ============ */
$stats = fu_phone_stats();

$active = rows("SELECT o.*, p.name AS product_name, c.name AS courier_name
                FROM orders o
                LEFT JOIN products p ON p.id=o.product_id
                LEFT JOIN couriers c ON c.id=o.courier_id
                WHERE o.status IN ('pending','processing')
                  AND COALESCE(o.ncm_order_id,'')=''
                ORDER BY o.id ASC");

/* duplicate index: same phone+product among active */
$dupKeys=[];
foreach($active as $o){ $k=fu_norm10($o['phone']).'|'.(int)$o['product_id']; $dupKeys[$k]=($dupKeys[$k]??0)+1; }

/* collapse groups into parcels: head = lowest id */
$parcels=[]; $seen=[];
foreach($active as $o){
  $g=trim((string)($o['order_group'] ?? ''));
  if($g==='' ){ $parcels[]=['head'=>$o,'items'=>[$o],'cod'=>(strtolower((string)$o['payment_type'])==='cod'?(float)$o['sell_price']*(int)$o['qty']:0)]; continue; }
  if(isset($seen[$g])){
    $i=$seen[$g];
    $parcels[$i]['items'][]=$o;
    if(strtolower((string)$o['payment_type'])==='cod') $parcels[$i]['cod']+=(float)$o['sell_price']*(int)$o['qty'];
    continue;
  }
  $parcels[]=['head'=>$o,'items'=>[$o],'cod'=>(strtolower((string)$o['payment_type'])==='cod'?(float)$o['sell_price']*(int)$o['qty']:0)];
  $seen[$g]=count($parcels)-1;
}

/* risk + auto fast-lane for low risk */
$autoLow = setting('followup_auto_low','1')==='1';
foreach($parcels as &$P){
  [$sc,$lv,$why]=fu_risk($P['head'],$P['cod'],$stats,$dupKeys);
  $P['score']=$sc; $P['level']=$lv; $P['why']=$why;
  $kph=fu_norm10($P['head']['phone']);
  $trusted = (($stats[$kph]['delivered'] ?? 0) >= 1);
  if($autoLow && $lv==='low' && $trusted && ($P['head']['confirm_status']??'pending')==='pending'){
    $g=trim((string)($P['head']['order_group'] ?? ''));
    $ids=$g!==''?array_column(rows("SELECT id FROM orders WHERE order_group=?",[$g]),'id'):[$P['head']['id']];
    foreach($ids as $gid) q("UPDATE orders SET confirm_status='ready', confirm_at=? WHERE id=?",[date('Y-m-d H:i:s'),$gid]);
    $P['head']['confirm_status']='ready'; $P['auto']=true;
  }
}
unset($P);

/* full call/notes history per head order (for the history modal) */
$callHist=[];
if($parcels){
  $hids=implode(',',array_map(fn($P)=>(int)$P['head']['id'],$parcels));
  foreach(rows("SELECT order_id,called_at,caller_name,result,note FROM order_calls WHERE order_id IN ($hids) ORDER BY id") as $r){
    $callHist[(int)$r['order_id']][]=[
      't'=>date('d M H:i',strtotime($r['called_at'])),
      'by'=>(string)$r['caller_name'],
      'r'=>(string)$r['result'],
      'n'=>(string)($r['note']??'')];
  }
}
/* call counts + last call per head order */
$callCount=[]; $lastCall=[];
if($parcels){
  $ids=implode(',',array_map(fn($P)=>(int)$P['head']['id'],$parcels));
  foreach(rows("SELECT order_id, COUNT(*) c, MAX(called_at) m FROM order_calls WHERE order_id IN ($ids) GROUP BY order_id") as $r){
    $callCount[(int)$r['order_id']]=(int)$r['c']; $lastCall[(int)$r['order_id']]=$r['m'];
  }
}

/* sort: risk desc, oldest first */
usort($parcels,function($a,$b){
  if($b['score']!==$a['score']) return $b['score']-$a['score'];
  return strcmp($a['head']['order_date'],$b['head']['order_date']);
});

/* ============ TILES ============ */
$today=date('Y-m-d');
$tAwait=0;$tNotAns=0;$tReady=0;
foreach($parcels as $P){
  $cs=$P['head']['confirm_status']??'pending';
  if(in_array($cs,['pending','contacted','not_reachable'],true)) $tAwait++;
  if($cs==='not_reachable') $tNotAns++;
  if(in_array($cs,['confirmed','ready'],true)) $tReady++;
}
$tConfToday=(int)val("SELECT COUNT(DISTINCT COALESCE(NULLIF(order_group,''),CAST(id AS CHAR)))
                      FROM orders WHERE confirm_status IN ('confirmed','ready') AND DATE(confirm_at)=?",[$today]);
$mStart=date('Y-m-01');
$mDel=(int)val("SELECT COUNT(*) FROM orders WHERE status='delivered' AND order_date>=?",[$mStart]);
$mRet=(int)val("SELECT COUNT(*) FROM orders WHERE status IN ('returned','cancelled') AND order_date>=?",[$mStart]);
$retRate=($mDel+$mRet)>0 ? round($mRet/($mDel+$mRet)*100,1) : 0;

$gateOn = setting('followup_gate','1')==='1';
$waTpl = setting('wa_confirm_tpl','Namaste {name}! 🙏 Your order {code} ({items}) — total Rs.{cod} — is ready to ship via NCM courier. Please reply YES to confirm, or call us for any change. — Luprah Trading');

$csPill=function($cs){
  $m=['pending'=>['⏳ Pending','p-wait'],'contacted'=>['📞 Contacted','p-blue'],'not_reachable'=>['📵 Not reachable','p-no'],
      'confirmed'=>['✅ Confirmed','p-ok'],'addr_change'=>['🏠 Addr changed','p-blue'],'date_change'=>['📅 Date change','p-blue'],
      'cancelled_cust'=>['❌ Cancelled','p-no'],'ready'=>['🚀 Ready','p-ok']];
  [$t,$c]=$m[$cs]??['⏳ Pending','p-wait'];
  return '<span class="pill '.$c.'">'.$t.'</span>';
};

require __DIR__.'/includes/header.php';
?>
<div class="page-head">
  <div><h1>📞 Follow-up</h1><p>Confirm customers before NCM booking · highest risk first · every call logged</p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <?php if($isAdmin): ?>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="gate">
      <button class="btn btn-sm" title="When ON, unconfirmed orders cannot be booked on the NCM page"><?= $gateOn?'🚧 Gate: ON':'🟢 Gate: OFF' ?></button></form>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="autolow">
      <button class="btn btn-sm" title="Low-risk trusted customers skip the queue automatically"><?= $autoLow?'⚡ Fast lane: ON':'🐢 Fast lane: OFF' ?></button></form>
    <?php endif; ?>
    <a class="btn" href="ncm.php">📮 NCM page</a>
  </div>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="fu-tiles">
  <div class="fu-tile"><b><?= $tAwait ?></b><span>Awaiting confirmation</span></div>
  <div class="fu-tile"><b style="color:var(--green)"><?= $tConfToday ?></b><span>Confirmed today</span></div>
  <div class="fu-tile"><b style="color:var(--red)"><?= $tNotAns ?></b><span>Not answering</span></div>
  <div class="fu-tile"><b style="color:var(--green)"><?= $tReady ?></b><span>Ready to book</span></div>
  <div class="fu-tile"><b><?= $retRate ?>%</b><span>Return rate (month)</span></div>
</div>

<div class="panel">
  <div class="panel-head"><h2>Worklist — call top to bottom</h2><span class="muted" style="font-size:12px"><?= count($parcels) ?> parcel<?= count($parcels)==1?'':'s' ?> · unbooked pending orders</span></div>
  <div style="overflow-x:auto">
  <table class="tbl fu-tbl">
    <thead><tr><th>Risk</th><th>Order</th><th>Customer</th><th>Why</th><th>Calls</th><th>Status</th><th style="min-width:330px">Actions</th></tr></thead>
    <tbody>
    <?php if(!$parcels): ?>
      <tr><td colspan="7" class="muted" style="text-align:center;padding:26px">🎉 Nothing to confirm — all pending orders are booked or the queue is clear.</td></tr>
    <?php endif; ?>
    <?php foreach($parcels as $P): $o=$P['head']; $oid=(int)$o['id']; $cs=$o['confirm_status']??'pending';
      $itemsTxt=implode(', ',array_map(fn($it)=>($it['product_name']?:'Goods').' ×'.(int)$it['qty'],$P['items']));
      $wa='';
      $ph=fu_norm10($o['phone']);
      if(strlen($ph)===10){
        $msg=strtr($waTpl,['{name}'=>(string)$o['customer'],'{code}'=>(string)$o['code'],'{items}'=>$itemsTxt,'{cod}'=>number_format($P['cod'])]);
        $wa='https://wa.me/977'.$ph.'?text='.rawurlencode($msg);
      }
      $lvCls=$P['level']==='low'?'rl':($P['level']==='med'?'rm':'rh');
      $lvIco=$P['level']==='low'?'🟢':($P['level']==='med'?'🟡':'🔴');
    ?>
    <tr class="<?= $P['level']==='high'?'fu-hi':'' ?>">
      <td><span class="risk <?= $lvCls ?>" title="<?= e(implode(' · ',$P['why'])?:'no risk signals') ?>"><?= $lvIco ?> <?= $P['score'] ?></span></td>
      <td><b class="mono"><?= e($o['code']) ?></b>
          <?php if(count($P['items'])>1): ?><span class="pill p-blue" style="font-size:9px">📦 <?= count($P['items']) ?></span><?php endif; ?>
          <div class="muted" style="font-size:11px">Rs. <?= number_format($P['cod']) ?> · <?= e($o['order_date']) ?></div></td>
      <td><b><?= e($o['customer']?:'—') ?></b><div class="muted" style="font-size:11px"><?= e($o['phone']) ?> · <?= e(mb_strimwidth((string)$o['address'],0,30,'…')) ?></div>
          <div class="muted" style="font-size:11px;color:var(--blue)"><?= e(mb_strimwidth($itemsTxt,0,44,'…')) ?></div></td>
      <td class="muted" style="font-size:11.5px;max-width:150px"><?= e(implode(' · ',array_slice($P['why'],0,3))?:'—') ?></td>
      <td><?php $cc=$callCount[$oid]??0; ?>
        <a href="#" onclick="fuHist(<?= $oid ?>,<?= e(json_encode((string)($o['customer']?:$o['code']),JSON_HEX_APOS|JSON_HEX_QUOT)) ?>);return false" title="View call history & notes" style="font-weight:800"><?= $cc?:'—' ?> 🗒</a>
        <?php if($cc && isset($lastCall[$oid])) echo '<div class="muted" style="font-size:10.5px">'.e(date('d M H:i',strtotime($lastCall[$oid]))).'</div>'; ?></td>
      <td><?= $csPill($cs) ?><?php if(!empty($P['auto'])): ?><div class="muted" style="font-size:10px">auto · trusted</div><?php endif; ?></td>
      <td>
        <?php if(in_array($cs,['confirmed','ready'],true)): ?>
          <a class="btn btn-sm btn-primary" href="ncm.php?book=<?= $oid ?>">📦 Book NCM</a>
          <button class="btn btn-sm" onclick="fuNote(<?= $oid ?>,'note','Remark about this customer / order:')" title="Add a note">📝</button>
          <?php if($wa): ?><a class="btn btn-sm fu-wa" target="_blank" rel="noopener" href="<?= e($wa) ?>">📱</a><?php endif; ?>
          <button class="btn btn-sm" onclick="fuOut(<?= $oid ?>,'reopen',false)" title="Pull back to Pending — needs a call after all">↩</button>
          <button class="btn btn-sm" onclick="fuOut(<?= $oid ?>,'cancel',true)" title="Customer cancelled">❌</button>
        <?php elseif($cs==='cancelled_cust'): ?>
          <span class="muted" style="font-size:12px">cancelled by customer</span>
          <button class="btn btn-sm" onclick="fuNote(<?= $oid ?>,'note','Remark:')">📝</button>
        <?php else: ?>
          <button class="btn btn-sm btn-primary" onclick="fuOut(<?= $oid ?>,'confirmed',false)">✅ Confirmed</button>
          <button class="btn btn-sm" onclick="fuOut(<?= $oid ?>,'no_answer',false)">📵</button>
          <button class="btn btn-sm" onclick="fuNote(<?= $oid ?>,'callback','Callback note (e.g. after 5pm)?')">📅</button>
          <button class="btn btn-sm" onclick="fuAddr(<?= $oid ?>,<?= e(json_encode((string)$o['address'],JSON_HEX_APOS|JSON_HEX_QUOT)) ?>)">🏠</button>
          <button class="btn btn-sm" onclick="fuOut(<?= $oid ?>,'cancel',true)">❌</button>
          <button class="btn btn-sm" onclick="fuNote(<?= $oid ?>,'note','Remark about this customer / order:')" title="Add a note">📝</button>
          <?php if($wa): ?><a class="btn btn-sm fu-wa" target="_blank" rel="noopener" href="<?= e($wa) ?>" title="Send WhatsApp confirmation">📱</a><?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div id="fuHistBg" style="display:none;position:fixed;inset:0;background:rgba(20,26,48,.45);z-index:200;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:#fff;border-radius:16px;max-width:480px;width:92%;max-height:70vh;overflow:auto;padding:18px 20px;box-shadow:0 24px 70px rgba(20,26,48,.35)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <b id="fuHistTitle" style="font-size:15px">Call history</b>
      <button class="btn btn-sm" onclick="document.getElementById('fuHistBg').style.display='none'">✕</button>
    </div>
    <div id="fuHistBody"></div>
  </div>
</div>
<script>var FU_HIST=<?= json_encode($callHist) ?>;</script>
<form method="post" id="fuForm" style="display:none">
  <input type="hidden" name="csrf" value="<?= csrf() ?>">
  <input type="hidden" name="_action" value="outcome">
  <input type="hidden" name="order_id" id="fu_oid">
  <input type="hidden" name="result" id="fu_res">
  <input type="hidden" name="note" id="fu_note">
  <input type="hidden" name="new_address" id="fu_addr">
</form>

<script>
function fuSend(oid,res,note,addr){
  document.getElementById('fu_oid').value=oid;
  document.getElementById('fu_res').value=res;
  document.getElementById('fu_note').value=note||'';
  document.getElementById('fu_addr').value=addr||'';
  document.getElementById('fuForm').submit();
}
function fuOut(oid,res,ask){
  if(ask && !confirm(res==='cancel'?'Mark as CANCELLED by customer? The order will be cancelled.':'Are you sure?')) return;
  fuSend(oid,res,'','');
}
function fuNote(oid,res,q){
  var n=prompt(q||'Note:'); if(n===null) return;
  fuSend(oid,res,n,'');
}
function fuHist(oid,name){
  var rows=FU_HIST[oid]||[];
  var lbl={no_answer:'📵 No answer',contacted:'📞 Contacted',confirmed:'✅ Confirmed',callback:'📅 Callback',
           addr_change:'🏠 Address change',date_change:'📅 Date change',cancel:'❌ Cancelled',ready:'🚀 Ready',
           note:'📝 Note',reopen:'↩ Reopened'};
  var h='';
  if(!rows.length) h='<div class="muted" style="padding:14px 4px">No calls or notes yet. Use 📝 to add the first remark.</div>';
  rows.forEach(function(r,i){
    h+='<div style="display:flex;gap:9px;align-items:flex-start;padding:8px 0;border-bottom:1px dashed #eef1f8">'
      +'<span style="background:#1f2740;color:#fff;border-radius:99px;min-width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900">'+(i+1)+'</span>'
      +'<div><b>'+(lbl[r.r]||r.r)+'</b> <span class="muted" style="font-size:11px">· '+r.t+(r.by?' · by '+r.by:'')+'</span>'
      +(r.n?'<div class="muted" style="font-size:12px">'+r.n.replace(/</g,'&lt;')+'</div>':'')+'</div></div>';
  });
  document.getElementById('fuHistTitle').textContent='🗒 '+name+' — calls & notes';
  document.getElementById('fuHistBody').innerHTML=h;
  document.getElementById('fuHistBg').style.display='flex';
}
function fuAddr(oid,cur){
  var a=prompt('New / corrected address:',cur||''); if(a===null) return;
  fuSend(oid,'addr_change','address updated',a);
}
</script>
<?php require __DIR__.'/includes/footer.php'; ?>