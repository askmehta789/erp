<?php
require_once __DIR__.'/daraz_api.php'; require_login(); require_page_access();
$PAGE_TITLE='Daraz';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;
$darazConnected = (new Daraz())->connected();
$darazAccount    = setting('daraz_account','');

/* Daraz is a courier/channel on your normal Sales orders.
   This page is a DASHBOARD: it reads orders where Courier = Daraz.
   Convention: put the Daraz Order ID in the order's Remarks. */

/* make sure the Daraz courier exists so it appears in the Sales courier dropdown */
$dzCourier = row("SELECT id FROM couriers WHERE LOWER(name)='daraz'");
if (!$dzCourier) { try { q("INSERT INTO couriers(name,status) VALUES('Daraz','active')"); } catch (Exception $e) { try { q("INSERT INTO couriers(name) VALUES('Daraz')"); } catch (Exception $e2) {} } $dzCourier = row("SELECT id FROM couriers WHERE LOWER(name)='daraz'"); }
$dzId = (int)($dzCourier['id'] ?? 0);

/* ads spend table (Daraz sponsored ads — separate from other expenses) */
try { q("CREATE TABLE IF NOT EXISTS daraz_ads(
  id INT AUTO_INCREMENT PRIMARY KEY,
  spend_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  note VARCHAR(160) DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  if (($_POST['_action'] ?? '')==='set_status') {
    $id=(int)($_POST['id'] ?? 0);
    $o=row("SELECT * FROM orders WHERE id=?",[$id]);
    if ($o) {
      $new=in_array($_POST['status'] ?? '', ['pending','processing','shipped','delivered','returned','cancelled'],true) ? $_POST['status'] : $o['status'];
      $old=$o['status'];
      $dc = ($_POST['delivery_charge'] ?? '')!=='' ? max(0,(float)$_POST['delivery_charge']) : (float)$o['delivery_charge'];
      $pay=$o['payment_status'];
      $cc=(float)$o['cancel_charge'];
      if ($new!==$old) {
        fifo_status_change($id,(int)$o['product_id'],(int)$o['qty'],$old,$new);   /* stock + COGS sync */
        if ($new==='delivered') $pay='paid';                                      /* COD collected on delivery */
        if (in_array($new,['returned','cancelled'],true) && $cc<=0) {
          try { $cc=(float)val("SELECT COALESCE(cancel_charge,0) FROM couriers WHERE id=?",[(int)$o['courier_id']]); } catch (Exception $e) { $cc=0; }
        }
      }
      q("UPDATE orders SET status=?, delivery_charge=?, cancel_charge=?, payment_status=? WHERE id=?",[$new,$dc,$cc,$pay,$id]);
      log_activity("Daraz order {$o['code']}: status $old→$new, delivery ".money($dc),'Daraz');
      flash("Order {$o['code']} updated — Sales page reflects it instantly.");
    }
    header('Location: daraz.php?m='.urlencode($_POST['m'] ?? date('Y-m'))); exit;
  }
  check_csrf();
  $act = $_POST['_action'] ?? '';
  if ($act==='add_ad') {
    $amt=(float)($_POST['amount'] ?? 0);
    if ($amt>0) {
      q("INSERT INTO daraz_ads(spend_date,amount,note) VALUES(?,?,?)",
        [($_POST['spend_date'] ?? '') ?: date('Y-m-d'), $amt, trim($_POST['note'] ?? '')]);
      log_activity("Daraz ad spend ".money($amt),'Daraz'); flash('Ad spend recorded: '.money($amt));
    } else flash('Amount must be greater than 0.');
    header('Location: daraz.php?m='.urlencode($_POST['m'] ?? date('Y-m'))); exit;
  }
  if ($act==='del_ad' && $isAdmin) {
    q("DELETE FROM daraz_ads WHERE id=?",[(int)($_POST['id'] ?? 0)]);
    flash('Ad entry deleted.'); header('Location: daraz.php?m='.urlencode($_POST['m'] ?? date('Y-m'))); exit;
  }
  if ($act==='map_sku') {
    try {
      $r = daraz_map_sku_to_product(trim((string)($_POST['sku'] ?? '')), (int)($_POST['product_id'] ?? 0));
      flash($r['fixed']>0 ? "Mapped — fixed {$r['fixed']} existing order line(s), and future syncs will match it automatically." : 'Mapped — future syncs will match it automatically.');
    } catch (Exception $ex) { flash('Could not map: '.$ex->getMessage()); }
    header('Location: daraz.php?m='.urlencode($_POST['m'] ?? date('Y-m'))); exit;
  }
  if ($act==='sync_now') {
    try {
      $r = daraz_sync_orders(14);
      if ($r['created'] > 0) {
        $msg = "Synced: {$r['created']} order line(s) imported";
        if ($r['unmatched'] > 0) $msg .= ", {$r['unmatched']} with an unmatched SKU — map them below";
        if (!empty($r['cancelled_skipped'])) $msg .= ", {$r['cancelled_skipped']} cancelled line(s) skipped (not imported)";
        if ($r['skipped'] > 0) $msg .= " ({$r['skipped']} already synced before)";
        flash($msg.'.');
      } else {
        $extra = !empty($r['cancelled_skipped']) ? " ({$r['cancelled_skipped']} cancelled line(s) skipped)" : '';
        flash($r['fetched']>0 ? "No new orders — all {$r['fetched']} seen were already synced or cancelled.{$extra}" : 'No Daraz orders found in the last 14 days.');
      }
    } catch (Exception $ex) { flash('Sync failed: '.$ex->getMessage()); }
    header('Location: daraz.php?m='.urlencode($_POST['m'] ?? date('Y-m'))); exit;
  }
}

/* ---- month filter ---- */
$m = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$mStart = $m.'-01'; $mEnd = date('Y-m-t', strtotime($mStart));

$orders = rows("SELECT o.*, p.name AS product_name FROM orders o
                LEFT JOIN products p ON p.id=o.product_id
                WHERE o.courier_id=? AND o.order_date BETWEEN ? AND ?
                ORDER BY o.order_date DESC, o.id DESC",[$dzId,$mStart,$mEnd]);
$ads = rows("SELECT * FROM daraz_ads WHERE spend_date BETWEEN ? AND ? ORDER BY spend_date DESC, id DESC",[$mStart,$mEnd]);
$darazUnmatched = $darazConnected ? daraz_unmatched_skus() : [];
$allProducts = $darazUnmatched ? rows("SELECT id,name,sku FROM products ORDER BY name") : [];

$mOrders=count($orders); $mRev=0; $mCost=0; $mDelv=0; $mDel=0; $mRet=0; $mExch=0; $mGross=0; $mUnits=0;
foreach ($orders as $o) {
  if ($o['status']==='delivered') {
    $mDel++; $mUnits+=(int)$o['qty']; $line=(float)$o['sell_price']*(int)$o['qty'];
    $mRev+=$line; $mCost+=(float)$o['cost_price']*(int)$o['qty']; $mDelv+=(float)$o['delivery_charge'];
    $mGross+=order_profit($o);
  } elseif (in_array($o['status'],['returned','cancelled'],true)) { $mRet++; $mGross+=order_profit($o); }
  elseif ($o['status']==='exchanged') { $mExch++; $mGross+=order_profit($o); }
}
$mAds=(float)val("SELECT COALESCE(SUM(amount),0) FROM daraz_ads WHERE spend_date BETWEEN ? AND ?",[$mStart,$mEnd]);
$mNet=$mGross-$mAds;
$mRoas=$mAds>0?round($mRev/$mAds,2):null;

/* bar widths for the metric tiles — all bounded 0-100 */
$pDel   = $mOrders ? round($mDel/$mOrders*100) : 0;
$pCost  = $mRev>0 ? min(100, round($mCost/$mRev*100)) : 0;
$pAds   = $mRev>0 ? min(100, round($mAds/$mRev*100)) : 0;
$pNet   = $mRev>0 ? max(0, min(100, round($mNet/$mRev*100))) : 0;
$pRoas  = $mRoas!==null ? max(0, min(100, round($mRoas/5*100))) : 0;

require __DIR__.'/includes/header.php';
echo delivery_disabled_banner('daraz.php');
?>
<style>
.ncmv3 .dz-grid{display:grid;grid-template-columns:1fr 320px;gap:16px;margin-top:20px;align-items:start}
@media(max-width:1024px){.ncmv3 .dz-grid{grid-template-columns:1fr}}
/* !important needed to beat the app-wide "input, select, textarea { background !important }"
   base style — found via computed-style inspection, not a guess: without it every status pill
   rendered as invisible white-on-white regardless of selector specificity. */
.dz-status{display:inline-block;padding:4px 11px;border-radius:999px;font-size:11px;font-weight:800;border:none!important;appearance:none;cursor:pointer;color:#fff!important;box-shadow:none!important}
.dz-status.st-pending,.dz-status.st-processing{background:#64748b!important}
.dz-status.st-shipped{background:#e0851b!important}
.dz-status.st-delivered{background:#0f9d63!important}
.dz-status.st-returned,.dz-status.st-cancelled{background:#dc4437!important}
.dz-dc{font-size:11px;padding:5px 7px;border-radius:8px;border:1px solid rgba(148,163,184,.4);width:72px;background:rgba(255,255,255,.75)}
body.dark .dz-dc{background:rgba(2,6,23,.5);color:var(--ink);border-color:rgba(148,163,184,.25)}
.dz-oid{font-weight:800;display:flex;align-items:center;gap:6px}
.dz-oid .dz-dot{width:7px;height:7px;border-radius:50%;background:#f85606;flex:none}
.dz-warn{color:#b45309;font-size:11px;margin-top:2px;display:block;max-width:260px;white-space:normal}
.dz-map-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:12px 0;border-bottom:1px solid rgba(148,163,184,.18)}
.dz-map-row:last-child{border-bottom:none}
.dz-map-title{font-weight:700;font-size:13px}
.dz-map-sku{color:var(--muted);font-size:11px;margin-top:1px}
.dz-map-panel{border-radius:18px;padding:4px 20px 8px;margin-bottom:18px;
  background:linear-gradient(135deg,rgba(251,191,36,.14),rgba(249,115,22,.08));
  border:1px solid rgba(245,158,11,.35);box-shadow:0 8px 28px rgba(245,158,11,.10)}
body.dark .dz-map-panel{background:linear-gradient(135deg,rgba(251,191,36,.08),rgba(249,115,22,.05));border-color:rgba(245,158,11,.25)}
.dz-map-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 0 6px}
.dz-map-head h2{font-size:14px;margin:0;font-weight:800;color:#b45309}
body.dark .dz-map-head h2{color:#fbbf24}
.dz-empty{padding:40px 20px;text-align:center;color:var(--muted)}
.dz-empty .big{font-size:32px;margin-bottom:8px}
</style>

<div class="ncmv3">
<div class="ncm-hero">
  <div class="nh-left">
    <h1><img src="assets/daraz-icon.ico" alt="Daraz" style="height:28px;vertical-align:-6px;margin-right:6px"><span class="grad">Daraz Channel</span></h1>
    <p>Auto-synced orders · SKU mapping · ad spend &amp; ROAS — cancelled Daraz orders are never imported</p>
    <div class="nh-status">
      <?php if ($darazConnected): ?>
        <span class="live"><span class="d"></span> LIVE · Connected as <?= e($darazAccount) ?></span>
      <?php else: ?>
        <span class="live off"><span class="d"></span> Not connected — <a href="settings.php">authorize in Settings → Channel / Daraz API</a></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="nh-actions">
    <form method="get" style="display:inline-flex;gap:6px;align-items:center">
      <input type="month" name="m" value="<?= e($m) ?>" style="border-radius:999px;padding:9px 14px;border:1px solid rgba(148,163,184,.35);background:rgba(255,255,255,.7);font-size:13px">
      <button class="btn nh-ghost">Go</button>
    </form>
    <?php if ($darazConnected): ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="sync_now"><input type="hidden" name="m" value="<?= e($m) ?>">
        <button class="btn nh-ghost" title="Pull new Daraz orders (last 14 days) into Sales — cancelled ones are skipped">🔄 Sync Orders Now</button>
      </form>
    <?php endif; ?>
    <a class="btn nh-cta" href="sales.php?new=daraz">＋ New Daraz Order</a>
  </div>
</div>

<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<?php if ($darazUnmatched): ?>
<div class="dz-map-panel">
  <div class="dz-map-head">
    <h2>🔗 Map Unmatched Daraz Products (<?= count($darazUnmatched) ?>)</h2>
    <span class="muted" style="font-size:12px">Pick the matching product — fixes every order already imported for it, and matches automatically from now on.</span>
  </div>
  <?php foreach ($darazUnmatched as $um): ?>
    <form method="post" class="dz-map-row">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="map_sku"><input type="hidden" name="m" value="<?= e($m) ?>">
      <input type="hidden" name="sku" value="<?= e($um['daraz_sku']) ?>">
      <div style="min-width:220px;flex:1">
        <div class="dz-map-title"><?= e($um['sample_name'] ?: 'Daraz item') ?></div>
        <div class="dz-map-sku">SKU: <?= e($um['daraz_sku']) ?> · <?= (int)$um['line_count'] ?> order line(s) waiting</div>
      </div>
      <select name="product_id" required style="flex:1;min-width:200px;border-radius:10px;padding:8px 10px;border:1px solid rgba(148,163,184,.35);background:rgba(255,255,255,.8)">
        <option value="">— pick the matching product —</option>
        <?php foreach ($allProducts as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?><?= $p['sku'] ? ' ('.e($p['sku']).')' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn nh-cta" style="padding:8px 16px;font-size:12.5px">✓ Map it</button>
    </form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="mgrid">
  <div class="metric blue"><span class="g">🛍</span><div class="mv"><?= $mOrders ?></div><div class="ml"><?= e(date('M Y',strtotime($mStart))) ?> · <?= $mDel ?> delivered</div><div class="bar"><i style="width:100%;background:linear-gradient(90deg,#3b82f6,#6366f1)"></i></div></div>
  <div class="metric green"><span class="g">💰</span><div class="mv" style="font-size:19px"><?= money($mRev) ?></div><div class="ml">Delivered Rev · <?= number_format($mUnits) ?> pcs</div><div class="bar"><i style="width:<?= $pDel ?>%;background:#22c55e"></i></div></div>
  <div class="metric amber"><span class="g">📦</span><div class="mv" style="font-size:19px"><?= money($mCost) ?></div><div class="ml">Product Cost · FIFO</div><div class="bar"><i style="width:<?= $pCost ?>%;background:#f59e0b"></i></div></div>
  <div class="metric orange"><span class="g">📣</span><div class="mv" style="font-size:19px"><?= money($mAds) ?></div><div class="ml">Ads Spend</div><div class="bar"><i style="width:<?= $pAds ?>%;background:#f97316"></i></div></div>
  <div class="metric <?= $mNet>=0?'teal':'red' ?>"><span class="g">🧾</span><div class="mv" style="font-size:19px"><?= money($mNet) ?></div><div class="ml">Net (after ads)</div><div class="bar"><i style="width:<?= $pNet ?>%;background:<?= $mNet>=0?'#14b8a6':'#ef4444' ?>"></i></div></div>
  <div class="metric indigo"><span class="g">🎯</span><div class="mv"><?= $mRoas!==null?$mRoas.'×':'—' ?></div><div class="ml">ROAS · revenue/ads</div><div class="bar"><i style="width:<?= $pRoas ?>%;background:#6366f1"></i></div></div>
</div>

<div class="dz-grid">
<div class="panel">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px"><h2>📦 Daraz Orders — <?= e(date('F Y',strtotime($mStart))) ?></h2>
    <input id="dzSearch" class="ncm-search" placeholder="🔍 Search order id, customer, phone, product…" style="max-width:280px;border-radius:999px;padding:9px 14px" oninput="dzFilter()">
    <span class="muted" style="font-size:12px"><?= $mRet ?> returned/cancelled · edit any order in <a href="sales.php">Sales</a></span></div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;padding:0 18px 10px" id="dzChips">
    <?php $dzCounts=['all'=>count($orders)]; foreach($orders as $oC){ $dzCounts[$oC['status']]=($dzCounts[$oC['status']]??0)+1; }
    foreach(['all'=>'All','pending'=>'Pending','processing'=>'Processing','shipped'=>'Shipped','delivered'=>'Delivered','returned'=>'Returned','cancelled'=>'Cancelled'] as $k=>$lbl): if($k!=='all'&&empty($dzCounts[$k]))continue; ?>
      <button class="ntab<?= $k==='all'?' on':'' ?>" data-f="<?= $k ?>" onclick="dzTab(this)"><?= $lbl ?> <span class="ntab-n"><?= (int)($dzCounts[$k]??0) ?></span></button>
    <?php endforeach; ?>
  </div>
<div class="table-wrap"><table class="tbl led-tbl"><thead><tr>
    <th>Daraz Order ID</th><th>Order</th><th>Date</th><th>Customer</th><th>Product</th><th>Qty</th><th>Price</th><th>Cost</th><th>Profit</th><th>Status</th>
  </tr></thead><tbody>
  <?php foreach($orders as $o):
    $isDel=$o['status']==='delivered';
    $prof=order_profit($o);
    $pot=((float)$o['sell_price']-(float)$o['cost_price'])*(int)$o['qty']-(float)$o['delivery_charge'];
    $hasWarn = !empty($o['daraz_order_id']) && !empty($o['remarks']) && strpos($o['remarks'],'⚠')!==false;
  ?>
    <tr data-s="<?= e(strtolower(($o['remarks']??'').' '.$o['code'].' '.($o['customer']??'').' '.($o['phone']??'').' '.($o['product_name']??''))) ?>" data-st="<?= e($o['status']) ?>">
      <td>
        <div class="dz-oid"><span class="dz-dot"></span><?= e($o['daraz_order_id'] ?: ($o['remarks'] ?: '—')) ?></div>
        <?php if($hasWarn): ?><span class="dz-warn">⚠ <?= e(str_replace('Daraz #'.$o['daraz_order_id'].' ⚠ ','',$o['remarks'])) ?></span><?php endif; ?>
      </td>
      <td class="muted"><?= e($o['code']) ?></td>
      <td><?= e($o['order_date']) ?></td>
      <td><b><?= e($o['customer'] ?: '—') ?></b></td>
      <td><b><?= e($o['product_name'] ?: '—') ?></b></td>
      <td style="text-align:right"><?= (int)$o['qty'] ?></td>
      <td style="text-align:right"><?= money($o['sell_price']) ?></td>
      <td style="text-align:right"><?= money($o['cost_price']) ?></td>
      <td style="text-align:right;font-weight:800;color:<?= ($isDel?$prof:$pot)>=0?'var(--green)':'var(--red)' ?>">
        <?= $isDel ? money($prof) : (in_array($o['status'],['returned','cancelled','exchanged'],true) ? money($prof) : '≈ '.money($pot)) ?></td>
      <td>
        <form method="post" style="display:flex;gap:5px;align-items:center;flex-wrap:wrap">
          <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="set_status">
          <input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="m" value="<?= e($m) ?>">
          <select name="status" class="dz-status st-<?= e($o['status']) ?>" onchange="this.className='dz-status st-'+this.value">
            <?php foreach(['pending','processing','shipped','delivered','returned','cancelled'] as $st): ?>
              <option value="<?= $st ?>"<?= $o['status']===$st?' selected':'' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="number" name="delivery_charge" value="<?= (float)$o['delivery_charge']>0?e((float)$o['delivery_charge']):'' ?>" placeholder="Dz chg" step="any" min="0" class="dz-dc" title="Daraz delivery/commission charge (Rs.)">
          <button class="btn btn-sm" title="Save — syncs Sales instantly">💾</button>
        </form>
      </td>
    </tr>
  <?php endforeach; if(!$orders): ?>
  <tr><td colspan="10">
    <div class="dz-empty">
      <div class="big">📦</div>
      <?php if ($darazConnected): ?>
        No Daraz orders this month yet — click <b>🔄 Sync Orders Now</b> above, or <b>＋ New Daraz Order</b> to add one by hand.
      <?php else: ?>
        No Daraz orders this month — connect Daraz in Settings to auto-sync, or click <b>＋ New Daraz Order</b> to add one by hand (put the Daraz Order ID in Remarks).
      <?php endif; ?>
    </div>
  </td></tr>
  <?php endif; ?>
  </tbody></table></div>
</div>

<aside class="panel">
  <div class="panel-head"><h2>📣 Ads Spend</h2><span class="muted" style="font-size:12px"><?= e(date('M Y',strtotime($mStart))) ?>: <b><?= money($mAds) ?></b></span></div>
  <div class="panel-body" style="padding-top:8px">
    <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="add_ad"><input type="hidden" name="m" value="<?= e($m) ?>">
      <input type="date" name="spend_date" value="<?= date('Y-m-d') ?>" style="width:128px">
      <input type="number" step="any" min="1" name="amount" placeholder="Amount" required style="flex:1;min-width:90px">
      <input name="note" placeholder="note (optional)" style="width:100%">
      <button class="btn nh-cta" style="width:100%">＋ Add Spend</button>
    </form>
    <table class="tbl led-tbl"><thead><tr><th>Date</th><th style="text-align:right">Amount</th><th>Note</th><?php if($isAdmin): ?><th></th><?php endif; ?></tr></thead><tbody>
      <?php foreach($ads as $a): ?>
      <tr><td><?= e($a['spend_date']) ?></td>
          <td style="text-align:right;font-weight:700"><?= money($a['amount']) ?></td>
          <td class="muted" style="white-space:normal"><?= e($a['note'] ?: '—') ?></td>
          <?php if($isAdmin): ?><td><form method="post" style="display:inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="del_ad"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="m" value="<?= e($m) ?>"><button class="rowdel">🗑</button></form></td><?php endif; ?>
      </tr>
      <?php endforeach; if(!$ads) echo '<tr><td colspan="4"><div class="empty" style="padding:14px">No ad spend recorded this month.</div></td></tr>'; ?>
    </tbody></table>
  </div>
</aside>
</div>
</div><!-- /ncmv3 -->
<script>
var dzF='all';
function dzTab(b){dzF=b.getAttribute('data-f');document.querySelectorAll('#dzChips .ntab').forEach(function(x){x.classList.toggle('on',x===b);});dzFilter();}
function dzFilter(){
  var q=(document.getElementById('dzSearch').value||'').toLowerCase().trim();
  document.querySelectorAll('tr[data-s]').forEach(function(tr){
    var okS=!q||tr.getAttribute('data-s').indexOf(q)>-1;
    var okF=dzF==='all'||tr.getAttribute('data-st')===dzF;
    tr.style.display=(okS&&okF)?'':'none';
  });
}
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
