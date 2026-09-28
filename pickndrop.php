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

/* build + fire one Pick & Drop order; returns [orderID, deliveryCharge, trackingUrl]; throws on failure */
function pd_book_one(array $bk, array $branchNames) {
  $phone = pd_norm_phone($bk['phone'] ?? '');
  if ($phone==='') throw new Exception('invalid phone "'.($bk['phone']??'').'" — Pick & Drop needs a 10-digit mobile');
  $branch = pd_match_branch($bk['branch'] ?? '', $branchNames);
  if ($branch==='') throw new Exception('destination branch "'.($bk['branch']??'').'" is not a Pick & Drop branch');
  $addr = trim((string)($bk['address'] ?? ''));
  $pickup = trim((string)($bk['pickup'] ?? '')) ?: trim((string)setting('pd_pickup_address',''));
  if ($pickup==='') throw new Exception('no pickup business address set — add one on this page first');
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
  $p2 = pd_norm_phone($bk['phone2'] ?? ''); if ($p2!=='') $payload['secondaryMobileNo']=$p2;
  if ($addr!=='') $payload['landmark']=$addr;
  $w = (float)($bk['weight'] ?? 0); if ($w>0) $payload['weight']=$w;
  $ref = trim((string)($bk['ref'] ?? '')); if ($ref!=='') $payload['vendorTrackingNumber']=$ref;
  $ins = trim((string)($bk['instruction'] ?? '')); if ($ins!=='') $payload['instruction']=$ins;

  $res = pickndrop()->createOrder($payload);
  $orderId = (string)($res['orderID'] ?? '');
  if ($orderId==='') throw new Exception('Pick & Drop did not return an order id: '.json_encode($res));
  $charge = isset($res['delivery_charge']) ? (float)$res['delivery_charge'] : null;
  $trackUrl = (string)($res['tracking_url'] ?? '');
  return [$orderId, $charge, $trackUrl];
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
      [$pdOrderId,$charge,$trackUrl] = pd_book_one([
        'name'=>$_POST['name']??'','phone'=>$_POST['phone']??'','phone2'=>$_POST['phone2']??'',
        'cod'=>$_POST['cod_charge']??0,'address'=>$_POST['address']??'','branch'=>$_POST['branch']??'',
        'pickup'=>$_POST['pickup']??'','package'=>$_POST['package']??'','weight'=>$_POST['weight']??'',
        'ref'=>$_POST['vref_id']??'','instruction'=>$_POST['instruction']??'',
      ], $bn);
      $oid=(int)($_POST['order_id'] ?? 0);
      if ($oid) {
        q("UPDATE orders SET pd_order_id=?, pd_status='Open', pd_tracking_url=?, courier_id=? WHERE id=?",
          [$pdOrderId,$trackUrl,$pdId,$oid]);
        if ($charge!==null) q("UPDATE orders SET delivery_charge=? WHERE id=?",[$charge,$oid]);
        if ((string)($_POST['also_ship'] ?? '')==='1') {
          $o=row("SELECT * FROM orders WHERE id=?",[$oid]);
          if ($o && $o['status']==='pending') { fifo_status_change($oid,(int)$o['product_id'],(int)$o['qty'],'pending','processing'); q("UPDATE orders SET status='processing' WHERE id=?",[$oid]); }
        }
      }
      log_activity('Booked Pick & Drop order '.$pdOrderId,'Pick & Drop');
      flash("Booked on Pick & Drop ✓ Order ID: $pdOrderId".($charge!==null?(' · delivery '.money($charge).' added to sale'):''));
    }
    elseif ($act==='book_bulk') {
      $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
      $ids = array_slice($ids, 0, 40);
      if(function_exists('set_time_limit')) @set_time_limit(300);
      $bn=[]; foreach (pickndrop()->branches() as $b) if(!empty($b['name'])) $bn[]=$b['name'];
      $pickup = trim((string)setting('pd_pickup_address',''));
      $ok=[]; $fail=[];
      foreach ($ids as $oid) {
        $o = row("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id WHERE o.id=?",[$oid]);
        if (!$o) { $fail[]="#$oid: not found"; continue; }
        if (!empty($o['pd_order_id'])) { $fail[]=e($o['code']).": already booked"; continue; }
        $dest = pd_guess_branch($o['address'], $bn);
        if ($dest==='') { $fail[]=e($o['code']).": no branch match in address — book manually"; continue; }
        try {
          [$nid,$chg,$trackUrl] = pd_book_one([
            'name'=>$o['customer'],'phone'=>$o['phone'],
            'cod'=>(strtolower((string)$o['payment_type'])==='cod' ? (float)$o['sell_price']*(int)$o['qty'] : 0),
            'address'=>$o['address'],'branch'=>$dest,'pickup'=>$pickup,
            'package'=>trim(($o['product_name']?:'Goods').' x'.(int)$o['qty']),'ref'=>$o['code'],
          ], $bn);
          q("UPDATE orders SET pd_order_id=?, pd_status='Open', pd_tracking_url=?, courier_id=? WHERE id=?", [$nid,$trackUrl,$pdId,$oid]);
          if ($chg!==null) q("UPDATE orders SET delivery_charge=? WHERE id=?", [$chg,$oid]);
          $ok[]=e($o['code'])."→$nid ($dest)";
        } catch (Exception $ex) { $fail[]=e($o['code']).': '.$ex->getMessage(); }
      }
      log_activity('Bulk Pick & Drop booking: '.count($ok).' ok, '.count($fail).' failed','Pick & Drop');
      $msg = count($ok).' booked ✓'.($ok?(' — '.implode(', ',$ok)):'');
      if ($fail) $msg .= '  ·  '.count($fail).' failed: '.implode(' | ',$fail);
      flash($msg);
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
        $mapped = $raw!=='' ? pd_to_local_status($raw) : null;
        if ($mapped && $mapped!==$o['status']) {
          fifo_status_change($oid,(int)$o['product_id'],(int)$o['qty'],$o['status'],$mapped);
          if ($mapped==='delivered') q("UPDATE orders SET status='delivered',payment_status='paid' WHERE id=?",[$oid]);
          else q("UPDATE orders SET status=? WHERE id=?",[$mapped,$oid]);
        }
        flash("Synced {$o['code']} — Pick & Drop status: ".($raw?:'unknown'));
      }
    }
    elseif ($act==='sync_all') {
      $rows = rows("SELECT * FROM orders WHERE courier_id=? AND COALESCE(pd_order_id,'')<>'' AND status NOT IN ('delivered','cancelled','returned') LIMIT 40",[$pdId]);
      $n=0;
      foreach ($rows as $o) {
        try {
          $d = pickndrop()->orderDetails($o['pd_order_id']);
          $raw = (string)($d['status'] ?? '');
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
      log_activity("Pick & Drop sync-all: ".count($rows)." checked, $n updated",'Pick & Drop');
      flash(count($rows)." order(s) checked, $n updated.");
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
</style>

<div class="pd-hero">
  <div class="pd-hero-top">
    <span style="font-size:28px">🟢</span>
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
      <?php foreach($branches as $b): ?>
        <span class="pd-branch-chip">📍 <?= e($b['branch_name'] ?? ($b['name'] ?? '?')) ?><?= !empty($b['branch_code']) ? ' · '.e($b['branch_code']) : '' ?></span>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<datalist id="pdBrList"><?php foreach($branches as $b): ?><option value="<?= e($b['name'] ?? '') ?>"><?php endforeach; ?></datalist>

<div class="panel">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px"><h2>📦 Pick & Drop Orders — <?= e(date('F Y',strtotime($mStart))) ?></h2>
    <input id="pdSearch" placeholder="🔍 Search order code, customer, phone, product…" style="max-width:280px" oninput="pdFilter()">
    <form method="get" style="display:flex;gap:6px;align-items:center"><input type="month" name="m" value="<?= e($m) ?>"><button class="btn btn-sm">Go</button></form>
    <span class="muted" style="font-size:12px"><?= $mRet ?> returned/cancelled · edit any order in <a href="sales.php">Sales</a></span></div>
  <div style="display:flex;gap:6px;flex-wrap:wrap;padding:0 18px 10px" id="pdChips">
  <?php $pdCounts=['all'=>count($orders)]; foreach($orders as $oC){ $pdCounts[$oC['status']]=($pdCounts[$oC['status']]??0)+1; }
  foreach(['all'=>'All','pending'=>'Pending','processing'=>'Processing','shipped'=>'Shipped','delivered'=>'Delivered','returned'=>'Returned','cancelled'=>'Cancelled'] as $k=>$lbl): if($k!=='all'&&empty($pdCounts[$k]))continue; ?>
    <button class="ntab<?= $k==='all'?' on':'' ?>" data-f="<?= $k ?>" onclick="pdTab(this)"><?= $lbl ?> <span class="ntab-n"><?= (int)($pdCounts[$k]??0) ?></span></button>
  <?php endforeach; ?>
  </div>
  <?php if($pdConfigured): ?>
  <div class="pd-bulkbar" id="pdBulkBar">
    <span><b id="pdSelN">0</b> selected</span>
    <form method="post" id="pdBulkForm" style="display:inline">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="book_bulk"><input type="hidden" name="ids" id="pdBulkIds"><input type="hidden" name="m" value="<?= e($m) ?>">
      <button class="btn btn-sm btn-primary" type="submit" onclick="return confirm('Book all selected orders on Pick & Drop? Destination branch is guessed from each address.')">🚀 Book Selected</button>
    </form>
    <button class="btn btn-sm" type="button" onclick="pdSelClear()">✕ Clear</button>
  </div>
  <?php endif; ?>
  <div class="table-wrap"><table class="tbl led-tbl"><thead><tr>
    <?php if($pdConfigured): ?><th style="width:24px"><input type="checkbox" onchange="pdSelAll(this)" title="Select all visible"></th><?php endif; ?>
    <th>Order</th><th>Date</th><th>Customer</th><th>Product</th><th>Qty</th><th>Price</th><th>Pick & Drop</th><th>Status</th>
  </tr></thead><tbody>
  <?php foreach($orders as $o):
    $isDel=$o['status']==='delivered';
    $prof=order_profit($o);
    $pot=((float)$o['sell_price']-(float)$o['cost_price'])*(int)$o['qty']-(float)$o['delivery_charge'];
    $booked = !empty($o['pd_order_id']);
    $canCancel = $booked && !in_array($o['status'],['delivered','returned','cancelled'],true);
  ?>
    <tr data-s="<?= e(strtolower($o['code'].' '.($o['customer']??'').' '.($o['phone']??'').' '.($o['product_name']??''))) ?>" data-st="<?= e($o['status']) ?>">
      <?php if($pdConfigured): ?><td><?php if(!$booked && in_array($o['status'],['pending','processing'],true)): ?><input type="checkbox" class="pdSel" value="<?= (int)$o['id'] ?>" onchange="pdSelCount()"><?php endif; ?></td><?php endif; ?>
      <td class="muted"><?= e($o['code']) ?></td>
      <td><?= e($o['order_date']) ?></td>
      <td><b><?= e($o['customer'] ?: '—') ?></b></td>
      <td><b><?= e($o['product_name'] ?: '—') ?></b></td>
      <td style="text-align:right"><?= (int)$o['qty'] ?></td>
      <td style="text-align:right"><?= money($o['sell_price']) ?></td>
      <td>
        <?php if($booked): ?>
          <b style="font-size:11.5px"><?= e($o['pd_order_id']) ?></b>
          <?php if(!empty($o['pd_status'])): ?><div><span class="pill <?= pd_status_class($o['pd_status']) ?>" style="font-size:9.5px"><?= e($o['pd_status']) ?></span></div><?php endif; ?>
          <?php if(!empty($o['pd_tracking_url'])): ?><a href="<?= e($o['pd_tracking_url']) ?>" target="_blank" rel="noopener" style="font-size:10px">Track ↗</a><?php endif; ?>
          <div style="display:flex;gap:4px;margin-top:3px">
            <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="sync_one"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="m" value="<?= e($m) ?>"><button class="btn btn-sm" title="Sync live status">🔄</button></form>
            <?php if($canCancel): ?><form method="post" style="display:inline" onsubmit="return confirm('Cancel this order with Pick & Drop?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="cancel_pd"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="m" value="<?= e($m) ?>"><button class="btn btn-sm" style="color:var(--red)" title="Cancel with Pick & Drop">✕</button></form><?php endif; ?>
          </div>
        <?php elseif($pdConfigured && in_array($o['status'],['pending','processing'],true)): ?>
          <button class="btn btn-sm btn-primary" onclick='pdBookFor(<?= json_encode(['id'=>(int)$o['id'],'name'=>$o['customer'],'phone'=>$o['phone'],'address'=>$o['address'],'cod'=>strtolower((string)$o['payment_type'])==='cod'?((float)$o['sell_price']*(int)$o['qty']):0,'package'=>trim(($o['product_name']?:'Goods').' x'.(int)$o['qty']),'ref'=>$o['code']], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>🚀 Send</button>
        <?php else: ?><span class="muted" style="font-size:11px">—</span><?php endif; ?>
      </td>
      <td>
        <form method="post" style="display:flex;gap:5px;align-items:center">
          <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="set_status">
          <input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="m" value="<?= e($m) ?>">
          <select name="status" class="pd-st st-<?= e($o['status']) ?>">
            <?php foreach(['pending','processing','shipped','delivered','returned','cancelled'] as $st): ?>
              <option value="<?= $st ?>"<?= $o['status']===$st?' selected':'' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="number" name="delivery_charge" value="<?= (float)$o['delivery_charge']>0?e((float)$o['delivery_charge']):'' ?>" placeholder="Chg" step="any" min="0" style="width:64px" title="Delivery charge (Rs.)">
          <button class="btn btn-sm" title="Save — syncs Sales instantly">💾</button>
        </form>
      </td>
    </tr>
  <?php endforeach; if(!$orders) echo '<tr><td colspan="9"><div class="empty">No Pick & Drop orders this month — click "＋ New Order".</div></td></tr>'; ?>
  </tbody></table></div>
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
    <div><label>Weight (kg)</label><input name="weight" value="1"></div>
    <div><label>Package / Contents</label><input name="package" id="pdbk_pkg" placeholder="e.g. Heel Guard Plus x2"></div>
    <div><label>Your Ref (order code)</label><input name="vref_id" id="pdbk_ref"></div>
    <div class="full"><label>Instructions</label><input name="instruction" placeholder="Optional note for Pick & Drop"></div>
    <div class="full"><label style="display:flex;gap:6px;align-items:center;font-weight:600"><input type="checkbox" name="also_ship" value="1" style="width:auto" checked> Mark order as Processing once booked</label></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="pdCloseBook()">Cancel</button><button class="btn btn-primary">🚀 Book on Pick & Drop</button></div>
</form></div>

<script>
var pdF='all';
function pdTab(b){pdF=b.getAttribute('data-f');document.querySelectorAll('#pdChips .ntab').forEach(function(x){x.classList.toggle('on',x===b);});pdFilter();}
function pdFilter(){
  var q=(document.getElementById('pdSearch').value||'').toLowerCase().trim();
  document.querySelectorAll('tr[data-s]').forEach(function(tr){
    var okS=!q||tr.getAttribute('data-s').indexOf(q)>-1;
    var okF=pdF==='all'||tr.getAttribute('data-st')===pdF;
    tr.style.display=(okS&&okF)?'':'none';
  });
}
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
  document.getElementById('pdbk_branch').value=pdBestBranch(o.address);
  document.getElementById('pdBookModal').classList.add('open');document.body.classList.add('modal-open');
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
