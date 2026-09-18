<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
/* one label (?id=) or bulk (?status=processing / pending / shipped) */
$id=(int)($_GET['id']??0); $st=$_GET['status']??'';
$idsRaw=trim((string)($_GET['ids']??''));
if($idsRaw!==''){
  $ids=array_values(array_filter(array_map('intval',explode(',',$idsRaw)),fn($x)=>$x>0));
  $ids=array_slice($ids,0,60);
  if($ids){
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $orders=rows("SELECT o.*, p.name AS product_name, c.name AS courier_name FROM orders o LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id WHERE o.id IN ($ph) ORDER BY o.id DESC",$ids);
  } else $orders=[];
}
elseif($id){ $orders=rows("SELECT o.*, p.name AS product_name, c.name AS courier_name FROM orders o LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id WHERE o.id=?",[$id]); }
elseif(in_array($st,['pending','processing','shipped'],true)){ $orders=rows("SELECT o.*, p.name AS product_name, c.name AS courier_name FROM orders o LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id WHERE o.status=? ORDER BY o.id DESC LIMIT 60",[$st]); }
else { $orders=rows("SELECT o.*, p.name AS product_name, c.name AS courier_name FROM orders o LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id WHERE o.status IN ('pending','processing') ORDER BY o.id DESC LIMIT 60"); }
$store=setting('store_name','Luprah Trading'); $phone=setting('store_phone','');
?>
<!doctype html><html><head><meta charset="utf-8"><title>Shipping Labels</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.6/JsBarcode.all.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font:13px/1.45 'Segoe UI',Arial,sans-serif;background:#f4f6f9;padding:22px}
.noprint{display:flex;gap:10px;justify-content:flex-end;max-width:840px;margin:0 auto 14px}
.btn{background:#3b82f6;color:#fff;border:0;border-radius:8px;padding:10px 16px;font-weight:700;cursor:pointer;text-decoration:none;font-size:13px}
.btn.gray{background:#64748b}
.wrap{max-width:840px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:14px}
.label{background:#fff;border:2px solid #111;border-radius:10px;padding:14px;page-break-inside:avoid}
.lhead{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:8px}
.lhead img{height:26px}.lhead b{font-size:14px}
.to{font-size:15px;font-weight:800}.addr{font-size:13px;margin:2px 0 6px}
.row{display:flex;justify-content:space-between;font-size:12px;margin-top:4px}
.cod{font-size:16px;font-weight:900;border:2px solid #111;border-radius:8px;padding:4px 10px;display:inline-block;margin-top:6px}
svg.bc{width:100%;height:52px;margin-top:6px}
@media print{body{background:#fff;padding:0}.noprint{display:none}.wrap{max-width:none}}
</style></head><body>
<div class="noprint">
  <a class="btn gray" href="labels.php?status=pending">Pending</a>
  <a class="btn gray" href="labels.php?status=processing">Processing</a>
  <a class="btn gray" href="labels.php?status=shipped">Shipped</a>
  <button class="btn" onclick="window.print()">🖨 Print Labels</button>
</div>
<div class="wrap">
<?php foreach($orders as $o): $cod=strtolower((string)$o['payment_type'])==='cod' ? (float)$o['sell_price']*(int)$o['qty'] : 0; /* price includes delivery */ ?>
  <div class="label">
    <div class="lhead"><span style="display:flex;gap:8px;align-items:center"><img src="assets/luprah-logo.png"><b><?= e($store) ?></b></span><b><?= e($o['code']) ?></b></div>
    <div class="to">📦 <?= e($o['customer']?:'—') ?></div>
    <div class="addr"><?= e($o['address']?:'—') ?> · <?= e(ucfirst($o['zone'])) ?> Valley</div>
    <div class="row"><span>📞 <?= e($o['phone']?:'—') ?></span><span><?= e($o['product_name']?:'') ?> ×<?= (int)$o['qty'] ?></span></div>
    <div class="row"><span>Courier: <b><?= e($o['courier_name']?:'—') ?></b></span><span><?= $o['ncm_order_id']?'NCM #'.e($o['ncm_order_id']):'' ?></span></div>
    <?php if($cod>0): ?><div class="cod">COD: <?= money($cod) ?></div><?php else: ?><div class="cod" style="border-style:dashed">PREPAID ✓</div><?php endif; ?>
    <svg class="bc" data-code="<?= e($o['ncm_order_id']?:$o['code']) ?>"></svg>
    <div class="row" style="color:#667085"><span>From: <?= e($store) ?> · <?= e($phone) ?></span><span><?= e($o['order_date']) ?></span></div>
  </div>
<?php endforeach; if(!$orders) echo '<div style="grid-column:1/-1;background:#fff;border-radius:10px;padding:40px;text-align:center;color:#667085">No orders for labels in this view.</div>'; ?>
</div>
<script>
document.querySelectorAll('svg.bc').forEach(function(s){
  try{ JsBarcode(s, s.getAttribute('data-code')||'0', {format:'CODE128',displayValue:true,fontSize:12,height:40,margin:0}); }catch(e){ s.remove(); }
});
</script>
</body></html>
