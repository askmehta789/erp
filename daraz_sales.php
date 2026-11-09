<?php
/* Daraz Sales — a sheet of ONLY Daraz orders (they also still appear in the main Sales
   sheet). Edit status / Daraz fees per row or in bulk, link or re-check orders against
   Daraz by order number, filter, sort and export. */
require_once __DIR__.'/daraz_common.php';
require_login(); require_page_access();
daraz_ensure_cols();
$PAGE_TITLE = 'Daraz Sales';
$perm = order_perms();
$dzId = daraz_courier_id();
$R = daraz_range($_SERVER['REQUEST_METHOD']==='POST' ? $_POST : $_GET);
$STATUSES = ['pending','processing','shipped','delivered','returned','cancelled'];

/* one order line: status change (stock + payment + return charge) and/or Daraz fee edit */
function dzs_update_line(int $id, ?string $newStatus, ?float $fees): bool {
  $o = row("SELECT * FROM orders WHERE id=? AND courier_id=?", [$id, daraz_courier_id()]);
  if (!$o) return false;
  $old = $o['status']; $new = $newStatus ?? $old;
  $pay = $o['payment_status']; $cc = (float)$o['cancel_charge'];
  if ($new !== $old) {
    if ($o['product_id']) fifo_status_change($id, (int)$o['product_id'], (int)$o['qty'], $old, $new);
    if ($new === 'delivered') $pay = 'paid';
    if (in_array($new, ['returned','cancelled'], true) && $cc <= 0) {
      try { $cc = (float)val("SELECT COALESCE(cancel_charge,0) FROM couriers WHERE id=?", [(int)$o['courier_id']]); } catch (Exception $e) { $cc = 0; }
    }
  }
  $dc = $fees === null ? (float)$o['delivery_charge'] : max(0, $fees);
  $src = $o['daraz_fee_source'];
  if ($fees !== null && abs($dc - (float)$o['delivery_charge']) > 0.009) $src = $dc > 0 ? 'manual' : 'none';
  q("UPDATE orders SET status=?, delivery_charge=?, cancel_charge=?, payment_status=?, daraz_fee_source=? WHERE id=?", [$new, $dc, $cc, $pay, $src, $id]);
  return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf();
  $act = $_POST['_action'] ?? '';
  $back = 'daraz_sales.php?'.$R['qs'];
  if (!$perm['edit']) { flash('You do not have permission to edit orders.'); header('Location: '.$back); exit; }
  if ($act === 'set_row') {
    $st = in_array($_POST['status'] ?? '', $STATUSES, true) ? $_POST['status'] : null;
    $fees = ($_POST['fees'] ?? '') === '' ? null : (float)$_POST['fees'];
    $o = row("SELECT code FROM orders WHERE id=?", [(int)($_POST['id'] ?? 0)]);
    if (dzs_update_line((int)($_POST['id'] ?? 0), $st, $fees)) {
      log_activity("Daraz order {$o['code']} updated (status/fees)", 'Daraz');
      flash("Order {$o['code']} saved.");
    }
    header('Location: '.$back.'#o'.(int)$_POST['id']); exit;
  }
  if ($act === 'bulk') {
    $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
    $st = in_array($_POST['status'] ?? '', $STATUSES, true) ? $_POST['status'] : null;
    $fees = ($_POST['fees'] ?? '') === '' ? null : (float)$_POST['fees'];
    $n = 0;
    if ($ids && ($st !== null || $fees !== null)) foreach ($ids as $id) if (dzs_update_line($id, $st, $fees)) $n++;
    log_activity("Daraz bulk update: $n line(s)".($st ? " → $st" : '').($fees !== null ? ', fees '.money($fees) : ''), 'Daraz');
    flash($n ? "Updated $n order line(s)." : 'Nothing to update — pick a status or enter fees.');
    header('Location: '.$back); exit;
  }
  header('Location: '.$back); exit;
}

$orders = daraz_orders_between($R['start'], $R['end']);
$prodOpts = [];
foreach ($orders as $o) { $pl = $o['product_name'] ?: ($o['daraz_item_name'] ?? '') ?: '—'; $prodOpts[$pl] = ($prodOpts[$pl] ?? 0) + 1; }
ksort($prodOpts, SORT_NATURAL | SORT_FLAG_CASE);
$M = daraz_metrics($orders, daraz_ads_between($R['start'], $R['end']));

require __DIR__.'/includes/header.php';
echo delivery_disabled_banner('daraz.php');
echo daraz_shared_css();
?>
<style>
.dzs-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:0 18px 12px}
.dzs-bar .ncm-search{flex:1;min-width:200px;max-width:300px;border-radius:999px;padding:8px 14px}
.dzs-bar select{border-radius:999px;padding:8px 12px;font-size:12.5px;border:1px solid rgba(148,163,184,.35);max-width:220px}
.dz-tog{padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;cursor:pointer;border:1px dashed rgba(148,163,184,.6);background:transparent;color:var(--ink)}
.dz-tog.on{border-style:solid;border-color:#f85606;background:rgba(248,86,6,.12);color:#c2410c}
body.dark .dz-tog.on{color:#fdba74}
.dzs-sum{margin-left:auto;font-size:12.5px;color:var(--muted)}
.dzs-bulk{display:none;gap:8px;align-items:center;flex-wrap:wrap;margin:0 18px 12px;padding:10px 14px;border-radius:14px;background:rgba(248,86,6,.08);border:1px solid rgba(248,86,6,.25)}
.dzs-bulk select,.dzs-bulk input{border-radius:999px;padding:6px 10px;font-size:12.5px;border:1px solid rgba(148,163,184,.35)}
.dzs-fee{font-size:11.5px;padding:5px 7px;border-radius:8px;border:1px solid rgba(148,163,184,.4);width:76px;background:rgba(255,255,255,.75)}
body.dark .dzs-fee{background:rgba(2,6,23,.5);color:var(--ink);border-color:rgba(148,163,184,.25)}
.dzs-tbl td{vertical-align:top}
.dzs-more{text-align:center;padding:12px}
</style>

<div class="ncmv3">
<div class="ncm-hero">
  <div class="nh-left">
    <h1><img src="assets/daraz-icon.ico" alt="Daraz" style="height:28px;vertical-align:-6px;margin-right:6px"><span class="grad">Daraz Sales</span></h1>
    <p>Only Daraz orders · book them in Sales, link each to its Daraz order number, and status &amp; Daraz charges sync from Daraz</p>
  </div>
  <div class="nh-actions">
    <button type="button" class="btn nh-ghost" onclick="dzlOpen('')">🔗 Check a Daraz order by ID</button>
    <?php if ($perm['add']): ?><a class="btn nh-cta" href="sales.php?new=daraz">＋ New Daraz Order</a><?php endif; ?>
  </div>
</div>
<?= daraz_tabs('daraz_sales.php') ?>
<?= daraz_range_bar($R, 'daraz_sales.php') ?>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<div class="panel" style="margin-top:16px">
  <div class="panel-head" style="flex-wrap:wrap;gap:10px">
    <h2>🧾 Daraz orders <span id="dzsShowing" class="muted" style="font-size:12px;font-weight:600"></span></h2>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <button type="button" class="btn btn-sm" onclick="dzsRecheckShown()" title="Get the latest status and Daraz charges for every linked order shown below">🔄 Sync shown with Daraz</button>
      <button type="button" class="btn btn-sm" onclick="dzsExport()">⬇ Export CSV</button>
    </div>
  </div>
  <div class="dzs-bar">
    <input id="dzsSearch" class="ncm-search" placeholder="🔍 Order id, customer, phone, product…" oninput="dzsFilter()">
    <select id="dzsProd" onchange="dzsFilter()" aria-label="Product"><option value="">All products</option>
      <?php foreach ($prodOpts as $pl => $pc): ?><option value="<?= e($pl) ?>"><?= e($pl) ?> (<?= $pc ?>)</option><?php endforeach; ?></select>
    <select id="dzsStatus" onchange="dzsFilter()" aria-label="Status"><option value="">All status</option>
      <option value="pack">To pack (pending + processing)</option>
      <?php foreach ($STATUSES as $s): ?><option value="<?= $s ?>"><?= ucfirst($s) ?></option><?php endforeach; ?></select>
    <select id="dzsPay" onchange="dzsFilter()" aria-label="Payment"><option value="">COD + prepaid</option><option value="cod">COD only</option><option value="prepaid">Prepaid only</option></select>
    <select id="dzsSort" onchange="dzsSort()" aria-label="Sort">
      <option value="new">Newest first</option><option value="old">Oldest first</option>
      <option value="profit_hi">Highest profit</option><option value="profit_lo">Lowest profit / loss</option><option value="val_hi">Highest order value</option>
    </select>
  </div>
  <div class="dzs-bar" style="padding-top:0">
    <button type="button" class="dz-tog" data-k="loss" onclick="dzsTog(this)">🔻 Loss only</button>
    <button type="button" class="dz-tog" data-k="nofee" onclick="dzsTog(this)" title="Delivered orders with no Daraz fees yet">💸 Delivered, no fees</button>
    <button type="button" class="dz-tog" data-k="warn" onclick="dzsTog(this)">⚠ Unmapped SKU</button>
    <button type="button" class="dz-tog" data-k="unlinked" onclick="dzsTog(this)" title="Booked in the ERP but no Daraz order number linked yet">🔗 Not linked</button>
    <button type="button" class="dz-tog" data-k="unchk" onclick="dzsTog(this)" title="Linked but never synced with Daraz">❔ Not synced yet</button>
    <a href="#" class="muted" style="font-size:12px;text-decoration:none" onclick="dzsClear();return false">✕ Clear filters</a>
    <span class="dzs-sum" id="dzsSum"></span>
  </div>

  <?php if ($perm['edit']): ?>
  <form method="post" class="dzs-bulk" id="dzsBulk" onsubmit="return dzsBulkSubmit(this)">
    <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="bulk"><?= $R['keep'] ?>
    <input type="hidden" name="ids" id="dzsBulkIds">
    <b><span id="dzsSelN">0</span> selected</b>
    <select name="status"><option value="">Status: keep</option><?php foreach ($STATUSES as $s): ?><option value="<?= $s ?>">→ <?= ucfirst($s) ?></option><?php endforeach; ?></select>
    <input type="number" name="fees" step="any" min="0" placeholder="Daraz fees each" style="width:140px">
    <button class="btn btn-sm btn-primary">Apply to selected</button>
    <button type="button" class="btn btn-sm" onclick="dzlRecheck(dzsSelIds(true))">🔄 Re-check selected</button>
    <a href="#" class="muted" style="font-size:12px" onclick="dzsSelAll(false);return false">clear</a>
  </form>
  <?php endif; ?>

  <div class="table-wrap"><table class="tbl led-tbl dzs-tbl" id="dzsTbl"><thead><tr>
    <th style="width:28px"><input type="checkbox" id="dzsAll" onclick="dzsSelAll(this.checked)" aria-label="Select all shown"></th>
    <th>Daraz order</th><th>Customer</th><th>Product</th><th style="text-align:right">Customer paid</th>
    <th style="text-align:right">Daraz fees</th><th style="text-align:right">Profit</th><th>Status</th><th></th>
  </tr></thead><tbody>
  <?php foreach ($orders as $o):
    $isDel = $o['status']==='delivered';
    $closed = in_array($o['status'], ['returned','cancelled','exchanged'], true);
    $prof = order_profit($o);
    $pot = ((float)$o['sell_price']-(float)$o['cost_price'])*(int)$o['qty'] - (float)$o['delivery_charge'];
    $rowProfit = ($isDel || $closed) ? $prof : $pot;
    $val = (float)$o['sell_price']*(int)$o['qty'];
    $ph = preg_replace('/\D+/','',(string)($o['phone'] ?? ''));
    $pl = $o['product_name'] ?: ($o['daraz_item_name'] ?? '') ?: '—';
    $warn = !empty($o['remarks']) && strpos($o['remarks'],'⚠')!==false;
    $dzOid = $o['daraz_order_id'] ?: (preg_match('/^(?:Daraz #)?(\d{6,})/', trim((string)$o['remarks']), $mm) ? $mm[1] : '');
    $grp = in_array($o['status'],['pending','processing'],true) ? 'pack' : $o['status'];
    $src = $o['daraz_fee_source'] ?? '';
  ?>
    <tr id="o<?= (int)$o['id'] ?>" data-s="<?= e(strtolower($dzOid.' '.$o['code'].' '.($o['customer']??'').' '.($o['phone']??'').' '.$pl.' '.($o['daraz_sku']??''))) ?>"
        data-st="<?= e($o['status']) ?>" data-g="<?= e($grp) ?>" data-prod="<?= e($pl) ?>" data-pay="<?= e($o['payment_type'] ?? '') ?>"
        data-profit="<?= round($rowProfit,2) ?>" data-val="<?= round($val,2) ?>" data-nofee="<?= ($isDel && (float)$o['delivery_charge']<=0 && $src!=='finance')?1:0 ?>"
        data-warn="<?= $warn?1:0 ?>" data-chk="<?= $o['daraz_checked_at']?1:0 ?>" data-linked="<?= $dzOid!==''?1:0 ?>" data-date="<?= e($o['order_date']) ?>" data-id="<?= (int)$o['id'] ?>" data-oid="<?= e($dzOid) ?>">
      <td><input type="checkbox" class="dzs-sel" onclick="dzsSelChanged()" aria-label="Select order"></td>
      <td>
        <div class="dz-oid"><span class="dz-dot"></span><?= e($dzOid ?: '—') ?></div>
        <div class="dz-sub"><?= e($o['code']) ?> · <?= e(date('j M', strtotime($o['order_date']))) ?></div>
        <?php if ($dzOid===''): ?><span class="dz-badge dz-b-warn" title="No Daraz order number linked yet">not linked</span>
        <?php elseif ($o['daraz_checked_at']): ?><span class="dz-badge dz-b-ok" title="Last synced with Daraz <?= e($o['daraz_checked_at']) ?>">✓ synced<?= $o['daraz_status_raw'] ? ' · '.e($o['daraz_status_raw']) : '' ?></span>
        <?php else: ?><span class="dz-badge dz-b-fin" title="Linked, not synced yet">linked</span><?php endif; ?>
      </td>
      <td><b><?= e($o['customer'] ?: '—') ?></b>
        <?php if ($ph !== ''): ?><div class="dz-sub"><?= e($o['phone']) ?><?php if (strlen($ph)===10 && $ph[0]==='9'): ?> · <a href="https://wa.me/977<?= e($ph) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></div><?php endif; ?>
        <div class="dz-sub"><?= ($o['payment_type'] ?? '')==='prepaid' ? 'Prepaid' : 'COD' ?></div>
      </td>
      <td><b><?= e($pl) ?></b> × <?= (int)$o['qty'] ?>
        <?php if (!empty($o['daraz_sku'])): ?><div class="dz-sub">SKU <?= e($o['daraz_sku']) ?></div><?php endif; ?>
        <?php if ($warn): ?><span class="dz-warn">⚠ SKU not mapped — map it on the Dashboard</span><?php endif; ?>
      </td>
      <td style="text-align:right"><?= money((float)$o['sell_price']) ?>
        <?php if ($o['daraz_list_price'] !== null && ((float)$o['daraz_seller_discount'] > 0 || (float)$o['daraz_platform_voucher'] > 0)): ?><div class="dz-sub" title="Daraz list price and vouchers on this line">list <?= money($o['daraz_list_price']) ?><?= (float)$o['daraz_seller_discount'] > 0 ? ' · your voucher −'.money($o['daraz_seller_discount']) : '' ?><?= (float)$o['daraz_platform_voucher'] > 0 ? ' · Daraz voucher −'.money($o['daraz_platform_voucher']) : '' ?></div><?php endif; ?>
        <div class="dz-sub">cost <?= money((float)$o['cost_price']) ?></div></td>
      <td style="text-align:right">
        <?php if ($perm['edit']): ?><input form="f<?= (int)$o['id'] ?>" type="number" name="fees" class="dzs-fee" step="any" min="0" value="<?= (float)$o['delivery_charge']>0 ? e((float)$o['delivery_charge']) : '' ?>" placeholder="fees"><?php else: ?><?= money($o['delivery_charge']) ?><?php endif; ?>
        <div class="dz-sub"><?= $src==='finance' ? '<span class="dz-badge dz-b-fin">real</span>' : ($src==='manual' ? 'typed' : '') ?></div></td>
      <td style="text-align:right;font-weight:800;color:<?= $rowProfit>=0?'var(--green)':'var(--red)' ?>"><?= ($isDel||$closed) ? money($prof) : '≈ '.money($pot) ?></td>
      <td>
        <?php if ($perm['edit']): ?>
        <form method="post" id="f<?= (int)$o['id'] ?>" style="display:flex;gap:5px;align-items:center;flex-wrap:nowrap">
          <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="set_row"><?= $R['keep'] ?>
          <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
          <select name="status" class="dz-status st-<?= e($o['status']) ?>" onchange="this.className='dz-status st-'+this.value">
            <?php foreach ($STATUSES as $st): ?><option value="<?= $st ?>"<?= $o['status']===$st?' selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-sm" title="Save status and fees">💾</button>
        </form>
        <?php else: ?><span class="dz-status st-<?= e($o['status']) ?>"><?= ucfirst(e($o['status'])) ?></span><?php endif; ?>
      </td>
      <td style="white-space:nowrap"><?php if ($dzOid): ?><button type="button" class="btn btn-sm" title="Sync status and charges from Daraz" onclick="dzlOpen('<?= e($dzOid) ?>')">🔄</button><?php if ($perm['edit'] && $o['daraz_order_id']): ?> <a href="#" class="muted" style="font-size:11px" title="Remove the Daraz link" onclick="dzlUnlink(<?= (int)$o['id'] ?>,'<?= e($o['code']) ?>');return false">unlink</a><?php endif; ?>
        <?php elseif ($perm['edit']): ?><button type="button" class="btn btn-sm btn-primary" title="Link this order to its Daraz order number" onclick="dzlOpenFor(<?= (int)$o['id'] ?>,'<?= e($o['code']) ?>',<?= e(json_encode(($o['customer'] ?: '').' · '.($o['phone'] ?: ''))) ?>)">🔗 Link</button><?php endif; ?></td>
    </tr>
  <?php endforeach; if (!$orders): ?>
    <tr><td colspan="9"><div class="empty" style="padding:30px">No Daraz orders in this period. Book Daraz orders in <a href="sales.php">Sales</a> (courier Daraz), then link each one to its Daraz order number here.</div></td></tr>
  <?php endif; ?>
  </tbody></table></div>
  <div class="dzs-more" id="dzsMore" style="display:none"><button type="button" class="btn btn-sm" onclick="dzsPage+=1;dzsFilter(true)">Show 50 more</button></div>
</div>
</div><!-- /ncmv3 -->
<?= daraz_link_ui() ?>
<script>
var dzsT={}, dzsPage=1, DZS_PER=50;
function dzsRows(){return [].slice.call(document.querySelectorAll('#dzsTbl tr[data-id]'));}
function dzsTog(b){var k=b.getAttribute('data-k');dzsT[k]=!dzsT[k];b.classList.toggle('on',dzsT[k]);dzsPage=1;dzsFilter();}
function dzsClear(){dzsT={};document.querySelectorAll('.dz-tog').forEach(function(b){b.classList.remove('on');});
  ['dzsSearch','dzsProd','dzsStatus','dzsPay'].forEach(function(id){document.getElementById(id).value='';});dzsPage=1;dzsFilter();}
function dzsMoney(v){return 'Rs. '+Math.round(v).toLocaleString('en-IN');}
function dzsMatch(tr){
  var a=function(k){return tr.getAttribute('data-'+k);},q=(document.getElementById('dzsSearch').value||'').toLowerCase().trim();
  var st=document.getElementById('dzsStatus').value,p=parseFloat(a('profit'))||0;
  return (!q||a('s').indexOf(q)>-1)&&(!st||(st==='pack'?a('g')==='pack':a('st')===st))
    &&(!document.getElementById('dzsProd').value||a('prod')===document.getElementById('dzsProd').value)
    &&(!document.getElementById('dzsPay').value||a('pay')===document.getElementById('dzsPay').value)
    &&(!dzsT.loss||p<0)&&(!dzsT.nofee||a('nofee')==='1')&&(!dzsT.warn||a('warn')==='1')&&(!dzsT.unchk||(a('chk')==='0'&&a('linked')==='1'))&&(!dzsT.unlinked||a('linked')==='0');
}
function dzsFilter(keepPage){
  if(!keepPage)dzsPage=1;
  var n=0,shown=0,val=0,prof=0,limit=dzsPage*DZS_PER;
  dzsRows().forEach(function(tr){
    var ok=dzsMatch(tr);
    if(ok){n++;val+=parseFloat(tr.getAttribute('data-val'))||0;prof+=parseFloat(tr.getAttribute('data-profit'))||0;}
    var vis=ok&&n<=limit;tr.style.display=vis?'':'none';if(vis)shown++;
    if(!ok){var c=tr.querySelector('.dzs-sel');if(c)c.checked=false;}
  });
  document.getElementById('dzsShowing').textContent='· '+n+' match'+(shown<n?' (showing '+shown+')':'');
  document.getElementById('dzsSum').innerHTML='';
  var s=document.getElementById('dzsSum');s.textContent=n+' line'+(n===1?'':'s')+' · value '+dzsMoney(val)+' · profit ';
  var b=document.createElement('b');b.style.color=prof>=0?'var(--green)':'var(--red)';b.textContent=dzsMoney(prof);s.appendChild(b);
  document.getElementById('dzsMore').style.display=shown<n?'':'none';
  dzsSelChanged();
}
function dzsSort(){
  var how=document.getElementById('dzsSort').value,tb=document.querySelector('#dzsTbl tbody'),rows=dzsRows();
  var num=function(tr,k){return parseFloat(tr.getAttribute('data-'+k))||0;},key=function(tr){return tr.getAttribute('data-date')+'|'+String(num(tr,'id')).padStart(9,'0');};
  rows.sort(function(x,y){
    if(how==='old')return key(x)<key(y)?-1:1;
    if(how==='profit_hi')return num(y,'profit')-num(x,'profit');
    if(how==='profit_lo')return num(x,'profit')-num(y,'profit');
    if(how==='val_hi')return num(y,'val')-num(x,'val');
    return key(x)<key(y)?1:-1;
  });
  rows.forEach(function(r){tb.appendChild(r);});dzsFilter(true);
}
function dzsSelIds(oids){return dzsRows().filter(function(tr){var c=tr.querySelector('.dzs-sel');return c&&c.checked;}).map(function(tr){return oids?tr.getAttribute('data-oid'):tr.getAttribute('data-id');}).filter(Boolean);}
function dzsSelAll(on){dzsRows().forEach(function(tr){var c=tr.querySelector('.dzs-sel');if(c)c.checked=on&&tr.style.display!=='none';});var a=document.getElementById('dzsAll');if(a)a.checked=on;dzsSelChanged();}
function dzsSelChanged(){var n=dzsSelIds(false).length,b=document.getElementById('dzsBulk');if(b){b.style.display=n?'flex':'none';document.getElementById('dzsSelN').textContent=n;}}
function dzsBulkSubmit(f){var ids=dzsSelIds(false);if(!ids.length)return false;
  if(!f.status.value&&f.fees.value===''){alert('Pick a status or enter Daraz fees to apply.');return false;}
  document.getElementById('dzsBulkIds').value=ids.join(',');return confirm('Apply to '+ids.length+' order line(s)?');}
function dzsRecheckShown(){var ids=dzsRows().filter(function(tr){return dzsMatch(tr);}).map(function(tr){return tr.getAttribute('data-oid');}).filter(Boolean);dzlRecheck(ids);}
function dzsExport(){
  var lines=[['Daraz order','ERP code','Date','Customer','Phone','Payment','Product','SKU','Qty','Customer paid (per unit)','Cost','Daraz fees','Fee source','Profit','Status','Checked with Daraz']];
  <?php $csv=[]; foreach ($orders as $o) { $csv[(int)$o['id']] = [
      $o['daraz_order_id'] ?: '', $o['code'], $o['order_date'], $o['customer'] ?: '', $o['phone'] ?: '', $o['payment_type'] ?: '',
      $o['product_name'] ?: ($o['daraz_item_name'] ?? ''), $o['daraz_sku'] ?? '', (int)$o['qty'], (float)$o['sell_price'], (float)$o['cost_price'],
      (float)$o['delivery_charge'], $o['daraz_fee_source'] ?? '', round(order_profit($o),2), $o['status'], $o['daraz_checked_at'] ?? '' ]; } ?>
  var DATA=<?= json_encode($csv) ?>;
  dzsRows().forEach(function(tr){if(dzsMatch(tr)&&DATA[tr.getAttribute('data-id')])lines.push(DATA[tr.getAttribute('data-id')]);});
  var csv=lines.map(function(r){return r.map(function(v){return '"'+String(v).replace(/"/g,'""')+'"';}).join(',');}).join('\n');
  var a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download='daraz-sales-<?= e($R['start']) ?>-to-<?= e($R['end']) ?>.csv';a.click();
}
/* links from the Dashboard open with a filter already applied (?st=pack, ?f=nofee …) */
(function(){
  var p=new URLSearchParams(location.search),st=p.get('st'),f=p.get('f');
  if(st&&document.querySelector('#dzsStatus option[value="'+st.replace(/[^a-z]/g,'')+'"]'))document.getElementById('dzsStatus').value=st;
  if(f){var b=document.querySelector('.dz-tog[data-k="'+f.replace(/[^a-z]/g,'')+'"]');if(b){dzsT[f]=true;b.classList.add('on');}}
})();
dzsFilter();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
