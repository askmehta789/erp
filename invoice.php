<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
$id=(int)($_GET['id']??0);
$slip=isset($_GET['slip']);
$o=row("SELECT o.*, p.name AS product_name, c.name AS courier_name FROM orders o
        LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id WHERE o.id=?",[$id]);
if(!$o){ die('Order not found.'); }
$store=setting('store_name','Luprah Trading PVT.LTD'); $pan=setting('store_pan',''); $vat=(float)setting('vat_percent',0);
$phone=setting('store_phone',''); $email=setting('store_email',''); $addr=setting('store_address','');
$sub=(float)$o['sell_price']*(int)$o['qty']; $delv=(float)$o['delivery_charge'];
$vatAmt=$vat>0? round(($sub)*$vat/100,2):0; $total=$sub+$vatAmt; /* delivery is free for the customer */
$title=$slip?'Packing Slip':'Invoice';
?>
<!doctype html><html><head><meta charset="utf-8"><title><?= $title ?> <?= e($o['code']) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font:14px/1.5 'Segoe UI',Arial,sans-serif;color:#111;background:#f4f6f9;padding:28px}
.sheet{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e5e9f0;border-radius:12px;padding:34px}
.top{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #111;padding-bottom:18px;margin-bottom:20px}
.logo{display:flex;gap:10px;align-items:center}.logo img{height:44px}.logo b{font-size:19px}
.muted{color:#667085;font-size:12.5px}.tag{font-size:26px;font-weight:800;letter-spacing:.04em}
.grid{display:flex;justify-content:space-between;gap:20px;margin-bottom:22px}
.box b{display:block;font-size:12px;text-transform:uppercase;color:#667085;margin-bottom:5px;letter-spacing:.05em}
table{width:100%;border-collapse:collapse;margin:8px 0 16px}
th{background:#f1f5f9;text-align:left;padding:10px 12px;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
td{padding:10px 12px;border-bottom:1px solid #eef1f6}
.r{text-align:right}.totals{margin-left:auto;width:280px}
.totals td{padding:7px 12px;border:0}.totals tr:last-child td{border-top:2px solid #111;font-weight:800;font-size:16px}
.foot{margin-top:26px;display:flex;justify-content:space-between;align-items:flex-end}
.sig{border-top:1px solid #999;width:200px;text-align:center;padding-top:6px;font-size:12px;color:#667085}
.badge{display:inline-block;background:#111;color:#fff;border-radius:6px;padding:3px 10px;font-size:12px;font-weight:700}
.noprint{max-width:720px;margin:0 auto 14px;display:flex;gap:10px;justify-content:flex-end}
.btn{background:#3b82f6;color:#fff;border:0;border-radius:8px;padding:10px 16px;font-weight:700;cursor:pointer;text-decoration:none;font-size:13px}
.btn.gray{background:#64748b}
@media print{body{background:#fff;padding:0}.noprint{display:none}.sheet{border:0;border-radius:0;max-width:none}}
</style></head><body>
<div class="noprint">
  <a class="btn gray" href="invoice.php?id=<?= $id ?><?= $slip?'':'&slip=1' ?>"><?= $slip?'View Invoice':'View Packing Slip' ?></a>
  <a class="btn gray" href="labels.php?id=<?= $id ?>">Shipping Label</a>
  <button class="btn" onclick="window.print()">🖨 Print / Save PDF</button>
</div>
<div class="sheet">
  <div class="top">
    <div class="logo"><img src="assets/luprah-logo.png" alt=""><div><b><?= e($store) ?></b>
      <div class="muted"><?= e($addr) ?><?= $addr?' · ':'' ?><?= e($phone) ?><?= $email?' · '.e($email):'' ?><?= $pan?' · PAN: '.e($pan):'' ?></div></div></div>
    <div style="text-align:right"><div class="tag"><?= strtoupper($title) ?></div>
      <div class="muted"><?= e($o['code']) ?> · <?= e($o['order_date']) ?></div></div>
  </div>
  <div class="grid">
    <div class="box"><b>Bill / Ship To</b><?= e($o['customer']?:'—') ?><br><?= e($o['address']?:'') ?><br><?= e($o['phone']?:'') ?></div>
    <div class="box" style="text-align:right"><b>Delivery</b><?= e($o['courier_name']?:'—') ?><br>
      <?= e(ucfirst($o['zone'])) ?> valley<br><?= $o['ncm_order_id']?'Tracking: <b>'.e($o['ncm_order_id']).'</b>':'' ?></div>
  </div>
  <table><thead><tr><th>Item</th><th class="r">Qty</th><?php if(!$slip): ?><th class="r">Rate</th><th class="r">Amount</th><?php endif; ?></tr></thead>
  <tbody><tr><td><?= e($o['product_name']?:'Item') ?><?= $o['remarks']?'<div class="muted">'.e($o['remarks']).'</div>':'' ?></td>
    <td class="r"><?= (int)$o['qty'] ?></td>
    <?php if(!$slip): ?><td class="r"><?= money($o['sell_price']) ?></td><td class="r"><?= money($sub) ?></td><?php endif; ?></tr></tbody></table>
  <?php if(!$slip): ?>
  <table class="totals">
    <tr><td>Subtotal</td><td class="r"><?= money($sub) ?></td></tr>
    <tr><td>Delivery</td><td class="r" style="color:#16a34a;font-weight:700">FREE ✓</td></tr>
    <?php if($vatAmt>0): ?><tr><td>VAT (<?= $vat ?>%)</td><td class="r"><?= money($vatAmt) ?></td></tr><?php endif; ?>
    <tr><td>Total <?= strtolower((string)$o['payment_type'])==='cod'?'(COD)':'' ?></td><td class="r"><?= money($total) ?></td></tr>
  </table>
  <div><span class="badge"><?= strtolower((string)$o['payment_status'])==='paid'?'PAYMENT RECEIVED':'PAYMENT DUE ON DELIVERY' ?></span></div>
  <?php else: ?>
  <div><span class="badge">CHECK CONTENTS BEFORE DISPATCH</span></div>
  <?php endif; ?>
  <div class="foot"><div class="muted">Thank you for shopping with <?= e($store) ?>!</div><div class="sig">Authorized Signature</div></div>
</div>
</body></html>
