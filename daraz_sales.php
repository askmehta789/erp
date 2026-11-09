<?php
/* Daraz Sales — a sheet of ONLY Daraz orders (they also still appear in the main Sales
   sheet). Edit status / Daraz fees per row or in bulk, link or re-check orders against
   Daraz by order number, filter, sort and export. */
require_once __DIR__.'/daraz_common.php';
/* Daraz Sales opens as the same spreadsheet as main Sales, Daraz orders only (sales.php in
   Daraz mode). ?view=list keeps this filterable list with bulk tools, import and the order form. */
if (($_GET['view'] ?? '') !== 'list' && !isset($_POST['_action'])) {
  define('SALES_DARAZ_MODE', true);
  require __DIR__.'/sales.php';
  exit;
}
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
  $back = 'daraz_sales.php?view=list&'.$R['qs'];

  /* ---- book / edit a Daraz order (Daraz orders live only on this page) ---- */
  if ($act === 'save_order') {
    $id  = (int)($_POST['id'] ?? 0);
    if (!$perm[$id ? 'edit' : 'add']) { flash('You do not have permission to '.($id ? 'edit' : 'add').' orders.'); header('Location: '.$back); exit; }
    $old = $id ? row("SELECT * FROM orders WHERE id=? AND courier_id=?", [$id, $dzId]) : null;
    if ($id && !$old) { flash('That Daraz order no longer exists.'); header('Location: '.$back); exit; }
    $pid    = (int)($_POST['product_id'] ?? 0) ?: null;
    $qty    = max(1, (int)($_POST['qty'] ?? 1));
    $price  = max(0, (float)($_POST['sell_price'] ?? 0));
    $fees   = max(0, (float)($_POST['fees'] ?? 0));
    $status = in_array($_POST['status'] ?? '', $STATUSES, true) ? $_POST['status'] : 'pending';
    $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['order_date'] ?? '') ? $_POST['order_date'] : date('Y-m-d');
    $dzNum  = preg_replace('/\D+/', '', (string)($_POST['daraz_order_id'] ?? ''));
    $item   = trim((string)($_POST['item_name'] ?? ''));
    if (!$pid && $item === '') { flash('Pick a product (or type the item name).'); header('Location: '.$back); exit; }
    $f = [
      'order_date'=>$date, 'customer'=>trim((string)($_POST['customer'] ?? '')), 'phone'=>daraz_clean_phone((string)($_POST['phone'] ?? '')),
      'address'=>trim((string)($_POST['address'] ?? '')), 'product_id'=>$pid, 'qty'=>$qty, 'sell_price'=>$price,
      'delivery_charge'=>$fees, 'payment_type'=>($_POST['payment_type'] ?? 'cod') === 'prepaid' ? 'prepaid' : 'cod',
      'status'=>$status, 'remarks'=>trim((string)($_POST['remarks'] ?? '')), 'daraz_order_id'=>$dzNum !== '' ? $dzNum : null,
      'daraz_item_name'=>$item !== '' ? $item : null, 'courier_id'=>$dzId,
    ];
    if ($fees > 0 && (!$old || abs((float)$old['delivery_charge'] - $fees) > 0.009)) $f['daraz_fee_source'] = 'manual';
    $f['payment_status'] = $status === 'delivered' ? 'paid' : ($old['payment_status'] ?? 'unpaid');
    if (in_array($status, ['returned','cancelled'], true)) {
      $f['cancel_charge'] = (float)($old['cancel_charge'] ?? 0) > 0 ? (float)$old['cancel_charge']
        : (float)val("SELECT COALESCE(cancel_charge,0) FROM couriers WHERE id=?", [$dzId]);
    }
    /* cost: current FIFO cost for a new line or a changed product (delivery recalculates it from batches) */
    if (!$old || (int)$old['product_id'] !== (int)$pid) $f['cost_price'] = $pid ? fifo_current_cost($pid) : 0;
    if ($old) {
      $productChanged = (int)$old['product_id'] !== (int)$pid;
      /* product swapped on a delivered order: put the OLD product's units back first
         (before the row changes, so the right qty is restored) */
      if ($productChanged && $old['status'] === 'delivered' && (int)$old['product_id']) fifo_restore($id, (int)$old['product_id']);
      $cols = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($f)));
      q("UPDATE orders SET $cols WHERE id=?", array_merge(array_values($f), [$id]));
      /* then the new product / qty / status moves stock with the same rules as the main Sales sheet */
      if ($pid) fifo_status_change($id, (int)$pid, $qty, $productChanged ? '' : $old['status'], $status);
      log_activity("Daraz order {$old['code']} edited", 'Daraz');
      flash("Order {$old['code']} saved.");
    } else {
      $f += ['code'=>daraz_next_code(), 'zone'=>'inside', 'cancel_charge'=>$f['cancel_charge'] ?? 0, 'exchange_charge'=>0];
      $cols = implode('`,`', array_keys($f));
      q("INSERT INTO orders(`$cols`) VALUES(".implode(',', array_fill(0, count($f), '?')).")", array_values($f));
      $id = (int)db()->lastInsertId();
      if ($pid) fifo_status_change($id, (int)$pid, $qty, '', $status);
      log_activity("Daraz order {$f['code']} booked".($dzNum ? " (Daraz #$dzNum)" : ''), 'Daraz');
      flash("Order {$f['code']} booked.".($dzNum ? '' : ' Add its Daraz order number later to sync status and charges.'));
    }
    header('Location: '.$back.'#o'.$id); exit;
  }
  /* ---- bulk paste import: rows were parsed + checked in the browser, re-validated here ---- */
  if ($act === 'import_orders') {
    if (!$perm['add']) { flash('You do not have permission to add orders.'); header('Location: '.$back); exit; }
    $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
    if (!is_array($rows) || !$rows) { flash('Nothing to import.'); header('Location: '.$back); exit; }
    $skipDup = ($_POST['skip_dup'] ?? '1') === '1';
    $prodIds = array_flip(array_map('intval', array_column(rows("SELECT id FROM products"), 'id')));
    $existing = [];
    foreach (rows("SELECT DISTINCT daraz_order_id FROM orders WHERE courier_id=? AND daraz_order_id IS NOT NULL", [$dzId]) as $r) $existing[$r['daraz_order_id']] = 1;
    $made = 0; $dup = max(0, (int)($_POST['skipped'] ?? 0)); $unmatched = 0; $bad = 0; $seenInFile = [];
    foreach (array_slice($rows, 0, 2000) as $r) {
      $dzNum = preg_replace('/\D+/', '', (string)($r['daraz_order_id'] ?? ''));
      /* an order already in the ERP is skipped (lines of the same order in THIS paste still go in) */
      if ($skipDup && $dzNum !== '' && isset($existing[$dzNum]) && !isset($seenInFile[$dzNum])) { $dup++; continue; }
      if ($dzNum !== '') $seenInFile[$dzNum] = 1;
      $pid  = (int)($r['product_id'] ?? 0); $pid = isset($prodIds[$pid]) ? $pid : null;
      $item = trim((string)($r['item'] ?? ''));
      if (!$pid && $item === '') { $bad++; continue; }
      if (!$pid) $unmatched++;
      $status = in_array($r['status'] ?? '', $STATUSES, true) ? $r['status'] : 'pending';
      $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($r['date'] ?? '')) ? $r['date'] : date('Y-m-d');
      $o = [
        'code'=>daraz_next_code(), 'order_date'=>$date, 'customer'=>trim((string)($r['customer'] ?? '')),
        'phone'=>daraz_clean_phone((string)($r['phone'] ?? '')), 'address'=>trim((string)($r['address'] ?? '')),
        'product_id'=>$pid, 'qty'=>max(1, (int)($r['qty'] ?? 1)), 'sell_price'=>max(0, (float)($r['price'] ?? 0)),
        'cost_price'=>$pid ? fifo_current_cost($pid) : 0, 'delivery_charge'=>max(0, (float)($r['fees'] ?? 0)),
        'daraz_fee_source'=>(float)($r['fees'] ?? 0) > 0 ? 'manual' : null,
        'payment_type'=>($r['payment'] ?? '') === 'prepaid' ? 'prepaid' : 'cod', 'payment_status'=>$status === 'delivered' ? 'paid' : 'unpaid',
        'status'=>$status, 'courier_id'=>$dzId, 'zone'=>'inside', 'cancel_charge'=>0, 'exchange_charge'=>0,
        'remarks'=>'imported'.($pid ? '' : ' ⚠ '.$item.' — not matched to a product yet'),
        'daraz_order_id'=>$dzNum !== '' ? $dzNum : null, 'daraz_item_name'=>$item !== '' ? $item : null,
        'daraz_sku'=>trim((string)($r['sku'] ?? '')) ?: null,
      ];
      $cols = implode('`,`', array_keys($o));
      q("INSERT INTO orders(`$cols`) VALUES(".implode(',', array_fill(0, count($o), '?')).")", array_values($o));
      $newId = (int)db()->lastInsertId();
      if ($pid) fifo_status_change($newId, $pid, $o['qty'], '', $status);
      $made++;
    }
    log_activity("Daraz paste import: $made line(s) added".($dup ? ", $dup already in the ERP (skipped)" : '').($unmatched ? ", $unmatched without a matched product" : ''), 'Daraz');
    flash("Imported $made order line(s)".($dup ? " · $dup skipped (Daraz number already in the ERP)" : '').($unmatched ? " · $unmatched need a product — use ✏ Edit on those rows" : '').($bad ? " · $bad empty line(s) ignored" : '').'.');
    header('Location: '.$back); exit;
  }
  if ($act === 'delete_order') {
    if (!$perm['delete']) { flash('You do not have permission to delete orders.'); header('Location: '.$back); exit; }
    $id = (int)($_POST['id'] ?? 0);
    $o = row("SELECT * FROM orders WHERE id=? AND courier_id=?", [$id, $dzId]);
    if ($o) {
      if ($o['status'] === 'delivered' && $o['product_id']) fifo_restore($id, (int)$o['product_id']);   /* units go back to their batches */
      q("DELETE FROM orders WHERE id=?", [$id]);
      log_activity("Daraz order {$o['code']} deleted", 'Daraz');
      flash("Order {$o['code']} deleted.");
    }
    header('Location: '.$back); exit;
  }

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
$allProducts = rows("SELECT id,name,sku,price,COALESCE(daraz_sku,'') AS daraz_sku FROM products ORDER BY name");
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
    <?php if ($perm['add']): ?><a class="btn nh-ghost" href="daraz_sales.php">📊 Sheet view</a><button type="button" class="btn nh-ghost" onclick="dziOpen()">📋 Paste Import</button><button type="button" class="btn nh-cta" onclick="dzoOpen(null)">＋ New Daraz Order</button><?php endif; ?>
  </div>
</div>
<?= daraz_tabs('daraz_sales.php') ?>
<?= daraz_range_bar($R, 'daraz_sales.php?view=list') ?>
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
      <td style="white-space:nowrap"><?php if ($perm['edit']): ?><button type="button" class="btn btn-sm" title="Edit this order" onclick="dzoOpen(this)" data-rec="<?= e(json_encode(['id'=>(int)$o['id'],'code'=>$o['code'],'order_date'=>$o['order_date'],'daraz_order_id'=>$o['daraz_order_id'],'customer'=>$o['customer'],'phone'=>$o['phone'],'address'=>$o['address'],'product_id'=>$o['product_id'],'item_name'=>$o['daraz_item_name'] ?? '','qty'=>(int)$o['qty'],'sell_price'=>(float)$o['sell_price'],'fees'=>(float)$o['delivery_charge'],'payment_type'=>$o['payment_type'],'status'=>$o['status'],'remarks'=>$o['remarks']])) ?>">✏</button><?php endif; ?>
        <?php if ($perm['delete']): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete order <?= e($o['code']) ?>? Stock is returned if it was delivered.')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="delete_order"><?= $R['keep'] ?><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn btn-sm" title="Delete this order">🗑</button></form><?php endif; ?>
        <?php if ($dzOid): ?><button type="button" class="btn btn-sm" title="Sync status and charges from Daraz" onclick="dzlOpen('<?= e($dzOid) ?>')">🔄</button><?php if ($perm['edit'] && $o['daraz_order_id']): ?> <a href="#" class="muted" style="font-size:11px" title="Remove the Daraz link" onclick="dzlUnlink(<?= (int)$o['id'] ?>,'<?= e($o['code']) ?>');return false">unlink</a><?php endif; ?>
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
<!-- bulk paste import -->
<style>
.dzi-tbl{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}
.dzi-tbl th,.dzi-tbl td{padding:5px 6px;border-bottom:1px solid rgba(148,163,184,.2);text-align:left;white-space:nowrap;max-width:180px;overflow:hidden;text-overflow:ellipsis}
.dzi-tbl tr.dup td{opacity:.45;text-decoration:line-through}
.dzi-tag{font-size:10.5px;font-weight:800;padding:1px 7px;border-radius:999px}
.dzi-ok{background:rgba(15,157,99,.14);color:#0f9d63}.dzi-warn{background:rgba(245,158,11,.16);color:#b45309}.dzi-skip{background:rgba(100,116,139,.16);color:#475569}
.dzi-opts{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:8px;font-size:12.5px}
.dzi-opts label{display:flex;gap:6px;align-items:center;margin:0;font-weight:600}
.dzi-opts select,.dzi-opts input[type=date]{width:auto}
</style>
<div class="modal-bg" id="dziModal" style="z-index:99985"><form class="modal" method="post" style="width:860px;max-width:96vw" onsubmit="return dziSubmit()">
  <div class="modal-head"><span>📋 Paste Daraz orders</span><span class="mx" onclick="dziClose()">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="import_orders"><?= $R['keep'] ?>
  <input type="hidden" name="rows" id="dzi_rows"><input type="hidden" name="skipped" id="dzi_skipped" value="0">
  <div class="modal-body" style="display:block">
    <p class="muted" style="font-size:12px;margin:0 0 8px">Copy rows from Excel / Google Sheets or the <b>Daraz Seller Center order export</b> and paste below — <b>first line = column names</b>, any order.<br>
      Understood: <i>order number · date · customer / shipping name · phone · address · product / item name / seller SKU · qty · price / paid price · Daraz charges / fees · status · payment</i>.
      No header? Default order: <code>Order number, Customer, Phone, Address, Product, Qty, Price</code>.</p>
    <textarea id="dziText" rows="7" style="width:100%;font-family:monospace;font-size:12px" oninput="dziPreview()"
      placeholder="Order Number&#9;Customer&#9;Phone&#9;Address&#9;Product&#9;Qty&#9;Paid Price&#9;Daraz Charges&#9;Status&#10;204188712345678&#9;Sita Rai&#9;9779812345678&#9;Kathmandu&#9;Smart Watch&#9;1&#9;2400&#9;210&#9;Delivered"></textarea>
    <div class="dzi-opts">
      <label>Status if not given <select name="def_status" id="dzi_defst" onchange="dziPreview()"><?php foreach ($STATUSES as $st): ?><option value="<?= $st ?>"><?= ucfirst($st) ?></option><?php endforeach; ?></select></label>
      <label>Date if not given <input type="date" id="dzi_defdate" value="<?= date('Y-m-d') ?>" onchange="dziPreview()"></label>
      <label><input type="checkbox" name="skip_dup" value="1" id="dzi_skip" checked onchange="dziPreview()" style="width:auto"> Skip orders already in the ERP</label>
    </div>
    <div id="dziOut" style="margin-top:6px;max-height:320px;overflow:auto"></div>
  </div>
  <div class="modal-foot"><span class="muted" id="dziSum" style="margin-right:auto;font-size:12.5px"></span>
    <button type="button" class="btn" onclick="dziClose()">Cancel</button><button class="btn btn-primary" id="dziBtn" disabled>⬆ Import</button></div>
</form></div>
<script>
var DZI_PRODUCTS=<?= json_encode(array_map(fn($p) => ['id'=>(int)$p['id'],'name'=>$p['name'],'sku'=>(string)$p['sku'],'dsku'=>(string)$p['daraz_sku'],'price'=>(float)$p['price']], $allProducts)) ?>;
var DZI_EXISTING=<?= json_encode(array_values(array_unique(array_filter(array_map(fn($r) => (string)$r['daraz_order_id'], rows("SELECT DISTINCT daraz_order_id FROM orders WHERE courier_id=? AND daraz_order_id IS NOT NULL", [$dzId])))))) ?>;
var DZI_SYN={
  dz:['order number','order no','order id','daraz order','daraz order id','daraz order number','order','order #','ordernumber'],
  date:['date','order date','created at','created','create time','order created at','order time'],
  customer:['customer','customer name','name','shipping name','buyer','buyer name','recipient','billing name','customer first name'],
  phone:['phone','mobile','contact','phone number','mobile number','shipping phone','shipping phone number','billing phone','customer phone'],
  address:['address','shipping address','city','location','shipping city','delivery address','shipping address1'],
  product:['product','product name','item','item name','name of product'],
  sku:['seller sku','sku','shop sku','daraz sku','sellersku'],
  qty:['qty','quantity','pcs','no of items','items'],
  price:['price','paid price','customer paid','amount','unit price','item price','total','sale price','selling price'],
  fees:['daraz charge','daraz charges','fees','fee','commission','charges','delivery','delivery charge','shipping fee paid by seller'],
  status:['status','order status','item status'],
  payment:['payment','payment method','payment type']
};
function dziNorm(t){return String(t==null?'':t).trim().toLowerCase().replace(/[_\-.:()\[\]*]/g,' ').replace(/\s+/g,' ').trim();}
function dziSplit(l){return l.indexOf('\t')>-1?l.split('\t'):l.split(',');}
function dziEsc(t){var d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML;}
function dziNum(v){var n=parseFloat(String(v==null?'':v).replace(/[^0-9.\-]/g,''));return isNaN(n)?0:n;}
function dziStatus(v){var t=dziNorm(v);if(!t)return '';
  if(/deliver/.test(t))return 'delivered';if(/return|rts|failed/.test(t))return 'returned';if(/cancel/.test(t))return 'cancelled';
  /* "ready to ship" / "to ship" = packed, not shipped yet — check before plain "ship" */
  if(/pack|ready|to ship|toship|process|confirm/.test(t))return 'processing';if(/ship|transit/.test(t))return 'shipped';if(/pend|unpaid|new/.test(t))return 'pending';return '';}
function dziDate(v){var t=String(v||'').trim();if(!t)return '';var m=t.match(/(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/);if(m)return m[1]+'-'+('0'+m[2]).slice(-2)+'-'+('0'+m[3]).slice(-2);
  m=t.match(/(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})/);if(m)return m[3]+'-'+('0'+m[2]).slice(-2)+'-'+('0'+m[1]).slice(-2);
  var d=new Date(t);return isNaN(d)?'':d.toISOString().slice(0,10);}
function dziProduct(name,sku){var n=dziNorm(name),s=dziNorm(sku),i,p;
  for(i=0;i<DZI_PRODUCTS.length;i++){p=DZI_PRODUCTS[i];if(s&&(dziNorm(p.dsku)===s||dziNorm(p.sku)===s))return p;}
  for(i=0;i<DZI_PRODUCTS.length;i++){p=DZI_PRODUCTS[i];if(n&&dziNorm(p.name)===n)return p;}
  for(i=0;i<DZI_PRODUCTS.length;i++){p=DZI_PRODUCTS[i];var pn=dziNorm(p.name);if(n&&pn.length>3&&(n.indexOf(pn)>-1||pn.indexOf(n)>-1))return p;}
  return null;}
function dziParse(){
  var lines=document.getElementById('dziText').value.split(/\r?\n/).filter(function(l){return l.trim()!=='';});
  if(!lines.length)return {rows:[],header:false};
  var head=dziSplit(lines[0]).map(dziNorm),map={},hits=0;
  head.forEach(function(h,i){for(var k in DZI_SYN){if(map[k]===undefined&&DZI_SYN[k].indexOf(h)>-1){map[k]=i;hits++;return;}}});
  var header=hits>=2;
  if(header)lines=lines.slice(1);else map={dz:0,customer:1,phone:2,address:3,product:4,qty:5,price:6};
  var defSt=document.getElementById('dzi_defst').value,defDate=document.getElementById('dzi_defdate').value,grouped={},out=[];
  lines.forEach(function(l){
    var f=dziSplit(l).map(function(x){return String(x).trim().replace(/^"|"$/g,'');});
    function g(k){var i=map[k];return i===undefined?'':(f[i]||'');}
    var item=g('product')||g('sku'),p=dziProduct(g('product'),g('sku'));
    var r={daraz_order_id:g('dz').replace(/\D+/g,''),date:dziDate(g('date'))||defDate,customer:g('customer'),phone:g('phone'),address:g('address'),
      item:item,sku:g('sku'),product_id:p?p.id:0,product_name:p?p.name:'',qty:Math.max(1,parseInt(dziNum(g('qty')))||1),
      price:dziNum(g('price'))||(p?p.price:0),fees:dziNum(g('fees')),status:dziStatus(g('status'))||defSt,
      payment:/cod|cash/.test(dziNorm(g('payment')))||!g('payment')?'cod':'prepaid'};
    if(!r.customer&&!item&&!r.daraz_order_id)return;
    /* Daraz exports one row per piece: same order + same item + same price -> one line, qty added up */
    var key=r.daraz_order_id?(r.daraz_order_id+'|'+(r.product_id||dziNorm(item))+'|'+r.price+'|'+r.status):null;
    if(key&&grouped[key]){grouped[key].qty+=r.qty;grouped[key].fees+=r.fees;return;}
    if(key)grouped[key]=r;out.push(r);
  });
  return {rows:out,header:header};
}
var dziData=null;
function dziPreview(){
  var res=dziParse(),rows=res.rows,box=document.getElementById('dziOut'),skip=document.getElementById('dzi_skip').checked;
  var ex={};DZI_EXISTING.forEach(function(x){ex[x]=1;});
  var nNew=0,nDup=0,nWarn=0,html='';
  if(!rows.length){box.innerHTML='';document.getElementById('dziSum').textContent='';document.getElementById('dziBtn').disabled=true;dziData=null;return;}
  html='<div class="muted" style="font-size:12px">'+(res.header?'✅ Column names recognised from the first line.':'ℹ️ No column names found — using the default order.')+'</div>';
  html+='<table class="dzi-tbl"><tr><th></th><th>Daraz #</th><th>Date</th><th>Customer</th><th>Phone</th><th>Product</th><th>Qty</th><th>Price</th><th>Charges</th><th>Status</th></tr>';
  rows.forEach(function(r){
    var dup=skip&&r.daraz_order_id&&ex[r.daraz_order_id];r._skip=!!dup;
    if(dup)nDup++;else{nNew++;if(!r.product_id)nWarn++;}
    var tag=dup?'<span class="dzi-tag dzi-skip">already in ERP</span>':(r.product_id?'<span class="dzi-tag dzi-ok">✓</span>':'<span class="dzi-tag dzi-warn">no product</span>');
    html+='<tr'+(dup?' class="dup"':'')+'><td>'+tag+'</td><td>'+dziEsc(r.daraz_order_id||'—')+'</td><td>'+dziEsc(r.date)+'</td><td>'+dziEsc(r.customer||'—')+'</td><td>'+dziEsc(r.phone)+'</td><td title="'+dziEsc(r.item)+'">'+dziEsc(r.product_name||r.item||'—')+'</td><td>'+r.qty+'</td><td>'+Math.round(r.price)+'</td><td>'+(r.fees?Math.round(r.fees):'')+'</td><td>'+dziEsc(r.status)+'</td></tr>';
  });
  box.innerHTML=html+'</table>';
  document.getElementById('dziSum').innerHTML='<b>'+nNew+'</b> line(s) to import'+(nDup?' · '+nDup+' skipped':'')+(nWarn?' · <span style="color:#b45309">'+nWarn+' without a matched product (imported, fix later)</span>':'');
  document.getElementById('dziBtn').disabled=!nNew;
  dziData=rows;
}
function dziSubmit(){
  if(!dziData)return false;
  var send=dziData.filter(function(r){return !r._skip;}).map(function(r){return {daraz_order_id:r.daraz_order_id,date:r.date,customer:r.customer,phone:r.phone,address:r.address,
    item:r.item,sku:r.sku,product_id:r.product_id,qty:r.qty,price:r.price,fees:r.fees,status:r.status,payment:r.payment};});
  if(!send.length)return false;
  document.getElementById('dzi_rows').value=JSON.stringify(send);
  document.getElementById('dzi_skipped').value=dziData.length-send.length;
  document.getElementById('dziBtn').disabled=true;document.getElementById('dziBtn').textContent='Importing…';
  return true;
}
function dziOpen(){document.getElementById('dziText').value='';dziPreview();document.getElementById('dziModal').classList.add('open');document.body.classList.add('modal-open');setTimeout(function(){document.getElementById('dziText').focus();},60);}
function dziClose(){document.getElementById('dziModal').classList.remove('open');document.body.classList.remove('modal-open');}
(function(){var m=document.getElementById('dziModal');m.addEventListener('click',function(e){if(e.target===m)dziClose();});
  if(new URLSearchParams(location.search).get('import')==='1')dziOpen();})();
</script>
<!-- book / edit a Daraz order -->
<div class="modal-bg" id="dzoModal" style="z-index:99980"><form class="modal" method="post" style="width:640px;max-width:96vw">
  <div class="modal-head"><span id="dzoTitle">＋ New Daraz order</span><span class="mx" onclick="dzoClose()">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="save_order"><?= $R['keep'] ?>
  <input type="hidden" name="id" id="dzo_id" value="">
  <div class="modal-body">
    <div><label>Daraz order number</label><input name="daraz_order_id" id="dzo_dz" inputmode="numeric" placeholder="e.g. 204188712345678"></div>
    <div><label>Order date *</label><input type="date" name="order_date" id="dzo_date" required></div>
    <div><label>Customer</label><input name="customer" id="dzo_cust"></div>
    <div><label>Phone</label><input name="phone" id="dzo_phone" inputmode="tel" placeholder="98XXXXXXXX"></div>
    <div class="full"><label>Address</label><input name="address" id="dzo_addr"></div>
    <div class="full"><label>Product *</label>
      <select name="product_id" id="dzo_prod" onchange="dzoProd()"><option value="">— pick a product —</option>
        <?php foreach ($allProducts as $p): ?><option value="<?= (int)$p['id'] ?>" data-price="<?= (float)$p['price'] ?>"><?= e($p['name']) ?><?= $p['sku'] ? ' ('.e($p['sku']).')' : '' ?></option><?php endforeach; ?>
      </select>
      <input name="item_name" id="dzo_item" placeholder="…or type the Daraz item name if it isn't a product yet" style="margin-top:6px"></div>
    <div><label>Qty *</label><input type="number" name="qty" id="dzo_qty" min="1" value="1" required></div>
    <div><label>Customer paid (per unit) *</label><input type="number" step="any" min="0" name="sell_price" id="dzo_price" required></div>
    <div><label>Daraz charges (Rs.)</label><input type="number" step="any" min="0" name="fees" id="dzo_fees" placeholder="0"></div>
    <div><label>Payment</label><select name="payment_type" id="dzo_pay"><option value="cod">COD</option><option value="prepaid">Prepaid</option></select></div>
    <div><label>Status</label><select name="status" id="dzo_status"><?php foreach ($STATUSES as $st): ?><option value="<?= $st ?>"><?= ucfirst($st) ?></option><?php endforeach; ?></select></div>
    <div><label>Remarks</label><input name="remarks" id="dzo_rem"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" onclick="dzoClose()">Cancel</button><button class="btn btn-primary">💾 Save order</button></div>
</form></div>
<script>
function dzoSet(id,v){var e=document.getElementById(id);if(e)e.value=(v===null||v===undefined)?'':v;}
function dzoOpen(btn){
  var r=btn?JSON.parse(btn.getAttribute('data-rec')):null;
  document.getElementById('dzoTitle').textContent=r?('✏ Edit Daraz order '+r.code):'＋ New Daraz order';
  dzoSet('dzo_id',r?r.id:''); dzoSet('dzo_dz',r?r.daraz_order_id:''); dzoSet('dzo_date',r?r.order_date:new Date().toISOString().slice(0,10));
  dzoSet('dzo_cust',r?r.customer:''); dzoSet('dzo_phone',r?r.phone:''); dzoSet('dzo_addr',r?r.address:'');
  dzoSet('dzo_prod',r?r.product_id:''); dzoSet('dzo_item',r?r.item_name:''); dzoSet('dzo_qty',r?r.qty:1);
  dzoSet('dzo_price',r?r.sell_price:''); dzoSet('dzo_fees',r&&r.fees?r.fees:''); dzoSet('dzo_pay',r?r.payment_type:'cod');
  dzoSet('dzo_status',r?r.status:'pending'); dzoSet('dzo_rem',r?r.remarks:'');
  document.getElementById('dzoModal').classList.add('open');document.body.classList.add('modal-open');
  setTimeout(function(){document.getElementById(r?'dzo_price':'dzo_dz').focus();},60);
}
function dzoClose(){document.getElementById('dzoModal').classList.remove('open');document.body.classList.remove('modal-open');}
/* picking a product pre-fills its usual price when no price is entered yet */
function dzoProd(){var s=document.getElementById('dzo_prod'),o=s.options[s.selectedIndex],p=document.getElementById('dzo_price');
  if(o&&o.value&&!(parseFloat(p.value)>0))p.value=o.getAttribute('data-price')||'';}
(function(){var m=document.getElementById('dzoModal');m.addEventListener('click',function(e){if(e.target===m)dzoClose();});
  if(new URLSearchParams(location.search).get('new')==='1'&&<?= $perm['add'] ? 'true' : 'false' ?>)dzoOpen(null);})();
</script>
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
