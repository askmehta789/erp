<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
ensure_stock_batches();
try { q("CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(140) NOT NULL, contact VARCHAR(120),
  city VARCHAR(80), category VARCHAR(60), balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)"); } catch (Exception $e) {}
try { q("ALTER TABLE stock_batches ADD COLUMN IF NOT EXISTS supplier_id INT NULL"); } catch (Exception $e) {}
$PAGE_TITLE='Vendor Purchases';

/* embedded Suppliers directory — add/edit vendor name, contact, balance */
if (($_POST['_entity'] ?? '') === 'suppliers') handle_crud('suppliers');

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action'] ?? '')==='assign_vendor') {
  check_csrf();
  $supplierId = (int)($_POST['supplier_id'] ?? 0);
  $ids = array_filter(array_map('intval', explode(',', (string)($_POST['batch_ids'] ?? ''))));
  if (!$supplierId || !$ids) {
    flash('Pick a vendor and at least one purchase to assign.');
  } else {
    $n=0; foreach($ids as $bid){ q("UPDATE stock_batches SET supplier_id=? WHERE id=?",[$supplierId,$bid]); $n++; }
    $vn = (string)val("SELECT name FROM suppliers WHERE id=?",[$supplierId]);
    log_activity("Assigned $n old purchase batch(es) to vendor $vn",'Purchases');
    flash("✅ Assigned {$n} purchase(s) to {$vn}.");
  }
  header('Location: vendor_purchases.php'.(($_POST['back'] ?? '')==='unlinked' ? '?view=unlinked' : '')); exit;
}

/* a batch is a genuine vendor purchase only if it wasn't created by an internal
   stock movement (HH returns, manual count corrections) — same rule used on the
   Products page for "lifetime purchased", kept consistent here. */
if (!function_exists('is_real_purchase_batch')) {
function is_real_purchase_batch($note){
  $n = trim((string)$note);
  return $n !== 'Count adjustment +' && $n !== 'returned from Hungry Hunter' && $n !== 'returned from Dropex';
}
}

/* vendors that have at least one real purchase batch linked, so the picker isn't
   cluttered with suppliers you've never actually bought anything from yet */
$vendorsWithData = rows("SELECT s.id, s.name, COUNT(*) n FROM suppliers s
  JOIN stock_batches b ON b.supplier_id=s.id
  GROUP BY s.id, s.name ORDER BY s.name");
$vid = (int)($_GET['vendor'] ?? ($vendorsWithData[0]['id'] ?? 0));
$vendor = $vid ? row("SELECT * FROM suppliers WHERE id=?",[$vid]) : null;

/* optional month filter (?pmonth=YYYY-MM) — narrows the totals/history below
   to spend in that one calendar month for the selected vendor */
$pmonth = preg_match('/^\d{4}-\d{2}$/', $_GET['pmonth'] ?? '') ? $_GET['pmonth'] : '';

$batches=[]; $byProduct=[]; $totQty=0; $totAmt=0;
if ($vendor) {
  $batches = rows("SELECT b.*, p.name AS product_name FROM stock_batches b
    LEFT JOIN products p ON p.id=b.product_id
    WHERE b.supplier_id=? ORDER BY b.purchase_date DESC, b.id DESC",[$vid]);
  $batches = array_values(array_filter($batches, fn($b)=>is_real_purchase_batch($b['note'])));
  if ($pmonth !== '') $batches = array_values(array_filter($batches, fn($b)=>substr($b['purchase_date'],0,7)===$pmonth));
  foreach($batches as $b){
    $qty=(int)$b['qty_in']; $amt=$qty*(float)$b['unit_cost'];
    $totQty+=$qty; $totAmt+=$amt;
    $pn=$b['product_name']?:'(deleted product)';
    if(!isset($byProduct[$pn])) $byProduct[$pn]=['qty'=>0,'amt'=>0];
    $byProduct[$pn]['qty']+=$qty; $byProduct[$pn]['amt']+=$amt;
  }
  arsort($byProduct);
}
$avgRate = $totQty>0 ? round($totAmt/$totQty,2) : 0;
$maxProdQty = $byProduct ? max(array_column($byProduct,'qty')) : 1;

/* orphaned purchases: real batches with NO vendor linked at all (created before
   this feature existed, or someone skipped picking a vendor) — shown honestly
   rather than silently excluded */
$unlinkedCount = (int)val("SELECT COUNT(*) FROM stock_batches WHERE supplier_id IS NULL AND note NOT IN ('Count adjustment +','returned from Hungry Hunter','returned from Dropex')");
$unlinkedQty   = (int)val("SELECT COALESCE(SUM(qty_in),0) FROM stock_batches WHERE supplier_id IS NULL AND note NOT IN ('Count adjustment +','returned from Hungry Hunter','returned from Dropex')");

$view = ($_GET['view'] ?? '')==='unlinked' ? 'unlinked' : 'vendor';
$unlinkedBatches=[]; $allVendorsForPicker=[];
if ($view==='unlinked') {
  $unlinkedBatches = rows("SELECT b.*, p.name AS product_name FROM stock_batches b
    LEFT JOIN products p ON p.id=b.product_id
    WHERE b.supplier_id IS NULL AND b.note NOT IN ('Count adjustment +','returned from Hungry Hunter','returned from Dropex')
    ORDER BY b.purchase_date DESC, b.id DESC");
  $allVendorsForPicker = rows("SELECT id,name FROM suppliers ORDER BY name");
}

require __DIR__.'/includes/header.php';
?>
<style>
.vp-hero{background:linear-gradient(120deg,#0f172a,#334155);border-radius:20px;padding:20px 24px;color:#fff;margin-bottom:14px}
.vp-hero h1{font-size:19px;font-weight:900}
.vp-hero p{font-size:11.5px;opacity:.8;margin-top:2px}
.vp-picker{margin-top:12px;display:flex;gap:8px;flex-wrap:wrap}
.vp-picker a{background:rgba(255,255,255,.15);border-radius:99px;padding:6px 14px;font-size:11.5px;font-weight:700;color:#fff;text-decoration:none}
.vp-picker a.on{background:#fff;color:#0f172a}
.vp-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.vp-stat{background:#fff;border-radius:14px;padding:13px;box-shadow:0 6px 16px rgba(30,41,80,.07);border-top:3px solid #ddd}
.vp-stat.b{border-top-color:#0369a1}.vp-stat.g{border-top-color:#16a34a}.vp-stat.t{border-top-color:#0d9488}.vp-stat.p{border-top-color:#7c3aed}
.vp-stat b{font-size:17px;font-weight:900;display:block;overflow-wrap:anywhere}
.vp-stat span{font-size:9px;color:#8a93a8;font-weight:700;text-transform:uppercase}
.vp-card{background:#fff;border-radius:16px;padding:16px 18px;box-shadow:0 6px 18px rgba(30,41,80,.07);margin-bottom:14px}
.vp-card h3{font-size:13px;font-weight:900;margin-bottom:10px}
.vp-prow{display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid #f1f4f9;font-size:12.5px}
.vp-prow:last-child{border-bottom:0}
.vp-prow .nm{width:140px;font-weight:800;flex:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.vp-pbar-bg{flex:1;height:8px;background:#eef1f5;border-radius:99px;overflow:hidden;min-width:40px}
.vp-pbar{height:100%;background:linear-gradient(90deg,#60a5fa,#0369a1)}
.vp-prow .amt{width:150px;text-align:right;font-weight:800;flex:none}
.vp-empty{padding:26px 18px;text-align:center;color:#8a93a8;font-size:12px;background:#fff;border-radius:16px}
.vp-unlinked{background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:11px 15px;font-size:11.5px;color:#9a3412;margin-bottom:14px}
@media(max-width:640px){ .vp-stats{grid-template-columns:repeat(2,1fr)} .vp-prow .nm{width:100px} .vp-prow .amt{width:110px} }
</style>

<div class="vp-hero">
  <h1>🏭 Vendor Purchases</h1>
  <p>Every product, every quantity, purchased from one supplier</p>
  <?php if($vendorsWithData || $unlinkedCount>0): ?>
  <div class="vp-picker">
    <?php foreach($vendorsWithData as $v): ?><a href="?vendor=<?= (int)$v['id'] ?><?= $pmonth!==''?'&pmonth='.e($pmonth):'' ?>" class="<?= ($view==='vendor'&&$vid===(int)$v['id'])?'on':'' ?>"><?= e($v['name']) ?></a><?php endforeach; ?>
    <?php if($unlinkedCount>0): ?><a href="?view=unlinked" class="<?= $view==='unlinked'?'on':'' ?>" style="background:rgba(217,119,6,.35)">🏷️ Unlinked (<?= $unlinkedCount ?>)</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if($unlinkedCount>0 && $view!=='unlinked'): ?>
<div class="vp-unlinked">ℹ️ <b><?= number_format($unlinkedQty) ?> pcs</b> across <b><?= $unlinkedCount ?></b> purchase batch<?= $unlinkedCount===1?'':'es' ?> have no vendor linked — likely added before this page existed. They're not guessed into any vendor's numbers here. <a href="?view=unlinked" style="color:#9a3412;font-weight:800;text-decoration:underline">Assign them to a vendor now →</a></div>
<?php endif; ?>

<?php if($view==='unlinked'): ?>

<div class="vp-card">
  <h3>🏷️ Unlinked Purchases — assign a vendor to old data</h3>
  <p class="muted" style="font-size:11.5px;margin-bottom:12px">Select the batches that came from the same vendor, pick who, and assign them all at once. Nothing here is guessed — you're confirming each one.</p>
  <form method="post" id="assignForm">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="assign_vendor"><input type="hidden" name="back" value="unlinked">
    <input type="hidden" name="batch_ids" id="assignIds">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px;background:#f8fafc;border-radius:10px;padding:10px 14px">
      <span style="font-size:12px;font-weight:700"><b id="assignCount">0</b> selected</span>
      <select name="supplier_id" style="border:1px solid var(--border);border-radius:8px;padding:7px 11px;font-size:12px">
        <option value="">— pick vendor to assign —</option>
        <?php foreach($allVendorsForPicker as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Assign Selected</button>
      <button type="button" class="btn btn-sm" onclick="assignSelectAll()">Select All</button>
      <button type="button" class="btn btn-sm" onclick="assignSelectNone()">Clear</button>
    </div>
  </form>
  <div class="table-wrap"><table class="tbl num-tbl">
    <thead><tr><th></th><th style="text-align:left">Product</th><th>Date</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Total</th><th style="text-align:left">Note</th></tr></thead>
    <tbody>
    <?php foreach($unlinkedBatches as $b): ?>
    <tr>
      <td><input type="checkbox" class="assignChk" value="<?= (int)$b['id'] ?>"></td>
      <td style="text-align:left"><b><?= e($b['product_name']?:'(deleted product)') ?></b></td>
      <td><?= e($b['purchase_date']) ?></td>
      <td class="right"><?= (int)$b['qty_in'] ?></td>
      <td class="right"><?= money($b['unit_cost']) ?></td>
      <td class="right" style="font-weight:800"><?= money((int)$b['qty_in']*(float)$b['unit_cost']) ?></td>
      <td style="text-align:left" class="muted"><?= e($b['note']) ?></td>
    </tr>
    <?php endforeach; if(!$unlinkedBatches): ?><tr><td colspan="7"><div class="vp-empty">Nothing left to assign — every purchase has a vendor. 🎉</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<script>
function assignRefresh(){
  var sel = document.querySelectorAll('.assignChk:checked');
  document.getElementById('assignCount').textContent = sel.length;
  document.getElementById('assignIds').value = Array.prototype.map.call(sel,function(c){return c.value;}).join(',');
}
document.querySelectorAll('.assignChk').forEach(function(cb){ cb.addEventListener('change', assignRefresh); });
function assignSelectAll(){ document.querySelectorAll('.assignChk').forEach(function(cb){ cb.checked=true; }); assignRefresh(); }
function assignSelectNone(){ document.querySelectorAll('.assignChk').forEach(function(cb){ cb.checked=false; }); assignRefresh(); }
document.getElementById('assignForm').addEventListener('submit', function(e){
  if(!document.getElementById('assignIds').value){ e.preventDefault(); alert('Select at least one purchase first.'); }
});
</script>

<?php elseif(!$vendorsWithData): ?>
<div class="vp-empty">No vendor-linked purchases yet. Add a stock batch from the Products page and pick a vendor — it'll show up here.</div>
<?php elseif(!$vendor): ?>
<div class="vp-empty">Pick a vendor above to see their purchase detail.</div>
<?php else: ?>

<div class="vp-card" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
  <form method="get" style="display:flex;gap:8px;align-items:center">
    <input type="hidden" name="vendor" value="<?= (int)$vid ?>">
    <label style="font-size:12px;font-weight:700;color:#8a93a8">📅 Filter by month</label>
    <input type="month" name="pmonth" value="<?= e($pmonth) ?>">
    <button class="btn btn-sm btn-primary">Apply</button>
    <?php if($pmonth!==''): ?><a class="btn btn-sm" href="?vendor=<?= (int)$vid ?>">✕ Clear</a><?php endif; ?>
  </form>
  <?php if($pmonth!==''): ?><span class="muted" style="font-size:12px">Showing <b><?= e(date('F Y',strtotime($pmonth.'-01'))) ?></b> only</span><?php endif; ?>
</div>

<div class="vp-stats">
  <div class="vp-stat b"><b><?= number_format($totQty) ?></b><span>Pcs Purchased<?= $pmonth!==''?' · '.e(date('M Y',strtotime($pmonth.'-01'))):'' ?></span></div>
  <div class="vp-stat g"><b><?= money($totAmt) ?></b><span>Total Spent<?= $pmonth!==''?' · '.e(date('M Y',strtotime($pmonth.'-01'))):'' ?></span></div>
  <div class="vp-stat t"><b><?= money($avgRate) ?></b><span>Avg Rate / Pc</span></div>
  <div class="vp-stat p"><b><?= count($batches) ?></b><span>Purchase Batches</span></div>
</div>

<div class="vp-card">
  <h3>📦 Quantity by Product — from <?= e($vendor['name']) ?><?= $pmonth!==''?' ('.e(date('M Y',strtotime($pmonth.'-01'))).')':'' ?></h3>
  <?php foreach($byProduct as $pn=>$pv): $pct=max(4,round($pv['qty']/$maxProdQty*100)); ?>
  <div class="vp-prow"><span class="nm" title="<?= e($pn) ?>"><?= e($pn) ?></span><div class="vp-pbar-bg"><div class="vp-pbar" style="width:<?= $pct ?>%"></div></div><span class="amt"><?= number_format($pv['qty']) ?> pcs · <?= money($pv['amt']) ?></span></div>
  <?php endforeach; if(!$byProduct): ?><div class="vp-empty"><?= $pmonth!==''?'No purchases from this vendor in '.e(date('F Y',strtotime($pmonth.'-01'))).'.':'No purchases recorded yet.' ?></div><?php endif; ?>
</div>

<div class="vp-card">
  <h3>🧾 Purchase History — from <?= e($vendor['name']) ?><?= $pmonth!==''?' ('.e(date('M Y',strtotime($pmonth.'-01'))).')':'' ?></h3>
  <div class="table-wrap"><table class="tbl num-tbl">
    <thead><tr><th>Date</th><th style="text-align:left">Product</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Total</th><th style="text-align:left">Note</th></tr></thead>
    <tbody>
    <?php foreach($batches as $b): ?>
    <tr>
      <td><?= e($b['purchase_date']) ?></td>
      <td style="text-align:left"><b><?= e($b['product_name']?:'(deleted product)') ?></b></td>
      <td class="right"><?= (int)$b['qty_in'] ?></td>
      <td class="right"><?= money($b['unit_cost']) ?></td>
      <td class="right" style="font-weight:800"><?= money((int)$b['qty_in']*(float)$b['unit_cost']) ?></td>
      <td style="text-align:left" class="muted"><?= e($b['note']) ?></td>
    </tr>
    <?php endforeach; if(!$batches): ?><tr><td colspan="6"><div class="vp-empty"><?= $pmonth!==''?'No purchases from this vendor in '.e(date('F Y',strtotime($pmonth.'-01'))).'.':'No purchases recorded yet.' ?></div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php endif; ?>

<div id="suppliers"></div>
<?php render_crud('suppliers','🏭 Suppliers','vendor directory — contact info & payable balance','embed'); ?>

<?php require __DIR__.'/includes/footer.php'; ?>