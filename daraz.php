<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
$PAGE_TITLE='Daraz';
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;

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
}

/* ---- month filter ---- */
$m = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$mStart = $m.'-01'; $mEnd = date('Y-m-t', strtotime($mStart));

$orders = rows("SELECT o.*, p.name AS product_name FROM orders o
                LEFT JOIN products p ON p.id=o.product_id
                WHERE o.courier_id=? AND o.order_date BETWEEN ? AND ?
                ORDER BY o.order_date DESC, o.id DESC",[$dzId,$mStart,$mEnd]);
$ads = rows("SELECT * FROM daraz_ads WHERE spend_date BETWEEN ? AND ? ORDER BY spend_date DESC, id DESC",[$mStart,$mEnd]);

$mOrders=count($orders); $mRev=0; $mCost=0; $mDelv=0; $mDel=0; $mRet=0; $mGross=0; $mUnits=0;
foreach ($orders as $o) {
  if ($o['status']==='delivered') {
    $mDel++; $mUnits+=(int)$o['qty']; $line=(float)$o['sell_price']*(int)$o['qty'];
    $mRev+=$line; $mCost+=(float)$o['cost_price']*(int)$o['qty']; $mDelv+=(float)$o['delivery_charge'];
    $mGross+=order_profit($o);
  } elseif (in_array($o['status'],['returned','cancelled'],true)) { $mRet++; $mGross+=order_profit($o); }
}
$mAds=(float)val("SELECT COALESCE(SUM(amount),0) FROM daraz_ads WHERE spend_date BETWEEN ? AND ?",[$mStart,$mEnd]);
$mNet=$mGross-$mAds;
$mRoas=$mAds>0?round($mRev/$mAds,2):null;

require __DIR__.'/includes/header.php';
echo delivery_disabled_banner('daraz.php');
?>
<div class="page-head">
  <div><h1>🛍 Daraz</h1><p>Channel dashboard — orders come from Sales with Courier = <b>Daraz</b> · Daraz Order ID goes in Remarks</p></div>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <form method="get" style="display:flex;gap:6px;align-items:center">
      <input type="month" name="m" value="<?= e($m) ?>"><button class="btn btn-sm">Go</button>
    </form>
    <a class="btn btn-primary" href="sales.php?new=daraz">＋ New Daraz Order</a>
  </div>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="mgrid">
  <div class="metric blue"><div><div class="mv"><?= $mOrders ?></div><div class="ml">Orders</div><div class="ms"><?= e(date('M Y',strtotime($mStart))) ?> · <?= $mDel ?> delivered</div></div><div class="mi">🛍</div></div>
  <div class="metric green"><div><div class="mv" style="font-size:19px"><?= money($mRev) ?></div><div class="ml">Delivered Revenue</div><div class="ms"><?= number_format($mUnits) ?> pcs delivered</div></div><div class="mi">💰</div></div>
  <div class="metric amber"><div><div class="mv" style="font-size:19px"><?= money($mCost) ?></div><div class="ml">Product Cost</div><div class="ms">FIFO actual</div></div><div class="mi">📦</div></div>
  <div class="metric orange"><div><div class="mv" style="font-size:19px"><?= money($mAds) ?></div><div class="ml">Ads Spend</div><div class="ms">Daraz sponsored</div></div><div class="mi">📣</div></div>
  <div class="metric <?= $mNet>=0?'teal':'red' ?>"><div><div class="mv" style="font-size:19px"><?= money($mNet) ?></div><div class="ml">Net (after ads)</div><div class="ms">profit − ads</div></div><div class="mi">🧾</div></div>
  <div class="metric indigo"><div><div class="mv"><?= $mRoas!==null?$mRoas.'×':'—' ?></div><div class="ml">ROAS</div><div class="ms">revenue / ads</div></div><div class="mi">🎯</div></div>
</div>

<div class="dz-grid">
<div class="panel">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px"><h2>📦 Daraz Orders — <?= e(date('F Y',strtotime($mStart))) ?></h2>
    <input id="dzSearch" placeholder="🔍 Search order id, customer, phone, product…" style="max-width:280px" oninput="dzFilter()">
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
  ?>
    <tr data-s="<?= e(strtolower(($o['remarks']??'').' '.$o['code'].' '.($o['customer']??'').' '.($o['phone']??'').' '.($o['product_name']??''))) ?>" data-st="<?= e($o['status']) ?>">
      <td><b><?= e($o['remarks'] ?: '—') ?></b></td>
      <td class="muted"><?= e($o['code']) ?></td>
      <td><?= e($o['order_date']) ?></td>
      <td><b><?= e($o['customer'] ?: '—') ?></b></td>
      <td><b><?= e($o['product_name'] ?: '—') ?></b></td>
      <td style="text-align:right"><?= (int)$o['qty'] ?></td>
      <td style="text-align:right"><?= money($o['sell_price']) ?></td>
      <td style="text-align:right"><?= money($o['cost_price']) ?></td>
      <td style="text-align:right;font-weight:800;color:<?= ($isDel?$prof:$pot)>=0?'var(--green)':'var(--red)' ?>">
        <?= $isDel ? money($prof) : (in_array($o['status'],['returned','cancelled'],true) ? money($prof) : '≈ '.money($pot)) ?></td>
      <td>
        <form method="post" style="display:flex;gap:5px;align-items:center">
          <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="set_status">
          <input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="m" value="<?= e($m) ?>">
          <select name="status" class="dz-st st-<?= e($o['status']) ?>">
            <?php foreach(['pending','processing','shipped','delivered','returned','cancelled'] as $st): ?>
              <option value="<?= $st ?>"<?= $o['status']===$st?' selected':'' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="number" name="delivery_charge" value="<?= (float)$o['delivery_charge']>0?e((float)$o['delivery_charge']):'' ?>" placeholder="Dz chg" step="any" min="0" class="dz-dc" title="Daraz delivery/commission charge (Rs.)">
          <button class="btn btn-sm" title="Save — syncs Sales instantly">💾</button>
        </form>
      </td>
    </tr>
  <?php endforeach; if(!$orders) echo '<tr><td colspan="10"><div class="empty">No Daraz orders this month — click "＋ New Daraz Order" (it opens Sales with Courier preset to Daraz; put the Daraz Order ID in Remarks).</div></td></tr>'; ?>
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
      <button class="btn btn-primary btn-sm" style="width:100%">＋ Add Spend</button>
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
