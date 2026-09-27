<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS label_printed_at DATETIME NULL"); } catch (Exception $e) {}
try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS label_print_count INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}

/* ---------------------------------------------------------------------
   AJAX ENDPOINT (same file)  labels.php?ajax=...
   --------------------------------------------------------------------- */
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');
  $token = $_POST['csrf'] ?? '';
  if (!is_string($token) || !hash_equals(csrf(), $token)) { echo json_encode(['ok'=>false,'error'=>'Session expired — please reload the page.']); exit; }
  $action = $_GET['ajax'];

  if ($action === 'mark_printed') {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))), fn($x)=>$x>0));
    $ids = array_slice($ids, 0, 200);
    $out = [];
    if ($ids) {
      $ph = implode(',', array_fill(0, count($ids), '?'));
      q("UPDATE orders SET label_printed_at=NOW(), label_print_count=label_print_count+1 WHERE id IN ($ph)", $ids);
      foreach (rows("SELECT id,label_printed_at,label_print_count FROM orders WHERE id IN ($ph)", $ids) as $r)
        $out[$r['id']] = ['count'=>(int)$r['label_print_count'], 'at'=>$r['label_printed_at']];
      log_activity('Printed '.count($ids).' shipping label(s)', 'Labels');
    }
    echo json_encode(['ok'=>true,'data'=>$out]); exit;
  }

  if ($action === 'save_settings') {
    $cur = json_decode((string)setting('label_settings',''), true) ?: [];
    foreach (['show_remarks','show_sales_person','show_weight','show_logo'] as $k) $cur[$k] = !empty($_POST[$k]) ? '1' : '0';
    if (isset($_POST['format'])) $cur['format'] = $_POST['format']==='thermal' ? 'thermal' : 'a4';
    q("INSERT INTO settings(skey,svalue) VALUES('label_settings',?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)", [json_encode($cur)]);
    log_activity('Updated label print settings', 'Labels');
    echo json_encode(['ok'=>true,'settings'=>$cur]); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;
}

/* ---------------------------------------------------------------------
   label template preferences (persisted in settings, key = label_settings)
   --------------------------------------------------------------------- */
$LS = array_merge(
  ['format'=>'a4','show_remarks'=>'1','show_sales_person'=>'0','show_weight'=>'0','show_logo'=>'1'],
  (json_decode((string)setting('label_settings',''), true) ?: [])
);
$format = in_array($_GET['format'] ?? '', ['a4','thermal'], true) ? $_GET['format'] : $LS['format'];

/* one label (?id=), a batch (?ids=), a status view, or bulk (?status=processing / pending / shipped / unprinted) */
$id=(int)($_GET['id']??0); $st=$_GET['status']??'';
$idsRaw=trim((string)($_GET['ids']??''));
$SEL="o.*, p.name AS product_name, c.name AS courier_name, c.color AS courier_color, sp.name AS sales_person_name";
$JOIN="LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id LEFT JOIN employees sp ON sp.id=o.sales_person_id";
if($idsRaw!==''){
  $ids=array_values(array_filter(array_map('intval',explode(',',$idsRaw)),fn($x)=>$x>0));
  $ids=array_slice($ids,0,60);
  if($ids){
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $orders=rows("SELECT $SEL FROM orders o $JOIN WHERE o.id IN ($ph) ORDER BY o.id DESC",$ids);
  } else $orders=[];
  $qp=['ids'=>$idsRaw];
}
elseif($id){ $orders=rows("SELECT $SEL FROM orders o $JOIN WHERE o.id=?",[$id]); $qp=['id'=>$id]; }
elseif($st==='unprinted'){ $orders=rows("SELECT $SEL FROM orders o $JOIN WHERE o.label_printed_at IS NULL AND o.status IN ('pending','processing') ORDER BY o.id DESC LIMIT 60"); $qp=['status'=>$st]; }
elseif(in_array($st,['pending','processing','shipped'],true)){ $orders=rows("SELECT $SEL FROM orders o $JOIN WHERE o.status=? ORDER BY o.id DESC LIMIT 60",[$st]); $qp=['status'=>$st]; }
else { $orders=rows("SELECT $SEL FROM orders o $JOIN WHERE o.status IN ('pending','processing') ORDER BY o.id DESC LIMIT 60"); $qp=[]; }
$store=setting('store_name','Luprah Trading'); $phone=setting('store_phone','');
$defWeight=setting('ncm_default_weight','');
function label_link($extra=[]){ global $qp; $q=array_merge($qp,$extra); return 'labels.php'.($q?('?'.http_build_query($q)):''); }
?>
<!doctype html><html><head><meta charset="utf-8"><title>Shipping Labels</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.6/JsBarcode.all.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font:13px/1.45 'Segoe UI',Arial,sans-serif;background:#f4f6f9;padding:22px}
.bar{display:flex;gap:8px;justify-content:space-between;align-items:center;max-width:900px;margin:0 auto 14px;flex-wrap:wrap}
.chips{display:flex;gap:6px;flex-wrap:wrap}
.chip{background:#fff;border:1.5px solid #d7dce3;color:#334155;border-radius:20px;padding:6px 12px;font-size:12px;font-weight:700;text-decoration:none}
.chip.on{background:#111;color:#fff;border-color:#111}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{background:#3b82f6;color:#fff;border:0;border-radius:8px;padding:9px 14px;font-weight:700;cursor:pointer;text-decoration:none;font-size:12.5px}
.btn.gray{background:#64748b}.btn.ghost{background:#fff;color:#334155;border:1.5px solid #d7dce3}
.wrap{max-width:900px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:14px}
body.fmt-thermal .wrap{grid-template-columns:1fr;max-width:400px}
.label{position:relative;background:#fff;border:2px solid #111;border-radius:10px;padding:14px;page-break-inside:avoid}
body.fmt-thermal .label{page-break-after:always}
.label.selected{outline:3px solid #3b82f6;outline-offset:2px}
.pick{position:absolute;top:10px;right:10px;width:18px;height:18px;cursor:pointer}
.lhead{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:8px}
.lhead img{height:26px}.lhead b{font-size:14px}
.to{font-size:15px;font-weight:800}.addr{font-size:13px;margin:2px 0 6px}
.row{display:flex;justify-content:space-between;font-size:12px;margin-top:4px}
.cod{font-size:16px;font-weight:900;border:2px solid #111;border-radius:8px;padding:4px 10px;display:inline-block;margin-top:6px}
.courier-badge{font-size:11px;font-weight:800;color:#fff;border-radius:6px;padding:2px 8px;display:inline-block}
.printbadge{font-size:10.5px;font-weight:700;border-radius:6px;padding:2px 7px;display:inline-block;margin-top:6px}
.printbadge.done{background:#dcfce7;color:#166534}.printbadge.pending{background:#f1f5f9;color:#64748b}
svg.bc{width:100%;height:52px;margin-top:6px}
.settings{display:none;max-width:900px;margin:0 auto 14px;background:#fff;border:1px solid #e5e9f0;border-radius:12px;padding:16px}
.settings.open{display:block}
.settings label{display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;margin-bottom:8px}
.settings label input{width:auto}
@media print{
  body{background:#fff;padding:0}.bar,.settings,.noprint{display:none}.wrap{max-width:none}
  .label.hide-for-print{display:none}
}
body.hide-remarks .f-remarks{display:none}body.hide-sp .f-sp{display:none}body.hide-weight .f-weight{display:none}body.hide-logo .f-logo{display:none}
</style></head><body class="fmt-<?= e($format) ?><?= $LS['show_remarks']==='0'?' hide-remarks':'' ?><?= $LS['show_sales_person']==='0'?' hide-sp':'' ?><?= $LS['show_weight']==='0'?' hide-weight':'' ?><?= $LS['show_logo']==='0'?' hide-logo':'' ?>">
<style id="pageSize"></style>
<div class="bar noprint">
  <div class="chips">
    <a class="chip<?= $st===''&&!$id&&!$idsRaw?' on':'' ?>" href="labels.php?format=<?= e($format) ?>">Default</a>
    <a class="chip<?= $st==='pending'?' on':'' ?>" href="labels.php?status=pending&format=<?= e($format) ?>">Pending</a>
    <a class="chip<?= $st==='processing'?' on':'' ?>" href="labels.php?status=processing&format=<?= e($format) ?>">Processing</a>
    <a class="chip<?= $st==='shipped'?' on':'' ?>" href="labels.php?status=shipped&format=<?= e($format) ?>">Shipped</a>
    <a class="chip<?= $st==='unprinted'?' on':'' ?>" href="labels.php?status=unprinted&format=<?= e($format) ?>">🖶 Unprinted</a>
  </div>
  <div class="actions">
    <a class="btn ghost<?= $format==='a4'?' on':'' ?>" href="<?= label_link(['format'=>'a4']) ?>" onclick="persistFormat('a4')">A4 Sheet</a>
    <a class="btn ghost<?= $format==='thermal'?' on':'' ?>" href="<?= label_link(['format'=>'thermal']) ?>" onclick="persistFormat('thermal')">4×6 Thermal</a>
    <button class="btn gray" type="button" onclick="document.querySelector('.settings').classList.toggle('open')">⚙ Fields</button>
    <button class="btn gray" type="button" onclick="selectAll(true)">Select All</button>
    <button class="btn gray" type="button" onclick="selectAll(false)">Select None</button>
    <button class="btn" type="button" onclick="doPrint('selected')">🖨 Print Selected</button>
    <button class="btn" type="button" onclick="doPrint('all')">🖨 Print All</button>
  </div>
</div>

<div class="settings">
  <label><input type="checkbox" id="s_remarks" <?= $LS['show_remarks']==='1'?'checked':'' ?> onchange="toggleField('remarks',this.checked)"> Show remarks / notes</label>
  <label><input type="checkbox" id="s_sp" <?= $LS['show_sales_person']==='1'?'checked':'' ?> onchange="toggleField('sp',this.checked)"> Show sales person</label>
  <label><input type="checkbox" id="s_weight" <?= $LS['show_weight']==='1'?'checked':'' ?> onchange="toggleField('weight',this.checked)"> Show default parcel weight</label>
  <label><input type="checkbox" id="s_logo" <?= $LS['show_logo']==='1'?'checked':'' ?> onchange="toggleField('logo',this.checked)"> Show logo</label>
  <button class="btn" type="button" onclick="saveSettings()">💾 Save as default</button>
</div>

<div class="wrap">
<?php foreach($orders as $o):
  $cod=strtolower((string)$o['payment_type'])==='cod' ? (float)$o['sell_price']*(int)$o['qty'] : 0; /* price includes delivery */
  $isNcm = !empty($o['ncm_order_id']);
  $ccolor = $o['courier_color'] ?: '#64748b';
  $printed = !empty($o['label_printed_at']);
?>
  <div class="label" data-id="<?= (int)$o['id'] ?>">
    <input type="checkbox" class="pick noprint" onclick="event.stopPropagation();togglePick(this)">
    <div class="lhead">
      <span class="f-logo" style="display:flex;gap:8px;align-items:center"><img src="assets/luprah-logo.png"><b><?= e($store) ?></b></span>
      <b><?= e($o['code']) ?></b>
    </div>
    <div class="row" style="margin-top:0">
      <?php if($isNcm): ?><span class="courier-badge" style="background:#14b8a6">📦 NCM #<?= e($o['ncm_order_id']) ?></span>
      <?php else: ?><span class="courier-badge" style="background:<?= e($ccolor) ?>">🚚 <?= e($o['courier_name']?:'No courier') ?></span><?php endif; ?>
      <span></span>
    </div>
    <div class="to">📦 <?= e($o['customer']?:'—') ?></div>
    <div class="addr"><?= e($o['address']?:'—') ?> · <?= e(ucfirst($o['zone'])) ?> Valley</div>
    <div class="row"><span>📞 <?= e($o['phone']?:'—') ?></span><span><?= e($o['product_name']?:'') ?> ×<?= (int)$o['qty'] ?></span></div>
    <?php if($o['remarks']): ?><div class="row f-remarks"><span>📝 <?= e($o['remarks']) ?></span><span></span></div><?php endif; ?>
    <?php if($o['sales_person_name']): ?><div class="row f-sp"><span>Sales: <?= e($o['sales_person_name']) ?></span><span></span></div><?php endif; ?>
    <?php if($defWeight!==''): ?><div class="row f-weight"><span>Weight: ~<?= e($defWeight) ?> kg</span><span></span></div><?php endif; ?>
    <?php if($cod>0): ?><div class="cod">COD: <?= money($cod) ?></div><?php else: ?><div class="cod" style="border-style:dashed">PREPAID ✓</div><?php endif; ?>
    <svg class="bc" data-code="<?= e($o['ncm_order_id']?:$o['code']) ?>"></svg>
    <div class="row" style="color:#667085"><span>From: <?= e($store) ?> · <?= e($phone) ?></span><span><?= e($o['order_date']) ?></span></div>
    <div class="noprint">
      <?php if($printed): ?><span class="printbadge done">🖨 Printed <?= (int)$o['label_print_count'] ?>× · last <?= e(date('d M, H:i', strtotime($o['label_printed_at']))) ?></span>
      <?php else: ?><span class="printbadge pending">Not printed yet</span><?php endif; ?>
    </div>
  </div>
<?php endforeach; if(!$orders) echo '<div style="grid-column:1/-1;background:#fff;border-radius:10px;padding:40px;text-align:center;color:#667085">No orders for labels in this view.</div>'; ?>
</div>
<script>
var CSRF=<?= json_encode(csrf()) ?>;
document.querySelectorAll('svg.bc').forEach(function(s){
  try{ JsBarcode(s, s.getAttribute('data-code')||'0', {format:'CODE128',displayValue:true,fontSize:12,height:40,margin:0}); }catch(e){ s.remove(); }
});
function setPageSize(){
  document.getElementById('pageSize').textContent = document.body.classList.contains('fmt-thermal')
    ? '@page{size:4in 6in;margin:2mm}' : '@page{size:auto;margin:10mm}';
}
setPageSize();
function persistFormat(fmt){
  fetch('labels.php?ajax=save_settings',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'csrf='+encodeURIComponent(CSRF)+'&format='+encodeURIComponent(fmt)});
}
function toggleField(name,on){
  document.body.classList.toggle('hide-'+name, !on);
}
function saveSettings(){
  var body='csrf='+encodeURIComponent(CSRF)
    +'&format='+encodeURIComponent(document.body.classList.contains('fmt-thermal')?'thermal':'a4')
    +'&show_remarks='+(document.getElementById('s_remarks').checked?1:0)
    +'&show_sales_person='+(document.getElementById('s_sp').checked?1:0)
    +'&show_weight='+(document.getElementById('s_weight').checked?1:0)
    +'&show_logo='+(document.getElementById('s_logo').checked?1:0);
  fetch('labels.php?ajax=save_settings',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
    .then(r=>r.json()).then(function(j){ if(j.ok){ var b=document.querySelector('.settings'); b.classList.remove('open'); } });
}
function togglePick(cb){ cb.closest('.label').classList.toggle('selected', cb.checked); }
function selectAll(on){ document.querySelectorAll('.pick').forEach(function(cb){ cb.checked=on; togglePick(cb); }); }
function visibleIds(mode){
  var ids=[];
  document.querySelectorAll('.label').forEach(function(l){
    if(mode==='all' || l.classList.contains('selected')) ids.push(l.getAttribute('data-id'));
  });
  return ids;
}
function doPrint(mode){
  var ids=visibleIds(mode);
  if(mode==='selected' && !ids.length){ alert('Tick at least one label first, or use Print All.'); return; }
  document.querySelectorAll('.label').forEach(function(l){
    l.classList.toggle('hide-for-print', mode==='selected' && !l.classList.contains('selected'));
  });
  window.__printIds=ids;
  window.print();
}
window.addEventListener('afterprint', function(){
  document.querySelectorAll('.label').forEach(function(l){ l.classList.remove('hide-for-print'); });
  var ids=window.__printIds; if(!ids||!ids.length) return; window.__printIds=null;
  fetch('labels.php?ajax=mark_printed',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'csrf='+encodeURIComponent(CSRF)+'&ids='+encodeURIComponent(ids.join(','))})
    .then(r=>r.json()).then(function(j){
      if(!j.ok) return;
      Object.keys(j.data).forEach(function(id){
        var l=document.querySelector('.label[data-id="'+id+'"]'); if(!l) return;
        var b=l.querySelector('.printbadge'); var d=j.data[id];
        if(b){ b.className='printbadge done'; b.textContent='🖨 Printed '+d.count+'× · last just now'; }
      });
    });
});
</script>
</body></html>
