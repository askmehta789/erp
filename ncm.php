<?php
require_once __DIR__.'/ncm_api.php';
require_once __DIR__.'/ai.php';
require_login(); require_page_access();
ensure_order_group();
ncm_ensure_cols();   /* ncm_order_id + flags must exist before anything else */
/* self-heal a broken portal-URL template saved in Settings (old guessed paths bounce to NCM's homepage) */
try { $ptpl=trim((string)setting('ncm_portal_tpl',''));
  if($ptpl!=='' && stripos($ptpl,'nepalcanmove.com')!==false && stripos($ptpl,'/accounts/vendor/order/')===false) set_setting('ncm_portal_tpl','');
} catch (Exception $e) {}
/* self-heal: a multi-product order is ONE parcel — if any row of a group has an NCM id,
   every sibling gets it too (fixes parcels showing "Book" when one line was booked/linked). */
try {
  q("UPDATE orders o
     JOIN (SELECT order_group, MIN(NULLIF(ncm_order_id,'')) AS nid
             FROM orders
            WHERE COALESCE(order_group,'')<>''
            GROUP BY order_group
           HAVING nid IS NOT NULL) x ON x.order_group=o.order_group
     SET o.ncm_order_id=x.nid,
         o.courier_id=COALESCE(o.courier_id,(SELECT id FROM couriers WHERE name LIKE '%NCM%' LIMIT 1))
     WHERE COALESCE(o.ncm_order_id,'')=''");
} catch (Exception $e) {}
$PAGE_TITLE='NCM Courier';

/* store NCM order id against our orders (safe if exists) */
try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS ncm_order_id VARCHAR(40) NULL"); } catch (Exception $e) {}

/* NCM vendor-portal deep link for an order id */

/* match user text to NCM's exact branch name (case-insensitive) */
if(!function_exists('ncm_norm_phone')){
  /* NCM needs a clean 10-digit Nepali mobile: strip spaces/+977/leading 0s, keep last 10 digits */
  function ncm_norm_phone($p){
    $d=preg_replace('/[^0-9]/','',(string)$p);
    $d=preg_replace('/^977/','',$d);
    $d=ltrim($d,'0');
    if(strlen($d)>10) $d=substr($d,-10);
    return (strlen($d)===10 && $d[0]==='9') ? $d : '';
  }
}
function ncm_match_branch($name,$names){
  $t = mb_strtoupper(trim((string)$name));
  if ($t==='') return '';
  foreach ($names as $b) if (mb_strtoupper($b)===$t) return $b;
  foreach ($names as $b) if (mb_strpos(mb_strtoupper($b),$t)!==false || mb_strpos($t,mb_strtoupper($b))!==false) return $b;
  return '';
}
/* guess destination branch from a free-text address */
function ncm_guess_branch($addr,$names){
  $A = mb_strtoupper((string)$addr);
  if ($A==='') return '';
  $best=''; $bestLen=0;
  foreach ($names as $b) {
    $B = mb_strtoupper($b);
    if ($B!=='' && mb_strpos($A,$B)!==false && mb_strlen($B)>$bestLen) { $best=$b; $bestLen=mb_strlen($B); }
  }
  return $best;
}
/* build + fire one NCM booking; returns [ncmId, deliveryCharge]; throws on failure */
function ncm_book_one(array $bk, array $branchNames){
  $phone = ncm_norm_phone($bk['phone'] ?? '');
  if ($phone==='') throw new Exception('invalid phone "'.($bk['phone']??'').'" — NCM needs a 10-digit mobile');
  $branch = ncm_match_branch($bk['branch'] ?? '', $branchNames);
  if ($branch==='') throw new Exception('destination branch "'.($bk['branch']??'').'" is not an NCM branch');
  $fbranch = ncm_match_branch($bk['fbranch'] ?? '', $branchNames);
  if ($fbranch==='') throw new Exception('pickup branch "'.($bk['fbranch']??'').'" is not an NCM branch — set it in Settings');
  $payload = [
    'name'          => trim((string)($bk['name'] ?? '')) ?: 'Customer',
    'phone'         => $phone,
    'cod_charge'    => (string)max(0,(int)round((float)($bk['cod'] ?? 0))),
    'address'       => trim((string)($bk['address'] ?? '')) ?: $branch,
    'fbranch'       => $fbranch,
    'branch'        => $branch,
    'package'       => trim((string)($bk['package'] ?? '')) ?: 'Goods',
    'delivery_type' => $bk['delivery_type'] ?? 'Door2Door',
  ];
  $p2 = ncm_norm_phone($bk['phone2'] ?? ''); if ($p2!=='') $payload['phone2']=$p2;
  foreach (['vref_id','instruction'] as $opt) { $v=trim((string)($bk[$opt] ?? '')); if ($v!=='') $payload[$opt]=$v; }
  $res = ncm()->createOrder($payload);
  $ncmId = $res['orderid'] ?? ($res['id'] ?? '');
  if (is_array($ncmId)) $ncmId = json_encode($ncmId);
  if (!$ncmId) throw new Exception('NCM did not return an order id: '.json_encode($res));
  $charge = isset($res['delivery_charge']) ? (float)$res['delivery_charge'] : null;
  if ($charge===null) { try { $od = ncm()->order((int)$ncmId); if (isset($od['delivery_charge'])) $charge=(float)$od['delivery_charge']; } catch (Exception $e) {} }

  /* AUTO-COMMENT: post the full delivery address (+ name/phone/package) on the new NCM order,
     so the branch & rider see exactly where to deliver — e.g. "Birgunj, Ghantaghar".
     Plain text (no emoji — some NCM endpoints reject them), retried once after a short wait
     (a brand-new order may not accept comments in the same second it is created).
     Result is recorded so the booking flash can show ✓ or the exact error. Never breaks booking. */
  $tpl = trim((string)setting('ncm_book_comment_tpl',''));
  if ($tpl==='') $tpl = "Address: {address} | Customer: {name} | Ph: {phone} | Items: {package}";
  $cmt = strtr($tpl, [
    '{address}' => trim((string)($bk['address'] ?? '')),
    '{name}'    => trim((string)($bk['name'] ?? '')),
    '{phone}'   => $phone,
    '{package}' => trim((string)($bk['package'] ?? '')),
    '{cod}'     => number_format((float)($bk['cod'] ?? 0)),
    '{ref}'     => trim((string)($bk['vref_id'] ?? '')),
  ]);
  $cmt = trim($cmt);
  if ($cmt!=='' && trim((string)($bk['address'] ?? ''))!=='') {
    $cmtErr='';
    for ($try=1; $try<=2; $try++) {
      try { ncm()->addComment((int)$ncmId, $cmt); $cmtErr=''; break; }
      catch (Exception $e) { $cmtErr=$e->getMessage(); if($try===1) sleep(2); }
    }
    if ($cmtErr==='') {
      $GLOBALS['ncm_cmt_status'][(string)$ncmId]='ok';
      log_activity('Auto-comment posted on NCM #'.$ncmId,'NCM');
    } else {
      $GLOBALS['ncm_cmt_status'][(string)$ncmId]=$cmtErr;
      log_activity('Auto-comment FAILED on NCM #'.$ncmId.': '.$cmtErr,'NCM');
    }
  }

  return [$ncmId,$charge];
}

/* book an entire order group as ONE NCM parcel:
   combined package list, summed COD, one delivery — then stamp the NCM id on every row in the group. */
function ncm_book_group($grp, array $branchNames, $fbranch, $deliveryType, $branchOverride=''){
  try {
    $rows = rows("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id
                  WHERE o.order_group=? ORDER BY o.id", [$grp]);
  } catch (Exception $e) { throw new Exception('group column missing — open Sales once, then retry'); }
  if (!$rows) throw new Exception('group not found');
  // if any already booked, reuse that id for the rest (don't double-book)
  foreach ($rows as $r) if (!empty($r['ncm_order_id'])) {
    foreach ($rows as $r2) if (empty($r2['ncm_order_id']))
      q("UPDATE orders SET ncm_order_id=?, courier_id=(SELECT id FROM couriers WHERE name LIKE '%NCM%' LIMIT 1) WHERE id=?",[$r['ncm_order_id'],$r2['id']]);
    return [$r['ncm_order_id'], null, 'reused'];
  }
  $head = $rows[0];
  $dest = $branchOverride!=='' ? ncm_match_branch($branchOverride,$branchNames) : ncm_guess_branch($head['address'],$branchNames);
  if ($dest==='') throw new Exception('no branch match in address');
  // combined package text + total COD across the group (prepaid rows add 0)
  $parts=[]; $cod=0;
  foreach ($rows as $r) {
    $parts[] = ($r['product_name'] ?: 'Goods').' x'.(int)$r['qty'];
    if (strtolower((string)$r['payment_type'])==='cod') $cod += (float)$r['sell_price']*(int)$r['qty'];
  }
  $package = implode(', ', $parts);
  [$nid,$chg] = ncm_book_one([
    'name'=>$head['customer'],'phone'=>$head['phone'],'cod'=>$cod,
    'address'=>$head['address'],'fbranch'=>$fbranch,'branch'=>$dest,
    'package'=>$package,'vref_id'=>$head['code'],'delivery_type'=>$deliveryType,
  ], $branchNames);
  // stamp NCM id on ALL rows; put the (single) delivery charge on the first row only
  $first=true;
  foreach ($rows as $r) {
    q("UPDATE orders SET ncm_order_id=?, courier_id=(SELECT id FROM couriers WHERE name LIKE '%NCM%' LIMIT 1) WHERE id=?",[$nid,$r['id']]);
    if ($chg!==null) { q("UPDATE orders SET delivery_charge=? WHERE id=?",[$first?$chg:0,$r['id']]); }
    $first=false;
  }
  return [$nid,$chg,$dest];
}

/* stamp one NCM id across every row of the order's group (a parcel has ONE id) */
function ncm_stamp_group($orderId,$nid,$chg=null){
  try {
    $g=trim((string)val("SELECT order_group FROM orders WHERE id=?",[$orderId]));
    if($g==='') return;
    $first=true;
    foreach (rows("SELECT id FROM orders WHERE order_group=? ORDER BY id",[$g]) as $r) {
      q("UPDATE orders SET ncm_order_id=?, courier_id=COALESCE(courier_id,(SELECT id FROM couriers WHERE name LIKE '%NCM%' LIMIT 1)) WHERE id=?",[$nid,$r['id']]);
      if($chg!==null) q("UPDATE orders SET delivery_charge=? WHERE id=?",[$first?$chg:0,$r['id']]);
      $first=false;
    }
  } catch (Exception $e) {}
}

/* dig a phone number out of an NCM order-details payload (field names vary) *//* dig a phone number out of an NCM order-details payload (field names vary) *//* dig a phone number out of an NCM order-details payload (field names vary) */
function ncm_extract_phone($d){
  $found='';
  $walk=function($v)use(&$walk,&$found){
    if($found!=='')return;
    if(is_array($v)){foreach($v as $x)$walk($x);return;}
    if(is_string($v)||is_numeric($v)){
      $p=ncm_norm_phone($v);
      if($p!=='')$found=$p;
    }
  };
  $walk($d);
  return $found;
}
function ncm_extract_name($d){
  foreach(['name','customer_name','receiver_name','to_name','customer'] as $k)
    if(isset($d[$k])&&is_string($d[$k])&&trim($d[$k])!=='') return trim($d[$k]);
  return '';
}
/* parse "812345, 812350-812360" style input into ids (capped) */
function ncm_parse_ids($text,$cap=100){
  $out=[];
  foreach(preg_split('/[\s,;]+/',(string)$text) as $tok){
    if($tok==='')continue;
    if(preg_match('/^(\d+)\s*-\s*(\d+)$/',$tok,$m)){
      $a=(int)$m[1];$b=(int)$m[2]; if($b<$a){$t=$a;$a=$b;$b=$t;}
      if($b-$a>200)$b=$a+200;
      for($i=$a;$i<=$b;$i++)$out[$i]=1;
    } elseif(ctype_digit($tok)) $out[(int)$tok]=1;
    if(count($out)>=$cap)break;
  }
  return array_slice(array_keys($out),0,$cap);
}


/* Nepal phone → wa.me format */
if(!function_exists('wa_phone')){ function wa_phone($p){ $ph=preg_replace('/[^0-9]/','',(string)$p); $ph=ltrim($ph,'0'); if(strpos($ph,'977')!==0)$ph='977'.$ph; return $ph; } }



/* ---- POST actions ---- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act = $_POST['_action'] ?? '';
  try {
    if ($act==='confirm_delivered') {
      ncm_ensure_cols();
      $id=(int)($_POST['id']??0); $o=row("SELECT * FROM orders WHERE id=?",[$id]);
      if($o){
        $prev=(string)$o['status'];
        q("UPDATE orders SET status='delivered', payment_status='paid', ncm_return_flag=6, ncm_status='Delivered' WHERE id=?",[$id]);
        try { stock_on_status_change($o['product_id']??0,$o['qty']??0,$prev,'delivered',$id); } catch (Exception $e) {}
        log_activity('Manually confirmed DELIVERED: '.($o['code']??$id).' (return-suspect resolved by user)','NCM');
        flash('✔ '.($o['code']??('#'.$id)).' confirmed as DELIVERED to the customer.');
      }
      header('Location: ncm.php'); exit;
    }
    if ($act==='confirm_return') {
      ncm_ensure_cols();
      $id=(int)($_POST['id']??0); $o=row("SELECT * FROM orders WHERE id=?",[$id]);
      if($o){
        /* pull NCM's discounted return charge unless a charge is already set by hand */
        $rc=null;
        if(!(isset($o['cancel_charge']) && (float)$o['cancel_charge']>0))
          $rc=ncm_return_charge($o['ncm_order_id']??'', isset($o['delivery_charge'])?(float)$o['delivery_charge']:null);
        if($o['status']!=='returned'){
          if($rc!==null && $rc>0) q("UPDATE orders SET status='returned', ncm_return_flag=2, cancel_charge=? WHERE id=?",[$rc,$id]);
          else                    q("UPDATE orders SET status='returned', ncm_return_flag=2 WHERE id=?",[$id]);
          stock_on_status_change($o['product_id']??0,$o['qty']??0,$o['status'],'returned',$id);
          log_activity("Return confirmed for ".($o['code']??('#'.$id)).($rc?" (NCM charge Rs.".$rc.")":''),'NCM');
        } else {
          if($rc!==null && $rc>0) q("UPDATE orders SET ncm_return_flag=2, cancel_charge=? WHERE id=?",[$rc,$id]);
          else                    q("UPDATE orders SET ncm_return_flag=2 WHERE id=?",[$id]);
        }
        flash('↩ Marked as returned.');
      }
      header('Location: ncm.php?f=retcheck#orders'); exit;
    }
    if ($act==='keep_active') {
      ncm_ensure_cols();
      $id=(int)($_POST['id']??0); $o=row("SELECT * FROM orders WHERE id=?",[$id]);
      if($o){
        /* parcel is still being delivered — undo a wrong 'returned' and keep chasing it */
        if($o['status']==='returned'){
          q("UPDATE orders SET status='shipped', ncm_return_flag=0 WHERE id=?",[$id]);
          stock_on_status_change($o['product_id']??0,$o['qty']??0,'returned','shipped',$id);
          log_activity("Return rejected (still active) for ".($o['code']??('#'.$id)),'NCM');
        } else q("UPDATE orders SET ncm_return_flag=0 WHERE id=?",[$id]);
        flash('✓ Kept active — NCM will retry delivery.');
      }
      header('Location: ncm.php?f=retcheck#orders'); exit;
    }
    if ($act==='book') {
      $bn=[]; foreach (ncm()->branches() as $b) if(!empty($b['name'])) $bn[]=$b['name'];
      [$ncmId,$ncmCharge] = ncm_book_one([
        'name'=>$_POST['name']??'','phone'=>$_POST['phone']??'','phone2'=>$_POST['phone2']??'',
        'cod'=>$_POST['cod_charge']??0,'address'=>$_POST['address']??'',
        'fbranch'=>$_POST['fbranch']??'','branch'=>$_POST['branch']??'',
        'package'=>$_POST['package']??'','vref_id'=>$_POST['vref_id']??'',
        'instruction'=>$_POST['instruction']??'','delivery_type'=>$_POST['delivery_type']??'Door2Door',
      ], $bn);
      if (!empty($_POST['order_id'])) {
        $oid=(int)$_POST['order_id'];
        $grp=''; try { $grp=trim((string)val("SELECT order_group FROM orders WHERE id=?",[$oid])); } catch (Exception $e) {}
        if ($grp!=='') {
          /* stamp the id + single delivery across the whole group */
          $first=true;
          foreach (rows("SELECT id FROM orders WHERE order_group=? ORDER BY id",[$grp]) as $gr) {
            q("UPDATE orders SET ncm_order_id=?, courier_id=(SELECT id FROM couriers WHERE name LIKE '%NCM%' LIMIT 1) WHERE id=?",[$ncmId,$gr['id']]);
            if ($ncmCharge!==null) q("UPDATE orders SET delivery_charge=? WHERE id=?",[$first?$ncmCharge:0,$gr['id']]);
            $first=false;
          }
        } else {
          q("UPDATE orders SET ncm_order_id=?, courier_id=(SELECT id FROM couriers WHERE name LIKE '%NCM%' LIMIT 1) WHERE id=?", [$ncmId,$oid]);
          if ($ncmCharge!==null) q("UPDATE orders SET delivery_charge=? WHERE id=?", [$ncmCharge,$oid]);
        }
      }
      log_activity('Booked NCM order '.$ncmId,'NCM');
      $cst=$GLOBALS['ncm_cmt_status'][(string)$ncmId] ?? null;
      $cNote = $cst===null ? '' : ($cst==='ok' ? ' · address comment posted ✓' : ' · ⚠ address comment failed: '.$cst.' (order still booked — add it via Thread)');
      flash("Booked on NCM ✓ Order ID: $ncmId".($ncmCharge!==null?(' · delivery '.money($ncmCharge).' added to sale'):'').$cNote);
    }
    elseif ($act==='book_bulk') {
      $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
      $ids = array_slice($ids, 0, 60);
      set_time_limit(600);
      $bn=[]; foreach (ncm()->branches() as $b) if(!empty($b['name'])) $bn[]=$b['name'];
      $fb = $_POST['fbranch'] ?? setting('ncm_from_branch','TINKUNE');
      $dt = $_POST['delivery_type'] ?? 'Door2Door';
      $ok=[]; $fail=[]; $doneGroups=[];
      foreach ($ids as $oid) {
        $o = row("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id WHERE o.id=?",[$oid]);
        if (!$o) { $fail[]="#$oid: not found"; continue; }
        if (!empty($o['ncm_order_id'])) { $fail[]=e($o['code']).": already booked"; continue; }
        $grp = (is_array($o) && array_key_exists('order_group',$o)) ? trim((string)$o['order_group']) : '';
        if ($grp!=='') {
          /* MULTI-PRODUCT ORDER: book the whole group as ONE parcel, once */
          if (isset($doneGroups[$grp])) continue;               /* a sibling already booked this group */
          $doneGroups[$grp]=1;
          $ov = trim((string)($_POST['branch_'.$oid] ?? ''));
          try {
            [$nid,$chg,$dest] = ncm_book_group($grp, $bn, $fb, $dt, $ov);
            $cnt=(int)val("SELECT COUNT(*) FROM orders WHERE order_group=?",[$grp]);
            $ok[]=e($o['customer']?:$o['code'])." ({$cnt} items)→$nid".($dest&&$dest!=='reused'?" ($dest)":"");
          } catch (Exception $ex) { $fail[]=e($o['code']).' (group): '.$ex->getMessage(); }
          continue;
        }
        /* single-product order — book normally */
        $ov = trim((string)($_POST['branch_'.$oid] ?? ''));
        $dest = $ov!=='' ? ncm_match_branch($ov,$bn) : ncm_guess_branch($o['address'], $bn);
        if ($dest==='') { $fail[]=e($o['code']).": no branch match in address — book manually"; continue; }
        try {
          [$nid,$chg] = ncm_book_one([
            'name'=>$o['customer'],'phone'=>$o['phone'],
            'cod'=>(strtolower((string)$o['payment_type'])==='cod' ? (float)$o['sell_price']*(int)$o['qty'] : 0),
            'address'=>$o['address'],'fbranch'=>$fb,'branch'=>$dest,
            'package'=>trim(($o['product_name']?:'Goods').' x'.(int)$o['qty']),
            'vref_id'=>$o['code'],'delivery_type'=>$dt,
          ], $bn);
          q("UPDATE orders SET ncm_order_id=?, courier_id=(SELECT id FROM couriers WHERE name LIKE '%NCM%' LIMIT 1) WHERE id=?", [$nid,$oid]);
          if ($chg!==null) q("UPDATE orders SET delivery_charge=? WHERE id=?", [$chg,$oid]);
          $ok[]=e($o['code'])."→$nid ($dest)";
        } catch (Exception $ex) { $fail[]=e($o['code']).': '.$ex->getMessage(); }
      }
      log_activity('Bulk NCM booking: '.count($ok).' ok, '.count($fail).' failed','NCM');
      $cmtOk=0;$cmtFail=0;
      foreach(($GLOBALS['ncm_cmt_status']??[]) as $v){ $v==='ok'?$cmtOk++:$cmtFail++; }
      $cmtNote = ($cmtOk+$cmtFail)>0 ? ' · address comments: '.$cmtOk.' posted'.($cmtFail?(', ⚠ '.$cmtFail.' failed (see Activity log)'):'') : '';
      $msg = count($ok).' booked ✓'.($ok?(' — '.implode(', ',$ok)):'').$cmtNote;
      if ($fail) $msg .= '  ·  '.count($fail).' failed: '.implode(' | ',$fail);
      flash($msg);
    }
    elseif ($act==='link_manual') {
      $oid=(int)($_POST['order_id']??0); $nid=trim($_POST['ncm_id']??''); $force=!empty($_POST['force']);
      if(!$oid||!ctype_digit($nid)) throw new Exception('Enter a numeric NCM Order ID.');
      $o=row("SELECT * FROM orders WHERE id=?",[$oid]);
      if(!$o) throw new Exception('Order not found.');
      $dupe=row("SELECT code FROM orders WHERE ncm_order_id=? AND id<>? AND (COALESCE(order_group,'')='' OR COALESCE(order_group,'')<>COALESCE((SELECT order_group FROM orders x WHERE x.id=?),''))",[$nid,$oid,$oid]);
      if($dupe) throw new Exception('NCM #'.$nid.' is already linked to order '.$dupe['code'].'.');
      $d=ncm()->order((int)$nid);
      $ncmPhone=ncm_extract_phone($d); $ncmName=ncm_extract_name($d);
      $ourPhone=ncm_norm_phone($o['phone']);
      if(!$force){
        if($ncmPhone==='') throw new Exception('NCM order #'.$nid.' has no readable phone — tick "link anyway" if you are sure.');
        if($ourPhone==='' || $ncmPhone!==$ourPhone)
          throw new Exception('Phone mismatch: NCM #'.$nid.' → '.$ncmPhone.($ncmName?' ('.$ncmName.')':'').', but order '.$o['code'].' → '.($o['phone']?:'none').'. Tick "link anyway" to force.');
      }
      $chg=isset($d['delivery_charge'])?(float)$d['delivery_charge']:null;
      q("UPDATE orders SET ncm_order_id=?, courier_id=(SELECT id FROM couriers WHERE name LIKE '%NCM%' LIMIT 1) WHERE id=?",[$nid,$oid]);
      if($chg!==null) q("UPDATE orders SET delivery_charge=? WHERE id=?",[$chg,$oid]);
      ncm_stamp_group($oid,$nid,$chg);   /* multi-product order: siblings get the same parcel id */
      log_activity("Linked NCM #$nid to ".$o['code'],'NCM');
      flash('Linked ✓ '.$o['code'].' ↔ NCM #'.$nid.($ncmName?' — '.$ncmName:'').($chg!==null?(' · delivery '.money($chg).' recorded'):''));
    }
    elseif ($act==='scan_comments') {
      if(function_exists('set_time_limit')) @set_time_limit(180);
      [$cnt,$scn] = ncm_scan_today_comments(120);
      flash("Fetched today's comments ✓ $cnt comments found across $scn active orders.");
    }
    elseif ($act==='link_scan') {
      $ids=ncm_parse_ids($_POST['ids_text']??'',100);
      if(!$ids) throw new Exception('Paste NCM order IDs or ranges, e.g. 812345, 812350-812390');
      /* pool of unlinked ERP orders keyed by normalized phone */
      $pool=[];
      foreach(rows("SELECT id,code,phone,customer FROM orders WHERE (ncm_order_id IS NULL OR ncm_order_id='') AND status NOT IN ('cancelled','returned') ORDER BY id") as $o){
        $p=ncm_norm_phone($o['phone']); if($p!=='')$pool[$p][]=$o;
      }
      $taken=[]; foreach(rows("SELECT ncm_order_id FROM orders WHERE ncm_order_id IS NOT NULL AND ncm_order_id<>''") as $t)$taken[(string)$t['ncm_order_id']]=1;
      $ok=[];$miss=0;$err=0;$already=0;
      foreach($ids as $nid){
        if(isset($taken[(string)$nid])){$already++;continue;}
        try{ $d=ncm()->order((int)$nid); }catch(Exception $e){$err++;continue;}
        $p=ncm_extract_phone($d);
        if($p===''||empty($pool[$p])){$miss++;continue;}
        $o=array_shift($pool[$p]);   /* oldest unlinked order with this phone */
        $chg=isset($d['delivery_charge'])?(float)$d['delivery_charge']:null;
        q("UPDATE orders SET ncm_order_id=?, courier_id=(SELECT id FROM couriers WHERE name LIKE '%NCM%' LIMIT 1) WHERE id=?",[$nid,(int)$o['id']]);
        if($chg!==null) q("UPDATE orders SET delivery_charge=? WHERE id=?",[$chg,(int)$o['id']]);
        ncm_stamp_group((int)$o['id'],$nid,$chg);
        $taken[(string)$nid]=1;
        $ok[]=$o['code'].'↔'.$nid;
        usleep(120000);
      }
      log_activity('NCM scan-match: '.count($ok).' linked','NCM');
      $msg=count($ok).' linked by phone ✓'.($ok?(' — '.implode(', ',$ok)):'');
      $extra=[]; if($already)$extra[]="$already already linked"; if($miss)$extra[]="$miss no phone match"; if($err)$extra[]="$err not found on NCM";
      if($extra)$msg.='  ·  '.implode(' · ',$extra);
      flash($msg);
    }
    elseif ($act==='comment') {
      ncm()->addComment((int)$_POST['ncm_id'], trim($_POST['comment']??''));
      log_activity('Commented on NCM #'.$_POST['ncm_id'],'NCM');
      flash('Comment sent to NCM.');
    }
  } catch (Exception $ex) { flash('Error: '.$ex->getMessage()); }
  $back=$_POST['back']??''; header('Location: ncm.php'.($back?('?'.$back):'')); exit;
}

require __DIR__.'/includes/header.php';
echo delivery_disabled_banner('ncm.php');

/* ---- connection + branches (for dropdowns only, not shown as a table) ---- */
$connected=false; $connErr=''; $branches=[];
/* ?book=ORDER_ID → open the booking modal prefilled (from the Sales sheet) */
$bookPrefill=null;
$bkId=(int)($_GET['book']??0);
if($bkId){
  $bo=row("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id WHERE o.id=?",[$bkId]);
  if($bo){
    $grp=trim((string)($bo['order_group'] ?? ''));
    if($grp!==''){
      /* multi-product order: prefill combined package + summed COD for the whole group */
      $gr=rows("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id WHERE o.order_group=? ORDER BY o.id",[$grp]);
      $parts=[]; $cod=0;
      foreach($gr as $g){ $parts[]=($g['product_name']?:'Goods').' x'.(int)$g['qty']; if(strtolower((string)$g['payment_type'])==='cod') $cod+=(float)$g['sell_price']*(int)$g['qty']; }
      $bookPrefill=['id'=>(int)$bo['id'],'name'=>(string)$bo['customer'],'phone'=>(string)$bo['phone'],
        'address'=>(string)$bo['address'],'cod'=>$cod,'ref'=>(string)$bo['code'],
        'package'=>implode(', ',$parts),'group'=>$grp,'items'=>count($gr)];
    } else {
      $bookPrefill=['id'=>(int)$bo['id'],'name'=>(string)$bo['customer'],'phone'=>(string)$bo['phone'],
        'address'=>(string)$bo['address'],'cod'=>(float)$bo['sell_price']*(int)$bo['qty'],'ref'=>(string)$bo['code'],
        'package'=>trim((($bo['product_name'] ?? '') ?: 'Goods').' x'.(int)$bo['qty'])];
    }
  }
}
if (ncm()->configured()) {
  try { $branches = ncm()->branches(); $connected = is_array($branches); }
  catch (Exception $e) { $connErr = $e->getMessage(); }
}
$branchNames=[]; foreach($branches as $b){ if(!empty($b['name'])) $branchNames[]=$b['name']; }
sort($branchNames);

/* ---- our NCM orders ---- */
$ncmOrders = rows("SELECT o.*, p.name AS product_name, c.name AS courier_name
                   FROM orders o LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id
                   WHERE c.name LIKE '%NCM%' ORDER BY o.id DESC");

/* live statuses */
$liveStatus=[];
$bookedIds=array_values(array_filter(array_map(fn($o)=>$o['ncm_order_id']??null,$ncmOrders)));
if ($connected && $bookedIds) {
  try { $r=ncm()->ordersStatuses(array_map('intval',$bookedIds)); if(isset($r['result'])&&is_array($r['result'])) $liveStatus=$r['result']; }
  catch (Exception $e) {}
}

/* ---- auto-sync NCM status → Sales (delivered/returned/etc update the sale) ---- */
$syncedCount=0; $healedCount=0;
if ($liveStatus) {
  ncm_ensure_cols();
  foreach ($ncmOrders as $idx=>$o) {
    $nid=(string)($o['ncm_order_id']??''); if($nid===''||!isset($liveStatus[$nid])) continue;
    $liveRaw = (string)$liveStatus[$nid];
    $stageNow = ncm_return_stage($liveRaw);
    $curFlag  = (int)($o['ncm_return_flag'] ?? 0);          /* 0 none · 1 confirm me · 2 confirmed · 5 verified genuine delivery */
    $prevRaw  = (string)($o['ncm_status'] ?? '');

    $mapped = ncm_to_local_status($liveRaw);

    /* YOUR decision is final: once you confirmed an order (2 = returned by you,
       6 = delivered by you), the auto-sync never changes its status again.
       Same for any order already returned/cancelled in your books — however it got
       there (NCM page, Sales sheet, edit form) — the sync can never resurrect it. */
    if ($curFlag===2 || $curFlag===6) $mapped = null;
    if (in_array(($o['status']??''),['returned','cancelled'],true)) $mapped = null;

    /* "Sent to Vendor" is definitive: the parcel IS coming back — mark it RETURNED now,
       lock it (flag 2) so the later plain "Delivered" (= handed to you) can never
       flip it to a customer delivery. The return charge is captured just below. */
    if ($curFlag!==2 && $curFlag!==6 && ($o['status']??'')!=='returned' && ncm_vendor_bound($liveRaw)) {
      $mapped   = 'returned';
      $curFlag  = 2;               /* locked — your books, your rules */
      $stageNow = 'final';
    }

    /* ===== RETURNED PARCEL ARRIVING BACK =====
       On a return, NCM's FINAL status is a plain "Delivered" — meaning delivered back to
       the VENDOR, not the customer (timeline: Dispatched to RETURN → Sent to Vendor → Delivered).
       So: once this order has been on a return journey (flag 1, or its previous NCM status
       was return-ish), a plain "Delivered" must NEVER count as a customer delivery. */
    $wasReturnward = ($curFlag===1) || (ncm_return_stage($prevRaw)!=='none');
    $plainDelivered = ($stageNow==='none' && $mapped==='delivered');
    if ($plainDelivered && $wasReturnward && $curFlag!==2 && $curFlag!==6) {
      /* MANUAL MODE: "Delivered" after a return-ish journey is ambiguous — it can mean handed
         BACK TO VENDOR (a real return) or a genuine customer delivery after a mid-route fix.
         The system takes NO automatic action here: the order is held unchanged, flagged into
         the Return? (confirm) tab, and YOU decide with "✔ Returned" or "✔ Delivered". */
      $mapped = null;
      $stageNow = 'progress';   /* keeps flag=1 so the order stays in the confirm tab */
    }

    $newFlag  = ($curFlag===2||$curFlag===6) ? $curFlag : ((($stageNow==='progress'||$stageNow==='ambiguous') ? 1 : 0));
    try { q("UPDATE orders SET ncm_status=?, ncm_return_flag=? WHERE id=?", [$liveRaw, $newFlag, $o['id']]); } catch (Exception $e) {}
    $ncmOrders[$idx]['ncm_status']=$liveRaw; $ncmOrders[$idx]['ncm_return_flag']=$newFlag;
    if ($mapped && $mapped !== $o['status']) {
      try {
        if ($mapped==='delivered') q("UPDATE orders SET status='delivered', payment_status='paid' WHERE id=?", [$o['id']]);
        else                       q("UPDATE orders SET status=? WHERE id=?", [$mapped, $o['id']]);
        stock_on_status_change($o['product_id'] ?? 0, $o['qty'] ?? 0, $o['status'], $mapped, $o['id']); // FIFO stock sync
        $ncmOrders[$idx]['status']=$mapped; $syncedCount++;
        $ocode = $o['code'] ?? ('#'.$o['id']);
        if ($mapped==='delivered')      notify("NCM delivered order $ocode ✅", 'delivered', 'ncm.php', 24);
        elseif ($mapped==='returned') {
          /* NCM discounts the delivery charge into a return fee on return — capture it as the sale's charge,
             but only if a charge isn't already set manually (never overwrite a human-entered number) */
          if (!(isset($o['cancel_charge']) && (float)$o['cancel_charge']>0)) {
            $rc = ncm_return_charge($nid, isset($o['delivery_charge'])?(float)$o['delivery_charge']:null);
            if ($rc!==null && $rc>0) { try { q("UPDATE orders SET cancel_charge=? WHERE id=?",[$rc,$o['id']]); } catch (Exception $e) {} }
          }
          notify("NCM returned order $ocode ↩️", 'ncm', 'ncm.php', 24);
        }
        elseif ($mapped==='cancelled')  notify("NCM cancelled order $ocode", 'ncm', 'ncm.php', 24);
      } catch (Exception $e) {}
    }
  }
}


/* NCM comments feed → map ncm id → latest comment + no-response flag */
$bulk=[]; $bulkErr=''; $commentByOrder=[]; $todayComments=[]; $today_ymd=date('Y-m-d');
if ($connected) {
  try {
    $bulk = ncm()->bulkComments(); if(isset($bulk['detail'])) $bulk=[];
    foreach($bulk as $c){ $oid=(string)($c['orderid']??($c['order']??'')); if($oid==='')continue;
      $txt=$c['comments']??($c['comment']??'');
      if(!isset($commentByOrder[$oid])) $commentByOrder[$oid]=['text'=>$txt,'time'=>$c['added_time']??($c['addedTime']??''),'flag'=>ncm_no_response($txt)];
      $tstr=$c['added_time']??($c['addedTime']??''); $ts=strtotime((string)$tstr);
      if($ts && date('Y-m-d',$ts)===$today_ymd){ $todayComments[]=$c;
        $by=strtolower((string)($c['addedBy']??''));
        if(strpos($by,'vendor')===false){ /* a comment from NCM's side awaiting our reply */
          notify("NCM #$oid: new comment — reply needed", 'comment', 'ncm.php?comments='.$oid, 12);
        }
      }
    }
  } catch (Exception $e) { $bulkErr=$e->getMessage(); }
}
/* full-day scan cache (all of today's comments, beyond the ~25 the bulk feed returns) */
$cmtScan = kv_get('ncm_today_comments'); $cmtScanInfo='';
if ($cmtScan && ($cmtScan['v']['date'] ?? '') === $today_ymd && is_array($cmtScan['v']['items'] ?? null)) {
  $full = $cmtScan['v']['items'];
  if (count($full) >= count($todayComments)) {
    $todayComments = $full;
    $cmtScanInfo = 'full scan '.date('H:i', strtotime($cmtScan['at'])).' · '.(int)($cmtScan['v']['scanned'] ?? 0).' orders checked';
  }
}

/* ---- analyse each order: age, final, aging, at-risk ---- */
$today=new DateTime('today');
$agingDays  = max(1,(int)setting('ncm_aging_days',5));
$atriskDays = max(1,(int)setting('ncm_atrisk_days',4));
$midDays    = max(1,(int)round($agingDays*0.6));
$rows=[]; $mTotal=count($ncmOrders); $mDelivered=0;$mTransit=0;$mPickup=0;$mReturned=0;$mAging=0;$mRisk=0;$mRetCheck=0;
foreach($ncmOrders as $o){
  $nid=(string)($o['ncm_order_id']??'');
  $hasLive = ($nid!=='' && isset($liveStatus[$nid]));
  $status  = $hasLive ? $liveStatus[$nid] : ucfirst($o['status']);
  $sl=strtolower($status);
  $oFlag = (int)($o['ncm_return_flag'] ?? 0);
  if ($hasLive) {
    $rstage  = ncm_return_stage($status);                        /* none | progress | final | ambiguous */
    /* order already RETURNED in our books: show that truth, not NCM's "Delivered (to vendor)" */
    if (($o['status']??'')==='returned') { $status='Returned to Vendor'; $sl=strtolower($status); $rstage='final'; }
    /* returned parcel handed back to vendor: NCM says plain "Delivered" but our flag knows better */
    elseif ($rstage==='none' && $oFlag===1 && strpos($sl,'deliver')!==false && strpos($sl,'sent')===false && strpos($sl,'out for')===false) {
      $rstage='progress'; $status.=' (back to vendor?)'; $sl=strtolower($status);
    }
    $retFlag = ($rstage==='progress' || $rstage==='ambiguous') && $oFlag!==2;   /* 2 = you already confirmed */
  } else {
    /* no live NCM data right now — trust our own record, but honour a flag stored by cron */
    $rstage  = ($o['status']==='returned') ? 'final' : 'none';
    $retFlag = ($oFlag===1);
  }
  $final = (strpos($sl,'deliver')!==false && strpos($sl,'sent')===false && $rstage==='none') || strpos($sl,'cancel')!==false || $rstage==='final';
  $inDelivery = strpos($sl,'dispatch')!==false||strpos($sl,'sent for delivery')!==false||strpos($sl,'arrived')!==false||strpos($sl,'out for')!==false||strpos($sl,'sent to vendor')!==false||strpos($sl,'ship')!==false;   /* local 'shipped' counts as in-transit too */
  $pickupPhase = strpos($sl,'pickup')!==false||strpos($sl,'created')!==false||strpos($sl,'collect')!==false||$sl==='pending'||$sl==='processing';
  $age = $o['order_date'] ? (int)$today->diff(new DateTime($o['order_date']))->days : 0;
  $cmt = $commentByOrder[$nid] ?? null;
  $noResp = $cmt['flag'] ?? false;
  $aging  = !$final && $age>=$agingDays;
  $atRisk = !$final && ($noResp || ($inDelivery && $age>=$atriskDays));

  if(strpos($sl,'deliver')!==false && strpos($sl,'sent')===false && $rstage==='none') $mDelivered++;
  elseif($rstage==='final'||strpos($sl,'cancel')!==false) $mReturned++;
  elseif($inDelivery) $mTransit++;
  elseif($pickupPhase) $mPickup++;
  if($aging)$mAging++; if($atRisk)$mRisk++; if($retFlag)$mRetCheck++;

  $rows[]=compact('o','nid','status','sl','final','inDelivery','pickupPhase','age','cmt','noResp','aging','atRisk','rstage','retFlag');
}
$returnRate = $mTotal? round($mReturned/$mTotal*100,1):0;
/* COD still to collect = value of NCM orders not yet delivered/cancelled/returned that are COD */
$mCodPending=0;
foreach($rows as $r){ $o=$r['o']; if(!$r['final'] && strtolower((string)$o['payment_type'])==='cod') $mCodPending += (float)$o['sell_price']*(int)$o['qty']; }
/* card/tab toggle helper: clicking the active filter switches back to All */
$f = $_GET['f'] ?? 'all';
$tHref = function($k) use ($f){ return 'ncm.php?f='.($f===$k?'all':$k).'#orders'; };
$tOn   = function($k) use ($f){ return $f===$k ? ' on' : ''; };

/* alert lists */
$agingList=array_filter($rows,fn($r)=>$r['aging']);
$riskList =array_filter($rows,fn($r)=>$r['atRisk']);

/* generate follow-up notifications for at-risk / aging orders (deduped) */
foreach($riskList as $r){ $oc=$r['o']['code']??$r['nid'];
  if($r['noResp']) notify("Follow up: customer not responding on order $oc", 'alert', 'ncm.php?comments='.$r['nid'], 24);
}
foreach($agingList as $r){ $oc=$r['o']['code']??$r['nid'];
  notify("Order $oc is ".$r['age']." days old, still not delivered", 'alert', 'ncm.php', 24);
}

/* tracking view */
$trackId=$_GET['track']??''; $trackHistory=null;$trackErr='';
if($trackId!==''){ try{$trackHistory=ncm()->statusHistory((int)$trackId);}catch(Exception $e){$trackErr=$e->getMessage();} }

/* full order detail view (customer + courier + everything NCM returns) */
$commentsId=$_GET['comments']??''; $thread=null;$threadErr='';$detail=null;$detailErr='';$detailHist=null;$localOrder=null;
if($commentsId!==''){
  try{$thread=ncm()->comments((int)$commentsId);}catch(Exception $e){$threadErr=$e->getMessage();}
  try{$detail=ncm()->order((int)$commentsId);}catch(Exception $e){$detailErr=$e->getMessage();}
  try{$detailHist=ncm()->statusHistory((int)$commentsId);}catch(Exception $e){}
  try{$localOrder=row("SELECT o.*, p.name AS product_name, c.name AS courier_name FROM orders o LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id WHERE o.ncm_order_id=? LIMIT 1",[$commentsId]);}catch(Exception $e){}

  /* opt-in AI auto-reply (works free with built-in templates; smarter if an API key is set) */
  if ($thread && strtolower((string)setting('ai_auto_reply','')) === 'yes') {
    $lastc = end($thread); $by = strtolower((string)($lastc['addedBy'] ?? ''));
    if ($lastc && strpos($by,'vendor') === false) {
      try {
        $ctx = $localOrder
          ? "Order {$localOrder['code']}, customer {$localOrder['customer']}, address {$localOrder['address']}, status {$localOrder['status']}"
          : "NCM order $commentsId";
        $reply = ai_ncm_reply($lastc['comments'] ?? '', $ctx);
        if ($reply !== '') {
          ncm()->addComment((int)$commentsId, $reply);
          notify("AI auto-replied to customer on NCM #$commentsId", 'comment', 'ncm.php?comments='.$commentsId, 6);
          $thread = ncm()->comments((int)$commentsId);
        }
      } catch (Exception $e) {}
    }
  }
}

/* helpers to render any NCM field */
function ncm_label($k){ return ucwords(str_replace(['_','-'],' ',(string)$k)); }
function ncm_val($k,$v){
  if(is_bool($v)) return $v?'<span class="pill p-green">Yes</span>':'<span class="pill p-grey">No</span>';
  if($v===null||$v==='') return '<span class="muted">—</span>';
  if(is_array($v)) return e(json_encode($v));
  if(preg_match('/charge|cod|amount|price|fee/i',(string)$k) && is_numeric($v)) return money($v);
  return e((string)$v);
}

/* rate calc */
$rateResult=null;$rateErr='';
if(isset($_GET['rate_from'],$_GET['rate_to'],$_GET['rate_type'])){
  try{ $types=ncm_delivery_types(); $rv=$types[$_GET['rate_type']]['rate']??'Pickup/Collect';
    $rr=ncm()->rate($_GET['rate_from'],$_GET['rate_to'],$rv); $rateResult=$rr['charge']??(is_numeric($rr)?$rr:json_encode($rr)); }
  catch(Exception $e){$rateErr=$e->getMessage();}
}
?>
<style>
.row-danger td{background:var(--red-bg)!important}
.row-warn td{background:var(--amber-bg)!important}
.age-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800}
.age-old{background:var(--red-bg);color:var(--red)}.age-mid{background:var(--amber-bg);color:var(--amber)}.age-ok{background:var(--surface-2);color:var(--muted)}
.legend{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--muted);margin-top:10px}
.legend span{display:inline-flex;align-items:center;gap:6px}.dot{width:11px;height:11px;border-radius:3px;display:inline-block}
</style>

<div class="ncmv3">
<!-- ===== NCM hero ===== -->
<div class="ncm-hero">
  <div class="nh-left">
    <h1>🚚 <span class="grad">NCM Courier Center</span></h1>
    <p>Live booking · tracking · COD · return prevention</p>
    <div style="margin-top:10px"><a class="btn btn-primary" href="ncm_comments.php" style="font-size:13px;padding:10px 18px">💬 Comment Center<?php
      $ccNeeds=0; foreach(($todayComments??[]) as $tc){ $tb=strtolower((string)($tc['addedBy']??'')); if(strpos($tb,'vendor')===false) $ccNeeds++; }
      echo $ccNeeds? ' <span style="background:rgba(255,255,255,.25);border-radius:99px;padding:1px 9px;font-size:11px;margin-left:6px">'.$ccNeeds.' need reply</span>':'';
    ?></a></div>
    <div class="nh-status">
      <?php if(!ncm()->configured()): ?><span class="live off"><span class="d"></span> API key missing — <a href="settings.php">add in Settings</a></span>
      <?php elseif($connected): ?><span class="live"><span class="d"></span> LIVE · Connected · <?= count($branchNames) ?> branches · Vendor <?= e(setting('vendor_id','')) ?></span>
      <?php else: ?><span class="live off"><span class="d"></span> Connection failed — <?= e($connErr) ?></span><?php endif; ?>
    </div>
  </div>
  <div class="nh-actions">
    <a class="btn nh-ghost" href="ncm.php">🔄 Sync Now</a>
    <?php if($connected): ?><button class="btn nh-cta" onclick="openBook()">＋ Book Parcel</button><?php endif; ?>
  </div>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<?php if($syncedCount>0): ?><div class="flash" style="background:var(--blue-bg);color:var(--blue)">🔄 <?= $syncedCount ?> order<?= $syncedCount>1?'s':'' ?> auto-updated from NCM (status synced to Sales).</div><?php endif; ?>

<!-- metrics -->
<?php
/* units delivered via NCM (from ERP orders assigned to the NCM courier) */
$ncmUnits=(int)val("SELECT COALESCE(SUM(o.qty),0) FROM orders o JOIN couriers c ON c.id=o.courier_id WHERE LOWER(c.name)='ncm' AND o.status='delivered'");
?>
<?php $pDel=$mTotal?round($mDelivered/$mTotal*100):0; $pTr=$mTotal?round($mTransit/$mTotal*100):0; $pAg=$mTotal?round($mAging/$mTotal*100):0; $pRk=$mTotal?round($mRisk/$mTotal*100):0; ?>
<div class="mgrid">
  <a class="metric blue mlink<?= $tOn('all') ?>" href="<?= $tHref('all') ?>"><span class="g">📦</span><div class="mv"><?= $mTotal ?></div><div class="ml">NCM Orders</div><div class="bar"><i style="width:100%;background:linear-gradient(90deg,#3b82f6,#6366f1)"></i></div></a>
  <a class="metric green mlink<?= $tOn('delivered') ?>" href="<?= $tHref('delivered') ?>"><span class="g">✅</span><div class="mv"><?= $mDelivered ?></div><div class="ml">Delivered · <?= $pDel ?>% · <?= number_format($ncmUnits) ?> pcs</div><div class="bar"><i style="width:<?= $pDel ?>%;background:#22c55e"></i></div></a>
  <a class="metric amber mlink<?= $tOn('transit') ?>" href="<?= $tHref('transit') ?>"><span class="g">🚚</span><div class="mv"><?= $mTransit ?></div><div class="ml">In Transit · <?= $pTr ?>%</div><div class="bar"><i style="width:<?= $pTr ?>%;background:#f59e0b"></i></div></a>
  <a class="metric indigo mlink<?= $tOn('pickup') ?>" href="<?= $tHref('pickup') ?>"><span class="g">📮</span><div class="mv"><?= $mPickup ?></div><div class="ml">Pending Pickup</div><div class="bar"><i style="width:<?= $mTotal?round($mPickup/$mTotal*100):0 ?>%;background:#6366f1"></i></div></a>
  <a class="metric red mlink<?= $tOn('returned') ?>" href="<?= $tHref('returned') ?>"><span class="g">↩️</span><div class="mv"><?= $returnRate ?>%</div><div class="ml">Return Rate · <?= $mReturned ?> orders</div><div class="bar"><i style="width:<?= min(100,$returnRate) ?>%;background:#ef4444"></i></div></a>
  <a class="metric amber mlink<?= $tOn('retcheck') ?>" href="<?= $tHref('retcheck') ?>"><span class="g">↩</span><div class="mv"><?= $mRetCheck ?></div><div class="ml">Return? · confirm before counting</div><div class="bar"><i style="width:<?= $mTotal?round($mRetCheck/$mTotal*100):0 ?>%;background:#f59e0b"></i></div></a>
  <a class="metric orange mlink<?= $tOn('aging') ?>" href="<?= $tHref('aging') ?>"><span class="g">⏳</span><div class="mv"><?= $mAging ?></div><div class="ml">Aging <?= $agingDays ?>+ days</div><div class="bar"><i style="width:<?= $pAg ?>%;background:#ea580c"></i></div></a>
  <a class="metric purple mlink<?= $tOn('risk') ?>" href="<?= $tHref('risk') ?>"><span class="g">⚠️</span><div class="mv"><?= $mRisk ?></div><div class="ml">At Risk · not responding</div><div class="bar"><i style="width:<?= $pRk ?>%;background:#8b5cf6"></i></div></a>
  <a class="metric teal mlink<?= $tOn('cod') ?>" href="<?= $tHref('cod') ?>"><span class="g">💰</span><div class="mv" style="font-size:19px"><?= money($mCodPending) ?></div><div class="ml">COD to Collect</div><div class="bar"><i style="width:<?= $mTotal?round(min(100,($mTransit+$mPickup)/max(1,$mTotal)*100)):0 ?>%;background:#14b8a6"></i></div></a>
</div>

<!-- ===== ACTION NEEDED: now its own command center (ncm_action.php) ===== -->
<?php if($agingList || $riskList): $anCnt=count(array_unique(array_map(fn($r)=>$r['o']['id'], array_merge($riskList,$agingList)))); ?>
<a href="ncm_action.php" style="display:flex;align-items:center;gap:14px;margin-top:20px;background:linear-gradient(90deg,#fee2e2,#fef3c7);border:1.5px solid #fca5a5;border-radius:16px;padding:14px 18px;text-decoration:none;color:inherit">
  <span style="font-size:26px">🚨</span>
  <span style="flex:1;min-width:0"><b style="color:var(--red)"><?= $anCnt ?> order<?= $anCnt>1?'s':'' ?> need action to prevent returns</b>
    <span class="muted" style="display:block;font-size:12px">no-response customers · aging parcels · call them before NCM sends the parcel back</span></span>
  <span class="btn btn-primary" style="white-space:nowrap">Open Action Center →</span>
</a>
<?php endif; ?>

<!-- all NCM orders -->
<?php
  /* ---- collapse multi-product orders into ONE parcel line (same order_group) ---- */
  $grouped=[]; $seenGroup=[];
  foreach($rows as $r){
    $g = (is_array($r['o']) && array_key_exists('order_group',$r['o'])) ? trim((string)$r['o']['order_group']) : '';
    if($g===''){ $grouped[]=$r; continue; }
    if(isset($seenGroup[$g])){                       /* fold into the parcel's head row */
      $gi=$seenGroup[$g];
      $grouped[$gi]['_items'][]=$r['o'];
      /* sum COD across the parcel (prepaid rows add 0) */
      if(strtolower((string)$r['o']['payment_type'])==='cod')
        $grouped[$gi]['_codsum'] += (float)$r['o']['sell_price']*(int)$r['o']['qty'];
      /* if the current head has no NCM id but this sibling does, adopt it (one parcel, one id) */
      if(($grouped[$gi]['nid']??'')==='' && ($r['nid']??'')!==''){
        $grouped[$gi]['nid']=$r['nid'];
        $grouped[$gi]['status']=$r['status'];
        $grouped[$gi]['o']['ncm_order_id']=$r['o']['ncm_order_id'];
      }
      /* keep the LOWEST-id order as the parcel head (its code = booking vref, matches Sales) */
      if((int)$r['o']['id'] < (int)$grouped[$gi]['o']['id']){
        $keepItems=$grouped[$gi]['_items']; $keepCod=$grouped[$gi]['_codsum'];
        $keepNid=$grouped[$gi]['nid']??''; $keepSt=$grouped[$gi]['status']??'';
        $r['_group']=$g; $r['_items']=$keepItems; $r['_codsum']=$keepCod;
        if(($r['nid']??'')==='' && $keepNid!==''){ $r['nid']=$keepNid; $r['status']=$keepSt; $r['o']['ncm_order_id']=$keepNid; }
        $grouped[$gi]=$r;
      }
      continue;
    }
    /* first row of this group becomes the parcel line */
    $r['_group']=$g;
    $r['_items']=[$r['o']];
    $r['_codsum']=(strtolower((string)$r['o']['payment_type'])==='cod' ? (float)$r['o']['sell_price']*(int)$r['o']['qty'] : 0);
    $grouped[]=$r;
    $seenGroup[$g]=count($grouped)-1;
  }
  $rows=$grouped;   /* downstream (filter, tabs, table) now sees one row per parcel */

  $filtered = array_values(array_filter($rows, function($r) use($f){
    switch($f){
      case 'aging':     return $r['aging'];
      case 'risk':      return $r['atRisk'];
      case 'transit':   return $r['inDelivery'];
      case 'pickup':    return !$r['final'] && !$r['inDelivery'] && $r['pickupPhase'];
      case 'cod':       return !$r['final'] && strtolower((string)$r['o']['payment_type'])==='cod';
      case 'delivered': return ($r['o']['status']??'')!=='returned' && !$r['retFlag'] && strpos(strtolower($r['status']),'deliver')!==false && strpos(strtolower($r['status']),'sent')===false;
      case 'returned':  return ($r['o']['status']??'')==='returned' || $r['rstage']==='final'||strpos(strtolower($r['status']),'cancel')!==false;
      case 'retcheck':  return $r['retFlag'];
      default:          return true;
    }
  }));
  $tabs=['all'=>'All','pickup'=>'Pending Pickup','transit'=>'In Transit','delivered'=>'Delivered','cod'=>'COD to Collect','aging'=>'Aging','risk'=>'At Risk','retcheck'=>'↩ Return? (confirm)','returned'=>'↩ Returned to Vendor'];
  $tabCount=function($k) use($rows){ return count(array_filter($rows,function($r) use($k){switch($k){
    case 'aging':return $r['aging']; case 'risk':return $r['atRisk']; case 'transit':return $r['inDelivery'];
    case 'pickup':return !$r['final'] && !$r['inDelivery'] && $r['pickupPhase'];
    case 'cod':return !$r['final'] && strtolower((string)$r['o']['payment_type'])==='cod';
    case 'delivered':return ($r['o']['status']??'')!=='returned' && !$r['retFlag'] && strpos(strtolower($r['status']),'deliver')!==false && strpos(strtolower($r['status']),'sent')===false;
    case 'returned':return ($r['o']['status']??'')==='returned' || $r['rstage']==='final'||strpos(strtolower($r['status']),'cancel')!==false;
    case 'retcheck':return $r['retFlag'];
    default:return true;}})); };
?>
<div class="panel" style="margin-top:20px" id="orders">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px"><h2>📦 All NCM Orders <?= $f!=="all"?"<span class=\"pill p-blue\" style=\"font-size:10px\">".e($tabs[$f]??$f)." filter</span>":"" ?></h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input id="ncmSearch" class="ncm-search" placeholder="🔍 Search order, name, phone, NCM #…" oninput="ncmFilter()">
      <select id="ordPer" class="pgsel" onchange="PG.orders.per=parseInt(this.value)||25;PG.orders.page=1;renderOrders()" title="rows per page">
        <option value="25">25 / page</option><option value="50">50 / page</option><option value="100">100 / page</option><option value="100000">All</option>
      </select>
      <button class="btn btn-sm" onclick="exportNCM()">⬇ CSV</button>
    </div>
  </div>
  <div class="ncm-tabs">
    <?php foreach($tabs as $k=>$lbl): $c=$tabCount($k); ?>
      <a class="ntab <?= $f===$k?'on':'' ?>" href="<?= $tHref($k) ?>"><?= e($lbl) ?> <span class="ntab-n"><?= $c ?></span></a>
    <?php endforeach; ?>
  </div>
  <div class="bkbar" id="bkBar" style="display:none">
    <b><span id="bkN">0</span> selected</b>
    <label>Pickup: <input name="x" id="bkFb" list="brlist" value="<?= e(setting('ncm_from_branch','TINKUNE')) ?>" style="width:130px"></label>
    <label>Type: <select id="bkDt"><?php foreach(ncm_delivery_types() as $k=>$t) echo '<option value="'.e($k).'">'.e($t['label']).'</option>'; ?></select></label>
    <button class="btn btn-sm" onclick="bkAll()">☑ Select All Pending</button>
    <button class="btn btn-sm btn-primary" onclick="bkReview()">🚚 Bulk Book on NCM…</button>
    <button class="btn btn-sm" onclick="bkClear()">✕ Clear</button>
    <span class="muted" style="font-size:11px">destination branch auto-detected from each address</span>
  </div>
  <div style="margin:0 18px 12px"><a class="btn btn-sm" href="ncm_comments.php">💬 Comment Center</a> <button class="btn btn-sm" onclick="document.getElementById('scanModal').classList.add('open');document.body.classList.add('modal-open')">🔗 Scan &amp; Match NCM by Phone</button>
    <span class="muted" style="font-size:11px;margin-left:8px">for orders booked directly on the NCM portal</span></div>
<form method="post" id="bkForm" style="display:none">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="book_bulk">
    <input type="hidden" name="ids" id="bkIds"><input type="hidden" name="fbranch" id="bkFb2"><input type="hidden" name="delivery_type" id="bkDt2">
  </form>
  <div class="table-wrap tw-sticky"><table class="tbl">
    <thead><tr><th style="width:30px"></th><th>Order</th><th>Customer</th><th>Phone</th><th class="right">COD</th><th>Age</th><th>NCM ID</th><th>Status</th><th></th></tr></thead>
    <tbody id="ncmRows">
    <?php foreach($filtered as $r){ $o=$r['o']; $cls=$r['aging']?'row-danger':($r['atRisk']?'row-warn':''); $ap=$r['age']>=$agingDays?'age-old':($r['age']>=$midDays?'age-mid':'age-ok'); ?>
      <tr class="<?= $cls ?>" data-s="<?= e(strtolower($o['code'].' '.$o['customer'].' '.$o['phone'].' '.$r['nid'].' '.$r['status'])) ?>">
        <td><?php if(!$r['nid'] && $connected): ?><input type="checkbox" class="bkc" value="<?= (int)$o['id'] ?>" data-code="<?= e($o['code']) ?>" data-cust="<?= e($o['customer']) ?>" data-addr="<?= e($o['address']) ?>" data-cod="<?= !empty($r['_items'])&&count($r['_items'])>1 ? (float)($r['_codsum']??0) : (strtolower((string)$o['payment_type'])==='cod' ? (float)$o['sell_price']*(int)$o['qty'] : 0) ?>" data-pending="<?= in_array(strtolower((string)$o['status']),['pending','processing'])?1:0 ?>" onchange="bkCount()"><?php endif; ?></td>
        <td><b><?= e($o['code']) ?></b><?php if(!empty($r['_items'])&&count($r['_items'])>1): ?><br><span class="pill p-blue" style="font-size:9px;margin-top:3px;display:inline-block" title="Multi-product parcel — <?= count($r['_items']) ?> items, one delivery, one NCM booking">📦 <?= count($r['_items']) ?> items</span><?php endif; ?></td>
        <td><div class="ncm-cust"><span class="cav"><?= e(strtoupper(mb_substr(trim((string)$o['customer'])?:'?',0,1))) ?></span>
          <span><span class="cn"><?= e($o['customer']?:'—') ?></span><span class="ca"><?= e(mb_strimwidth((string)$o['address'],0,34,'…')) ?></span>
          <?php if(!empty($r['_items'])&&count($r['_items'])>1): $pl=array_map(function($it){return ($it['product_name']?:'Goods').' x'.(int)$it['qty'];}, $r['_items']); ?><span class="ca" style="color:#4f7cf7;font-weight:600" title="Items in this parcel"><?= e(implode(', ',$pl)) ?></span><?php endif; ?></span></div></td>
        <td class="num nowrap"><?= e($o['phone']?:'—') ?>
          <?php if($o['phone']): ?><button class="mini" title="Copy" onclick="cpy('<?= e($o['phone']) ?>',this)">⧉</button><a class="mini" title="WhatsApp" target="_blank" rel="noopener" href="https://wa.me/<?= e(wa_phone($o['phone'])) ?>">💬</a><?php endif; ?></td>
        <td class="num right"><b><?= money(!empty($r['_items'])&&count($r['_items'])>1 ? ($r['_codsum'] ?? ($o['sell_price']*$o['qty'])) : $o['sell_price']*$o['qty']) ?></b><?php if(!empty($r['_items'])&&count($r['_items'])>1): ?><br><span class="muted" style="font-size:9.5px">parcel total</span><?php endif; ?></td>
        <td><span class="age-pill <?= $ap ?>"><?= $r['age'] ?>d</span></td>
        <td><?= $r['nid']?'<a class="ncm-link" href="'.e(ncm_portal_url($r['nid'])).'" target="_blank" rel="noopener" title="Open in NCM portal"><b>'.e($r['nid']).'</b> ↗</a>':'<span class="muted">—</span>' ?></td>
        <td><span class="pill <?= ncm_status_class($r['status']) ?>"><?= e($r['status']) ?></span>
          <?php if($o['status']==='returned'): ?><div class="muted" style="font-size:10.5px;margin-top:3px">return charge: <b><?= (float)($o['cancel_charge']??0)>0 ? 'Rs. '.number_format((float)$o['cancel_charge']) : '—' ?></b></div><?php endif; ?>
          <?php if($r['retFlag']): ?><div style="margin-top:4px"><span class="pill p-amber" style="font-size:9.5px" title="NCM's status mentions a return, but a failed attempt is usually retried the next day. This is NOT counted as returned until you confirm.">↩ return? not counted yet</span></div><?php endif; ?></td>
        <td class="right nowrap">
          <?php if($o['status']==='delivered' && !$r['retFlag']): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('This order shows DELIVERED but was actually RETURNED to you?\n\nIt will be counted as returned: revenue removed, NCM return charge added as loss, stock restored.')">
              <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="confirm_return"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <button class="btn btn-sm" title="Wrongly shown as delivered — mark it returned">↩ Returned</button></form>
          <?php endif; ?>
          <?php if($r['retFlag'] || $o['status']==='returned'): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Mark this order as RETURNED? Do this only when the parcel is actually back with you.')">
              <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="confirm_return"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <button class="btn btn-sm" title="Parcel is back with us — count it as returned">✔ Returned</button></form>
            <?php if(stripos($r['status'],'deliver')!==false): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Confirm the CUSTOMER really received this order? It will count as delivered + paid.')">
              <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="confirm_delivered"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <button class="btn btn-sm" style="color:var(--green)" title="The customer really received it — count as delivered">✔ Delivered</button></form>
            <?php endif; ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="keep_active"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <button class="btn btn-sm" title="NCM will retry delivery — keep this order live">↩ Still active</button></form>
          <?php endif; ?>
          <?php if($r['nid']): ?><a class="btn btn-sm btn-primary" href="<?= e(ncm_portal_url($r['nid'])) ?>" target="_blank" rel="noopener">View ↗</a> <a class="btn btn-sm" href="ncm.php?comments=<?= e($r['nid']) ?>">Thread</a> <a class="btn btn-sm" href="ncm.php?track=<?= e($r['nid']) ?>">Track</a>
          <?php elseif($connected): ?><button class="btn btn-sm btn-primary" onclick='bookFor(<?php
            if(!empty($r["_items"]) && count($r["_items"])>1){
              $pl=array_map(function($it){return ($it["product_name"]?:"Goods")." x".(int)$it["qty"];}, $r["_items"]);
              echo json_encode(["id"=>$o["id"],"name"=>$o["customer"],"phone"=>$o["phone"],"address"=>$o["address"],"cod"=>(float)($r["_codsum"]??0),"ref"=>$o["code"],"package"=>implode(", ",$pl)], JSON_HEX_APOS|JSON_HEX_QUOT);
            } else {
              echo json_encode(["id"=>$o["id"],"name"=>$o["customer"],"phone"=>$o["phone"],"address"=>$o["address"],"cod"=>$o["sell_price"]*$o["qty"],"ref"=>$o["code"],"package"=>trim(($o["product_name"]?:"Goods")." x".(int)$o["qty"])], JSON_HEX_APOS|JSON_HEX_QUOT);
            } ?>)'>Book</button> <button class="btn btn-sm" title="Already created on NCM portal? Link it here" onclick='linkFor(<?= (int)$o["id"] ?>,<?= json_encode((string)$o["code"]) ?>,<?= json_encode((string)$o["phone"]) ?>)'>🔗</button><?php endif; ?>
        </td>
      </tr>
    <?php } if(!$filtered) echo '<tr><td colspan="9"><div class="empty">No orders in this view.</div></td></tr>'; ?>
    </tbody>
  </table></div>
  <div class="legend" style="padding:0 18px 14px">
    <span><span class="dot" style="background:var(--red-bg);border:1px solid var(--red)"></span> <?= $agingDays ?>+ days, not delivered</span>
    <span><span class="dot" style="background:var(--amber-bg);border:1px solid var(--amber)"></span> At risk — customer not responding</span>
  </div>
</div>

<!-- tracking result -->
<?php if($trackId!==''): ?>
<div class="panel" style="margin-top:20px"><div class="panel-head"><h2>📍 Tracking NCM #<?= e($trackId) ?></h2><a class="btn btn-sm" href="ncm.php">✕ Close</a></div>
<div class="panel-body">
<?php if($trackErr): ?><div class="muted"><?= e($trackErr) ?></div>
<?php elseif($trackHistory): foreach(array_reverse($trackHistory) as $h): ?>
  <div class="info-row"><span class="ii b">📦</span><span class="it"><?= e($h['status']??'') ?></span><span class="iv muted" style="font-weight:600"><?= e($h['added_time']??'') ?></span></div>
<?php endforeach; else: ?><div class="muted">No status history found.</div><?php endif; ?>
</div></div>
<?php endif; ?>

<!-- full order detail + comment thread -->
<?php if($commentsId!==''): ?>
<div class="panel" style="margin-top:20px">
  <div class="panel-head"><h2>📋 NCM Order #<?= e($commentsId) ?> — Full Details</h2>
    <span style="display:flex;gap:8px">
      <a class="btn btn-sm btn-primary" href="<?= e(ncm_portal_url($commentsId)) ?>" target="_blank" rel="noopener">Open in NCM Portal ↗</a>
      <a class="btn btn-sm" href="ncm.php">✕ Close</a>
    </span></div>
  <div class="panel-body">

  <?php if($localOrder): ?>
    <div class="dash-sec" style="margin:0 0 10px">Customer & Order (your records)</div>
    <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr;margin-bottom:6px">
      <div><label>Order Code</label><div style="font-weight:700"><?= e($localOrder['code']) ?></div></div>
      <div><label>Customer</label><div style="font-weight:700"><?= e($localOrder['customer']) ?></div></div>
      <div><label>Phone</label><div style="font-weight:700"><?php if($localOrder['phone']): ?><a href="tel:<?= e($localOrder['phone']) ?>" style="color:var(--brand)"><?= e($localOrder['phone']) ?></a><?php else: ?>—<?php endif; ?></div></div>
      <div class="full"><label>Address</label><div style="font-weight:700"><?= e($localOrder['address']?:'—') ?></div></div>
      <div><label>Product</label><div style="font-weight:700"><?= e($localOrder['product_name']?:'—') ?> × <?= (int)$localOrder['qty'] ?></div></div>
      <div><label>Order Value</label><div style="font-weight:700"><?= money($localOrder['sell_price']*$localOrder['qty']) ?></div></div>
      <div><label>Zone / Delivery</label><div style="font-weight:700"><?= e(ucfirst($localOrder['zone'])) ?> · <?= money($localOrder['delivery_charge']) ?></div></div>
    </div>
  <?php endif; ?>

    <div class="dash-sec" style="margin:16px 0 10px">NCM Shipment Details <span style="text-transform:none;letter-spacing:0;font-weight:600">(everything NCM returns)</span></div>
  <?php if($detailErr): ?><div class="muted" style="margin-bottom:6px"><?= e($detailErr) ?></div>
  <?php elseif($detail && is_array($detail)): ?>
    <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr">
      <?php foreach($detail as $k=>$v): if(is_array($v)) continue; ?>
        <div><label><?= e(ncm_label($k)) ?></label><div style="font-weight:700"><?= ncm_val($k,$v) ?></div></div>
      <?php endforeach; ?>
    </div>
    <?php foreach($detail as $k=>$v): if(!is_array($v)||!$v) continue; ?>
      <div style="margin-top:12px"><label style="font-size:12px;color:var(--muted);font-weight:700"><?= e(ncm_label($k)) ?></label>
        <div class="muted" style="font-size:12px;background:var(--surface-2);border-radius:8px;padding:8px 10px;margin-top:4px"><?= e(json_encode($v)) ?></div></div>
    <?php endforeach; ?>
  <?php else: ?><div class="muted">No detail returned by NCM for this order.</div><?php endif; ?>

  <?php if($detailHist): ?>
    <div class="dash-sec" style="margin:18px 0 10px">Status Timeline</div>
    <?php foreach(array_reverse($detailHist) as $h): ?>
      <div class="info-row"><span class="ii b">📦</span><span class="it"><span class="pill <?= ncm_status_class($h['status']??'') ?>"><?= e($h['status']??'') ?></span></span><span class="iv muted" style="font-weight:600"><?= e($h['added_time']??'') ?></span></div>
    <?php endforeach; ?>
  <?php endif; ?>

    <div class="dash-sec" style="margin:18px 0 10px">💬 Conversation with NCM</div>
    <div class="chat-shell">
      <div class="chat-scroll" id="chatScroll">
      <?php if($threadErr): ?><div class="chat-empty"><?= e($threadErr) ?></div>
      <?php elseif($thread): foreach($thread as $c): $by=$c['addedBy']??''; $mine=(stripos($by,'vendor')!==false); ?>
        <div class="msg <?= $mine?'me':'them' ?>">
          <span class="mav" title="<?= e($by?:'NCM') ?>"><?= $mine?'Y':'N' ?></span>
          <span class="mbody">
            <span class="mtext"><?= e($c['comments']??'') ?></span>
            <span class="mmeta"><?= e($mine?'You':($by?:'NCM')) ?> · <?= e($c['added_time']??'') ?></span>
          </span>
        </div>
      <?php endforeach; else: ?><div class="chat-empty">🗨️ No comments yet — start the conversation below.</div><?php endif; ?>
      </div>

      <div class="chat-chips">
        <?php foreach([
          'Customer confirmed — please re-attempt delivery.',
          'Please call the customer again, phone was off earlier.',
          'Customer will collect from branch — please hold.',
          'Address confirmed as correct.',
          'Customer will pay full COD on delivery.',
          'Please deliver tomorrow, customer requested.',
          'Customer not responding to us either — please hold 1 day.',
          'Return to us — customer refused the order.',
        ] as $qr): ?>
          <button type="button" class="chip" onclick="setThr(<?= htmlspecialchars(json_encode($qr),ENT_QUOTES) ?>)"><?= e(mb_strimwidth($qr,0,30,'…')) ?></button>
        <?php endforeach; ?>
      </div>

      <form method="post" class="chat-composer">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="comment">
        <input type="hidden" name="ncm_id" value="<?= e($commentsId) ?>"><input type="hidden" name="back" value="comments=<?= e($commentsId) ?>">
        <textarea name="comment" id="thr_input" required rows="1" placeholder="Write a message to NCM… (Enter to send, Shift+Enter = new line)"></textarea>
        <button type="button" class="btn" onclick="aiDraft('<?= e($commentsId) ?>','thr_input',this)" title="AI draft from last customer comment">🤖</button>
        <button class="btn btn-primary send">Send ➤</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- rate + track tools -->
<div class="grid cols-2" style="align-items:start;margin-top:20px">
  <div class="panel"><div class="panel-head"><h2>💰 Delivery Rate Calculator</h2></div><div class="panel-body">
    <?php if(!$connected): ?><div class="muted">Connect to NCM to calculate rates.</div>
    <?php else: ?>
    <form method="get" class="form-grid" style="grid-template-columns:1fr 1fr">
      <div><label>From branch</label><input name="rate_from" list="brlist" value="<?= e($_GET['rate_from']??setting('ncm_from_branch','TINKUNE')) ?>"></div>
      <div><label>To branch</label><input name="rate_to" list="brlist" value="<?= e($_GET['rate_to']??'') ?>"></div>
      <div><label>Delivery Type</label><select name="rate_type"><?php foreach(ncm_delivery_types() as $k=>$t) echo '<option value="'.e($k).'"'.((($_GET['rate_type']??'')===$k)?' selected':'').'>'.e($t['label']).'</option>'; ?></select></div>
      <div style="display:flex;align-items:flex-end"><button class="btn btn-primary" style="width:100%">Calculate</button></div>
    </form>
    <?php if($rateErr): ?><div class="flash" style="background:var(--red-bg);color:var(--red);margin-top:14px"><?= e($rateErr) ?></div>
    <?php elseif($rateResult!==null): ?><div style="margin-top:16px">Estimated charge: <b style="color:var(--brand);font-size:20px"><?= money($rateResult) ?></b></div><?php endif; ?>
    <?php endif; ?>
  </div></div>
  <div class="panel"><div class="panel-head"><h2>📍 Track a Shipment</h2></div><div class="panel-body">
    <form method="get" class="form-grid" style="grid-template-columns:1fr auto">
      <div><label>NCM Order ID</label><input name="track" value="<?= e($trackId) ?>" placeholder="e.g. 1234567"></div>
      <div style="display:flex;align-items:flex-end"><button class="btn btn-primary">Track</button></div>
    </form>
    <p class="muted" style="margin-top:12px;font-size:12.5px">See the full status history of any NCM shipment.</p>
  </div></div>
</div>

<!-- today's NCM comments feed -->
<div class="panel" style="margin-top:20px">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px"><h2>💬 Today's NCM Comments</h2>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <a class="btn btn-sm" href="ncm_comments.php?v=feed">↗ Open Comment Center</a>
      <span class="muted" style="font-size:12px"><b><?= count($todayComments) ?></b> today<?= $cmtScanInfo?' · '.e($cmtScanInfo):' · NCM feed shows only the latest ~25' ?></span>
      <?php if($connected): ?>
      <form method="post" style="display:inline" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='⏳ Fetching…'">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="scan_comments">
        <button class="btn btn-sm btn-primary">🔄 Fetch ALL today's comments</button>
      </form><form style="display:none">
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>NCM Order</th><th>Comment</th><th>By</th><th>Time</th><th></th></tr></thead><tbody id="cmtRows">
    <?php
      $feed = $todayComments ?: $bulk;   // if nothing today, fall back to latest
      if($bulkErr): ?><tr><td colspan="5"><div class="empty"><?= e($bulkErr) ?></div></td></tr>
    <?php elseif($feed): foreach($feed as $c): $oid=(string)($c['orderid']??($c['order']??'')); $flag=ncm_no_response($c['comments']??''); ?>
      <tr data-c="1"<?= $flag?' class="row-warn"':'' ?>>
        <td><b><?= e($oid) ?></b></td>
        <td><?= e($c['comments']??($c['comment']??'')) ?><?= $flag?' <span class="pill p-red" style="font-size:10px">no response</span>':'' ?></td>
        <td class="muted"><?= e($c['addedBy']??'') ?></td>
        <td class="num muted"><?= e($c['added_time']??($c['addedTime']??'')) ?></td>
        <td class="right nowrap"><a class="btn btn-sm btn-primary" href="ncm_comments.php?c=<?= e($oid) ?>">💬 Chat</a> <a class="btn btn-sm" href="ncm.php?comments=<?= e($oid) ?>">View</a> <a class="btn btn-sm" href="<?= e(ncm_portal_url($oid)) ?>" target="_blank" rel="noopener">Portal ↗</a> <button class="btn btn-sm" onclick='replyTo(<?= json_encode($oid) ?>)'>Reply</button></td>
      </tr>
    <?php endforeach; if(!$todayComments && $bulk): ?><tr><td colspan="5" class="muted" style="text-align:center;font-size:12px;padding:10px">No comments today — showing latest instead.</td></tr><?php endif; ?>
    <?php else: ?><tr><td colspan="5"><div class="empty"><?= $connected?'No comments today.':'Connect to NCM to load comments.' ?></div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <div class="pager" id="cmtPager"></div>
</div>

<datalist id="brlist"><?php foreach($branchNames as $bn) echo '<option value="'.e($bn).'">'; ?></datalist>

  <!-- bulk book review — Design B (card sheet) -->
<div class="bb-bg" id="bbBg">
  <div class="bb-sheet">
    <div class="bb-head">
      <div style="min-width:0">
        <div class="bb-title">🚚 Bulk Book on NCM <span class="pill p-blue" id="bbCnt">0 orders</span></div>
        <div class="bb-sub">Pickup <b id="bbFbTxt">—</b> · <b id="bbDtTxt">Door to Door</b> · branch auto-detected from each address</div>
        <div class="bb-bar"><i id="bbBar" style="width:0%"></i></div>
      </div>
      <button type="button" class="bb-x" onclick="bbClose()">✕</button>
    </div>
    <div class="bb-body" id="bbRows"></div>
    <div class="bb-foot">
      <span class="bb-sum" id="bbSummary"></span>
      <button type="button" class="btn" onclick="bbClose()">Cancel</button>
      <button type="button" class="btn btn-primary" id="bbGo" onclick="bbConfirm()">🚚 Confirm &amp; Book</button>
    </div>
  </div>
</div>

<!-- link modal -->
<div class="modal-bg" id="linkModal" style="z-index:99991"><form class="modal" method="post" style="width:460px;max-width:94vw">
  <div class="modal-head"><span>🔗 Link NCM Order</span><span class="mx" onclick="document.getElementById('linkModal').classList.remove('open');document.body.classList.remove('modal-open')">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="link_manual"><input type="hidden" name="order_id" id="lk_oid">
  <div class="modal-body">
    <div class="full"><div class="muted" style="font-size:12px">Order <b id="lk_code"></b> · phone <b id="lk_phone"></b></div></div>
    <div class="full"><label>NCM Order ID (from NCM portal)</label><input name="ncm_id" id="lk_nid" required placeholder="e.g. 812345" inputmode="numeric"></div>
    <div class="full"><label style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" name="force" value="1" style="width:auto"> Link anyway even if the phone doesn't match</label></div>
    <div class="full muted" style="font-size:11.5px">We fetch the NCM order and verify the customer phone matches before linking — the NCM delivery charge is recorded automatically.</div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="document.getElementById('linkModal').classList.remove('open');document.body.classList.remove('modal-open')">Cancel</button><button class="btn btn-primary">🔗 Verify &amp; Link</button></div>
</form></div>

<!-- scan & match modal -->
<div class="modal-bg" id="scanModal" style="z-index:99991"><form class="modal" method="post" style="width:520px;max-width:94vw" onsubmit="this.querySelector('button.btn-primary').disabled=true">
  <div class="modal-head"><span>🔗 Scan &amp; Match NCM by Phone</span><span class="mx" onclick="document.getElementById('scanModal').classList.remove('open');document.body.classList.remove('modal-open')">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="link_scan">
  <div class="modal-body">
    <div class="full"><label>NCM Order IDs or ranges</label>
      <textarea name="ids_text" rows="3" required placeholder="e.g. 812345, 812350-812390" style="width:100%;font:inherit;padding:9px 12px;border:1px solid rgba(148,163,184,.4);border-radius:10px"></textarea></div>
    <div class="full muted" style="font-size:11.5px">Open the NCM portal → note the order IDs you created there (a range like 812350-812390 works). We fetch each one, read the <b>customer phone</b>, and auto-link it to your matching unbooked order — details &amp; delivery charge come along. Up to 100 IDs per run.</div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="document.getElementById('scanModal').classList.remove('open');document.body.classList.remove('modal-open')">Cancel</button><button class="btn btn-primary">🔍 Fetch &amp; Match</button></div>
</form></div>

<!-- booking modal -->
<div class="modal-bg" id="bookModal"><form class="modal" method="post">
  <div class="modal-head"><span>Book Order on NCM</span><span class="mx" onclick="closeBook()">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="book"><input type="hidden" name="order_id" id="bk_order_id" value="">
  <div class="modal-body">
    <div><label>Customer Name</label><input name="name" id="bk_name" required></div>
    <div><label>Phone</label><input name="phone" id="bk_phone" required></div>
    <div><label>Alt Phone</label><input name="phone2"></div>
    <div><label>COD Amount (Rs.)</label><input name="cod_charge" id="bk_cod" type="number" step="any"></div>
    <div class="full"><label>Delivery Address</label><input name="address" id="bk_address"></div>
    <div><label>From Branch (pickup)</label><input name="fbranch" list="brlist" value="<?= e(setting('ncm_from_branch','TINKUNE')) ?>"></div>
    <div><label>To Branch (destination)</label><input name="branch" list="brlist" required></div>
    <div><label>Delivery Type</label><select name="delivery_type"><?php foreach(ncm_delivery_types() as $k=>$t) echo '<option value="'.e($k).'">'.e($t['label']).'</option>'; ?></select></div>
    <div><label>Weight (kg)</label><input name="weight" value="<?= e(setting('ncm_default_weight','1')) ?>"></div>
    <div><label>Package / Contents</label><input name="package" id="bk_pkg" placeholder="e.g. Heel Guard Plus x2"></div>
    <div><label>Your Ref (order code)</label><input name="vref_id" id="bk_ref"></div>
    <div class="full"><label>Instructions</label><input name="instruction" placeholder="Optional note for NCM"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="closeBook()">Cancel</button><button class="btn btn-primary">Book on NCM</button></div>
</form></div>

<!-- reply modal -->
<div class="modal-bg" id="replyModal"><form class="modal" method="post" style="width:460px">
  <div class="modal-head"><span>Notify / Reply to NCM</span><span class="mx" onclick="document.getElementById('replyModal').classList.remove('open')">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="comment"><input type="hidden" name="ncm_id" id="rp_id"><input type="hidden" name="back" id="rp_back" value="">
  <div class="modal-body"><div class="full"><label>Message for NCM Order #<span id="rp_lbl"></span></label>
    <input name="comment" id="rp_text" required placeholder="e.g. Customer confirmed — please re-attempt delivery after 5pm"></div>
    <div class="full"><button type="button" class="btn btn-sm" onclick="aiDraft(document.getElementById('rp_id').value,'rp_text',this)">🤖 AI draft reply</button></div>
    <div class="full"><div class="muted" style="font-size:12px">Quick replies:</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
        <button type="button" class="btn btn-sm" onclick="qr('Customer confirmed available — please re-attempt delivery.')">Re-attempt</button>
        <button type="button" class="btn btn-sm" onclick="qr('Customer will collect from branch. Please hold.')">Hold at branch</button>
        <button type="button" class="btn btn-sm" onclick="qr('Please call customer again, phone was temporarily off.')">Call again</button>
      </div>
    </div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="document.getElementById('replyModal').classList.remove('open')">Cancel</button><button class="btn btn-primary">Send to NCM</button></div>
</form></div>

<script>
function openBook(){document.getElementById('bk_order_id').value='';document.getElementById('bookModal').classList.add('open');}
<?php if($bookPrefill && $connected): ?>
document.addEventListener('DOMContentLoaded',function(){bookFor(<?= json_encode($bookPrefill) ?>);});
<?php elseif($bookPrefill): ?>
document.addEventListener('DOMContentLoaded',function(){alert('Connect NCM first to book this order.');});
<?php endif; ?>
function closeBook(){document.getElementById('bookModal').classList.remove('open');}
function bookFor(o){document.getElementById('bk_order_id').value=o.id;document.getElementById('bk_name').value=o.name||'';document.getElementById('bk_phone').value=o.phone||'';document.getElementById('bk_address').value=o.address||'';document.getElementById('bk_cod').value=o.cod||0;document.getElementById('bk_ref').value=o.ref||'';document.getElementById('bk_pkg').value=o.package||'';var bf=document.querySelector('#bookModal [name=branch]');if(bf)bf.value=bestBranch(o.address);document.getElementById('bookModal').classList.add('open');}
function replyTo(id){document.getElementById('rp_id').value=id;document.getElementById('rp_lbl').textContent=id;document.getElementById('rp_back').value='comments='+id;document.getElementById('replyModal').classList.add('open');}
function qr(t){document.getElementById('rp_text').value=t;}
function setThr(t){var el=document.getElementById('thr_input');if(el){el.value=t;el.focus();autoGrow(el);}}
/* live table search */
/* ---- pagination (orders + comments) ---- */
var PG={orders:{page:1,per:25},comments:{page:1,per:10}};
function pgRender(rows,st,pagerId){
  var total=rows.length, pages=Math.max(1,Math.ceil(total/st.per));
  if(st.page>pages)st.page=pages;
  var a=(st.page-1)*st.per, b=Math.min(total,a+st.per);
  rows.forEach(function(tr,i){ tr.style.display=(i>=a&&i<b)?'':'none'; });
  var el=document.getElementById(pagerId); if(!el)return;
  if(total<=st.per){ el.innerHTML=total?('<span class="pg-info">Showing all '+total+'</span>'):''; return; }
  var h='<span class="pg-info">Showing '+(a+1)+'–'+b+' of '+total+'</span><span class="pg-btns">';
  h+='<button class="pg-b" '+(st.page<=1?'disabled':'')+' data-p="'+(st.page-1)+'">◀</button>';
  var win=[],last=0;
  for(var p=1;p<=pages;p++){ if(p===1||p===pages||Math.abs(p-st.page)<=2) win.push(p); }
  win.forEach(function(p){
    if(last&&p-last>1)h+='<span class="pg-dots">…</span>';
    h+='<button class="pg-b'+(p===st.page?' on':'')+'" data-p="'+p+'">'+p+'</button>'; last=p;
  });
  h+='<button class="pg-b" '+(st.page>=pages?'disabled':'')+' data-p="'+(st.page+1)+'">▶</button></span>';
  el.innerHTML=h;
  el.querySelectorAll('.pg-b[data-p]').forEach(function(btn){
    btn.onclick=function(){ st.page=parseInt(btn.getAttribute('data-p'))||1;
      pgRender(rows,st,pagerId);
      var top=el.closest('.panel'); if(top)top.scrollIntoView({behavior:'smooth',block:'start'}); };
  });
}
function ordRows(){
  var q=(document.getElementById('ncmSearch').value||'').toLowerCase().trim();
  var all=Array.prototype.slice.call(document.querySelectorAll('#ncmRows tr[data-s]'));
  document.querySelectorAll('#ncmRows tr:not([data-s])').forEach(function(tr){tr.style.display=q?'none':'';});
  return all.filter(function(tr){
    var hit=(!q||tr.getAttribute('data-s').indexOf(q)!==-1);
    if(!hit)tr.style.display='none';
    return hit;
  });
}
function renderOrders(){ pgRender(ordRows(),PG.orders,'ordPager'); }
function renderComments(){
  var rows=Array.prototype.slice.call(document.querySelectorAll('#cmtRows tr[data-c]'));
  pgRender(rows,PG.comments,'cmtPager');
}
function ncmFilter(){ PG.orders.page=1; renderOrders(); }
document.addEventListener('DOMContentLoaded',function(){ renderOrders(); renderComments(); });
/* copy phone */
function cpy(t,btn){ (navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(function(){ var o=btn.textContent; btn.textContent='✓'; setTimeout(function(){btn.textContent=o;},900); }).catch(function(){ prompt('Copy:',t); }); }
/* chat: autoscroll to newest + Enter-to-send + autosize */
function autoGrow(el){ el.style.height='auto'; el.style.height=Math.min(el.scrollHeight,140)+'px'; }
(function(){
  var sc=document.getElementById('chatScroll'); if(sc) sc.scrollTop=sc.scrollHeight;
  var ta=document.getElementById('thr_input');
  if(ta){
    ta.addEventListener('input',function(){autoGrow(ta);});
    ta.addEventListener('keydown',function(e){ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); if(ta.value.trim()) ta.form.submit(); } });
  }
})();
var NCM_ROWS=<?= json_encode(array_map(function($r){$o=$r['o'];return ['code'=>$o['code'],'customer'=>$o['customer'],'phone'=>$o['phone'],'cod'=>$o['sell_price']*$o['qty'],'age'=>$r['age'],'nid'=>$r['nid'],'status'=>$r['status']];}, $rows)) ?>;
var BRANCHES=<?= json_encode($branchNames) ?>;
var NCM_LASTCOMMENT=<?= json_encode(array_map(fn($c)=>$c['text'], $commentByOrder)) ?>;
var NCM_INFO=<?= json_encode(array_reduce($ncmOrders, function($acc,$o){ $nid=(string)($o['ncm_order_id']??''); if($nid!=='') $acc[$nid]="Order {$o['code']}, customer {$o['customer']}, phone {$o['phone']}, address {$o['address']}, status {$o['status']}"; return $acc; }, [])) ?>;

/* auto-match a destination branch from the customer address (empty if none) */
function linkFor(id,code,phone){
  document.getElementById('lk_oid').value=id;
  document.getElementById('lk_code').textContent=code||('#'+id);
  document.getElementById('lk_phone').textContent=phone||'—';
  document.getElementById('lk_nid').value='';
  document.getElementById('linkModal').classList.add('open');document.body.classList.add('modal-open');
  setTimeout(function(){document.getElementById('lk_nid').focus();},60);
}
function bkCount(){
  var c=document.querySelectorAll('.bkc:checked').length;
  var bar=document.getElementById('bkBar'); if(bar){bar.style.display=c?'flex':'none';var el=document.getElementById('bkN');if(el)el.textContent=c;}
}
function bkAll(){document.querySelectorAll('#ncmRows tr').forEach(function(tr){if(tr.style.display==='none')return;var c=tr.querySelector('.bkc');if(c&&c.getAttribute('data-pending')==='1')c.checked=true;});bkCount();}
function bbGuess(addr){var A=(addr||'').toUpperCase(),best='',bl=0;(window.BRANCHES||[]).forEach(function(b){var B=(b||'').toUpperCase();if(B&&A.indexOf(B)>-1&&B.length>bl){best=b;bl=B.length;}});return best;}
/* keep the sheet a direct child of <body>: a .panel ancestor has backdrop-filter,
   which would make position:fixed resolve against the panel instead of the screen */
(function(){ var f=function(){ var el=document.getElementById('bbBg');
  if(el && el.parentNode!==document.body) document.body.appendChild(el); };
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',f); else f(); })();
function bkReview(){
  var sel=Array.prototype.slice.call(document.querySelectorAll('.bkc:checked'));
  if(!sel.length){alert('Tick some unbooked orders first — or use ☑ Select All Pending.');return;}
  if(sel.length>60){alert('Max 60 per batch — the first 60 will be kept.');sel=sel.slice(0,60);}
  var box=document.getElementById('bbRows'); box.innerHTML='';
  var okN=0;
  var head=document.createElement('div'); head.className='bb-tip'; box.appendChild(head);
  sel.forEach(function(c){
    var g=bbGuess(c.getAttribute('data-addr')); if(g)okN++;
    var nm=(c.getAttribute('data-cust')||'—'), cod=parseFloat(c.getAttribute('data-cod'))||0;
    var d=document.createElement('div');
    d.className='bb-card'+(g?'':' warn');
    d.innerHTML='<input type="checkbox" class="bbc" '+(g?'checked':'')+' data-id="'+c.value+'" onchange="bbTally()">'+
      '<span class="bb-av">'+esc(nm.trim().charAt(0).toUpperCase()||'?')+'</span>'+
      '<div class="bb-mid">'+
        '<div class="bb-row"><div class="bb-nm">'+esc(nm)+' <span class="pill p-blue" style="font-size:9.5px">'+esc(c.getAttribute('data-code'))+'</span></div>'+
        '<span class="bb-cod">'+(cod>0?('Rs. '+cod.toLocaleString('en-IN')):'Prepaid')+'</span></div>'+
        '<div class="bb-addr">'+esc(c.getAttribute('data-addr')||'no address')+'</div>'+
        '<div class="bb-sel">'+(g?'<span class="pill p-green" style="font-size:9.5px">auto</span>':'<span class="pill p-amber" style="font-size:9.5px">no match</span>')+
          '<select class="bbsel" onchange="bbTally()"><option value="">— pick branch —</option>'+
          (window.BRANCHES||[]).map(function(b){return '<option'+(b===g?' selected':'')+'>'+esc(b)+'</option>';}).join('')+
          '</select></div>'+
      '</div>';
    box.appendChild(d);
  });
  head.innerHTML='✅ <b>'+okN+' of '+sel.length+'</b> branches detected from the address.'+(okN<sel.length?' ⚠ amber cards found no match — pick a branch or untick them.':'');
  var fb=document.getElementById('bkFb'), dt=document.getElementById('bkDt');
  document.getElementById('bbFbTxt').textContent=fb?fb.value:'—';
  document.getElementById('bbDtTxt').textContent=dt?(dt.options[dt.selectedIndex]||{}).text||'Door to Door':'Door to Door';
  bbTally();
  document.getElementById('bbBg').classList.add('open'); document.body.classList.add('modal-open');
}
function bbTally(){
  var cards=Array.prototype.slice.call(document.querySelectorAll('#bbRows .bb-card'));
  var ready=0, need=0;
  cards.forEach(function(d){
    var c=d.querySelector('.bbc'), s=d.querySelector('.bbsel');
    d.classList.toggle('off',!c.checked);
    if(!c.checked) return;
    if(s.value){ready++; d.classList.remove('warn');} else {need++; d.classList.add('warn');}
  });
  document.getElementById('bbCnt').textContent=cards.length+' orders';
  document.getElementById('bbBar').style.width=(cards.length?Math.round(ready/cards.length*100):0)+'%';
  document.getElementById('bbSummary').textContent=ready+' of '+cards.length+' ready'+(need?' · '+need+' need a branch':'');
  var go=document.getElementById('bbGo');
  go.textContent='🚚 Confirm & Book'+(ready?' '+ready:'');
  go.disabled=(ready===0||need>0);
}
function bbClose(){document.getElementById('bbBg').classList.remove('open');document.body.classList.remove('modal-open');}
function bbConfirm(){
  var f=document.getElementById('bkForm'), ids=[];
  f.querySelectorAll('.bbdyn').forEach(function(x){x.remove();});
  var bad=0;
  document.querySelectorAll('#bbRows .bb-card').forEach(function(d){
    var c=d.querySelector('.bbc'); if(!c.checked) return;
    var br=d.querySelector('.bbsel').value;
    if(!br){bad++; return;}
    ids.push(c.getAttribute('data-id'));
    var h=document.createElement('input'); h.type='hidden'; h.name='branch_'+c.getAttribute('data-id'); h.value=br; h.className='bbdyn'; f.appendChild(h);
  });
  if(bad){alert(bad+' ticked order(s) still have no branch.');return;}
  if(!ids.length){alert('Nothing ticked to book.');return;}
  if(!confirm('Book '+ids.length+' orders on NCM now?'))return;
  document.getElementById('bkIds').value=ids.join(',');
  document.getElementById('bkFb2').value=document.getElementById('bkFb')?document.getElementById('bkFb').value:'';
  document.getElementById('bkDt2').value=document.getElementById('bkDt')?document.getElementById('bkDt').value:'Door2Door';
  var go=document.getElementById('bbGo'); go.disabled=true; go.textContent='Booking…';
  f.submit();
}
function esc(t){var d=document.createElement('div');d.textContent=t==null?'':t;return d.innerHTML;}
function bkClear(){document.querySelectorAll('.bkc:checked').forEach(function(x){x.checked=false;});bkCount();}
function bkSubmit(){
  var ids=Array.prototype.map.call(document.querySelectorAll('.bkc:checked'),function(x){return x.value;});
  if(!ids.length)return;
  if(!confirm('Book '+ids.length+' order(s) on NCM? Destination branches will be auto-detected from addresses.'))return;
  document.getElementById('bkIds').value=ids.join(',');
  document.getElementById('bkFb2').value=document.getElementById('bkFb').value;
  document.getElementById('bkDt2').value=document.getElementById('bkDt').value;
  document.getElementById('bkForm').submit();
}
function bestBranch(addr){
  addr=(''+(addr||'')).toLowerCase(); if(!addr) return '';
  for(var i=0;i<BRANCHES.length;i++){ var b=BRANCHES[i]; if(b && addr.indexOf((''+b).toLowerCase())>-1) return b; }
  return '';
}
/* AI-draft a reply into a target textbox using the latest customer comment */
function aiDraft(nid, targetId, btn){
  var comment=NCM_LASTCOMMENT[nid]||'';
  if(!comment){ alert('No recent customer comment found for this order to reply to.'); return; }
  var el=document.getElementById(targetId); var old=btn.textContent; btn.disabled=true; btn.textContent='✨ Drafting…';
  var body='action=ncm_reply&csrf='+encodeURIComponent('<?= csrf() ?>')+'&comment='+encodeURIComponent(comment)+'&context='+encodeURIComponent(NCM_INFO[nid]||'');
  fetch('ai.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body:body})
    .then(function(r){return r.json();}).then(function(d){ btn.disabled=false; btn.textContent=old;
      if(d.ok){ el.value=d.text; el.focus(); } else alert(d.error||'AI error'); })
    .catch(function(e){ btn.disabled=false; btn.textContent=old; alert(e.message); });
}
function exportNCM(){
  var h=['Order','Customer','Phone','COD','Age(days)','NCM ID','Status'];
  var lines=[h.join(',')];
  NCM_ROWS.forEach(function(r){lines.push([r.code,r.customer,r.phone,r.cod,r.age,r.nid,r.status].map(function(v){return '"'+String(v==null?'':v).replace(/"/g,'""')+'"';}).join(','));});
  var blob=new Blob([lines.join('\n')],{type:'text/csv'});
  var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='ncm_orders.csv';a.click();
}
</script>
</div><!-- /ncmv3 -->
</div><!-- /ncmv3 -->
<?php require __DIR__.'/includes/footer.php'; ?>