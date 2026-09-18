<?php
require_once __DIR__.'/functions.php'; require_login();
$PAGE_TITLE='Search';
$q = trim($_GET['q'] ?? '');
$like = '%'.$q.'%';

$orders=[]; $products=[]; $couriers=[]; $users=[];
if ($q !== '') {
  try {
    $orders = rows("SELECT o.*, p.name AS product_name, c.name AS courier_name
                    FROM orders o LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id
                    WHERE o.code LIKE ? OR o.customer LIKE ? OR o.phone LIKE ? OR o.address LIKE ?
                       OR o.ncm_order_id LIKE ? OR o.remarks LIKE ? OR p.name LIKE ?
                    ORDER BY o.id DESC LIMIT 60",
                    [$like,$like,$like,$like,$like,$like,$like]);
  } catch (Exception $e) {
    $orders = rows("SELECT o.*, p.name AS product_name, c.name AS courier_name
                    FROM orders o LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id
                    WHERE o.code LIKE ? OR o.customer LIKE ? OR o.phone LIKE ? OR o.address LIKE ? OR p.name LIKE ?
                    ORDER BY o.id DESC LIMIT 60", [$like,$like,$like,$like,$like]);
  }
  try { $products = rows("SELECT * FROM products WHERE name LIKE ? OR sku LIKE ? OR category LIKE ? ORDER BY name LIMIT 40",[$like,$like,$like]); } catch(Exception $e){}
  try { $couriers = rows("SELECT * FROM couriers WHERE name LIKE ? LIMIT 20",[$like]); } catch(Exception $e){}
  if (can_see('users.php')) { try { $users = rows("SELECT id,name,username,role FROM users WHERE name LIKE ? OR username LIKE ? LIMIT 20",[$like,$like]); } catch(Exception $e){} }
}
$total = count($orders)+count($products)+count($couriers)+count($users);
require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>🔍 Search</h1><p><?= $q!==''?('Results for "'.e($q).'" — '.$total.' found'):'Type to search orders, customers, products, couriers, users' ?></p></div></div>

<div class="card" style="margin-bottom:18px"><form method="get" class="toolbar">
  <input class="search-in" name="q" value="<?= e($q) ?>" placeholder="Search anything — order code, customer, phone, tracking #, product…" autofocus>
  <button class="btn btn-primary">Search</button>
</form></div>

<?php if($q===''): ?>
<?php elseif($total===0): ?>
  <div class="panel"><div class="empty">No matches for "<?= e($q) ?>".</div></div>
<?php else: ?>

<?php if($orders): ?>
<div class="panel" style="margin-bottom:18px"><div class="panel-head"><h2>🛒 Orders (<?= count($orders) ?>)</h2></div>
  <div class="table-wrap"><table class="tbl"><thead><tr><th>Order</th><th>Customer</th><th>Phone</th><th>Product</th><th class="right">Amount</th><th>Courier</th><th>Tracking</th><th>Status</th></tr></thead><tbody>
  <?php foreach($orders as $o): ?>
    <tr><td><b><?= e($o['code']) ?></b></td><td><?= e($o['customer']) ?></td><td class="num"><?= e($o['phone']) ?></td>
    <td><?= e($o['product_name']) ?></td><td class="num right"><?= money($o['sell_price']*$o['qty']) ?></td>
    <td><?= e($o['courier_name']) ?></td><td><?= e($o['ncm_order_id']??'') ?></td><td><?= pill($o['status']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <div style="padding:12px 16px"><a class="btn btn-sm" href="sales.php?q=<?= urlencode($q) ?>">Open Sales →</a></div>
</div>
<?php endif; ?>

<?php if($products): ?>
<div class="panel" style="margin-bottom:18px"><div class="panel-head"><h2>🏷️ Products (<?= count($products) ?>)</h2></div>
  <div class="table-wrap"><table class="tbl"><thead><tr><th>Name</th><th>SKU</th><th>Category</th><th class="right">Price</th><th class="right">Stock</th></tr></thead><tbody>
  <?php foreach($products as $p): ?>
    <tr><td><b><?= e($p['name']) ?></b></td><td><?= e($p['sku']??'') ?></td><td><?= e($p['category']??'') ?></td><td class="num right"><?= money($p['price']??0) ?></td><td class="num right"><?= (int)($p['stock']??0) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <div style="padding:12px 16px"><a class="btn btn-sm" href="products.php">Open Products →</a></div>
</div>
<?php endif; ?>

<?php if($couriers): ?>
<div class="panel" style="margin-bottom:18px"><div class="panel-head"><h2>🚚 Couriers (<?= count($couriers) ?>)</h2></div>
  <div class="panel-body"><?php foreach($couriers as $c) echo '<span class="pill p-blue" style="margin:3px">'.e($c['name']).'</span> '; ?></div>
</div>
<?php endif; ?>

<?php if($users): ?>
<div class="panel"><div class="panel-head"><h2>👤 Users (<?= count($users) ?>)</h2></div>
  <div class="table-wrap"><table class="tbl"><thead><tr><th>Name</th><th>Username</th><th>Role</th></tr></thead><tbody>
  <?php foreach($users as $u2): ?><tr><td><b><?= e($u2['name']) ?></b></td><td><?= e($u2['username']) ?></td><td><?= e($u2['role']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>