<?php
require_once __DIR__.'/functions.php'; require_once __DIR__.'/ncm_api.php';
require_login(); require_page_access();
if (!can_see('ncm.php')) { http_response_code(403); die('Access denied for your role.'); }
if(!function_exists('wa_phone')){ function wa_phone($p){ $ph=preg_replace('/[^0-9]/','',(string)$p); $ph=ltrim($ph,'0'); if(strpos($ph,'977')!==0)$ph='977'.$ph; return $ph; } }
$PAGE_TITLE='NCM Comments';
$connected = ncm()->configured();   /* ask the API client itself — single source of truth */

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf(); $act=$_POST['_action']??''; $nid=(int)($_POST['nid']??0);
  try {
    if ($act==='send') {
      $txt=trim((string)($_POST['comment']??''));
      if(!$nid || $txt==='') throw new Exception('Write a message first.');
      ncm()->addComment($nid,$txt);
      log_activity("NCM comment sent on #$nid",'NCM');
      flash('Sent ✓');
      header('Location: ncm_comments.php?c='.$nid); exit;
    }
    if ($act==='scan') {
      if(function_exists('set_time_limit')) @set_time_limit(180);
      [$cnt,$scn] = ncm_scan_today_comments(120);
      flash("Scanned $scn active orders — $cnt comments today.");
      header('Location: ncm_comments.php'); exit;
    }
  } catch (Exception $e) { flash('⚠ '.$e->getMessage()); header('Location: ncm_comments.php'.($nid?'?c='.$nid:'')); exit; }
}

/* ---------- data: scan cache + local orders ---------- */
$__kv = kv_get('ncm_today_comments');
$scan = ($__kv['v'] ?? null) ?: ['date'=>'','items'=>[],'scanned'=>0];
$view = ($_GET['v'] ?? '') === 'feed' ? 'feed' : 'inbox';
$cId  = $_GET['c'] ?? '';

$locByNid=[];
foreach(rows("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id WHERE COALESCE(o.ncm_order_id,'')<>'' AND o.status NOT IN ('cancelled') ORDER BY o.id DESC LIMIT 400") as $o)
  $locByNid[(string)$o['ncm_order_id']]=$o;

$convs=[];
foreach (($scan['items']??[]) as $c) {
  $oid=(string)($c['orderid']??''); if($oid==='')continue;
  if(!isset($convs[$oid])) {
    $by=strtolower((string)($c['addedBy']??''));
    $mine = strpos($by,'vendor')!==false;
    $noresp = function_exists('ncm_no_response') ? ncm_no_response((string)($c['comments']??'')) : false;
    $convs[$oid]=[ 'nid'=>$oid,
      'last'=>(string)($c['comments']??''), 'lastBy'=>(string)($c['addedBy']??'NCM'),
      'lastAt'=>(string)($c['added_time']??''),
      'state'=> $mine?'answered':($noresp?'noresp':'reply'),
      'count'=>0, 'o'=>$locByNid[$oid]??null ];
  }
  $convs[$oid]['count']++;
}
$actives=[];
foreach($locByNid as $nid2=>$o){
  if(isset($convs[$nid2])) continue;
  if(strtolower((string)$o['status'])==='delivered') continue;
  $actives[]=['nid'=>$nid2,'o'=>$o];
}
$kReply=0;$kNoresp=0;$kDone=0;
foreach($convs as $cv){ if($cv['state']==='answered')$kDone++; elseif($cv['state']==='noresp'){$kNoresp++;$kReply++;} else $kReply++; }

/* ---------- thread ---------- */
$thread=null;$threadErr='';$local=null;$ncmStatus='';$hist=[];
if($view==='inbox' && $cId!==''){
  $local=$locByNid[(string)$cId] ?? row("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id WHERE o.ncm_order_id=? LIMIT 1",[$cId]);
  try{$thread=ncm()->comments((int)$cId);}catch(Exception $e){$threadErr=$e->getMessage();}
  try{$d=ncm()->order((int)$cId); $ncmStatus=(string)($d['last_delivery_status']??($d['status']??''));}catch(Exception $e){}
  try{$hh=ncm()->statusHistory((int)$cId); if(is_array($hh)) $hist=array_slice(array_reverse($hh),0,6);}catch(Exception $e){}
}
$custHist=null;
if($local && trim((string)$local['phone'])!==''){
  $ph=$local['phone'];
  $custHist=[
    'total'=>(int)val("SELECT COUNT(*) FROM orders WHERE phone=?",[$ph]),
    'del'=>(int)val("SELECT COUNT(*) FROM orders WHERE phone=? AND status='delivered'",[$ph]),
    'ret'=>(int)val("SELECT COUNT(*) FROM orders WHERE phone=? AND status IN ('returned','cancelled')",[$ph]),
  ];
}
$lastCustomer='';
if(is_array($thread)){ foreach(array_reverse($thread) as $c){ $by=strtolower((string)($c['addedBy']??'')); if(strpos($by,'vendor')===false){ $lastCustomer=(string)($c['comments']??''); break; } } }
if(!function_exists('cc_avcls')){ function cc_avcls($state){ return $state==='noresp'?'red':($state==='reply'?'amb':($state==='answered'?'grn':'blu')); } }
if(!function_exists('cc_day')){ function cc_day($t){ $ts=strtotime((string)$t); if(!$ts) return '';
  $d=date('Y-m-d',$ts);
  if($d===date('Y-m-d')) return 'TODAY';
  if($d===date('Y-m-d',strtotime('-1 day'))) return 'YESTERDAY';
  return strtoupper(date('D j M',$ts)); } }
require __DIR__.'/includes/header.php';
?>
<div class="cc3-hero">
  <div><h1>💬 NCM Comment Center</h1>
    <p class="muted" style="font-size:12px"><?= date('l j M') ?><?= $scan['date']?' · scanned '.e($scan['date']):'' ?><?= ($scan['scanned']??0)?' · '.(int)$scan['scanned'].' active parcels':'' ?></p></div>
  <div class="cc3-kpis">
    <span class="kpi r"><b><?= $kReply ?></b>need reply</span>
    <span class="kpi a"><b><?= $kNoresp ?></b>no response</span>
    <span class="kpi g"><b><?= $kDone ?></b>answered</span>
    <a class="kpi lnk<?= $view==='feed'?' on':'' ?>" href="ncm_comments.php?v=feed">📋 <b style="font-size:12px"><?= count($scan['items']??[]) ?></b>feed</a>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="scan">
      <button class="kpi btnk" <?= $connected?'':'disabled title="connect NCM first"' ?>><b>🔄</b>rescan</button></form>
    <a class="kpi lnk" href="ncm.php"><b>📮</b>NCM page</a>
  </div>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>
<?php if(!$connected): ?><div class="flash" style="background:var(--amber-bg,#fef3c7);color:var(--amber,#b45309)">⚠ NCM API key is missing or empty — comments cannot sync. Add your key in <a href="settings.php"><b>Settings → NCM API</b></a>, then press 🔄 rescan.</div><?php endif; ?>

<?php if($view==='feed'): ?>
<div class="panel">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px"><h2>📋 All of Today's Comments — <?= count($scan['items']??[]) ?></h2>
    <span style="display:flex;gap:8px;align-items:center"><a class="btn btn-sm" href="ncm_comments.php">💬 Back to Inbox</a>
    <input class="search-in" style="min-width:200px" placeholder="Filter comment, order, name…" oninput="fdFilter(this.value)"></span>
  </div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>NCM Order</th><th>Customer</th><th style="min-width:280px">Comment</th><th>By</th><th>Time</th><th></th></tr></thead>
    <tbody id="fdRows">
    <?php if(($scan['items']??[])): foreach($scan['items'] as $c2):
      $oid=(string)($c2['orderid']??''); $o2=$locByNid[$oid]??null;
      $flag=function_exists('ncm_no_response')?ncm_no_response((string)($c2['comments']??'')):false;
      $by2=strtolower((string)($c2['addedBy']??'')); $mine2=strpos($by2,'vendor')!==false; ?>
      <tr<?= $flag?' class="row-warn"':'' ?> data-s="<?= e(strtolower($oid.' '.($o2['customer']??'').' '.($o2['code']??'').' '.($c2['comments']??''))) ?>">
        <td><b><?= e($oid) ?></b><?= $o2?'<div class="muted" style="font-size:10.5px">'.e($o2['code']).'</div>':'' ?></td>
        <td><?= e($o2['customer']??'—') ?></td>
        <td><?= $mine2?'<span class="muted">↩ you: </span>':'' ?><?= e($c2['comments']??'') ?><?= $flag?' <span class="pill p-red" style="font-size:10px">no response</span>':'' ?></td>
        <td class="muted"><?= e($c2['addedBy']??'') ?></td>
        <td class="num muted"><?= e(substr((string)($c2['added_time']??''),11,5)) ?></td>
        <td class="right nowrap"><a class="btn btn-sm btn-primary" href="ncm_comments.php?c=<?= e($oid) ?>">💬 Open</a> <a class="btn btn-sm" target="_blank" rel="noopener" href="<?= e(function_exists('ncm_portal_url')?ncm_portal_url($oid):'#') ?>">Portal ↗</a></td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="6"><div class="empty">Nothing scanned yet — press 🔄 rescan above; this list then shows <b>every</b> comment across up to 120 active parcels.</div></td></tr>
    <?php endif; ?>
    </tbody></table></div>
</div>
<script>function fdFilter(q){q=(q||'').toLowerCase();document.querySelectorAll('#fdRows tr').forEach(function(tr){var s=tr.getAttribute('data-s')||'';tr.style.display=!q||s.indexOf(q)>-1?'':'none';});}</script>

<?php else: ?>
<div class="cc3<?= $cId!==''?' has-thread':'' ?>">
  <!-- ================= LEFT ================= -->
  <div class="card cc3-list">
    <div class="lt-head"><input class="search" placeholder="🔍 Search name, phone, NCM #…" oninput="ccApply()"></div>
    <div class="seg">
      <span class="on" data-f="reply" onclick="ccTab(this)">🔴 Reply <b><?= $kReply ?></b></span>
      <span data-f="today" onclick="ccTab(this)">Today <b><?= count($convs) ?></b></span>
      <span data-f="all" onclick="ccTab(this)">All <b><?= count($convs)+count($actives) ?></b></span>
    </div>
    <div class="lt-scroll" id="ccList">
      <?php foreach($convs as $nid2=>$cv): $o=$cv['o']; $st=$cv['state']; ?>
      <a class="ci<?= (string)$cId===(string)$nid2?' cur':'' ?>" data-f="today<?= $st!=='answered'?' reply':'' ?>"
         data-s="<?= e(strtolower(($o['customer']??'').' '.($o['phone']??'').' '.$nid2.' '.($o['code']??''))) ?>"
         href="ncm_comments.php?c=<?= e($nid2) ?>">
        <span class="av <?= cc_avcls($st) ?>"><?= e(strtoupper(mb_substr(trim((string)($o['customer']??'N'))?:'N',0,1))) ?></span>
        <span class="mid">
          <span class="nm"><?= e($o['customer']??('NCM #'.$nid2)) ?>
            <?= $st==='noresp'?'<span class="tag r">NO RESPONSE</span>':($st==='reply'?'<span class="tag a">REPLY</span>':'<span class="tag g">ANSWERED</span>') ?></span>
          <span class="pv"><?= $st==='answered'?'↩ you: ':'' ?><?= e(mb_strimwidth($cv['last'],0,42,'…')) ?></span>
        </span>
        <span class="tm"><?= e(substr($cv['lastAt'],11,5)) ?><br>#<?= e($nid2) ?></span>
      </a>
      <?php endforeach; ?>
      <?php if($actives): ?><div class="lt-sep">ACTIVE PARCELS — quiet today</div><?php endif; ?>
      <?php foreach($actives as $a): $o=$a['o']; $nid2=$a['nid']; ?>
      <a class="ci quiet<?= (string)$cId===(string)$nid2?' cur':'' ?>" data-f="all"
         data-s="<?= e(strtolower(($o['customer']??'').' '.($o['phone']??'').' '.$nid2.' '.($o['code']??''))) ?>"
         href="ncm_comments.php?c=<?= e($nid2) ?>">
        <span class="av blu q"><?= e(strtoupper(mb_substr(trim((string)($o['customer']??'N'))?:'N',0,1))) ?></span>
        <span class="mid"><span class="nm"><?= e($o['customer']??'—') ?></span>
          <span class="pv"><?= e($o['product_name']??'') ?> · <?= e($o['status']) ?></span></span>
        <span class="tm"><?= e($o['code']) ?><br>#<?= e($nid2) ?></span>
      </a>
      <?php endforeach; ?>
      <?php if(!$convs && !$actives): ?><div class="empty" style="padding:30px 14px">No booked NCM parcels yet.</div><?php endif; ?>
    </div>
  </div>

  <!-- ================= CENTER ================= -->
  <div class="card cc3-thread">
    <?php if($cId===''): ?>
      <div class="th-none">💬<br>Pick a conversation<br><span class="muted" style="font-size:12px">or press 🔄 rescan to pull today's comments</span></div>
    <?php else: $stc=$local?cc_avcls($convs[(string)$cId]['state']??'blu'):'blu'; ?>
      <div class="th-head">
        <div class="who">
          <a href="ncm_comments.php" class="cc-back">←</a>
          <span class="av <?= $stc ?>"><?= e(strtoupper(mb_substr(trim((string)($local['customer']??'N'))?:'N',0,1))) ?></span>
          <div><div class="nm"><?= e($local['customer'] ?? ('NCM #'.$cId)) ?><?= $local?' · '.e($local['code']):'' ?></div>
            <div class="st">● <?= e($ncmStatus?:'NCM #'.$cId) ?></div></div>
        </div>
        <div class="chips-top">
          <?php if($local && $local['phone']): ?>
            <a class="icobtn" href="tel:<?= e($local['phone']) ?>">📞 <?= e($local['phone']) ?></a>
            <a class="icobtn" target="_blank" rel="noopener" href="https://wa.me/<?= e(wa_phone($local['phone'])) ?>">💬 WA</a>
          <?php endif; ?>
          <a class="icobtn" href="ncm.php?comments=<?= e($cId) ?>">↗ NCM</a>
        </div>
      </div>
      <div class="chat" id="ccChat">
        <?php if($threadErr): ?><div class="th-none" style="padding:40px 20px">⚠ <?= e($threadErr) ?></div>
        <?php elseif(is_array($thread) && $thread): $prevDay='';
          foreach($thread as $c):
            $by=(string)($c['addedBy']??''); $mine=stripos($by,'vendor')!==false;
            $day=cc_day($c['added_time']??'');
            if($day!==$prevDay){ echo '<div class="day">'.e($day).'</div>'; $prevDay=$day; } ?>
          <div class="m <?= $mine?'me':'them' ?>"><span class="b"><?= e($c['comments']??'') ?>
            <span class="meta"><?= e($mine?'You':($by?:'NCM')) ?> · <?= e(substr((string)($c['added_time']??''),11,5)) ?></span></span></div>
        <?php endforeach; else: ?><div class="th-none" style="padding:40px 20px">🗨️ No comments yet — start below.</div><?php endif; ?>
      </div>
      <div class="qchips">
        <?php foreach([
          'Customer confirmed — please re-attempt delivery.',
          'Please call the customer again, phone was off earlier.',
          'Customer will collect from branch — please hold.',
          'Address confirmed as correct.',
          'Please deliver tomorrow, customer requested.',
          'Return to us — customer refused the order.',
        ] as $qr): ?><button type="button" class="qc" onclick="ccSet(<?= json_encode($qr) ?>)"><?= e(mb_strimwidth($qr,0,32,'…')) ?></button><?php endforeach; ?>
      </div>
      <form method="post" class="compose" id="ccForm">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="send"><input type="hidden" name="nid" value="<?= e($cId) ?>">
        <textarea name="comment" id="ccInput" required rows="1" placeholder="Message NCM… (Enter = send, Shift+Enter = new line)"></textarea>
        <button type="button" class="ai" onclick="ccAI(this)" title="AI draft reply">🤖</button>
        <button class="send">Send ➤</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- ================= RIGHT ================= -->
  <?php if($cId!=='' && $local): ?>
  <div class="card cc3-ctx">
    <div class="cx">
      <h3>ORDER</h3>
      <div class="ord"><b><?= e($local['product_name']??'—') ?> ×<?= (int)$local['qty'] ?></b>
        <div class="row2"><span>COD</span><span>Rs. <?= number_format((float)$local['sell_price']*(int)$local['qty']) ?></span></div>
        <div class="row2"><span>Booked</span><span><?= e($local['order_date']??'—') ?></span></div>
        <div class="row2"><span>Age</span><span><?= max(0,(int)((time()-strtotime((string)$local['order_date']))/86400)) ?> days</span></div>
        <div class="row2"><span>Status</span><span><?= e($local['status']) ?></span></div>
        <div class="row2"><span>Address</span><span title="<?= e($local['address']) ?>"><?= e(mb_strimwidth((string)$local['address'],0,20,'…')) ?></span></div>
      </div>
      <?php if($custHist): ?>
      <h3>CUSTOMER HISTORY</h3>
      <div class="ord">
        <div class="row2"><span>Past orders</span><span><?= $custHist['total'] ?></span></div>
        <div class="row2"><span>Delivered</span><span style="color:var(--green)"><?= $custHist['del'] ?> ✓</span></div>
        <div class="row2"><span>Returned/Cancel</span><span style="color:<?= $custHist['ret']?'var(--red)':'inherit' ?>"><?= $custHist['ret'] ?><?= $custHist['ret']?' ⚠':'' ?></span></div>
      </div>
      <?php endif; ?>
      <?php if($hist): ?>
      <h3>PARCEL TIMELINE</h3>
      <div class="tl">
        <?php foreach($hist as $i=>$hrow): $hs=(string)($hrow['status']??($hrow['delivery_status']??'')); $ht=(string)($hrow['added_time']??($hrow['date']??'')); ?>
          <div<?= $i===0?' class="on"':'' ?>><b><?= e($hs?:'—') ?></b><?= $ht?' · '.e(substr($ht,5,11)):'' ?></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <h3 style="margin-top:12px">ACTIONS</h3>
      <div class="act">
        <a class="icobtn" href="ncm.php?track=<?= e($cId) ?>">🔁 Track</a>
        <a class="icobtn" target="_blank" rel="noopener" href="<?= e(function_exists('ncm_portal_url')?ncm_portal_url($cId):'#') ?>">Portal ↗</a>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<style>
.cc3-hero{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.cc3-hero h1{font-size:20px;font-weight:900;letter-spacing:-.02em}
.cc3-kpis{display:flex;gap:8px;flex-wrap:wrap;align-items:stretch}
.kpi{background:var(--surface);border-radius:14px;padding:7px 13px;box-shadow:0 4px 14px rgba(15,23,42,.06);font-size:11px;font-weight:700;color:var(--muted);border:1px solid var(--border);text-decoration:none;display:inline-flex;flex-direction:column;align-items:center;line-height:1.25;cursor:default}
.kpi b{font-size:15px;color:var(--ink)}
.kpi.r b{color:var(--red)}.kpi.a b{color:var(--amber)}.kpi.g b{color:var(--green)}
.kpi.lnk,.kpi.btnk{cursor:pointer}
.kpi.lnk:hover,.kpi.btnk:hover{background:var(--surface-2)}
.kpi.on{outline:2px solid var(--blue)}
.kpi.btnk{font:inherit}
.cc3{display:grid;grid-template-columns:330px minmax(0,1fr) 262px;gap:14px;align-items:start}
.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:0 6px 22px rgba(15,23,42,.06);overflow:hidden}
.lt-head{padding:12px 13px;border-bottom:1px solid var(--border)}
.search{width:100%;background:var(--surface-2);border:0;border-radius:10px;padding:9px 12px;font:inherit;font-size:12px;color:var(--ink)}
.seg{display:flex;gap:4px;padding:9px 11px;border-bottom:1px solid var(--border)}
.seg span{flex:1;text-align:center;border-radius:9px;padding:7px 4px;font-size:10.5px;font-weight:800;color:var(--muted);cursor:pointer;user-select:none}
.seg span.on{background:var(--ink);color:var(--surface)}
.lt-scroll{max-height:58vh;overflow-y:auto}
.ci{display:flex;gap:10px;padding:11px 13px;border-bottom:1px solid var(--surface-2);text-decoration:none;color:var(--ink)}
.ci:hover{background:var(--surface-2)}
.ci.cur{background:var(--surface-2);box-shadow:inset 3px 0 0 var(--blue)}
.ci.quiet{opacity:.8}
.av{width:37px;height:37px;border-radius:12px;display:grid;place-items:center;font-weight:900;flex:none;font-size:13px}
.av.red{background:var(--red-bg,#fee2e2);color:var(--red)}
.av.amb{background:var(--amber-bg,#fef3c7);color:var(--amber)}
.av.grn{background:var(--green-bg,#dcfce7);color:var(--green)}
.av.blu{background:var(--blue-bg,#dbeafe);color:var(--blue)}
.ci .mid{min-width:0;flex:1}
.ci .nm{font-weight:800;font-size:12.5px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.tag{font-size:8px;font-weight:900;padding:1px 7px;border-radius:99px;letter-spacing:.04em}
.tag.r{background:var(--red-bg,#fee2e2);color:var(--red)}.tag.a{background:var(--amber-bg,#fef3c7);color:var(--amber)}.tag.g{background:var(--green-bg,#dcfce7);color:var(--green)}
.ci .pv{display:block;font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.ci .tm{font-size:9.5px;color:var(--muted);text-align:right;flex:none;line-height:1.5}
.lt-sep{padding:8px 13px;font-size:9px;font-weight:900;letter-spacing:.13em;color:var(--muted);background:var(--surface-2)}
.cc3-thread{display:flex;flex-direction:column;min-height:60vh}
.th-none{flex:1;display:grid;place-items:center;text-align:center;color:var(--muted);font-size:15px;line-height:2;padding:60px 10px}
.th-head{padding:12px 15px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
.th-head .who{display:flex;gap:10px;align-items:center}
.th-head .nm{font-weight:900;font-size:13.5px}
.th-head .st{font-size:10.5px;color:var(--blue);font-weight:700}
.chips-top{display:flex;gap:6px;flex-wrap:wrap}
.icobtn{background:var(--surface-2);border:1px solid var(--border);border-radius:9px;padding:7px 11px;font-size:11.5px;cursor:pointer;font-weight:700;color:var(--ink);text-decoration:none;display:inline-block;text-align:center}
.icobtn:hover{background:var(--border)}
.chat{flex:1;padding:15px;background:var(--surface-2);min-height:300px;max-height:46vh;overflow-y:auto}
.day{display:flex;align-items:center;gap:10px;color:var(--muted);font-size:9.5px;font-weight:800;margin:8px 0 12px}
.day:before,.day:after{content:'';flex:1;height:1px;background:var(--border)}
.m{display:flex;margin-bottom:9px}
.m .b{max-width:78%;padding:9px 13px;border-radius:15px;font-size:12.5px;box-shadow:0 2px 8px rgba(15,23,42,.05)}
.m.them .b{background:var(--surface);border-bottom-left-radius:4px}
.m.me{justify-content:flex-end}
.m.me .b{background:var(--blue);color:#fff;border-bottom-right-radius:4px}
.m .meta{display:block;font-size:9.5px;opacity:.65;margin-top:4px}
.qchips{display:flex;gap:6px;flex-wrap:wrap;padding:9px 13px;border-top:1px solid var(--border)}
.qc{background:var(--surface-2);border:0;border-radius:999px;font-size:10.5px;font-weight:600;padding:5px 11px;cursor:pointer;color:var(--ink)}
.qc:hover{background:var(--border)}
.compose{display:flex;gap:8px;padding:11px 13px;border-top:1px solid var(--border);align-items:flex-end}
.compose textarea{flex:1;border:1px solid var(--border);border-radius:13px;padding:10px 13px;font:inherit;resize:none;height:41px;max-height:110px;background:var(--surface);color:var(--ink)}
.send{background:var(--blue);color:#fff;border:0;border-radius:12px;padding:10px 16px;font-weight:800;cursor:pointer}
.ai{background:linear-gradient(135deg,#7c3aed,var(--blue));color:#fff;border:0;border-radius:12px;padding:10px 13px;cursor:pointer}
.cc3-ctx .cx{padding:13px 15px}
.cx h3{font-size:9.5px;letter-spacing:.13em;color:var(--muted);font-weight:900;margin-bottom:8px}
.ord{background:var(--surface-2);border-radius:13px;padding:11px 12px;margin-bottom:12px}
.ord b{display:block;font-size:12.5px;margin-bottom:4px}
.row2{display:flex;justify-content:space-between;font-size:11.5px;padding:2.5px 0;color:var(--muted)}
.row2 span:last-child{font-weight:700;color:var(--ink)}
.act{display:flex;gap:7px;flex-wrap:wrap}
.act .icobtn{flex:1}
.tl{border-left:2px solid var(--border);margin:4px 0 0 6px;padding-left:14px}
.tl div{position:relative;font-size:10.5px;padding-bottom:9px;color:var(--muted)}
.tl div b{color:var(--ink)}
.tl div:before{content:'';position:absolute;left:-19px;top:3px;width:8px;height:8px;border-radius:99px;background:var(--border)}
.tl div.on:before{background:var(--blue)}
.cc-back{display:none;text-decoration:none;font-size:18px;color:var(--ink)}
@media(max-width:1080px){.cc3{grid-template-columns:300px 1fr}.cc3-ctx{display:none}}
@media(max-width:860px){
  .cc3{grid-template-columns:1fr}
  .cc3.has-thread .cc3-list{display:none}
  .cc-back{display:inline}
  .chat{max-height:44vh}
}
</style>
<script>
function ccTab(el){document.querySelectorAll('.seg span').forEach(function(t){t.classList.remove('on')});el.classList.add('on');ccApply();}
function ccApply(){
  var on=document.querySelector('.seg span.on'); var f=on?on.getAttribute('data-f'):'reply';
  var si=document.querySelector('.lt-head .search'); var q=(si&&si.value||'').toLowerCase();
  document.querySelectorAll('.ci').forEach(function(it){
    var okF = f==='all' || (it.getAttribute('data-f')||'').indexOf(f)>-1;
    var okQ = !q || (it.getAttribute('data-s')||'').indexOf(q)>-1;
    it.style.display = okF&&okQ ? '' : 'none';
  });
  var sep=document.querySelector('.lt-sep'); if(sep) sep.style.display = f==='all'&&!q ? '' : 'none';
}
function ccSet(t){var el=document.getElementById('ccInput');if(el){el.value=t;el.focus();}}
function ccAI(btn){
  var last=<?= json_encode($lastCustomer) ?>;
  if(!last){alert('No customer comment on this thread to reply to yet.');return;}
  var ctx=<?= json_encode($local? "Order {$local['code']}, customer {$local['customer']}, address {$local['address']}, status {$local['status']}" : ("NCM order ".$cId)) ?>;
  var el=document.getElementById('ccInput'); var old=btn.textContent; btn.disabled=true; btn.textContent='✨';
  fetch('ai.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',
    body:'action=ncm_reply&csrf='+encodeURIComponent('<?= csrf() ?>')+'&comment='+encodeURIComponent(last)+'&context='+encodeURIComponent(ctx)})
    .then(function(r){return r.json();}).then(function(d){btn.disabled=false;btn.textContent=old;
      if(d.ok){el.value=d.text;el.focus();}else alert(d.error||'AI error');})
    .catch(function(e){btn.disabled=false;btn.textContent=old;alert(e.message);});
}
(function(){
  ccApply();
  var sc=document.getElementById('ccChat'); if(sc) sc.scrollTop=sc.scrollHeight;
  var ta=document.getElementById('ccInput');
  if(ta){
    ta.addEventListener('input',function(){ta.style.height='auto';ta.style.height=Math.min(ta.scrollHeight,110)+'px';});
    ta.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();document.getElementById('ccForm').requestSubmit();}});
  }
  <?php if($view==='inbox' && $cId!==''): ?>setTimeout(function(){location.reload();},90000);<?php endif; ?>
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>