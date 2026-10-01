<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
require_once __DIR__.'/pickndrop_api.php';
$PAGE_TITLE='Pick & Drop';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;
pd_ensure_cols();

/* Pick & Drop is a courier/channel on your normal Sales orders (same as Daraz/NCM),
   but with a real API behind it: order creation, cancel, live status, and a webhook —
   built from https://pickndrop.apidog.io/ */

/* make sure the Pick & Drop courier exists so it appears in the Sales courier dropdown */
$pdCourier = row("SELECT * FROM couriers WHERE LOWER(name)='pick & drop'");
if (!$pdCourier) {
  try { q("INSERT INTO couriers(name,color,status) VALUES('Pick & Drop','#16a34a','active')"); }
  catch (Exception $e) { try { q("INSERT INTO couriers(name) VALUES('Pick & Drop')"); } catch (Exception $e2) {} }
  $pdCourier = row("SELECT * FROM couriers WHERE LOWER(name)='pick & drop'");
} elseif (empty($pdCourier['color']) || $pdCourier['color']==='#6366f1') {
  try { q("UPDATE couriers SET color='#16a34a' WHERE id=?",[(int)$pdCourier['id']]); } catch (Exception $e) {}
}
$pdId = (int)($pdCourier['id'] ?? 0);

/* build + fire one Pick & Drop order; returns [orderID, deliveryCharge, trackingUrl, orderType]; throws on failure */
function pd_book_one(array $bk, array $branchNames) {
  $phone = pd_norm_phone($bk['phone'] ?? '');
  if ($phone==='') throw new Exception('invalid phone "'.($bk['phone']??'').'" — Pick & Drop needs a 10-digit mobile');
  $branch = pd_match_branch($bk['branch'] ?? '', $branchNames);
  if ($branch==='') throw new Exception('destination branch "'.($bk['branch']??'').'" is not a Pick & Drop branch');
  $addr = trim((string)($bk['address'] ?? ''));
  $pickup = trim((string)($bk['pickup'] ?? '')) ?: trim((string)setting('pd_pickup_address',''));
  if ($pickup==='') throw new Exception('no pickup business address set — add one on this page first');
  $orderType = pd_order_type_norm($bk['order_type'] ?? 'Regular');
  /* Pick & Drop's "orderType" field is only Regular/Exchange/Return (the kind of
     order) — Express/fast delivery is a completely separate "express_delivery"
     0/1 flag (confirmed against their API docs). Sending orderType:"Express" is
     always rejected with "Order Type cannot be Express", which is what was
     silently breaking every Express booking. */
  $payload = [
    'customerName'      => trim((string)($bk['name'] ?? '')) ?: 'Customer',
    'primaryMobileNo'   => $phone,
    'destinationBranch' => $branch,
    'codAmount'         => max(0, (float)($bk['cod'] ?? 0)),
    'orderDescription'  => trim((string)($bk['package'] ?? '')) ?: 'Goods',
    'destinationCityArea' => $addr,
    'businessAddress'   => $pickup,
    'orderType'         => 'Regular',
  ];
  if ($orderType === 'Express') $payload['express_delivery'] = 1;
  $p2 = pd_norm_phone($bk['phone2'] ?? ''); if ($p2!=='') $payload['secondaryMobileNo']=$p2;
  if ($addr!=='') $payload['landmark']=$addr;
  $w = (float)($bk['weight'] ?? 0); if ($w>0) $payload['weight']=$w;
  $ref = trim((string)($bk['ref'] ?? '')); if ($ref!=='') $payload['vendorTrackingNumber']=$ref;
  $ins = trim((string)($bk['instruction'] ?? '')); if ($ins!=='') $payload['instruction']=$ins;

  $res = pickndrop()->createOrder($payload);
  $orderId = pd_extract_order_id($res);
  if ($orderId==='') {
    /* Pick & Drop can accept the order and just answer with a shape we don't
       recognize yet — the order still exists on their side even though we can't
       read an id back from it. Keep the raw response in the activity log so it
       can be linked with the 🔗 Link button instead of silently vanishing. */
    log_activity('Pick & Drop create_order returned no recognizable order id — raw response: '.json_encode($res),'Pick & Drop');
    throw new Exception('Pick & Drop accepted the order but did not return a recognizable order id. Check their dashboard/app for the new order, then use the 🔗 Link button here with its Order ID. Raw response: '.json_encode($res));
  }
  $charge = pd_extract_charge($res);
  $trackUrl = pd_extract_tracking_url($res);
  return [$orderId, $charge, $trackUrl, $orderType];
}

/* ---- POST actions ---- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act = $_POST['_action'] ?? '';
  try {
    if ($act==='set_status') {
      $id=(int)($_POST['id'] ?? 0);
      $o=row("SELECT * FROM orders WHERE id=?",[$id]);
      if ($o) {
        $new=in_array($_POST['status'] ?? '', ['pending','processing','shipped','delivered','returned','cancelled'],true) ? $_POST['status'] : $o['status'];
        $old=$o['status'];
        $dc = ($_POST['delivery_charge'] ?? '')!=='' ? max(0,(float)$_POST['delivery_charge']) : (float)$o['delivery_charge'];
        $pay=$o['payment_status'];
        $cc=(float)$o['cancel_charge'];
        if ($new!==$old) {
          fifo_status_change($id,(int)$o['product_id'],(int)$o['qty'],$old,$new);
          if ($new==='delivered') $pay='paid';
          if (in_array($new,['returned','cancelled'],true) && $cc<=0) {
            try { $cc=(float)val("SELECT COALESCE(cancel_charge,0) FROM couriers WHERE id=?",[(int)$o['courier_id']]); } catch (Exception $e) { $cc=0; }
          }
        }
        q("UPDATE orders SET status=?, delivery_charge=?, cancel_charge=?, payment_status=? WHERE id=?",[$new,$dc,$cc,$pay,$id]);
        log_activity("Pick & Drop order {$o['code']}: status $old→$new, delivery ".money($dc),'Pick & Drop');
        flash("Order {$o['code']} updated — Sales page reflects it instantly.");
      }
      header('Location: pickndrop.php?m='.urlencode($_POST['m'] ?? date('Y-m'))); exit;
    }
    if ($act==='refresh_branches') {
      kv_set('pickndrop_branches_cache', null);
      flash('Branch list refreshed.');
      header('Location: pickndrop.php?m='.urlencode($_POST['m'] ?? date('Y-m'))); exit;
    }
    if ($act==='book') {
      $bn=[]; foreach (pickndrop()->branches() as $b) if(!empty($b['name'])) $bn[]=$b['name'];
      [$pdOrderId,$charge,$trackUrl,$orderType] = pd_book_one([
        'name'=>$_POST['name']??'','phone'=>$_POST['phone']??'','phone2'=>$_POST['phone2']??'',
        'cod'=>$_POST['cod_charge']??0,'address'=>$_POST['address']??'','branch'=>$_POST['branch']??'',
        'pickup'=>$_POST['pickup']??'','package'=>$_POST['package']??'','weight'=>$_POST['weight']??'',
        'ref'=>$_POST['vref_id']??'','instruction'=>$_POST['instruction']??'','order_type'=>$_POST['order_type']??'Regular',
      ], $bn);
      $oid=(int)($_POST['order_id'] ?? 0);
      if ($oid) {
        q("UPDATE orders SET pd_order_id=?, pd_status='Open', pd_tracking_url=?, pd_order_type=?, courier_id=? WHERE id=?",
          [$pdOrderId,$trackUrl,$orderType,$pdId,$oid]);
        if ($charge!==null) q("UPDATE orders SET delivery_charge=? WHERE id=?",[$charge,$oid]);
        if ((string)($_POST['also_ship'] ?? '')==='1') {
          $o=row("SELECT * FROM orders WHERE id=?",[$oid]);
          if ($o && $o['status']==='pending') { fifo_status_change($oid,(int)$o['product_id'],(int)$o['qty'],'pending','processing'); q("UPDATE orders SET status='processing' WHERE id=?",[$oid]); }
        }
      }
      log_activity('Booked Pick & Drop order '.$pdOrderId." ($orderType)",'Pick & Drop');
      flash("Booked on Pick & Drop ✓ Order ID: $pdOrderId".($orderType==='Express'?' · ⚡ Express':'').($charge!==null?(' · delivery '.money($charge).' added to sale'):''));
    }
    elseif ($act==='book_bulk') {
      $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
      $ids = array_slice($ids, 0, 40);
      if(function_exists('set_time_limit')) @set_time_limit(300);
      $bn=[]; foreach (pickndrop()->branches() as $b) if(!empty($b['name'])) $bn[]=$b['name'];
      /* valley-only ops: one destination branch for the whole batch instead of
         guessing it from each order's free-text address (that guess failed too
         often and dumped orders into "book manually") */
      $branchIn = trim((string)($_POST['branch'] ?? ''));
      if ($branchIn==='') throw new Exception('Pick the destination branch for this batch first.');
      $branch = pd_match_branch($branchIn, $bn);
      if ($branch==='') throw new Exception('"'.$branchIn.'" is not a Pick & Drop branch — pick one from the list.');
      set_setting('pd_default_branch', $branch);
      $pickup = trim((string)setting('pd_pickup_address',''));
      $orderType = $_POST['order_type'] ?? 'Regular';
      $ok=[]; $fail=[];
      foreach ($ids as $oid) {
        $o = row("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id WHERE o.id=?",[$oid]);
        if (!$o) { $fail[]="#$oid: not found"; continue; }
        if (!empty($o['pd_order_id'])) { $fail[]=e($o['code']).": already booked"; continue; }
        try {
          [$nid,$chg,$trackUrl,$typeUsed] = pd_book_one([
            'name'=>$o['customer'],'phone'=>$o['phone'],
            'cod'=>(strtolower((string)$o['payment_type'])==='cod' ? (float)$o['sell_price']*(int)$o['qty'] : 0),
            'address'=>$o['address'],'branch'=>$branch,'pickup'=>$pickup,
            'package'=>trim(($o['product_name']?:'Goods').' x'.(int)$o['qty']),'ref'=>$o['code'],'order_type'=>$orderType,
          ], $bn);
          q("UPDATE orders SET pd_order_id=?, pd_status='Open', pd_tracking_url=?, pd_order_type=?, courier_id=? WHERE id=?", [$nid,$trackUrl,$typeUsed,$pdId,$oid]);
          if ($chg!==null) q("UPDATE orders SET delivery_charge=? WHERE id=?", [$chg,$oid]);
          $ok[]=e($o['code'])."→$nid";
        } catch (Exception $ex) { $fail[]=e($o['code']).': '.$ex->getMessage(); }
      }
      log_activity('Bulk Pick & Drop booking to '.$branch." ($orderType): ".count($ok).' ok, '.count($fail).' failed','Pick & Drop');
      $msg = count($ok)." booked to $branch".($orderType==='Express'?' ⚡ Express':'')." ✓".($ok?(' — '.implode(', ',$ok)):'');
      if ($fail) $msg .= '  ·  '.count($fail).' failed: '.implode(' | ',$fail);
      flash($msg);
    }
    elseif ($act==='link_manual') {
      $oid=(int)($_POST['order_id'] ?? 0); $pdOid=trim((string)($_POST['pd_id'] ?? '')); $force=!empty($_POST['force']);
      if(!$oid || $pdOid==='') throw new Exception('Enter the Pick & Drop Order ID.');
      $o=row("SELECT * FROM orders WHERE id=?",[$oid]);
      if(!$o) throw new Exception('Order not found.');
      $dupe=row("SELECT code FROM orders WHERE pd_order_id=? AND id<>?",[$pdOid,$oid]);
      if($dupe) throw new Exception('Pick & Drop order '.$pdOid.' is already linked to order '.$dupe['code'].'.');
      $d=pickndrop()->orderDetails($pdOid);
      if(!$d) throw new Exception('Pick & Drop order '.$pdOid.' was not found.');
      $pdPhone=pd_extract_phone($d); $pdName=pd_extract_name($d);
      $ourPhone=pd_norm_phone($o['phone']);
      if(!$force){
        if($pdPhone==='') throw new Exception('Pick & Drop order '.$pdOid.' has no readable phone — tick "link anyway" if you are sure.');
        if($ourPhone==='' || $pdPhone!==$ourPhone)
          throw new Exception('Phone mismatch: Pick & Drop '.$pdOid.' → '.$pdPhone.($pdName?' ('.$pdName.')':'').', but order '.$o['code'].' → '.($o['phone']?:'none').'. Tick "link anyway" to force.');
      }
      $chg = pd_extract_charge($d);
      $rawStatus = (string)($d['status'] ?? 'Open');
      $pdType = pd_extract_order_type($d);
      q("UPDATE orders SET pd_order_id=?, pd_status=?, pd_order_type=COALESCE(?,pd_order_type), courier_id=? WHERE id=?",[$pdOid,$rawStatus,$pdType,$pdId,$oid]);
      if($chg!==null) q("UPDATE orders SET delivery_charge=? WHERE id=?",[$chg,$oid]);
      log_activity("Linked Pick & Drop $pdOid to ".$o['code'],'Pick & Drop');
      flash('Linked ✓ '.$o['code'].' ↔ Pick & Drop '.$pdOid.($pdName?' — '.$pdName:'').($chg!==null?(' · delivery '.money($chg).' recorded'):''));
    }
    elseif ($act==='cancel_pd') {
      $oid=(int)($_POST['id'] ?? 0);
      $o=row("SELECT * FROM orders WHERE id=?",[$oid]);
      if ($o && !empty($o['pd_order_id'])) {
        pickndrop()->cancelOrder($o['pd_order_id']);
        q("UPDATE orders SET pd_status='Cancelled' WHERE id=?",[$oid]);
        if (!in_array($o['status'],['delivered','returned'],true)) {
          fifo_status_change($oid,(int)$o['product_id'],(int)$o['qty'],$o['status'],'cancelled');
          $cc=(float)$o['cancel_charge']; if($cc<=0){ try{ $cc=(float)val("SELECT COALESCE(cancel_charge,0) FROM couriers WHERE id=?",[$pdId]); }catch(Exception $e){$cc=0;} }
          q("UPDATE orders SET status='cancelled', cancel_charge=? WHERE id=?",[$cc,$oid]);
        }
        log_activity("Cancelled Pick & Drop order {$o['pd_order_id']} ({$o['code']})",'Pick & Drop');
        flash("✕ Cancelled {$o['code']} with Pick & Drop.");
      }
    }
    elseif ($act==='sync_one') {
      $oid=(int)($_POST['id'] ?? 0);
      $o=row("SELECT * FROM orders WHERE id=?",[$oid]);
      if ($o && !empty($o['pd_order_id'])) {
        $d = pickndrop()->orderDetails($o['pd_order_id']);
        $raw = (string)($d['status'] ?? '');
        q("UPDATE orders SET pd_status=? WHERE id=?",[$raw,$oid]);
        /* Pick & Drop can revise the delivery charge after booking (reweigh,
           route/branch policy, COD surcharge, …) — pull their current number on
           every sync so ours doesn't go stale. */
        $chg = pd_extract_charge($d);
        $chgChanged = $chg!==null && abs($chg-(float)$o['delivery_charge'])>0.005;
        if ($chgChanged) q("UPDATE orders SET delivery_charge=? WHERE id=?",[$chg,$oid]);
        $mapped = $raw!=='' ? pd_to_local_status($raw) : null;
        if ($mapped && $mapped!==$o['status']) {
          fifo_status_change($oid,(int)$o['product_id'],(int)$o['qty'],$o['status'],$mapped);
          if ($mapped==='delivered') q("UPDATE orders SET status='delivered',payment_status='paid' WHERE id=?",[$oid]);
          else q("UPDATE orders SET status=? WHERE id=?",[$mapped,$oid]);
        }
        flash("Synced {$o['code']} — Pick & Drop status: ".($raw?:'unknown').($chgChanged?(' · delivery updated to '.money($chg)):''));
      }
    }
    elseif ($act==='sync_all') {
      $rows = rows("SELECT * FROM orders WHERE courier_id=? AND COALESCE(pd_order_id,'')<>'' AND status NOT IN ('delivered','cancelled','returned') LIMIT 40",[$pdId]);
      $n=0; $cn=0;
      foreach ($rows as $o) {
        try {
          $d = pickndrop()->orderDetails($o['pd_order_id']);
          $raw = (string)($d['status'] ?? '');
          $chg = pd_extract_charge($d);
          if ($chg!==null && abs($chg-(float)$o['delivery_charge'])>0.005) { q("UPDATE orders SET delivery_charge=? WHERE id=?",[$chg,$o['id']]); $cn++; }
          if ($raw==='') continue;
          q("UPDATE orders SET pd_status=? WHERE id=?",[$raw,$o['id']]);
          $mapped = pd_to_local_status($raw);
          if ($mapped && $mapped!==$o['status']) {
            fifo_status_change($o['id'],(int)$o['product_id'],(int)$o['qty'],$o['status'],$mapped);
            if ($mapped==='delivered') q("UPDATE orders SET status='delivered',payment_status='paid' WHERE id=?",[$o['id']]);
            else q("UPDATE orders SET status=? WHERE id=?",[$mapped,$o['id']]);
            $n++;
          }
        } catch (Exception $e) {}
        usleep(80000);
      }
      log_activity("Pick & Drop sync-all: ".count($rows)." checked, $n status-updated, $cn delivery-charge updated",'Pick & Drop');
      flash(count($rows)." order(s) checked, $n status updated, $cn delivery charge updated.");
    }
    elseif ($act==='request_pickup') {
      $addr = trim((string)setting('pd_pickup_address',''));
      if ($addr==='') throw new Exception('Set a pickup business address first.');
      $res = pickndrop()->pickupRequest($addr);
      log_activity('Requested Pick & Drop pickup: '.$addr,'Pick & Drop');
      flash('📮 Pickup requested — a rider will be assigned.');
    }
    elseif ($act==='add_location') {
      pickndrop()->createVendorLocation([
        'name'=>'', 'address'=>trim($_POST['loc_address']??''), 'phone'=>pd_norm_phone($_POST['loc_phone']??''),
        'contact_person'=>trim($_POST['loc_contact']??''), 'child_branch'=>trim($_POST['loc_branch']??''),
        'area_label'=>trim($_POST['loc_area']??''),
      ]);
      kv_set('pickndrop_locations_cache', null);
      log_activity('Registered Pick & Drop pickup location: '.trim($_POST['loc_address']??''),'Pick & Drop');
      flash('📍 Pickup location registered. Set it as default below.');
    }
    elseif ($act==='register_webhook') {
      $secret = trim((string)setting('pd_webhook_secret',''));
      if ($secret==='') { $secret = bin2hex(random_bytes(20)); set_setting('pd_webhook_secret',$secret); }
      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
      $url = $scheme.'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['SCRIPT_NAME']),'/').'/pickndrop_webhook.php?token='.$secret;
      pickndrop()->createWebhook($url, $secret, true);
      set_setting('pd_webhook_url',$url);
      log_activity('Registered Pick & Drop webhook: '.$url,'Pick & Drop');
      flash('🔔 Webhook registered — Pick & Drop will push live status updates.');
    }
  } catch (Exception $ex) { flash('Error: '.$ex->getMessage()); }
  header('Location: pickndrop.php?m='.urlencode($_POST['m'] ?? date('Y-m'))); exit;
}

/* ---- month filter ---- */
$m = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$mStart = $m.'-01'; $mEnd = date('Y-m-t', strtotime($mStart));

$orders = rows("SELECT o.*, p.name AS product_name FROM orders o
                LEFT JOIN products p ON p.id=o.product_id
                WHERE o.courier_id=? AND o.order_date BETWEEN ? AND ?
                ORDER BY o.order_date DESC, o.id DESC",[$pdId,$mStart,$mEnd]);

$mOrders=count($orders); $mRev=0; $mCost=0; $mDelv=0; $mDel=0; $mRet=0; $mGross=0; $mUnits=0;
foreach ($orders as $o) {
  if ($o['status']==='delivered') {
    $mDel++; $mUnits+=(int)$o['qty']; $line=(float)$o['sell_price']*(int)$o['qty'];
    $mRev+=$line; $mCost+=(float)$o['cost_price']*(int)$o['qty']; $mDelv+=(float)$o['delivery_charge'];
    $mGross+=order_profit($o);
  } elseif (in_array($o['status'],['returned','cancelled'],true)) { $mRet++; $mGross+=order_profit($o); }
}

/* ---- COD money (all-time, from the shared engine) ---- */
$codRow=['collected'=>0,'charges'=>0,'net'=>0,'released'=>0,'held'=>0];
foreach (cod_holdings() as $h) if ((int)$h['cid']===$pdId) { $codRow=$h; break; }

/* ---- working orders table (NCM-style): ALL-TIME, not month-scoped.
   Only ACTIVE orders (not yet delivered/cancelled/returned) are kept live in the
   render loop — a finalized order never changes again, so re-checking every order
   ever booked on every page load would only make this page slow for no reason.
   Delivered/Returned are fetched on demand (LIMIT 300) only when that tab is open. */
$pdAgingDays = max(1, (int)setting('pd_aging_days', 5));
$todayDt = new DateTime('today');

$pdActiveOrders = rows("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id
                        WHERE o.courier_id=? AND o.status NOT IN ('delivered','cancelled','returned')
                        ORDER BY o.id DESC",[$pdId]);
$pdTotalAll     = (int)val("SELECT COUNT(*) FROM orders WHERE courier_id=?",[$pdId]);
$pdDeliveredAll = (int)val("SELECT COUNT(*) FROM orders WHERE courier_id=? AND status='delivered'",[$pdId]);
$pdReturnedAll  = (int)val("SELECT COUNT(*) FROM orders WHERE courier_id=? AND status IN ('returned','cancelled')",[$pdId]);

$pdRowify = function(array $orders) use ($todayDt) {
  $out=[];
  foreach ($orders as $o) {
    $age = $o['order_date'] ? (int)$todayDt->diff(new DateTime($o['order_date']))->days : 0;
    $cod = strtolower((string)$o['payment_type'])==='cod' ? (float)$o['sell_price']*(int)$o['qty'] : 0;
    $out[] = ['o'=>$o,'age'=>$age,'cod'=>$cod];
  }
  return $out;
};
$pdRows = $pdRowify($pdActiveOrders);

$f = $_GET['f'] ?? 'all';
$pdTHref = function($k) use ($f) { return 'pickndrop.php?f='.($f===$k?'all':$k).'#orders'; };
$pdTOn   = function($k) use ($f) { return $f===$k ? ' on' : ''; };
$pdFilterFn = function($r,$f) use ($pdAgingDays) {
  $st = $r['o']['status'];
  switch($f){
    case 'pending':    return $st==='pending';
    case 'processing': return $st==='processing';
    case 'shipped':    return $st==='shipped';
    case 'aging':      return $r['age']>=$pdAgingDays;
    case 'cod':        return $r['cod']>0;
    default:           return true;
  }
};
$pdTabs = ['all'=>'All','pending'=>'Pending','processing'=>'Processing','shipped'=>'In Transit',
           'aging'=>"Aging {$pdAgingDays}d+",'cod'=>'COD to Collect','delivered'=>'Delivered','returned'=>'↩ Returned/Cancelled'];
$pdTabCount = function($k) use ($pdRows,$pdFilterFn) { return count(array_filter($pdRows, fn($r)=>$pdFilterFn($r,$k))); };

if (in_array($f, ['delivered','returned'], true)) {
  $histWhere = $f==='delivered' ? "o.status='delivered'" : "o.status IN ('returned','cancelled')";
  $pdHistOrders = rows("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id
                         WHERE o.courier_id=? AND $histWhere ORDER BY o.id DESC LIMIT 300",[$pdId]);
  $pdFiltered = $pdRowify($pdHistOrders);
} else {
  $pdFiltered = array_values(array_filter($pdRows, fn($r)=>$pdFilterFn($r,$f)));
}

/* ---- live data (only if API key/secret set) ---- */
$pdConfigured = pickndrop()->configured();
$branches = []; $branchErr = '';
$locations = []; $locErr = '';
if ($pdConfigured) {
  try { $branches = pickndrop()->branches(); } catch (Exception $e) { $branchErr = $e->getMessage(); }
  try { $locations = pickndrop()->vendorLocations(); } catch (Exception $e) { $locErr = $e->getMessage(); }
}
$pickupAddr = trim((string)setting('pd_pickup_address',''));
$webhookUrl = trim((string)setting('pd_webhook_url',''));
$pdDefaultBranch = trim((string)setting('pd_default_branch',''));

require __DIR__.'/includes/header.php';
echo delivery_disabled_banner('pickndrop.php');
?>
<style>
.pd-hero{background:linear-gradient(120deg,#16a34a,#14532d);border-radius:22px;padding:22px 26px;color:#fff;margin-bottom:14px;box-shadow:0 18px 40px rgba(20,83,45,.28)}
.pd-hero-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.pd-hero h1{font-size:21px;font-weight:900;margin:0}
.pd-hero p{opacity:.85;font-size:12px;margin:2px 0 0}
.pd-hero-links{display:flex;gap:8px;margin-left:auto;flex-wrap:wrap}
.pd-hlink{background:rgba(255,255,255,.9);color:#14532d;border-radius:10px;padding:8px 13px;font-size:11.5px;font-weight:800;text-decoration:none;border:0;cursor:pointer}
.pd-hlink.ghost{background:rgba(255,255,255,.18);color:#fff}
.pd-branch-chip{display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:99px;padding:4px 11px;font-size:11px;font-weight:700;margin:3px}
.pd-bulkbar{display:none;align-items:center;gap:12px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:13px;padding:9px 16px;margin:0 18px 10px}
.pd-bulkbar.on{display:flex}
.age-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800}
.age-old{background:var(--red-bg);color:var(--red)}.age-mid{background:var(--amber-bg);color:var(--amber)}.age-ok{background:var(--surface-2);color:var(--muted)}
</style>

<div class="pd-hero">
  <div class="pd-hero-top">
    <img src="assets/pickndrop-logo.svg" alt="Pick & Drop" style="height:30px">
    <div><h1>Pick & Drop</h1><p>Orders come from Sales with Courier = <b>Pick & Drop</b> · auto-booked on their live API</p></div>
    <div class="pd-hero-links">
      <a class="pd-hlink" href="sales.php?new=pickndrop">＋ New Order</a>
      <?php if($pdConfigured): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="sync_all"><input type="hidden" name="m" value="<?= e($m) ?>"><button class="pd-hlink ghost">🔄 Sync All</button></form>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="request_pickup"><input type="hidden" name="m" value="<?= e($m) ?>"><button class="pd-hlink ghost">📮 Request Pickup</button></form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="mgrid">
  <div class="metric blue"><div><div class="mv"><?= $mOrders ?></div><div class="ml">Orders</div><div class="ms"><?= e(date('M Y',strtotime($mStart))) ?> · <?= $mDel ?> delivered</div></div><div class="mi">🟢</div></div>
  <div class="metric green"><div><div class="mv" style="font-size:19px"><?= money($mRev) ?></div><div class="ml">Delivered Revenue</div><div class="ms"><?= number_format($mUnits) ?> pcs delivered</div></div><div class="mi">💰</div></div>
  <div class="metric amber"><div><div class="mv" style="font-size:19px"><?= money($mCost) ?></div><div class="ml">Product Cost</div><div class="ms">FIFO actual</div></div><div class="mi">📦</div></div>
  <div class="metric red"><div><div class="mv" style="font-size:19px"><?= money($mDelv) ?></div><div class="ml">Delivery Charges</div><div class="ms">paid to Pick & Drop</div></div><div class="mi">🚚</div></div>
  <div class="metric <?= $mGross>=0?'teal':'red' ?>"><div><div class="mv" style="font-size:19px"><?= money($mGross) ?></div><div class="ml">Net Profit</div><div class="ms">delivered + returned/cancelled</div></div><div class="mi">📈</div></div>
</div>

<div class="panel" style="margin-bottom:16px">
  <div class="panel-head"><h2>💰 COD With Pick & Drop</h2><a href="cod.php" style="font-weight:800;font-size:12px">COD Ledger →</a></div>
  <div class="panel-body" style="display:flex;gap:22px;flex-wrap:wrap">
    <div><b style="font-size:15px;display:block"><?= money($codRow['collected']) ?></b><span class="muted" style="font-size:10.5px;font-weight:700;text-transform:uppercase">Collected</span></div>
    <div><b style="font-size:15px;display:block;color:var(--red)">− <?= money($codRow['charges']) ?></b><span class="muted" style="font-size:10.5px;font-weight:700;text-transform:uppercase">Their Charges</span></div>
    <div><b style="font-size:15px;display:block"><?= money($codRow['net']) ?></b><span class="muted" style="font-size:10.5px;font-weight:700;text-transform:uppercase">Net Payable</span></div>
    <div><b style="font-size:15px;display:block;color:var(--green)"><?= money($codRow['released']) ?></b><span class="muted" style="font-size:10.5px;font-weight:700;text-transform:uppercase">Released</span></div>
    <div><b style="font-size:15px;display:block"><?= money(max(0,$codRow['held'])) ?></b><span class="muted" style="font-size:10.5px;font-weight:700;text-transform:uppercase">Still Holding</span></div>
  </div>
</div>

<div class="panel" style="margin-bottom:16px">
  <div class="panel-head"><h2>⚙️ Setup</h2></div>
  <div class="panel-body">
    <?php if(!$pdConfigured): ?>
      <p class="muted" style="font-size:12.5px">Add your Pick & Drop <b>Api Key</b> and <b>Api Secret</b> in <a href="settings.php#pd-settings">Settings → Courier / Pick & Drop</a> first.</p>
    <?php else: ?>
      <div style="display:flex;flex-wrap:wrap;gap:22px">
        <div style="flex:1;min-width:260px">
          <div class="muted" style="font-size:10.5px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Pickup Business Address (used when booking)</div>
          <?php if($pickupAddr): ?>
            <div style="font-weight:700;font-size:13px">📍 <?= e($pickupAddr) ?></div>
            <p class="muted" style="font-size:11px;margin-top:4px">Change it in <a href="settings.php#pd-settings">Settings</a>.</p>
          <?php else: ?>
            <p class="muted" style="font-size:12px">Not set. Pick one below (once registered with Pick & Drop) or register a new one, then paste it into <a href="settings.php#pd-settings">Settings → Default Pickup Business Address</a>.</p>
          <?php endif; ?>
          <?php if($locErr): ?><p class="muted" style="font-size:11.5px;color:var(--red)">Couldn't load registered locations: <?= e($locErr) ?></p>
          <?php elseif($locations): ?>
            <div style="margin-top:8px">
              <?php foreach($locations as $l): ?><span class="pd-branch-chip">📍 <?= e($l['address'] ?? ($l['name'] ?? '?')) ?></span><?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="muted" style="font-size:11.5px;margin-top:6px">No pickup locations registered yet — add one:</p>
            <form method="post" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">
              <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="add_location"><input type="hidden" name="m" value="<?= e($m) ?>">
              <input name="loc_address" placeholder="Address (e.g. Balaju, Kathmandu)" required style="flex:2;min-width:150px">
              <input name="loc_phone" placeholder="Phone" required style="width:110px">
              <input name="loc_contact" placeholder="Contact person" required style="flex:1;min-width:110px">
              <input name="loc_branch" list="pdBrList" placeholder="Nearest branch" required style="width:130px">
              <input name="loc_area" placeholder="Area (from branch's area list)" style="width:150px">
              <button class="btn btn-sm btn-primary">＋ Register</button>
            </form>
          <?php endif; ?>
        </div>
        <div style="flex:1;min-width:220px">
          <div class="muted" style="font-size:10.5px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Live Status Webhook</div>
          <?php if($webhookUrl): ?>
            <div style="font-weight:700;font-size:12.5px;color:var(--green)">🔔 Registered</div>
            <p class="muted" style="font-size:11px;margin-top:4px;word-break:break-all"><?= e($webhookUrl) ?></p>
          <?php else: ?>
            <p class="muted" style="font-size:12px">Not registered — without it, statuses only update when you click Sync/Sync All (or via the cron job).</p>
          <?php endif; ?>
          <form method="post" style="margin-top:6px"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="register_webhook"><input type="hidden" name="m" value="<?= e($m) ?>"><button class="btn btn-sm"><?= $webhookUrl?'🔄 Re-register':'🔔 Register Webhook' ?></button></form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="panel" style="margin-bottom:16px">
  <div class="panel-head"><h2>🏢 Branches <span class="muted" style="font-size:11px;font-weight:600">(live from Pick & Drop API)</span></h2>
    <?php if($pdConfigured): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="refresh_branches"><input type="hidden" name="m" value="<?= e($m) ?>"><button class="btn btn-sm">🔄 Refresh</button></form>
    <?php endif; ?>
  </div>
  <div class="panel-body">
    <?php if(!$pdConfigured): ?>
      <p class="muted" style="font-size:12.5px">Add your Api Key/Secret above to pull the live branch list.</p>
    <?php elseif($branchErr): ?>
      <p class="muted" style="font-size:12.5px;color:var(--red)">Couldn't reach Pick & Drop: <?= e($branchErr) ?></p>
    <?php elseif(!$branches): ?>
      <p class="muted" style="font-size:12.5px">No branches returned yet.</p>
    <?php else: ?>
      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <div><b style="font-size:24px;display:block;line-height:1"><?= number_format(count($branches)) ?></b><span class="muted" style="font-size:10.5px;font-weight:700;text-transform:uppercase">active branches</span></div>
        <input id="pdBrSearch" placeholder="🔍 Search a branch by name, code or district…" style="flex:1;min-width:220px" oninput="pdBrFilter()">
      </div>
      <div id="pdBrResults" style="margin-top:10px"></div>
    <?php endif; ?>
  </div>
</div>
<datalist id="pdBrList"><?php foreach($branches as $b): ?><option value="<?= e($b['name'] ?? '') ?>"><?php endforeach; ?></datalist>
<?php if($pdConfigured && $branches): ?>
<script>
/* trimmed branch data for the on-demand search below — kept out of the DOM by
   default (rendering all <?= count($branches) ?> as chips is what was lagging the page) */
var PD_BRANCHES=<?= json_encode(array_map(fn($b)=>[$b['branch_name']??($b['name']??'?'),$b['branch_code']??'',$b['district']??''],$branches)) ?>;
function pdBrFilter(){
  var q=(document.getElementById('pdBrSearch').value||'').toLowerCase().trim();
  var box=document.getElementById('pdBrResults');
  if(!q){ box.innerHTML=''; return; }
  var hits=[];
  for(var i=0;i<PD_BRANCHES.length && hits.length<30;i++){
    var b=PD_BRANCHES[i];
    if((b[0]+' '+b[1]+' '+b[2]).toLowerCase().indexOf(q)>-1) hits.push(b);
  }
  if(!hits.length){ box.innerHTML='<span class="muted" style="font-size:12px">No match.</span>'; return; }
  var esc=function(s){ return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); };
  box.innerHTML=hits.map(function(b){
    return '<span class="pd-branch-chip">📍 '+esc(b[0])+(b[1]?' · '+esc(b[1]):'')+(b[2]?' · '+esc(b[2]):'')+'</span>';
  }).join('');
}
</script>
<?php endif; ?>

<div class="panel" id="orders">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px"><h2>📦 All Pick & Drop Orders <?= $f!=='all'?'<span class="pill p-blue" style="font-size:10px">'.e($pdTabs[$f]??$f).' filter</span>':'' ?></h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input id="pdSearch" class="ncm-search" placeholder="🔍 Search order, name, phone, PD #…" oninput="pdFilter()">
      <select id="pdPer" class="pgsel" onchange="PDPG.per=parseInt(this.value)||25;PDPG.page=1;pdRenderPage()" title="rows per page">
        <option value="25">25 / page</option><option value="50">50 / page</option><option value="100">100 / page</option><option value="100000">All</option>
      </select>
      <button class="btn btn-sm" onclick="pdExportCSV()">⬇ CSV</button>
    </div>
  </div>
  <div class="ncm-tabs">
    <?php foreach($pdTabs as $k=>$lbl): $c = $k==='delivered' ? $pdDeliveredAll : ($k==='returned' ? $pdReturnedAll : $pdTabCount($k)); ?>
      <a class="ntab <?= $f===$k?'on':'' ?>" href="<?= $pdTHref($k) ?>"><?= e($lbl) ?> <span class="ntab-n"><?= $c ?></span></a>
    <?php endforeach; ?>
  </div>
  <?php if($pdConfigured): ?>
  <div class="pd-bulkbar" id="pdBulkBar">
    <span><b id="pdSelN">0</b> selected</span>
    <input id="pdBulkBranch" form="pdBulkForm" name="branch" list="pdBrList" placeholder="Destination branch (used for all)" value="<?= e($pdDefaultBranch) ?>" required style="min-width:190px">
    <select form="pdBulkForm" name="order_type" title="Delivery type (used for all selected)"><option value="Regular">Standard</option><option value="Express">⚡ Express</option></select>
    <form method="post" id="pdBulkForm" style="display:inline">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="book_bulk"><input type="hidden" name="ids" id="pdBulkIds"><input type="hidden" name="m" value="<?= e($m) ?>">
      <button class="btn btn-sm btn-primary" type="submit" onclick="return pdBulkConfirm()">🚀 Book Selected</button>
    </form>
    <button class="btn btn-sm" type="button" onclick="pdSelClear()">✕ Clear</button>
  </div>
  <?php endif; ?>
  <div class="table-wrap tw-sticky"><table class="tbl">
    <thead><tr>
      <?php if($pdConfigured): ?><th style="width:24px"><input type="checkbox" onchange="pdSelAll(this)" title="Select all visible"></th><?php endif; ?>
      <th>Order</th><th>Customer</th><th>Phone</th><th class="right">COD</th><th>Age</th><th>PD ID</th><th>Status</th><th></th>
    </tr></thead>
    <tbody id="pdRows">
    <?php foreach($pdFiltered as $r): $o=$r['o'];
      $booked = !empty($o['pd_order_id']);
      $canCancel = $booked && !in_array($o['status'],['delivered','returned','cancelled'],true);
      $unbooked = !$booked && !in_array($o['status'],['delivered','returned','cancelled'],true);
      $ap = $r['age']>=$pdAgingDays ? 'age-old' : ($r['age']>=max(1,(int)round($pdAgingDays*0.6)) ? 'age-mid' : 'age-ok');
    ?>
      <tr class="<?= $r['age']>=$pdAgingDays?'row-danger':'' ?>" data-s="<?= e(strtolower($o['code'].' '.($o['customer']??'').' '.($o['phone']??'').' '.($o['pd_order_id']??'').' '.$o['status'])) ?>">
        <?php if($pdConfigured): ?><td><?php if($unbooked && in_array($o['status'],['pending','processing'],true)): ?><input type="checkbox" class="pdSel" value="<?= (int)$o['id'] ?>" onchange="pdSelCount()"><?php endif; ?></td><?php endif; ?>
        <td><b><?= e($o['code']) ?></b></td>
        <td><div class="ncm-cust"><span class="cav"><?= e(strtoupper(mb_substr(trim((string)$o['customer'])?:'?',0,1))) ?></span>
          <span><span class="cn"><?= e($o['customer']?:'—') ?></span><span class="ca"><?= e(mb_strimwidth((string)$o['address'],0,34,'…')) ?></span>
          <span class="ca" style="color:#16a34a;font-weight:600"><?= e($o['product_name']?:'Goods') ?> ×<?= (int)$o['qty'] ?></span></span></div></td>
        <td class="num nowrap"><?= e($o['phone']?:'—') ?>
          <?php if($o['phone']): ?><button class="mini" title="Copy" onclick="pdCpy('<?= e($o['phone']) ?>',this)">⧉</button><a class="mini" title="WhatsApp" target="_blank" rel="noopener" href="https://wa.me/<?= e(wa_phone($o['phone'])) ?>">💬</a><?php endif; ?></td>
        <td class="num right"><?= $r['cod']>0 ? '<b>'.money($r['cod']).'</b>' : '<span class="muted">prepaid</span>' ?></td>
        <td><span class="age-pill <?= $ap ?>"><?= $r['age'] ?>d</span></td>
        <td><?= $booked ? ('<b style="font-size:11.5px">'.e($o['pd_order_id']).'</b>'.(($o['pd_order_type']??'')==='Express'?' <span class="pill p-yellow" style="font-size:9.5px;padding:1px 6px">⚡ Express</span>':'').(!empty($o['pd_tracking_url'])?' <a href="'.e($o['pd_tracking_url']).'" target="_blank" rel="noopener" style="font-size:10px">↗</a>':'')) : '<span class="muted">—</span>' ?></td>
        <td>
          <span class="pill <?= $booked && !empty($o['pd_status']) ? pd_status_class($o['pd_status']) : status_class($o['status']) ?>"><?= e($booked && !empty($o['pd_status']) ? $o['pd_status'] : ucfirst($o['status'])) ?></span>
          <?php if($isAdmin || true): ?>
          <details style="margin-top:4px"><summary class="muted" style="font-size:10px;cursor:pointer;list-style:none">✏️ change</summary>
            <form method="post" style="display:flex;gap:4px;align-items:center;margin-top:4px">
              <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="set_status">
              <input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="m" value="<?= e($m) ?>">
              <select name="status" style="font-size:11px">
                <?php foreach(['pending','processing','shipped','delivered','returned','cancelled'] as $st): ?>
                  <option value="<?= $st ?>"<?= $o['status']===$st?' selected':'' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="number" name="delivery_charge" value="<?= (float)$o['delivery_charge']>0?e((float)$o['delivery_charge']):'' ?>" placeholder="Chg" step="any" min="0" style="width:56px;font-size:11px" title="Delivery charge (Rs.)">
              <button class="btn btn-sm" style="padding:3px 8px" title="Save">💾</button>
            </form>
          </details>
          <?php endif; ?>
        </td>
        <td class="right nowrap">
          <?php if($booked): ?>
            <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="sync_one"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="m" value="<?= e($m) ?>"><button class="btn btn-sm" title="Sync live status">🔄</button></form>
            <?php if($canCancel): ?><form method="post" style="display:inline" onsubmit="return confirm('Cancel this order with Pick & Drop?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="cancel_pd"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="m" value="<?= e($m) ?>"><button class="btn btn-sm" style="color:var(--red)" title="Cancel with Pick & Drop">✕</button></form><?php endif; ?>
          <?php elseif($pdConfigured && $unbooked): ?>
            <?php if(in_array($o['status'],['pending','processing'],true)): ?>
            <button class="btn btn-sm btn-primary" onclick='pdBookFor(<?= json_encode(['id'=>(int)$o['id'],'name'=>$o['customer'],'phone'=>$o['phone'],'address'=>$o['address'],'cod'=>$r['cod'],'package'=>trim(($o['product_name']?:'Goods').' x'.(int)$o['qty']),'ref'=>$o['code']], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Book</button>
            <?php endif; ?>
            <button class="btn btn-sm" title="Already created on the Pick & Drop website/app? Link it here" onclick='pdLinkFor(<?= (int)$o['id'] ?>,<?= json_encode((string)$o['code']) ?>,<?= json_encode((string)$o['phone']) ?>)'>🔗</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; if(!$pdFiltered) echo '<tr><td colspan="9"><div class="empty">No orders in this view.</div></td></tr>'; ?>
    </tbody>
  </table></div>
  <div id="pdPager" class="pager" style="padding:10px 18px"></div>
</div>

<!-- booking modal -->
<div class="modal-bg" id="pdBookModal"><form class="modal" method="post">
  <div class="modal-head"><span>🚀 Send to Pick & Drop</span><span class="mx" onclick="pdCloseBook()">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="book"><input type="hidden" name="order_id" id="pdbk_order_id" value=""><input type="hidden" name="m" value="<?= e($m) ?>">
  <div class="modal-body">
    <div><label>Customer Name</label><input name="name" id="pdbk_name" required></div>
    <div><label>Phone</label><input name="phone" id="pdbk_phone" required></div>
    <div><label>Alt Phone</label><input name="phone2"></div>
    <div><label>COD Amount (Rs.)</label><input name="cod_charge" id="pdbk_cod" type="number" step="any"></div>
    <div class="full"><label>Delivery Address / Landmark</label><input name="address" id="pdbk_address"></div>
    <div><label>Pickup Business Address</label><input name="pickup" id="pdbk_pickup" value="<?= e($pickupAddr) ?>" required></div>
    <div><label>Destination Branch</label><input name="branch" id="pdbk_branch" list="pdBrList" required></div>
    <div><label>Delivery Type</label><select name="order_type" id="pdbk_order_type"><option value="Regular">Standard</option><option value="Express">⚡ Express</option></select></div>
    <div><label>Weight (kg)</label><input name="weight" value="1"></div>
    <div><label>Package / Contents</label><input name="package" id="pdbk_pkg" placeholder="e.g. Heel Guard Plus x2"></div>
    <div><label>Your Ref (order code)</label><input name="vref_id" id="pdbk_ref"></div>
    <div class="full"><label>Instructions</label><input name="instruction" placeholder="Optional note for Pick & Drop"></div>
    <div class="full"><label style="display:flex;gap:6px;align-items:center;font-weight:600"><input type="checkbox" name="also_ship" value="1" style="width:auto" checked> Mark order as Processing once booked</label></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="pdCloseBook()">Cancel</button><button class="btn btn-primary">🚀 Book on Pick & Drop</button></div>
</form></div>

<!-- link-existing-order modal -->
<div class="modal-bg" id="pdLinkModal" style="z-index:99991"><form class="modal" method="post" style="width:460px;max-width:94vw">
  <div class="modal-head"><span>🔗 Link Pick & Drop Order</span><span class="mx" onclick="pdCloseLink()">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="link_manual"><input type="hidden" name="order_id" id="pdlk_oid"><input type="hidden" name="m" value="<?= e($m) ?>">
  <div class="modal-body">
    <div class="full"><div class="muted" style="font-size:12px">Order <b id="pdlk_code"></b> · phone <b id="pdlk_phone"></b></div></div>
    <div class="full"><label>Pick & Drop Order ID</label><input name="pd_id" id="pdlk_pdid" required placeholder="e.g. XGAC-17"></div>
    <div class="full"><label style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" name="force" value="1" style="width:auto"> Link anyway even if the phone doesn't match</label></div>
    <div class="full muted" style="font-size:11.5px">We fetch the Pick & Drop order and verify the customer phone matches before linking — their delivery charge is recorded automatically.</div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="pdCloseLink()">Cancel</button><button class="btn btn-primary">🔗 Verify &amp; Link</button></div>
</form></div>

<script>
function pdLinkFor(id,code,phone){
  document.getElementById('pdlk_oid').value=id;
  document.getElementById('pdlk_code').textContent=code||('#'+id);
  document.getElementById('pdlk_phone').textContent=phone||'—';
  document.getElementById('pdlk_pdid').value='';
  document.getElementById('pdLinkModal').classList.add('open');document.body.classList.add('modal-open');
  setTimeout(function(){document.getElementById('pdlk_pdid').focus();},60);
}
function pdCloseLink(){document.getElementById('pdLinkModal').classList.remove('open');document.body.classList.remove('modal-open');}
(function(){var mm=document.getElementById('pdLinkModal');if(mm)mm.addEventListener('click',function(e){if(e.target===mm)pdCloseLink();});})();
</script>
<script>
/* ---- search + client-side pagination (same pattern as the NCM Courier page) ---- */
var PDPG={page:1,per:25};
function pdPgRender(rows,st,pagerId){
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
      pdPgRender(rows,st,pagerId);
      var top=el.closest('.panel'); if(top)top.scrollIntoView({behavior:'smooth',block:'start'}); };
  });
}
function pdOrdRows(){
  var q=(document.getElementById('pdSearch').value||'').toLowerCase().trim();
  var all=Array.prototype.slice.call(document.querySelectorAll('#pdRows tr[data-s]'));
  document.querySelectorAll('#pdRows tr:not([data-s])').forEach(function(tr){tr.style.display=q?'none':'';});
  return all.filter(function(tr){
    var hit=(!q||tr.getAttribute('data-s').indexOf(q)!==-1);
    if(!hit)tr.style.display='none';
    return hit;
  });
}
function pdRenderPage(){ pdPgRender(pdOrdRows(),PDPG,'pdPager'); }
function pdFilter(){ PDPG.page=1; pdRenderPage(); }
document.addEventListener('DOMContentLoaded',pdRenderPage);
function pdCpy(t,btn){ (navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(function(){ var o=btn.textContent; btn.textContent='✓'; setTimeout(function(){btn.textContent=o;},900); }).catch(function(){ prompt('Copy:',t); }); }
var PD_ROWS=<?= json_encode(array_map(function($r){$o=$r['o']; $st=(!empty($o['pd_order_id'])&&!empty($o['pd_status']))?$o['pd_status']:ucfirst($o['status']); return ['code'=>$o['code'],'customer'=>$o['customer'],'phone'=>$o['phone'],'cod'=>$r['cod'],'age'=>$r['age'],'pdid'=>$o['pd_order_id'],'status'=>$st];}, $pdFiltered)) ?>;
function pdExportCSV(){
  var h=['Order','Customer','Phone','COD','Age(days)','PD ID','Status'];
  var lines=[h.join(',')];
  PD_ROWS.forEach(function(r){lines.push([r.code,r.customer,r.phone,r.cod,r.age,r.pdid,r.status].map(function(v){return '"'+String(v==null?'':v).replace(/"/g,'""')+'"';}).join(','));});
  var blob=new Blob([lines.join('\n')],{type:'text/csv'});
  var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='pickndrop_orders.csv';a.click();
}
var PD_DEFAULT_BRANCH=<?= json_encode($pdDefaultBranch) ?>;
function pdBestBranch(addr){
  addr=(addr||'').toUpperCase();
  var opts=document.querySelectorAll('#pdBrList option'), best='',bl=0;
  opts.forEach(function(o){ var v=(o.value||'').toUpperCase(); if(v && addr.indexOf(v)>-1 && v.length>bl){best=o.value;bl=v.length;} });
  return best;
}
function pdBookFor(o){
  document.getElementById('pdbk_order_id').value=o.id;
  document.getElementById('pdbk_name').value=o.name||'';
  document.getElementById('pdbk_phone').value=o.phone||'';
  document.getElementById('pdbk_address').value=o.address||'';
  document.getElementById('pdbk_cod').value=o.cod||0;
  document.getElementById('pdbk_ref').value=o.ref||'';
  document.getElementById('pdbk_pkg').value=o.package||'';
  document.getElementById('pdbk_branch').value=pdBestBranch(o.address)||PD_DEFAULT_BRANCH;
  document.getElementById('pdbk_order_type').value='Regular';
  document.getElementById('pdBookModal').classList.add('open');document.body.classList.add('modal-open');
}
function pdBulkConfirm(){
  var br=(document.getElementById('pdBulkBranch').value||'').trim();
  if(!br){ alert('Pick the destination branch first — it\'s used for every selected order.'); return false; }
  return confirm('Book all selected orders on Pick & Drop → branch: '+br+'?');
}
function pdCloseBook(){document.getElementById('pdBookModal').classList.remove('open');document.body.classList.remove('modal-open');}
(function(){var mm=document.getElementById('pdBookModal');if(mm)mm.addEventListener('click',function(e){if(e.target===mm)pdCloseBook();});})();

function pdSelCount(){
  var boxes=Array.prototype.slice.call(document.querySelectorAll('.pdSel:checked'));
  var n=boxes.length;
  var lbl=document.getElementById('pdSelN'); if(lbl) lbl.textContent=n;
  var bar=document.getElementById('pdBulkBar'); if(bar) bar.classList.toggle('on',n>0);
  var idsEl=document.getElementById('pdBulkIds'); if(idsEl) idsEl.value=boxes.map(function(b){return b.value;}).join(',');
}
function pdSelAll(master){
  document.querySelectorAll('.pdSel').forEach(function(b){ var tr=b.closest('tr'); if(tr && tr.style.display!=='none') b.checked=master.checked; });
  pdSelCount();
}
function pdSelClear(){ document.querySelectorAll('.pdSel').forEach(function(b){b.checked=false;}); pdSelCount(); }
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
