<?php
/* =====================================================================
   sales.php  —  Google-Sheet style order management (single file)
   - Renders the spreadsheet page normally
   - Answers its OWN AJAX calls (?ajax=1) so NO /api/ folder is needed
   - All operations use POST (works on every shared host)
   ===================================================================== */
require_once __DIR__.'/functions.php';
require_login(); require_page_access();
repair_zero_cost_profit();   /* auto-fix orders saved with Rs.0 cost so profit is honest everywhere */
ensure_order_group();
try { q("ALTER TABLE orders ADD COLUMN IF NOT EXISTS sales_person_id INT NULL AFTER code"); } catch (Exception $e) {}
try { q("ALTER TABLE orders ADD INDEX idx_sales_person_id (sales_person_id)"); } catch (Exception $e) {}
ensure_company_salesperson();

/* ---------------------------------------------------------------------
   AJAX ENDPOINT  (same file)   sales.php?ajax=1
   --------------------------------------------------------------------- */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf(), $token)) { echo json_encode(['ok'=>false,'error'=>'Session expired — please reload the page.']); exit; }

    $op = $_POST['op'] ?? '';

    /* build a clean field set from POST, looking up cost + defaults */
    $build = function() {
        $pid  = (int)($_POST['product_id'] ?? 0) ?: null;
        $cost = $pid ? fifo_current_cost($pid) : (float)($_POST['cost_price'] ?? 0);   /* FIFO: oldest open batch, not latest rate */

        $zone     = strtolower(trim($_POST['zone'] ?? 'inside')) === 'outside' ? 'outside' : 'inside';
        /* delivery charge is YOUR courier cost, entered manually per order (customer gets free delivery) */
        $delivery = (float)($_POST['delivery_charge'] ?? 0);

        $status     = $_POST['status'] ?? 'pending';
        $ps_in      = strtolower(trim($_POST['payment_status'] ?? ''));
        $manualPaid = in_array($ps_in, ['received','paid'], true);
        $pay_status = ($status === 'delivered' || $manualPaid) ? 'paid' : 'unpaid';
        $courierCancelDef = 100.0;
        $cidPost = (int)($_POST['courier_id'] ?? 0);
        if ($cidPost) { $cc = val("SELECT COALESCE(cancel_charge,100) FROM couriers WHERE id=?", [$cidPost]); if ($cc !== null && $cc !== false) $courierCancelDef = (float)$cc; }
        /* cancel charge varies by location — never assume a default; use only what was entered */
        $cancel     = in_array($status, ['cancelled','returned'], true)
                      ? ((isset($_POST['cancel_charge']) && $_POST['cancel_charge'] !== '' && (float)$_POST['cancel_charge'] > 0)
                          ? (float)$_POST['cancel_charge'] : 0.0)
                      : 0.0;

        return [
            'order_date'      => ($_POST['order_date'] ?? '') ?: date('Y-m-d'),
            'customer'        => trim($_POST['customer']  ?? ''),
            'phone'           => trim($_POST['phone']     ?? ''),
            'address'         => trim($_POST['address']   ?? ''),
            'sales_person_id' => (int)($_POST['sales_person_id'] ?? 0) ?: null,
            'product_id'      => $pid,
            'qty'             => max(1, (int)($_POST['qty'] ?? 1)),
            'sell_price'      => (float)($_POST['sell_price'] ?? 0),
            'cost_price'      => $cost,
            'delivery_charge' => $delivery,
            'cancel_charge'   => $cancel,
            'zone'            => $zone,
            'payment_type'    => (in_array(strtolower(trim($_POST['payment_type'] ?? 'cash')), ['online','prepaid','bank_transfer','wallet'], true) ? 'prepaid' : 'cod'),
            'payment_status'  => $pay_status,
            'status'          => $status,
            'courier_id'      => (int)($_POST['courier_id'] ?? 0) ?: null,
            'remarks'         => trim($_POST['remarks'] ?? ''),
            'order_group'     => (($g=trim($_POST['order_group'] ?? ''))!=='' ? $g : null),
        ];
    };

    $fullRow = function($id) {
        $r = row("SELECT o.*, p.name AS product_name, c.name AS courier_name, sp.name AS sales_person_name
                  FROM orders o
                  LEFT JOIN products p ON p.id=o.product_id
                  LEFT JOIN couriers c ON c.id=o.courier_id
                  LEFT JOIN employees sp ON sp.id=o.sales_person_id
                  WHERE o.id=?", [$id]);
        if ($r) { $r['revenue']=order_revenue($r); $r['profit']=order_profit($r); }
        return $r ?: null;
    };

    try {
        if ($op === 'create_multi') {
            /* one customer, several products -> one row per product, linked by order_group.
               Delivery charge is applied ONCE (on the first line) so COD/parcel isn't double-charged. */
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (!is_array($items) || !count($items)) { echo json_encode(['ok'=>false,'error'=>'No products to add.']); exit; }
            $grp = 'G'.date('ymdHis').substr((string)mt_rand(100,999),0,3);
            // shared customer fields
            $cust=trim($_POST['customer']??''); $phone=trim($_POST['phone']??''); $addr=trim($_POST['address']??'');
            $spid=(int)($_POST['sales_person_id']??0)?:null;
            $zone=strtolower(trim($_POST['zone']??'inside'))==='outside'?'outside':'inside';
            $status=$_POST['status']??'pending';
            $ps_in=strtolower(trim($_POST['payment_status']??''));
            $pay_status=($status==='delivered'||in_array($ps_in,['received','paid'],true))?'paid':'unpaid';
            $ptype=in_array(strtolower(trim($_POST['payment_type']??'cash')),['online','prepaid','bank_transfer','wallet'],true)?'prepaid':'cod';
            $cid=(int)($_POST['courier_id']??0)?:null;
            $odate=($_POST['order_date']??'')?:date('Y-m-d');
            $totalDelivery=(float)($_POST['delivery_charge']??0);
            $remarks=trim($_POST['remarks']??'');

            // code numbering (reuse smallest-unused logic once, then increment)
            $pref=setting('order_prefix','ORD-'); $used=[];
            foreach (rows("SELECT code FROM orders") as $cr){ $c=(string)$cr['code']; if($pref!==''&&strpos($c,$pref)===0)$c=substr($c,strlen($pref)); $n=(int)preg_replace('/\\D+/','',$c); if($n>0)$used[$n]=1; }
            $nextNum=function() use (&$used){ $n=1; while(isset($used[$n]))$n++; $used[$n]=1; return $n; };

            $created=[]; $first=true;
            foreach ($items as $it) {
                $pid=(int)($it['product_id']??0)?:null;
                if(!$pid) continue;
                $cost=fifo_current_cost($pid);
                $code=$pref.str_pad($nextNum(),4,'0',STR_PAD_LEFT);
                $f=[
                  'code'=>$code,'order_date'=>$odate,'customer'=>$cust,'phone'=>$phone,'address'=>$addr,
                  'sales_person_id'=>$spid,
                  'product_id'=>$pid,'qty'=>max(1,(int)($it['qty']??1)),'sell_price'=>(float)($it['sell_price']??0),
                  'cost_price'=>$cost,'delivery_charge'=>($first?$totalDelivery:0.0),'cancel_charge'=>0.0,
                  'zone'=>$zone,'payment_type'=>$ptype,'payment_status'=>$pay_status,'status'=>$status,
                  'courier_id'=>$cid,'remarks'=>$remarks,'order_group'=>$grp,
                ];
                $cols=implode('`,`',array_keys($f)); $ph=implode(',',array_fill(0,count($f),'?'));
                q("INSERT INTO orders(`$cols`) VALUES($ph)", array_values($f));
                $id=(int)db()->lastInsertId();
                stock_on_status_change($pid,$f['qty'],'',$status,$id);
                $created[]=$fullRow($id);
                $first=false;
            }
            if(!count($created)){ echo json_encode(['ok'=>false,'error'=>'None of the products were valid.']); exit; }
            log_activity("Created multi-product order ($grp): ".count($created)." items for ".($cust?:'customer'),'Sales');
            notify("New multi-item order for ".($cust?:'a customer')." (".count($created)." products)", 'order','sales.php',0);
            echo json_encode(['ok'=>true,'orders'=>$created,'group'=>$grp]); exit;
        }
        if ($op === 'add' || $op === 'create') {
            $f = $build();
            /* next code = smallest unused number, so deletes don't leave holes
               (was MAX(id)+1 — but MySQL never reuses deleted ids, causing 0014 → 0025 jumps) */
            $pref = setting('order_prefix','ORD-');
            $used = [];
            foreach (rows("SELECT code FROM orders") as $cr) {
                $c = (string)$cr['code'];
                if ($pref !== '' && strpos($c,$pref)===0) $c = substr($c,strlen($pref));
                $n = (int)preg_replace('/\D+/','',$c);
                if ($n>0) $used[$n]=1;
            }
            $next = 1; while (isset($used[$next])) $next++;
            $code = $pref.str_pad($next, 4, '0', STR_PAD_LEFT);
            $f = array_merge(['code'=>$code], $f);
            $cols = implode('`,`', array_keys($f));
            $ph   = implode(',', array_fill(0, count($f), '?'));
            q("INSERT INTO orders(`$cols`) VALUES($ph)", array_values($f));
            $id = (int)db()->lastInsertId();
            stock_on_status_change($f['product_id'] ?? 0, $f['qty'] ?? 0, '', $f['status'] ?? '', $id);
            log_activity("Created order $code",'Sales');
            notify("New order $code created".($f['customer']?" for {$f['customer']}":''), 'order', 'sales.php', 0);
            echo json_encode(['ok'=>true,'order'=>$fullRow($id)]); exit;
        }

        if ($op === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { echo json_encode(['ok'=>false,'error'=>'Missing id']); exit; }
            $prev = row("SELECT code,status FROM orders WHERE id=?", [$id]);
            $f = $build();
            $set = implode(',', array_map(fn($k)=>"`$k`=?", array_keys($f)));
            $vals = array_values($f); $vals[] = $id;
            q("UPDATE orders SET $set WHERE id=?", $vals);
            stock_on_status_change($f['product_id'] ?? 0, $f['qty'] ?? 0, $prev['status'] ?? '', $f['status'] ?? '', $id);
            log_activity("Updated order #$id",'Sales');
            if ($prev && ($f['status'] ?? '') !== $prev['status']) {
              $code = $prev['code'] ?: ('#'.$id);
              if ($f['status']==='delivered') notify("Order $code delivered ✅", 'delivered', 'sales.php', 0);
              else notify("Order $code → ".$f['status'], 'status', 'sales.php', 0);
            }
            echo json_encode(['ok'=>true,'order'=>$fullRow($id)]); exit;
        }

        if ($op === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { echo json_encode(['ok'=>false,'error'=>'Missing id']); exit; }
            $ord = row("SELECT product_id, qty, status FROM orders WHERE id=?", [$id]);
            if ($ord && $ord['status']==='delivered') fifo_restore($id, (int)$ord['product_id']); // put units back into their batches
            q("DELETE FROM orders WHERE id=?", [$id]);
            log_activity("Deleted order #$id",'Sales');
            echo json_encode(['ok'=>true,'deleted'=>$id]); exit;
        }

        if ($op === 'assign_unassigned_to_company') {
            /* manual cleanup button on the Sales Team dashboard — bulk-tags every
               order with no sales person as "Luprah" (company sales), optionally
               scoped to a date range so it matches whatever the banner is showing */
            ensure_company_salesperson();
            $spid = (int)val("SELECT id FROM employees WHERE is_company=1 LIMIT 1");
            if (!$spid) { echo json_encode(['ok'=>false,'error'=>'Company sales person not found']); exit; }
            $from = trim($_POST['from'] ?? ''); $to = trim($_POST['to'] ?? '');
            $ranged = ($from !== '' && $to !== '');
            $where  = "sales_person_id IS NULL".($ranged ? " AND order_date BETWEEN ? AND ?" : "");
            $params = $ranged ? [$from,$to] : [];
            $n = (int)val("SELECT COUNT(*) FROM orders WHERE $where", $params);
            if ($n > 0) q("UPDATE orders SET sales_person_id=? WHERE $where", array_merge([$spid], $params));
            log_activity("Assigned $n unassigned order(s) to Luprah (company sales)",'Sales');
            echo json_encode(['ok'=>true,'updated'=>$n]); exit;
        }

        echo json_encode(['ok'=>false,'error'=>'Unknown operation']); exit;

    } catch (Exception $ex) {
        echo json_encode(['ok'=>false,'error'=>$ex->getMessage()]); exit;
    }
}

/* ---------------------------------------------------------------------
   NORMAL PAGE RENDER
   --------------------------------------------------------------------- */
$PAGE_TITLE = 'Sales';
$products = rows("SELECT id,name,sku,price,cost,stock,low_stock FROM products ORDER BY name");
foreach ($products as &$pp) { $pp['cost'] = fifo_current_cost((int)$pp['id']); } unset($pp);
try { q("ALTER TABLE couriers ADD COLUMN IF NOT EXISTS default_delivery DECIMAL(10,2) DEFAULT 0"); q("ALTER TABLE couriers ADD COLUMN IF NOT EXISTS cancel_charge DECIMAL(10,2) DEFAULT 100"); } catch (Exception $e) {}
$couriers = rows("SELECT id,name,COALESCE(default_delivery,0) AS default_delivery,COALESCE(cancel_charge,100) AS cancel_charge FROM couriers WHERE status='active' ORDER BY name");
$salespersons = rows("SELECT id,name FROM employees WHERE department='Sales' AND status='active' ORDER BY name");
$orders   = rows("
    SELECT o.*, p.name AS product_name, c.name AS courier_name, sp.name AS sales_person_name
    FROM   orders o
    LEFT JOIN products p ON p.id = o.product_id
    LEFT JOIN couriers c ON c.id = o.courier_id
    LEFT JOIN employees sp ON sp.id = o.sales_person_id
    ORDER  BY o.order_date ASC,
             COALESCE((SELECT MIN(g.id) FROM orders g WHERE g.order_group=o.order_group AND o.order_group IS NOT NULL AND o.order_group<>''), o.id) ASC,
             o.id ASC");
foreach ($orders as &$o) { $o['revenue']=order_revenue($o); $o['profit']=order_profit($o); }
unset($o);

$inside  = (int)setting('delivery_inside',  80);
$outside = (int)setting('delivery_outside', 150);

$sRev=0; $sDel=0; $sProf=0; $sUnits=0;
$custStat=[]; $custInfo=[]; $delivFreq=[];
foreach ($orders as $o) {
    $sRev += (float)$o['revenue']; $sProf += (float)$o['profit'];
    if ($o['status']==='delivered') { $sDel += (float)$o['delivery_charge']; $sUnits += (int)$o['qty']; }
    /* per-phone history for smart warnings + autofill (orders are date ASC, so last write = latest) */
    $ph = substr(preg_replace('/\D+/','',(string)$o['phone']), -10);
    if (strlen($ph) >= 7) {
        if (!isset($custStat[$ph])) $custStat[$ph] = ['o'=>0,'r'=>0];
        $custStat[$ph]['o']++;
        if (in_array($o['status'], ['returned','cancelled'], true)) $custStat[$ph]['r']++;
        $custInfo[$ph] = ['name'=>(string)$o['customer'], 'address'=>(string)$o['address']];
    }
    $dc = (float)$o['delivery_charge'];
    if ($dc > 0) $delivFreq[(string)round($dc)] = ($delivFreq[(string)round($dc)] ?? 0) + 1;
}
arsort($delivFreq);
$commonDeliv = array_slice(array_keys($delivFreq), 0, 8);
$agingDays = max(1, (int)setting('sales_aging_days', 3));

require __DIR__.'/includes/header.php';
?>
<link  rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/handsontable/6.2.2/handsontable.full.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/handsontable/6.2.2/handsontable.full.min.js"></script>

<style>
.htCore td{font-size:13px}
/* ===== Signal design — dark header bar ===== */
.htCore th{font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#aeb8d4;background:#1f2740;border-color:#2a3350 !important}
.htCore th .colHeader{color:#aeb8d4}
.ht_clone_top th,.ht_clone_left th,.ht_clone_corner th{background:#1f2740;color:#aeb8d4}
.htCore thead th .relative{padding-top:2px;padding-bottom:2px}
.handsontable .columnSorting.sortAction:hover{color:#fff}
.handsontable th .colHeader.columnSorting::before{opacity:.7}
/* solid status pills (Signal) */
.ht-status{display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:800;color:#fff}
.ht-status.delivered{background:#0f9d63}
.ht-status.shipped{background:#e0851b}
.ht-status.cancelled,.ht-status.returned{background:#dc4437}
.ht-status.pending,.ht-status.processing{background:#eef0f4;color:#64748b}
.save-ind{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;padding:5px 12px;border-radius:8px}
.save-ind.saving{background:#fef3c7;color:#b45309}
.save-ind.saved{background:#dcfce7;color:#16a34a}
.save-ind.error{background:#fee2e2;color:#dc2626}
.save-ind.idle{color:var(--muted)}
.sheet-toolbar{display:flex;align-items:center;gap:10px;padding:12px 16px;flex-wrap:wrap;border-bottom:1px solid var(--border)}
.rowdel{border:none;background:none;color:#dc2626;cursor:pointer;font-size:14px;opacity:.55;border-radius:5px;padding:1px 5px}
.rowdel:hover{opacity:1;background:#fee2e2}
#hotBox{width:100%}
.ht_master .wtHolder{overflow:auto}
</style>

<div class="page-head">
  <div><h1>Sales</h1><p>Works like Google Sheets — type in the empty bottom row to add orders, edit any cell, sort &amp; filter by clicking column headers</p></div>
  <div style="display:flex;gap:10px">
    <div class="colpick">
      <button class="btn" id="colBtn">⚙ Columns</button>
      <div class="colpanel" id="colPanel"></div>
    </div>
    <button class="btn" id="importBtn">📋 Paste Import</button>
    <button class="btn" id="assignLuprahBtn" title="Bulk-tag every order with no sales person as Luprah (company sales)">🏢 Unassigned → Luprah</button>
    <a class="btn" href="labels.php" target="_blank">🏷 Print Labels</a>
    <button class="btn" id="csvBtn">⬇ Export CSV</button>
    <button class="btn" id="addRowBtn">＋ Add Row</button>
    <button class="btn btn-primary" id="addFormBtn">📝 New Order</button>
    <button class="btn" onclick="try{hot.undo()}catch(e){}" title="Undo (Ctrl+Z)">↩️ Undo</button>
    <button class="btn" onclick="try{hot.redo()}catch(e){}" title="Redo (Ctrl+Y)">↪️ Redo</button>
  </div>
</div>

<div class="stat-strip">
  <div class="card stat"><div class="l">Total Orders</div><div class="v num" id="stTotal"><?= count($orders) ?></div></div>
  <div class="card stat"><div class="l">Units Delivered</div><div class="v num" id="stUnits" style="color:var(--green)"><?= number_format($sUnits) ?></div></div>
  <div class="card stat"><div class="l">Delivered Revenue</div><div class="v num" id="stRev" style="color:var(--green)"><?= money($sRev) ?></div></div>
  <div class="card stat"><div class="l">Delivery Charges</div><div class="v num muted" id="stDel"><?= money($sDel) ?></div></div>
  <div class="card stat"><div class="l">Gross Profit</div><div class="v num" id="stProfit" style="color:var(--brand)"><?= money($sProf) ?></div></div>
</div>

<div class="card" style="overflow:hidden">
  <div class="sheet-toolbar">
    <input id="sheetSearch" class="search-in" style="max-width:240px" placeholder="🔍 Search orders…" value="<?= e($_GET['q'] ?? '') ?>">
    <button class="btn btn-sm" onclick="openPhoneSearch()" title="Paste many phone numbers to find & select them all at once">📞 Multi-phone</button>
    <button class="btn btn-sm" id="sortDirBtn" onclick="toggleSortDir()" title="Flip between newest-on-top and oldest-on-top">⬇ Newest first</button>
    <button class="btn btn-sm" onclick="hot.scrollViewportTo(0,0)" title="Jump to top">⤒</button>
    <button class="btn btn-sm" onclick="hot.scrollViewportTo(Math.max(0,VIEW.length-1),0)" title="Jump to end">⤓</button>
    <span id="saveStatus" class="save-ind idle">● All saved</span>
    <span class="muted" id="rowCount" style="margin-left:auto;font-size:13px"><?= count($orders) ?> rows</span>
  </div>
  <div class="chips" id="chipBar">
    <button class="chip on" id="chip-all" onclick="applyFilter('all')">All <b>0</b></button>
    <button class="chip" id="chip-pending" onclick="applyFilter('pending')">Pending <b>0</b></button>
    <button class="chip" id="chip-processing" onclick="applyFilter('processing')">Processing <b>0</b></button>
    <button class="chip" id="chip-shipped" onclick="applyFilter('shipped')">Shipped <b>0</b></button>
    <button class="chip" id="chip-delivered" onclick="applyFilter('delivered')">Delivered <b>0</b></button>
    <button class="chip" id="chip-today" onclick="applyFilter('today')">Today <b>0</b></button>
    <button class="chip chip-warn" id="chip-aging" onclick="applyFilter('aging')">⏳ Aging <b>0</b></button>
    <button class="chip" id="chip-cancelled" onclick="applyFilter('cancelled')">Cancelled <b>0</b></button>
    <button class="chip" id="chip-returned" onclick="applyFilter('returned')">Returned <b>0</b></button>
    <span style="width:1px;height:22px;background:var(--border);margin:0 3px;align-self:center"></span>
    <button class="chip" id="advBtn" onclick="toggleAdv()">⚙ Advanced <span id="advCount" class="advc" style="display:none">0</span></button>
  </div>
  <div class="advpanel" id="advPanel" style="display:none">
    <div class="advgrid">
      <div class="advf"><label>Product</label><select id="af_prod"><option value="">Any product</option></select></div>
      <div class="advf"><label>Zone</label><select id="af_zone"><option value="">Any</option><option>Inside</option><option>Outside</option></select></div>
      <div class="advf"><label>Payment</label><select id="af_pay"><option value="">Any</option></select></div>
      <div class="advf"><label>Courier</label><select id="af_cour"><option value="">Any</option></select></div>
      <div class="advf"><label>Sales Person</label><select id="af_sp"><option value="">Any</option><option value="__unassigned__">— Unassigned —</option></select></div>
      <div class="advf advf-daterange"><label>Date from → to</label><div class="advrange"><input type="date" id="af_from"><span>→</span><input type="date" id="af_to"></div></div>
      <div class="advf"><label>Amount Rs. (revenue)</label><div class="advrange"><input type="number" id="af_amin" placeholder="min"><span>–</span><input type="number" id="af_amax" placeholder="max"></div></div>
      <div class="advf"><label>Quantity</label><div class="advrange"><input type="number" id="af_qmin" placeholder="min"><span>–</span><input type="number" id="af_qmax" placeholder="max"></div></div>
      <div class="advf"><label>Keyword (name/phone/address)</label><input type="text" id="af_kw" placeholder="type…"></div>
    </div>
    <div class="advfoot">
      <span class="muted" id="advSummary" style="margin-right:auto"></span>
      <button class="btn btn-sm" onclick="resetAdv()">↺ Reset</button>
      <button class="btn btn-sm" onclick="exportFiltered()">⬇ Export filtered</button>
      <button class="btn btn-sm btn-primary" onclick="applyAdv()">Apply</button>
    </div>
  </div>
  <div class="advactive" id="advActive" style="display:none"></div>
  <div class="bulkbar" id="bulkBar" style="display:none">
    <b><span id="bulkN">0</span> selected</b>
    <button class="btn btn-sm" onclick="markSel('shipped')">Mark Shipped</button>
    <button class="btn btn-sm" onclick="markSel('delivered')">Mark Delivered</button>
    <button class="btn btn-sm" onclick="labelsSel()">🏷 Labels</button>
    <button class="btn btn-sm" onclick="waBulk()">💬 WhatsApp</button>
    <button class="btn btn-sm" onclick="clearSel()">✕ Clear</button>
  </div>
  <div class="mp-bg" id="mpBg" style="display:none">
    <div class="mp-sheet">
      <div class="mp-head"><b>📞 Find orders by many phone numbers</b><button class="mp-x" onclick="closePhoneSearch()">✕</button></div>
      <div class="mp-body">
        <p class="muted" style="font-size:12px;margin-bottom:8px">Paste phone numbers — one per line, or separated by commas/spaces. We'll find every matching order and tick it, so you can Mark Delivered in one go.</p>
        <textarea id="mpInput" rows="8" placeholder="9842837242&#10;9845636174&#10;9800000001, 9800000002"></textarea>
        <div id="mpResult" class="mp-result" style="display:none"></div>
      </div>
      <div class="mp-foot">
        <span class="muted" id="mpSummary" style="margin-right:auto"></span>
        <button class="btn btn-sm" onclick="closePhoneSearch()">Cancel</button>
        <button class="btn btn-sm btn-primary" id="mpFindBtn" onclick="runPhoneSearch()">Find &amp; select</button>
      </div>
    </div>
  </div>
  <div id="hotBox"></div>
  <div class="sheet-foot">
    <span class="sf-hint"><span id="rowCount2">0</span> rows · <span id="saveStatus2" class="save-ind idle">● All saved</span></span>
    <button class="btn" id="addRowBtn2">＋ Add Row</button>
    <button class="btn btn-primary" id="addFormBtn2">📝 New Order</button>
  </div>
  <div id="hotFail" style="display:none;padding:30px;text-align:center;color:var(--red)">
    Could not load the spreadsheet library. Check your internet connection and reload.
  </div>
</div>

<!-- delete confirm -->
<div class="modal-bg" id="delModal" style="z-index:99990">
  <div class="modal" style="width:380px">
    <div class="modal-head"><span>Delete Order</span><span class="mx" onclick="closeDel()">✕</span></div>
    <div style="padding:20px 22px">Delete order <b id="delCode"></b>? This can't be undone.</div>
    <div class="modal-foot">
      <button class="btn" onclick="closeDel()">Cancel</button>
      <button class="btn btn-primary" style="background:var(--red);border-color:var(--red)" onclick="doDelete()">Delete</button>
    </div>
  </div>
</div>


<!-- ===== New Order form ===== -->
<div class="modal-bg" id="waModal" style="z-index:99991">
  <div class="modal" style="width:520px;max-width:94vw">
    <div class="modal-head"><span>💬 WhatsApp Confirmations</span><span class="mx" onclick="closeWa()">✕</span></div>
    <div style="padding:14px 20px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap">
        <b><span id="waDone">0</span>/<span id="waTot">0</span> sent</b>
        <button class="btn btn-primary btn-sm" onclick="waNext()">▶ Send Next</button>
      </div>
      <div id="waList" style="max-height:330px;overflow:auto;display:flex;flex-direction:column;gap:6px"></div>
      <p class="muted" style="font-size:11px;margin-top:10px">Each message opens in WhatsApp with the text ready — press send there. Includes tracking link for NCM-booked orders (link format in Settings). WhatsApp doesn't allow fully automatic sending without the paid Business API.</p>
    </div>
  </div>
</div>
<div class="modal-bg" id="orderModal" style="z-index:99990">
  <div class="modal" style="width:640px;max-width:94vw">
    <div class="modal-head"><span>📝 New Order</span>
      <span style="display:flex;gap:8px;align-items:center">
        <button class="btn btn-sm mob-save" onclick="saveOrderForm(true)" title="Save & New">💾＋</button>
        <button class="btn btn-sm btn-primary mob-save" onclick="saveOrderForm()" title="Save Order">💾 Save</button>
        <span class="mx" onclick="closeOrderForm()" style="font-size:20px;padding:4px 8px">✕</span>
      </span></div>
    <div style="padding:18px 22px;max-height:70vh;overflow-y:auto">
      <div class="fgrid">
        <div><label>Order Date</label><input type="date" id="f_date"></div>
        <div><label>Customer Name *</label><input id="f_customer" placeholder="e.g. Ramesh Shrestha"></div>
        <div><label>Phone</label><input id="f_phone" placeholder="98XXXXXXXX"><span id="fPhoneHint" style="font-size:11px;font-weight:700"></span></div>
        <div class="full"><label>Address</label><input id="f_address" placeholder="City / Tole, landmark…"></div>
        <div><label>Sales Person</label><select id="f_salesperson"><option value="">—</option><?php foreach($salespersons as $sp): ?><option value="<?= (int)$sp['id'] ?>"><?= e($sp['name']) ?></option><?php endforeach; ?></select></div>
        <div class="full"><label>Products *</label>
          <div id="prodLines"></div>
          <button type="button" class="btn btn-sm" style="margin-top:6px" onclick="addProdLine()">＋ Add another product</button>
          <datalist id="fprodlist"></datalist>
        </div>
        <div><label>Delivery Zone</label><select id="f_zone"><option value="inside">Inside Valley</option><option value="outside">Outside Valley</option></select></div>
        <div><label>Delivery Charge</label><input type="number" id="f_delivery" step="any"></div>
        <div><label>Payment Type</label><select id="f_pay"><option>COD</option><option>Prepaid</option></select></div>
        <div><label>Status</label><select id="f_status"><option>pending</option><option>processing</option><option>shipped</option><option>delivered</option></select></div>
        <div><label>Courier</label><select id="f_courier"><option value="">—</option><?php foreach($couriers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="full"><label>Remarks</label><input id="f_remarks" placeholder="optional note…"></div>
      </div>
      <div class="ftotal" id="fTotal">Total: Rs. 0</div>
    </div>
    <div class="modal-foot">
      <button class="btn" onclick="closeOrderForm()">Cancel</button>
      <span id="fSavedNote" style="display:none;color:var(--green);font-weight:800;font-size:12.5px;align-self:center">✔ Saved</span>
      <button class="btn" id="fSaveNewBtn" onclick="saveOrderForm(true)">💾 Save &amp; New</button>
      <button class="btn btn-primary" id="fSaveBtn" onclick="saveOrderForm()">💾 Save Order</button>
    </div>
  </div>
</div>

<!-- ===== paste import ===== -->
<div class="modal-bg" id="importModal" style="z-index:99990">
  <div class="modal" style="width:620px;max-width:94vw">
    <div class="modal-head"><span>📋 Paste Import</span><span class="mx" onclick="closeImport()">✕</span></div>
    <div style="padding:16px 22px">
      <p class="muted" style="font-size:12px;margin:0 0 8px">One order per line, columns separated by <b>Tab</b> (paste from Excel) or comma.<br>
      <b>Any column order works</b> — just make the first line a header, e.g. <code>Product, Customer, Phone, Address, Qty, Price</code>.<br>
      No header? The default order is used: <code>Customer, Phone, Address, Product, Qty, Price, Delivery</code>.<br>
      <span style="font-size:11px">Understood names: customer/name · phone/mobile/contact · address/city/location · sales person/salesperson/agent/sold by · product/item · qty/quantity/pcs · price/rate/amount · delivery/shipping/charge</span></p>
      <textarea id="impText" rows="8" style="width:100%;font-family:monospace;font-size:12px" placeholder="Product, Customer, Phone, Address, Qty, Price&#10;Heel Guard Plus, Ramesh Shrestha, 9841234567, Banepa-8, 1, 1000&#10;Smart Watch, Sunita Rai, 9812345678, Pokhara-9, 1, 4500" oninput="impPreview()"></textarea>
      <div id="impMap" class="muted" style="font-size:11.5px;margin-top:8px;line-height:1.7"></div>
      <div id="impStatus" style="font-size:12.5px;font-weight:700;margin-top:8px;color:var(--brand)"></div>
    </div>
    <div class="modal-foot">
      <button class="btn" onclick="closeImport()">Cancel</button>
      <button class="btn btn-primary" id="impRunBtn" onclick="runImport()">⬆ Import Orders</button>
    </div>
  </div>
</div>

<script>
var CSRF='<?= csrf() ?>', DEL_IN=<?= $inside ?>, DEL_OUT=<?= $outside ?>, CURRENCY='<?= CURRENCY ?>';
var DEF_STATUS=<?= json_encode(setting('default_order_status','pending')) ?>, DEF_PAY=<?= json_encode(setting('default_payment_type','COD')) ?>, DEF_ZONE=<?= json_encode(strtolower(setting('default_zone','inside'))) ?>;
var PRODUCTS=<?= json_encode(array_values($products)) ?>;
var CUSTSTAT=<?= json_encode($custStat) ?>, CUSTINFO=<?= json_encode($custInfo) ?>;
var COMMON_DELIV=<?= json_encode($commonDeliv) ?>, AGING_DAYS=<?= (int)$agingDays ?>;
var TODAY='<?= date('Y-m-d') ?>';
var COURIERS=<?= json_encode(array_values($couriers)) ?>;
var SALESPERSONS=<?= json_encode(array_values($salespersons)) ?>;
var ORDERS  =<?= json_encode(array_values($orders)) ?>;

var prodNames = PRODUCTS.map(function(p){return p.name;});
var courNames = [''].concat(COURIERS.map(function(c){return c.name;}));
var spNames   = [''].concat(SALESPERSONS.map(function(s){return s.name;}));
var STATUSES=['pending','processing','shipped','delivered','cancelled','returned'];
var PAY_TYPES=['COD','Prepaid'];
window.prodNames=prodNames; window.courNames=courNames.filter(Boolean); window.spNames=spNames.filter(Boolean); window.PAY_TYPES=PAY_TYPES;
var TRK_TPL=<?= json_encode(setting('wa_tracking_template','https://nepalcanmove.com/tracking?id={id}')) ?>;
var DEF_PAY_UI=(String(DEF_PAY||'').toLowerCase()==='prepaid')?'Prepaid':'COD';   /* COD is the primary default */
var PAY_STATS=['unpaid','paid','partial','refunded'];
var PAY_RCV=['Paid','Unpaid'];
var ZONES=['Inside','Outside'];

function pnorm(x){return String(x==null?'':x).trim().toLowerCase().replace(/\s+/g,' ');}
var PRODMAP={}, COURMAP={}, SPMAP={};
(function(){ try{ (PRODUCTS||[]).forEach(function(p){PRODMAP[String(p.name).toLowerCase()]=p;}); }catch(e){}
             try{ (COURIERS||[]).forEach(function(c){COURMAP[String(c.name).toLowerCase()]=c;}); }catch(e){}
             try{ (SALESPERSONS||[]).forEach(function(s){SPMAP[String(s.name).toLowerCase()]=s;}); }catch(e){} })();
function prodByName(n){
  if(n==null||String(n).trim()==='')return null;
  var fast=PRODMAP[String(n).toLowerCase()];                                  /* 0. O(1) exact (renderers hit this) */
  if(fast)return fast;
  var t=pnorm(n);
  var p=PRODUCTS.find(function(x){return x.name===n;});                       /* 1. exact */
  if(p)return p;
  p=PRODUCTS.find(function(x){return pnorm(x.name)===t;});                    /* 2. case/space-insensitive */
  if(p)return p;
  p=PRODUCTS.find(function(x){return x.sku&&pnorm(x.sku)===t;});              /* 3. by SKU */
  if(p)return p;
  p=PRODUCTS.find(function(x){var a=pnorm(x.name);return a&&(a.indexOf(t)===0||t.indexOf(a)===0);});  /* 4. prefix */
  if(p)return p;
  var cands=PRODUCTS.filter(function(x){var a=pnorm(x.name);return a&&(a.indexOf(t)>-1||t.indexOf(a)>-1);}); /* 5. contains */
  return cands.length===1?cands[0]:null;   /* only if unambiguous */
}
function prodSuggest(n){
  var t=pnorm(n); if(!t)return '';
  var best='',bs=0;
  PRODUCTS.forEach(function(x){var a=pnorm(x.name),sc=0,i;
    for(i=0;i<Math.min(a.length,t.length);i++){ if(a[i]===t[i])sc++; else break; }
    if(sc>bs){bs=sc;best=x.name;}
  });
  return bs>=3?best:'';
}
function courByName(n){return COURMAP[String(n||'').toLowerCase()]||null;}
function spByName(n){return SPMAP[String(n||'').toLowerCase()]||null;}
function prodById(id){return PRODUCTS.find(function(p){return p.id==id;})||null;}
function courById(id){return COURIERS.find(function(c){return c.id==id;})||null;}
function spById(id){return SALESPERSONS.find(function(s){return s.id==id;})||null;}
function fmt(n){return CURRENCY+' '+Number(parseFloat(n)||0).toLocaleString('en-IN',{maximumFractionDigits:0});}

function toRow(o){
  var prod=prodById(o.product_id), cour=courById(o.courier_id), sp=spById(o.sales_person_id);
  return {
    _id:o.id, cost_price:parseFloat(o.cost_price)||0, cancel_charge:parseFloat(o.cancel_charge)||0,
    code:o.code||'', order_date:o.order_date||'', customer:o.customer||'', phone:o.phone||'',
    sales_person_name:sp?sp.name:(o.sales_person_name||''),
    address:o.address||'', product_name:prod?prod.name:(o.product_name||''),
    qty:parseInt(o.qty)||1, sell_price:parseFloat(o.sell_price)||0,
    delivery_charge:parseFloat(o.delivery_charge)||0, zone:(String(o.zone||'').toLowerCase()==='outside')?'Outside':'Inside',
    payment_type:(String(o.payment_type||'').toLowerCase()==='prepaid')?'Prepaid':'COD', payment_status:(String(o.payment_status||'').toLowerCase()==='paid')?'Paid':'Unpaid',
    status:o.status||'pending', courier_name:cour?cour.name:(o.courier_name||''),
    remarks:o.remarks||'', revenue:parseFloat(o.revenue)||0, profit:parseFloat(o.profit)||0, _ncm:(o.ncm_order_id||''), _group:(o.order_group||''), _sel:false
  };
}
var DATA = ORDERS.map(toRow);

/* renderers */
function statusR(inst,td,r,c,prop,val){
  var s=(val||'').toLowerCase();
  td.innerHTML='<span class="ht-status '+s+'">'+(s?s.charAt(0).toUpperCase()+s.slice(1):'')+'</span>';
  td.style.paddingTop='7px'; return td;
}
function payRcvR(inst,td,r,c,prop,val){
  var ok=(val==='Paid');
  td.innerHTML='<span class="pill '+(ok?'p-green':'p-red')+'">'+(ok?'Paid':'Unpaid')+'</span>';
  td.style.paddingTop='7px'; td.style.textAlign='center'; return td;
}
function moneyR(inst,td,r,c,prop,val){
  Handsontable.renderers.TextRenderer.apply(this,arguments);
  td.style.textAlign='right';
  if(prop==='cancel_charge'){
    var phys=inst.toPhysicalRow?inst.toPhysicalRow(r):r, row=(inst.getSourceDataAtRow?inst.getSourceDataAtRow(phys):VIEW[phys])||{};
    var isRet=['cancelled','returned'].indexOf(String(row.status||'').toLowerCase())>-1;
    if(isRet && !(parseFloat(val)>0)){
      td.innerHTML='<span class="cc-need" title="Cancel charge differs by location — type the real amount for this delivery">✎ set charge</span>';
      td.className+=' cc-needcell'; return td;
    }
  }
  td.innerHTML=fmt(val); return td;
}
function delR(inst,td,r){
  var phys=inst.toPhysicalRow?inst.toPhysicalRow(r):r, row=VIEW[phys]||{};
  var html='';
  if(row._id){
    html+='<button class="rowact" title="WhatsApp confirmation">📱</button>';
    html+='<button class="rowact" title="Invoice / label">🧾</button>';
    if(['pending','processing'].indexOf(String(row.status).toLowerCase())>-1)
      html+='<button class="rowact" title="Book with NCM">🚚</button>';
  }
  html+='<button class="rowdel" title="Delete">🗑</button>';
  td.innerHTML=html; td.style.textAlign='center'; td.style.whiteSpace='nowrap';
  var btns=td.querySelectorAll('button'), i=0;
  if(row._id){
    btns[i++].onclick=function(){waOrder(phys);};
    btns[i++].onclick=function(){window.open('invoice.php?id='+row._id,'_blank');};
    if(['pending','processing'].indexOf(String(row.status).toLowerCase())>-1)
      btns[i++].onclick=function(){window.location='ncm.php?book='+row._id;};
  }
  btns[i].onclick=function(){openDel(phys);};
  return td;
}
/* WhatsApp order-confirmation link (Nepal +977) */
function waPhone(r){
  var ph=String(r.phone||'').replace(/[^0-9]/g,'').replace(/^0+/,'');
  if(!ph) return '';
  if(ph.indexOf('977')!==0) ph='977'+ph;
  return ph;
}
function waMsg(r){
  var amt=(parseFloat(r.sell_price)||0)*(parseInt(r.qty)||1);   /* price includes free delivery for the customer */
  var msg='Namaste '+(r.customer||'')+'! 🙏\nYour order '+(r.code||'')+' — '+(r.product_name||'item')+' ×'+(r.qty||1)
    +' (Total Rs.'+amt.toLocaleString()+(String(r.payment_type).toLowerCase()==='cod'?', Cash on Delivery':'')
    +') has been confirmed by Luprah Trading.'+(r.courier_name?('\nDelivery via '+r.courier_name+'.'):'');
  if(r._ncm && TRK_TPL) msg+='\n📦 Track your parcel: '+TRK_TPL.replace('{id}',r._ncm);
  msg+='\nThank you for shopping with us!';
  return msg;
}
function waLink(r){var ph=waPhone(r);return ph?('https://wa.me/'+ph+'?text='+encodeURIComponent(waMsg(r))):'';}
function waOrder(i){
  var r=VIEW[i]; if(!r)return;
  var url=waLink(r);
  if(!url){alert('This order has no phone number.');return;}
  window.open(url,'_blank');
}

/* ---- bulk WhatsApp confirmations ---- */
var WAQ=[];
function waBulk(){
  var rows=selRows();
  WAQ=rows.map(function(r){return {r:r,url:waLink(r),sent:false};});
  var bad=WAQ.filter(function(x){return !x.url;}).length;
  if(!WAQ.length){alert('Select some orders first.');return;}
  var list=document.getElementById('waList'); list.innerHTML='';
  WAQ.forEach(function(x,idx){
    var d=document.createElement('div'); d.className='warow'+(x.url?'':' nophone'); d.id='warow'+idx;
    d.innerHTML='<span class="wast">'+(x.url?'⬜':'🚫')+'</span>'
      +'<span class="wanm"><b>'+esc(x.r.customer||'—')+'</b> · '+esc(x.r.code||'')+(x.r._ncm?' · NCM '+esc(x.r._ncm):'')+'<br><small>'+esc(x.r.phone||'no phone')+'</small></span>'
      +(x.url?'<button class="btn btn-sm btn-primary" onclick="waSendOne('+idx+')">💬 Send</button>':'<small class="muted">no phone</small>');
    list.appendChild(d);
  });
  document.getElementById('waTot').textContent=WAQ.length-bad;
  document.getElementById('waDone').textContent='0';
  document.getElementById('waModal').classList.add('open');document.body.classList.add('modal-open');
}
function waSendOne(i){
  var x=WAQ[i]; if(!x||!x.url)return;
  window.open(x.url,'_blank');
  if(!x.sent){x.sent=true;
    var row=document.getElementById('warow'+i);
    if(row){row.classList.add('done');row.querySelector('.wast').textContent='✅';}
    document.getElementById('waDone').textContent=WAQ.filter(function(y){return y.sent;}).length;
  }
}
function waNext(){
  for(var i=0;i<WAQ.length;i++){ if(WAQ[i].url&&!WAQ[i].sent){ waSendOne(i); return; } }
  alert('All done — every confirmation opened ✅');
}
function esc(t){var d=document.createElement('div');d.textContent=String(t);return d.innerHTML;}
function closeWa(){document.getElementById('waModal').classList.remove('open');document.body.classList.remove('modal-open');}


/* ===== smart-sheet helpers ===== */
function norm10(p){return String(p||'').replace(/\D+/g,'').slice(-10);}
function phoneOK(p){var d=String(p||'').replace(/\D+/g,'');if(!d)return true; if(/^977/.test(d))d=d.slice(3); if(/^0\d{7,9}$/.test(d))return true; return /^9[5-8]\d{8}$/.test(d);}
function custRisk(ph){var k=norm10(ph); if(k.length<7)return null; var st=CUSTSTAT[k]; if(!st)return null;
  if(st.r>=2||(st.o>=2&&st.r/st.o>=0.5)) return st; return null;}
function activeDup(row){var k=norm10(row.phone); if(k.length<7)return null;
  for(var i=0;i<DATA.length;i++){var o=DATA[i]; if(o===row||!o._id||(row._id&&o._id===row._id))continue;
    if(norm10(o.phone)!==k)continue;
    /* NOT a duplicate if they belong to the same multi-product order (linked group) */
    if(row._group && o._group && row._group===o._group)continue;
    /* NOT a duplicate if it's a DIFFERENT product — a customer can buy several items.
       A real double-order is the SAME product going to the same phone. */
    if(String(o.product_name||'').toLowerCase()!==String(row.product_name||'').toLowerCase())continue;
    if(['pending','processing','shipped'].indexOf(String(o.status).toLowerCase())>-1) return o;}
  return null;}
function stockOf(name){var p=prodByName(name);return p?parseInt(p.stock)||0:null;}
function potProfit(row){var sp=parseFloat(row.sell_price)||0,cp=parseFloat(row.cost_price)||0,
  q=parseInt(row.qty)||1,dl=parseFloat(row.delivery_charge)||0;
  return (sp-cp)*q-dl;}
function ageDays(d){if(!d)return 0;var ms=new Date(TODAY)-new Date(String(d).slice(0,10));return Math.floor(ms/86400000);}
function isAging(row){return ['pending','processing'].indexOf(String(row.status).toLowerCase())>-1 && ageDays(row.order_date)>=AGING_DAYS;}

/* ===== renderers ===== */
function codeR(inst,td,r,c,prop,val){
  Handsontable.renderers.TextRenderer.apply(this,arguments);
  var row=VIEW[inst.toPhysicalRow?inst.toPhysicalRow(r):r]||{};
  if(isAging(row)){td.innerHTML=(val||'')+' <span class="agepill">'+ageDays(row.order_date)+'d</span>';td.title='Pending for '+ageDays(row.order_date)+' days — dispatch or follow up';}
  return td;
}
function custR(inst,td,r,c,prop,val){
  Handsontable.renderers.TextRenderer.apply(this,arguments);
  var row=VIEW[inst.toPhysicalRow?inst.toPhysicalRow(r):r]||{};
  var st=custRisk(row.phone);
  var pr=inst.toPhysicalRow?inst.toPhysicalRow(r):r;
  if(row._group){
    var prev=VIEW[pr-1];
    var isFirst = !prev || prev._group!==row._group;
    if(isFirst){
      /* count items in this group for the tag */
      var n=0; for(var i=0;i<VIEW.length;i++){ if(VIEW[i]._group===row._group) n++; }
      td.innerHTML='<b>'+(val?String(val):'')+'</b><span class="grp-tag" title="Multi-product order — one customer, one delivery">📦 '+n+' items</span>';
    } else {
      /* continuation row: faint arrow + name so it reads as the same order */
      td.innerHTML='<span class="grp-cont">↳</span><span class="grp-same">'+(val?String(val):'')+'</span>';
    }
  } else {
    td.innerHTML='<b>'+(val?String(val):'')+'</b>';
  }
  if(st){td.innerHTML+=' <span title="risky">🚩</span>';td.className+=' c-bad';
    td.title='⚠ Risky customer: '+st.r+' return'+(st.r>1?'s':'')+' out of '+st.o+' orders — confirm by call before dispatch';}
  return td;
}
function prodR(inst,td,r,c,prop,val){
  Handsontable.renderers.AutocompleteRenderer.apply(this,arguments);
  td.innerHTML='<b>'+(val?String(val):'')+'</b><div class="htAutocompleteArrow">▼</div>';
  return td;
}
function phoneR(inst,td,r,c,prop,val){
  Handsontable.renderers.TextRenderer.apply(this,arguments);
  var row=VIEW[inst.toPhysicalRow?inst.toPhysicalRow(r):r]||{};
  var html=val?String(val):'';
  if(val&&!phoneOK(val)){td.className+=' c-warn';td.title='📵 Does not look like a valid Nepali number';}
  var dup=activeDup(row);
  if(dup){td.className+=' c-dup';td.title='👥 Same phone already has '+dup.code+' ('+dup.status+') — possible double order';html+=' <b>👥</b>';}
  if(val){var d=String(val).replace(/\D+/g,'');if(d){html+=' <a class="callmini" href="tel:'+d+'" title="Call" onclick="event.stopPropagation()">📞</a>';}}
  td.innerHTML=html; return td;
}
function qtyR(inst,td,r,c,prop,val){
  Handsontable.renderers.NumericRenderer.apply(this,arguments);
  var row=VIEW[inst.toPhysicalRow?inst.toPhysicalRow(r):r]||{};
  var st=stockOf(row.product_name);
  if(st!==null && String(row.status).toLowerCase()!=='delivered' && parseInt(val)>st){
    td.className+=' c-warn'; td.title='📦 Only '+st+' left in stock (FIFO batches)';
    td.innerHTML=val+' <b>!</b>';}
  return td;
}
function priceR(inst,td,r,c,prop,val){
  Handsontable.renderers.TextRenderer.apply(this,arguments);
  td.style.textAlign='right'; td.innerHTML=fmt(val);
  var row=VIEW[inst.toPhysicalRow?inst.toPhysicalRow(r):r]||{};
  if((parseFloat(val)||0)>0 && potProfit(row)<0 && ['cancelled','returned'].indexOf(String(row.status).toLowerCase())<0){
    td.className+=' c-bad'; td.title='💸 Below cost + delivery — you would LOSE '+fmt(Math.abs(potProfit(row)))+' on this order';}
  return td;
}
function payTypeR(inst,td,r,c,prop,val){
  Handsontable.renderers.AutocompleteRenderer.apply(this,arguments);
  td.innerHTML='<span class="ptypechip">'+(val?String(val):'')+'</span><div class="htAutocompleteArrow">▼</div>';
  return td;
}
function profitR(inst,td,r,c,prop,val){
  var row=VIEW[inst.toPhysicalRow?inst.toPhysicalRow(r):r]||{};
  var st=String(row.status).toLowerCase();
  td.style.textAlign='right'; td.style.whiteSpace='nowrap';
  if(st==='delivered'){var v=parseFloat(val)||0;td.innerHTML='<b style="color:'+(v>=0?'var(--green)':'var(--red)')+'">'+fmt(v)+'</b>';}
  else if(st==='cancelled'||st==='returned'){var v2=parseFloat(val)||0;td.innerHTML='<span style="color:var(--red)">'+fmt(v2)+'</span>';}
  else{var p=potProfit(row);td.innerHTML='<span style="opacity:.75;color:'+(p>=0?'var(--green)':'var(--red)')+'">≈ '+fmt(p)+'</span>';td.title='Projected profit if delivered (FIFO cost aware)';}
  return td;
}

var COLS=[
  {data:'_sel',title:'✓',width:36,type:'checkbox',className:'htCenter'},
  {data:'code',title:'Order',width:104,readOnly:true,renderer:codeR},
  {data:'sales_person_name',title:'Sales Person',width:130,type:'dropdown',source:spNames,className:'nowrapcell'},
  {data:'product_name',title:'Product',width:150,type:'dropdown',source:prodNames,renderer:prodR},
  {data:'order_date',title:'Date',width:104,type:'date',dateFormat:'YYYY-MM-DD',correctFormat:true},
  {data:'customer',title:'Customer',width:138,renderer:custR},
  {data:'phone',title:'Phone',width:126,renderer:phoneR},
  {data:'address',title:'Address',width:150},
  {data:'zone',title:'Zone',width:88,type:'dropdown',source:ZONES},
  {data:'qty',title:'Quantity',width:80,type:'numeric',renderer:qtyR},
  {data:'sell_price',title:'Price',width:88,type:'numeric',renderer:priceR},
  {data:'delivery_charge',title:'Delivery',width:92,type:'autocomplete',source:COMMON_DELIV,strict:false,filter:false,renderer:moneyR},
  {data:'cancel_charge',title:'Cancel Chg',width:92,type:'numeric',renderer:moneyR},
  {data:'revenue',title:'Revenue',width:98,readOnly:true,renderer:moneyR},
  {data:'payment_type',title:'Payment Type',width:110,type:'dropdown',source:PAY_TYPES,renderer:payTypeR},
  {data:'status',title:'Status',width:108,type:'dropdown',source:STATUSES,renderer:statusR},
  {data:'payment_status',title:'Payment Received',width:130,type:'dropdown',source:PAY_RCV,renderer:payRcvR},
  {data:'courier_name',title:'Courier',width:118,type:'dropdown',source:courNames,className:'nowrapcell'},
  {data:'profit',title:'Profit',width:98,readOnly:true,renderer:profitR},
  {data:'remarks',title:'Remarks',width:150},
  {data:'_del',title:'',width:100,readOnly:true,renderer:delR}
];
/* column show/hide (view-level; CSV export unaffected) */
var HIDEABLE={'phone':'Phone','address':'Address','zone':'Zone','delivery_charge':'Delivery','cancel_charge':'Cancel Chg','revenue':'Revenue','payment_type':'Payment Type','payment_status':'Payment Received','courier_name':'Courier','sales_person_name':'Sales Person','remarks':'Remarks'};
var hiddenCols={}; try{hiddenCols=JSON.parse(localStorage.getItem('sales_hidden_cols')||'{}');}catch(e){}
function visibleCols(){return COLS.filter(function(c){return !hiddenCols[c.data];});}


function setSave(s,m){['saveStatus','saveStatus2'].forEach(function(id){var el=document.getElementById(id);if(el){el.className='save-ind '+s;el.textContent=m;}});}

function recalc(row){
  var sp=parseFloat(row.sell_price)||0, cp=parseFloat(row.cost_price)||0, qty=parseInt(row.qty)||1, st=row.status, dl=parseFloat(row.delivery_charge)||0;
  row.revenue = st==='delivered'? sp*qty : 0;
  row.profit  = st==='delivered'? ((sp-cp)*qty - dl) : (st==='cancelled'||st==='returned'? -(parseFloat(row.cancel_charge)||0):0);
}
function updateStats(){ updateRowCount(); if(typeof updateChips==='function')updateChips(); if(typeof updateBulkBar==='function')updateBulkBar();
  var scope=(advActiveCount()>0||['cancelled','returned','shipped','delivered','pending','processing','today','aging'].indexOf(CURFILTER)>-1)?finalView():DATA;
  var rev=0,del=0,prof=0,units=0;
  scope.forEach(function(o){rev+=parseFloat(o.revenue)||0;prof+=parseFloat(o.profit)||0;if(o.status==='delivered'){del+=parseFloat(o.delivery_charge)||0;units+=parseInt(o.qty)||0;}});
  var su=document.getElementById('stUnits'); if(su)su.textContent=units.toLocaleString();
  document.getElementById('stTotal').textContent=scope.length;
  document.getElementById('stRev').textContent=fmt(rev);
  document.getElementById('stDel').textContent=fmt(del);
  document.getElementById('stProfit').textContent=fmt(prof);
  document.getElementById('rowCount').textContent=DATA.length+' rows';var _r2=document.getElementById('rowCount2');if(_r2)_r2.textContent=DATA.length;
}

/* POST helper (form-encoded, csrf in body) */
function post(params, cb){
  setSave('saving','● Saving…');
  params.csrf=CSRF;
  var body=Object.keys(params).map(function(k){return encodeURIComponent(k)+'='+encodeURIComponent(params[k]==null?'':params[k]);}).join('&');
  fetch('sales.php?ajax=1',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body:body})
   .then(function(r){return r.json();})
   .then(function(d){
     if(d.ok){setSave('saved','✔ Saved');setTimeout(function(){setSave('idle','● All saved');},1500);cb(null,d);}
     else{setSave('error','✗ '+d.error);cb(d.error,null);}
   })
   .catch(function(e){setSave('error','✗ Network error');cb(e.message,null);});
}

function payload(row){
  var prod=prodByName(row.product_name), cour=courByName(row.courier_name), sp=spByName(row.sales_person_name);
  return {
    op:'update', id:row._id,
    order_date:row.order_date, customer:row.customer, phone:row.phone, address:row.address,
    sales_person_id:sp?sp.id:'',
    product_id:prod?prod.id:'', qty:row.qty, sell_price:row.sell_price,
    delivery_charge:row.delivery_charge, zone:row.zone, payment_type:row.payment_type,
    payment_status:row.payment_status, status:row.status, courier_id:cour?cour.id:'',
    cancel_charge:row.cancel_charge||0, remarks:row.remarks,
    order_group:row._group||''
  };
}

var hot, VIEW=[], CURFILTER='all';
/* sheet fills the window: viewport minus header/toolbar/stats, min 560px */
function sheetH(){ return Math.max(540, window.innerHeight - 330); }
window.addEventListener('resize', function(){ if(hot) hot.updateSettings({height:sheetH()}); });
var _paintT=null;
function paintSoon(){ if(_paintT)clearTimeout(_paintT); _paintT=setTimeout(function(){ if(hot&&hot.render)hot.render(); },90); }
function currentView(){
  var t=TODAY;
  switch(CURFILTER){
    case 'pending':   return DATA.filter(function(o){return String(o.status).toLowerCase()==='pending';});
    case 'processing':return DATA.filter(function(o){return String(o.status).toLowerCase()==='processing';});
    case 'shipped':   return DATA.filter(function(o){return String(o.status).toLowerCase()==='shipped';});
    case 'delivered': return DATA.filter(function(o){return String(o.status).toLowerCase()==='delivered';});
    case 'today':     return DATA.filter(function(o){return String(o.order_date).slice(0,10)===t;});
    case 'aging':     return DATA.filter(isAging);
    case 'cancelled': return DATA.filter(function(o){return String(o.status).toLowerCase()==='cancelled';});
    case 'returned':  return DATA.filter(function(o){return String(o.status).toLowerCase()==='returned';});
    default:          return DATA;
  }
}
var ADV={prod:'',zone:'',pay:'',cour:'',sp:'',from:'',to:'',amin:'',amax:'',qmin:'',qmax:'',kw:''};
function advActiveCount(){var n=0;for(var k in ADV)if(ADV[k]!=='')n++;return n;}
function advMatch(o){
  if(ADV.prod && String(o.product_name)!==ADV.prod) return false;
  if(ADV.zone && String(o.zone).toLowerCase()!==ADV.zone.toLowerCase()) return false;
  if(ADV.pay && String(o.payment_type)!==ADV.pay) return false;
  if(ADV.cour && String(o.courier_name)!==ADV.cour) return false;
  if(ADV.sp==='__unassigned__'){ if(String(o.sales_person_name||'')!=='') return false; }
  else if(ADV.sp && String(o.sales_person_name)!==ADV.sp) return false;
  if(ADV.from && String(o.order_date).slice(0,10) < ADV.from) return false;
  if(ADV.to && String(o.order_date).slice(0,10) > ADV.to) return false;
  var rev=parseFloat(o.revenue)||0;
  if(ADV.amin!=='' && rev < parseFloat(ADV.amin)) return false;
  if(ADV.amax!=='' && rev > parseFloat(ADV.amax)) return false;
  var q=parseInt(o.qty)||0;
  if(ADV.qmin!=='' && q < parseInt(ADV.qmin)) return false;
  if(ADV.qmax!=='' && q > parseInt(ADV.qmax)) return false;
  if(ADV.kw){var k=ADV.kw.toLowerCase();
    var hay=[o.customer,o.phone,o.address,o.code,o.remarks].join(' ').toLowerCase();
    if(hay.indexOf(k)===-1) return false;}
  return true;
}
function baseView(){ return currentView(); }
function applyFilter(f){CURFILTER=f;refreshView();updateChips();}
var SORTDESC=(function(){try{return (localStorage.getItem('salesNewFirst')||'1')==='1';}catch(e){return true;}})();
function dirView(arr){
  if(!SORTDESC) return arr;
  /* newest first, but keep multi-product group blocks together in their original order */
  var out=[], i=arr.length-1;
  while(i>=0){
    var g=arr[i]._group;
    if(g){ var j=i; while(j>=0 && arr[j]._group===g) j--;
      for(var k=j+1;k<=i;k++) out.push(arr[k]); i=j;
    } else { out.push(arr[i]); i--; }
  }
  return out;
}
function toggleSortDir(){
  SORTDESC=!SORTDESC;
  try{localStorage.setItem('salesNewFirst',SORTDESC?'1':'0');}catch(e){}
  var b=document.getElementById('sortDirBtn'); if(b)b.textContent=SORTDESC?'⬇ Newest first':'⬆ Oldest first';
  refreshView(); hot.scrollViewportTo(0,0);
}
function newRowIndex(){ return SORTDESC?0:VIEW.length-1; }
try{ var _sb=document.getElementById('sortDirBtn'); if(_sb)_sb.textContent=SORTDESC?'⬇ Newest first':'⬆ Oldest first'; }catch(e){}
function finalView(){ var v=baseView(); if(advActiveCount()>0) v=v.filter(advMatch); return dirView(v); }
function refreshView(){VIEW=finalView();hot.loadData(VIEW);updateAdvUI();}
function toggleAdv(){var p=document.getElementById('advPanel');p.style.display=p.style.display==='none'?'block':'none';fillAdvSources();}
function fillAdvSources(){
  var ps=document.getElementById('af_prod'); if(ps && ps.options.length<=1){ (window.prodNames||[]).forEach(function(n){var o=document.createElement('option');o.textContent=n;ps.appendChild(o);}); }
  var pay=document.getElementById('af_pay'); if(pay && pay.options.length<=1){ (window.PAY_TYPES||['COD','Prepaid']).forEach(function(n){var o=document.createElement('option');o.textContent=n;pay.appendChild(o);}); }
  var cs=document.getElementById('af_cour'); if(cs && cs.options.length<=1){ (window.courNames||[]).forEach(function(n){var o=document.createElement('option');o.textContent=n;cs.appendChild(o);}); }
  var sp=document.getElementById('af_sp'); if(sp && sp.options.length<=2){ (window.spNames||[]).forEach(function(n){var o=document.createElement('option');o.textContent=n;o.value=n;sp.appendChild(o);}); }
}
function readAdv(){
  ADV.prod=val('af_prod');ADV.zone=val('af_zone');ADV.pay=val('af_pay');ADV.cour=val('af_cour');ADV.sp=val('af_sp');
  ADV.from=val('af_from');ADV.to=val('af_to');ADV.amin=val('af_amin');ADV.amax=val('af_amax');
  ADV.qmin=val('af_qmin');ADV.qmax=val('af_qmax');ADV.kw=val('af_kw');
  function val(id){var e=document.getElementById(id);return e?e.value.trim():'';}
}
function applyAdv(){readAdv();refreshView();updateStats();}
function resetAdv(){for(var k in ADV)ADV[k]='';['af_prod','af_zone','af_pay','af_cour','af_sp','af_from','af_to','af_amin','af_amax','af_qmin','af_qmax','af_kw'].forEach(function(id){var e=document.getElementById(id);if(e)e.value='';});refreshView();updateStats();}
function dropAdv(k){ADV[k]='';var map={prod:'af_prod',zone:'af_zone',pay:'af_pay',cour:'af_cour',sp:'af_sp',from:'af_from',to:'af_to',amin:'af_amin',amax:'af_amax',qmin:'af_qmin',qmax:'af_qmax',kw:'af_kw'};var e=document.getElementById(map[k]);if(e)e.value='';refreshView();updateStats();}
function updateAdvUI(){
  var n=advActiveCount(); var badge=document.getElementById('advCount');
  if(badge){badge.style.display=n?'inline-block':'none';badge.textContent=n;}
  var btn=document.getElementById('advBtn'); if(btn)btn.classList.toggle('on',n>0);
  var act=document.getElementById('advActive'); if(!act)return;
  if(!n){act.style.display='none';act.innerHTML='';}
  else{
    var lbl={prod:'Product',zone:'Zone',pay:'Payment',cour:'Courier',sp:'Sales Person',from:'From',to:'To',amin:'Amount ≥',amax:'Amount ≤',qmin:'Qty ≥',qmax:'Qty ≤',kw:'Search'};
    var html='';for(var k in ADV){if(ADV[k]!=='')html+='<span class="apill">'+lbl[k]+': '+esc(k==='sp'&&ADV[k]==='__unassigned__'?'Unassigned':ADV[k])+' <span class="x" onclick="dropAdv(\''+k+'\')">✕</span></span>';}
    html+='<span class="apill clr" onclick="resetAdv()">↺ clear all</span>';
    act.innerHTML=html;act.style.display='flex';
  }
  var sm=document.getElementById('advSummary'); if(sm)sm.textContent=finalView().length+' of '+DATA.length+' orders match';
}
function esc(t){var d=document.createElement('div');d.textContent=t==null?'':t;return d.innerHTML;}
function exportFiltered(){
  var rows=finalView();
  var heads=['Order','Sales Person','Product','Date','Customer','Phone','Address','Zone','Qty','Price','Delivery','Cancel Chg','Revenue','Payment','Status'];
  var keys=['code','sales_person_name','product_name','order_date','customer','phone','address','zone','qty','sell_price','delivery_charge','cancel_charge','revenue','payment_type','status'];
  var lines=[heads.join(',')];
  rows.forEach(function(r){lines.push(keys.map(function(k){var v=(r[k]==null?'':String(r[k]));return '"'+v.replace(/"/g,'""')+'"';}).join(','));});
  var blob=new Blob([lines.join('\n')],{type:'text/csv'});
  var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='sales_filtered.csv';a.click();
}
function updateChips(){
  var cnt={all:DATA.length,pending:0,processing:0,shipped:0,delivered:0,today:0,aging:0};
  DATA.forEach(function(o){var st=String(o.status).toLowerCase();
    if(cnt[st]!==undefined)cnt[st]++;
    if(String(o.order_date).slice(0,10)===TODAY)cnt.today++;
    if(isAging(o))cnt.aging++;});
  cnt.cancelled=0;cnt.returned=0;
  DATA.forEach(function(o){var st=String(o.status).toLowerCase();if(st==='cancelled')cnt.cancelled++;if(st==='returned')cnt.returned++;});
  ['all','pending','processing','shipped','delivered','today','aging','cancelled','returned'].forEach(function(k){
    var el=document.getElementById('chip-'+k); if(!el)return;
    el.querySelector('b').textContent=cnt[k]||0;
    el.classList.toggle('on',CURFILTER===k);
    el.style.display=(k==='aging'&&cnt.aging===0)?'none':((k==='returned'&&cnt.returned===0)?'none':'');});
  updateAdvUI();
}
function buildGrid(data){
  VIEW=data;
  hot=new Handsontable(document.getElementById('hotBox'),{
    data:data, columns:visibleCols(), colHeaders:visibleCols().map(function(c){return c.title;}),
    rowHeaders:true, height:sheetH(), width:'100%',
    autoColumnSize:false, autoRowSize:false, renderAllRows:false,
    viewportColumnRenderingOffset:14, viewportRowRenderingOffset:24,
    fragmentSelection:true,
    manualColumnResize:true, manualColumnMove:true,
    afterRenderer:function(td,row,col,prop,value,cellProps){
      /* colour the whole ROW by status — reliable in Handsontable 6.2.2 (cells:className is overridden by column classNames) */
      var vr=(this.getSourceDataAtRow?this.getSourceDataAtRow(row):null)||VIEW[row]; if(!vr)return;
      var tr=td.parentNode; if(!tr)return;
      tr.classList.remove('row-del','row-ship','row-red','row-needcancel','row-grp','row-grp-first','row-grp-last');
      var st=String(vr.status||'').toLowerCase();
      if(st==='delivered')tr.classList.add('row-del');
      else if(st==='shipped')tr.classList.add('row-ship');
      else if(st==='cancelled'||st==='returned')tr.classList.add('row-red');
      if(vr._needCancel)tr.classList.add('row-needcancel');
      /* Design A — soft group band for multi-product orders (same order_group) */
      if(vr._group){
        tr.classList.add('row-grp');
        var prev=VIEW[row-1], next=VIEW[row+1];
        var firstInGrp = !prev || prev._group!==vr._group;
        var lastInGrp  = !next || next._group!==vr._group;
        if(firstInGrp) tr.classList.add('row-grp-first');
        if(lastInGrp)  tr.classList.add('row-grp-last');
      }
    },
    columnSorting:true, filters:true, dropdownMenu:true,
    beforePaste:function(data,coords){
      /* 1) an active column sort makes pasted rows jump around — drop it so paste lands exactly where you point */
      try{ var cs=hot.getPlugin('columnSorting'); if(cs&&cs.isSorted&&cs.isSorted()){ cs.clearSort(); } }catch(e){}
      /* 2) pasting more rows than exist: grow the sheet so nothing is cut off (creates orders row by row) */
      try{
        if(CURFILTER==='all'&&coords&&coords[0]){
          var need=coords[0].startRow+data.length-VIEW.length;
          for(var i=0;i<need;i++) DATA.push(makeBlankRow());
          if(need>0){ refreshView(); updateRowCount(); }
        }
      }catch(e){}
    },
    contextMenu:{items:{
      'add_product':{name:'➕ Add another product for this customer', callback:function(key,sel){ try{var r=sel[0].start.row; var pr=hot.toPhysicalRow?hot.toPhysicalRow(r):r; addRowSameCustomer(VIEW[pr]); }catch(e){ addRowSameCustomer(); } }},
      'mark_returned':{name:'↩ Mark RETURNED + charge…', callback:function(key,sel){
        try{
          var r=sel[0].start.row; var row=VIEW[r]; if(!row||!row._id){alert('Save the order first.');return;}
          var def=parseFloat(row.cancel_charge)||0;
          if(!(def>0)){ var cn=String(row.courier_name||'').toLowerCase();
            for(var i=0;i<COURIERS.length;i++){ if(String(COURIERS[i].name||'').toLowerCase()===cn){ def=parseFloat(COURIERS[i].cancel_charge)||0; break; } } }
          if(!(def>0)) def=100;
          var v=prompt('Return / cancel charge for this order (Rs.):', def);
          if(v===null) return; v=parseFloat(v)||0;
          hot.setDataAtRowProp([[r,'cancel_charge',v],[r,'status','returned']]);
        }catch(e){ alert('Could not mark returned: '+e.message); }
      }},
      'sep1':'---------','copy':{},'cut':{},'sep2':'---------','undo':{},'redo':{}
    }},
    fillHandle:true, autoWrapRow:true, undo:true,
    afterChange:function(changes,source){
      if(!changes||source==='loadData'||source==='external'||source==='filter')return;
      var _reRender=false;
      var touched={};
      changes.forEach(function(ch){
        var ri=ch[0], prop=ch[1], oldVal=ch[2], val=ch[3];
        var phys=(hot.toPhysicalRow?hot.toPhysicalRow(ri):ri);
        var row=(hot.getSourceDataAtRow?hot.getSourceDataAtRow(phys):null)||VIEW[phys];
        if(!row)return;
        if(prop==='_sel'){updateBulkBar();return;}          /* selection is view-only — never saved */
        if(prop==='delivery_charge') row._delTouched=true;   /* manual delivery is respected from now on */
        if(prop==='product_name'){var p=prodByName(val);if(p){row.sell_price=p.price;row.cost_price=p.cost;}}
        if(prop==='courier_name'){                            /* courier's default charges (still editable) */
          var cc=courByName(val);
          if(cc){
            if(!row._delTouched && !(parseFloat(row.delivery_charge)>0)) row.delivery_charge=parseFloat(cc.default_delivery)||0;
          }
        }
        if(prop==='status' && ['cancelled','returned'].indexOf(String(val).toLowerCase())>-1){
          /* cancel charge differs by location — leave it for the user to type; just flag it */
          if(!(parseFloat(row.cancel_charge)>0)){ row.cancel_charge=''; row._needCancel=true; }
          else { row._needCancel=false; }
        }
        if(prop==='status') _reRender=true;
        if(prop==='cancel_charge'){ row._needCancel = !(parseFloat(row.cancel_charge)>0) && ['cancelled','returned'].indexOf(String(row.status).toLowerCase())>-1; }
        if(prop==='phone'){                                   /* returning customer → auto-fill name & address */
          var info=CUSTINFO[norm10(val)];
          if(info){ if(!String(row.customer||'').trim()) row.customer=info.name;
                    if(!String(row.address||'').trim())  row.address=info.address; }
        }
        recalc(row);
        touched[phys]={ri:ri,row:row};
      });
      paintSoon();    /* debounced paint of derived cells — avoids a full re-render on every keystroke */
      Object.keys(touched).forEach(function(k){
        var t=touched[k], row=t.row, ri=t.ri;
        if(!row._id){
          /* only save once the row has real content — keeps empty rows out of the DB */
          var meaningful=(row.customer&&(''+row.customer).trim())||(row.product_name&&(''+row.product_name).trim())||(parseFloat(row.sell_price)>0);
          if(!meaningful||row._creating)return;
          row._creating=true;
          var cp=payload(row); cp.op='create';
          post(cp,function(err,res){
            row._creating=false;
            if(err)return;
            if(res&&res.order){row._id=res.order.id;row.code=res.order.code;
              row.revenue=parseFloat(res.order.revenue)||0;row.profit=parseFloat(res.order.profit)||0;row.cost_price=parseFloat(res.order.cost_price)||0;
              hot.render();}
            updateStats();
          });
          return;
        }
        post(payload(row),function(err,res){
          if(err)return;
          if(res&&res.order){row.revenue=parseFloat(res.order.revenue)||0;row.profit=parseFloat(res.order.profit)||0;row.cost_price=parseFloat(res.order.cost_price)||0;
            hot.render();}
          updateStats();
        });
      });
      if(_reRender && hot && hot.render) hot.render();   /* status change re-tints immediately */
      updateStats();
    }
  });
}

if(typeof Handsontable==='undefined'){
  document.getElementById('hotBox').style.display='none';
  document.getElementById('hotFail').style.display='block';
}else{
  buildGrid(finalView());   /* respects newest-first + filters from the start */
  updateStats();
}

/* add new order — creates a real saved row immediately */
/* Add Order = add a blank row to the sheet only. It is saved to the
   database automatically the moment you type a customer or product,
   so empty rows are NEVER stored. */

/* ===== New Order form ===== */
/* ---------- multi-product lines ---------- */
function prodLineHTML(){
  return '<div class="pline" style="display:grid;grid-template-columns:1fr 70px 100px 26px;gap:6px;margin-bottom:6px;align-items:center">'
    +'<input class="pl-name" list="fprodlist" placeholder="Product…" oninput="plNameChange(this)">'
    +'<input class="pl-qty" type="number" min="1" value="1" placeholder="Qty" oninput="fCalc()">'
    +'<input class="pl-price" type="number" step="any" placeholder="Price" oninput="fCalc()">'
    +'<button type="button" class="btn btn-sm" title="Remove" onclick="delProdLine(this)" style="padding:4px 8px">✕</button>'
    +'</div>';
}
function addProdLine(){ var box=document.getElementById('prodLines'); box.insertAdjacentHTML('beforeend', prodLineHTML()); }
function delProdLine(btn){ var box=document.getElementById('prodLines'); var line=btn.closest('.pline');
  if(box.querySelectorAll('.pline').length<=1){ line.querySelector('.pl-name').value=''; line.querySelector('.pl-qty').value=1; line.querySelector('.pl-price').value=''; }
  else line.remove();
  fCalc();
}
function plNameChange(inp){
  var p=prodByName(inp.value);
  var line=inp.closest('.pline');
  if(p){ var pr=line.querySelector('.pl-price'); if(!pr.value) pr.value=p.price; }
  fCalc();
}
function collectLines(){
  var out=[];
  document.querySelectorAll('#prodLines .pline').forEach(function(line){
    var name=line.querySelector('.pl-name').value.trim(); if(!name) return;
    var p=prodByName(name); if(!p) return;
    out.push({product_id:p.id, qty:parseInt(line.querySelector('.pl-qty').value)||1, sell_price:parseFloat(line.querySelector('.pl-price').value)||0, _name:name, _cost:parseFloat(p.cost)||0, _stock:parseInt(p.stock)||0});
  });
  return out;
}
function openOrderForm(){
  var dl=document.getElementById('fprodlist');
  dl.innerHTML=PRODUCTS.map(function(p){return '<option value="'+String(p.name).replace(/"/g,'&quot;')+'">';}).join('');
  document.getElementById('f_date').value=new Date().toISOString().slice(0,10);
  document.getElementById('f_zone').value=DEF_ZONE;
  document.getElementById('f_pay').value=DEF_PAY_UI;
  document.getElementById('f_status').value=DEF_STATUS;
  document.getElementById('f_courier').value='';
  document.getElementById('f_salesperson').value='';
  document.getElementById('f_delivery').value=0;
  ['f_customer','f_phone','f_address','f_remarks'].forEach(function(i){document.getElementById(i).value='';});
  document.getElementById('prodLines').innerHTML=prodLineHTML();  /* one fresh product line */
  fCalc();
  document.getElementById('orderModal').classList.add('open');
  /* phone sheet layout enforced by JS: immune to cached/old stylesheets */
  if (window.innerWidth<=820) {
    var _bg=document.getElementById('orderModal');
    var _md=_bg.querySelector('.modal');
    _bg.style.padding='0';
    _bg.style.display='flex';
    _bg.style.alignItems='stretch';
    _md.style.cssText += ';width:100vw;max-width:100vw;height:100%;max-height:none;margin:0;border-radius:0;display:flex;flex-direction:column;overflow:hidden;';
    var _kids=_md.children; /* [head, scroller, foot] */
    if(_kids[1]) _kids[1].style.cssText += ';flex:1 1 auto;min-height:0;max-height:none;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:14px 16px;';
    var _ft=_md.querySelector('.modal-foot');
    if(_ft) _ft.style.cssText += ';flex:0 0 auto;position:static;display:flex;gap:8px;padding:10px 12px;';
  }
  document.body.classList.add('modal-open');          /* stop background scroll */
  if(hot) hot.deselectCell();                          /* stop sheet repaint churn */
  setTimeout(function(){document.getElementById('f_customer').focus();},60);
}
function closeOrderForm(){document.getElementById('orderModal').classList.remove('open');document.body.classList.remove('modal-open');}
function fCalc(){
  var d=parseFloat(document.getElementById('f_delivery').value)||0;
  var lines=collectLines();
  var items=0, profit=0, warn='';
  lines.forEach(function(l){ items+=l.qty*l.sell_price; profit+=(l.sell_price-l._cost)*l.qty; if(l.qty>l._stock) warn+=' · ⚠ '+l._name+': only '+l._stock+' in stock'; });
  profit-=d;  /* one delivery cost for the whole parcel */
  var msg='Customer pays: '+CURRENCY+' '+items.toLocaleString()+' (free delivery)  ·  Your delivery cost: '+CURRENCY+' '+d.toLocaleString();
  if(lines.length){ msg+='  ·  Est. profit: '+CURRENCY+' '+profit.toLocaleString(); if(lines.length>1) msg+='  ·  '+lines.length+' products'; }
  msg+=warn;
  var el=document.getElementById('fTotal');
  el.textContent=msg;
  el.style.color=(lines.length&&profit<0&&items>0)?'var(--red)':'';
}
(function(){
  var ph=document.getElementById('f_phone');
  if(ph) ph.addEventListener('blur',function(){
    var hint=document.getElementById('fPhoneHint'); if(hint)hint.textContent='';
    var info=CUSTINFO[norm10(ph.value)];
    if(info){
      var cEl=document.getElementById('f_customer'), aEl=document.getElementById('f_address');
      if(!cEl.value.trim())cEl.value=info.name;
      if(!aEl.value.trim())aEl.value=info.address;
      if(hint){hint.style.color='var(--green)';hint.textContent='✨ Returning customer — details filled';}
    }
    var st=custRisk(ph.value);
    if(st&&hint){hint.style.color='var(--red)';hint.textContent='🚩 Risky: '+st.r+' return'+(st.r>1?'s':'')+' / '+st.o+' orders — confirm by call';}
    else if(ph.value&&!phoneOK(ph.value)&&hint){hint.style.color='var(--amber)';hint.textContent='📵 Number looks invalid';}
  });
  var fd=document.getElementById('f_delivery'); if(fd) fd.addEventListener('input',fCalc);
  var fc=document.getElementById('f_courier');
  if(fc) fc.addEventListener('change',function(){
    var cc=courById(fc.value);
    var dEl=document.getElementById('f_delivery');
    if(cc && !(parseFloat(dEl.value)>0)){ dEl.value=parseFloat(cc.default_delivery)||0; fCalc(); }
  });
  var om=document.getElementById('orderModal');
  if(om) om.addEventListener('click',function(e){ if(e.target===om) closeOrderForm(); });
})();
function resetOrderForm(){
  /* sales person, courier, zone, status are left alone — same as they already are —
     so entering several orders in a row for one salesperson doesn't need reselecting */
  ['f_customer','f_phone','f_address','f_remarks'].forEach(function(i){document.getElementById(i).value='';});
  document.getElementById('prodLines').innerHTML=prodLineHTML();
  document.getElementById('f_delivery').value=0;
  document.getElementById('f_date').value=TODAY;
  var h=document.getElementById('fPhoneHint'); if(h)h.textContent='';
  fCalc(); setTimeout(function(){document.getElementById('f_customer').focus();},60);
}
function saveOrderForm(keepOpen){
  var cust=document.getElementById('f_customer').value.trim();
  var lines=collectLines();
  if(!cust && !lines.length){ alert('Please enter a customer name and at least one product.'); return; }
  if(!lines.length){ alert('Please add at least one valid product (pick from the list).'); return; }
  var btn=document.getElementById('fSaveBtn'); btn.disabled=true; btn.textContent='Saving…';
  var btn2=document.getElementById('fSaveNewBtn'); if(btn2)btn2.disabled=true;
  post({op:'create_multi',
    items:JSON.stringify(lines.map(function(l){return {product_id:l.product_id,qty:l.qty,sell_price:l.sell_price};})),
    order_date:document.getElementById('f_date').value||new Date().toISOString().slice(0,10),
    customer:cust, phone:document.getElementById('f_phone').value.trim(),
    address:document.getElementById('f_address').value.trim(),
    sales_person_id:document.getElementById('f_salesperson').value||'',
    delivery_charge:parseFloat(document.getElementById('f_delivery').value)||0,
    zone:document.getElementById('f_zone').value,
    payment_type:document.getElementById('f_pay').value,
    status:document.getElementById('f_status').value,
    courier_id:document.getElementById('f_courier').value||'',
    remarks:document.getElementById('f_remarks').value.trim()
  }, function(err,res){
    btn.disabled=false; btn.textContent='💾 Save Order';
    if(btn2)btn2.disabled=false;
    if(err){ alert('Could not save: '+err); return; }
    if(res&&res.orders&&res.orders.length){
      res.orders.forEach(function(o){ DATA.push(toRow(o)); });
      CURFILTER='all'; refreshView(); updateStats();
      hot.scrollViewportTo(newRowIndex(),0);
    }
    if(keepOpen){ resetOrderForm(); var st=document.getElementById('fSavedNote'); if(st){st.style.display='inline'; setTimeout(function(){st.style.display='none';},1800);} }
    else closeOrderForm();
  });
}
document.getElementById('addFormBtn').onclick=openOrderForm;
/* global Ctrl+Z / Ctrl+Y — works even when the grid isn't focused */
window.addEventListener('keydown',function(e){
  var t=e.target, tag=(t&&t.tagName||'').toLowerCase();
  if(tag==='input'||tag==='textarea'||tag==='select'||(t&&t.isContentEditable))return;
  if(t&&t.closest&&t.closest('.handsontable'))return;   /* let the grid's own undo handle it */
  var k=(e.key||'').toLowerCase();
  if((e.ctrlKey||e.metaKey)&&!e.shiftKey&&k==='z'){e.preventDefault();try{hot.undo();}catch(_){}}
  else if((e.ctrlKey||e.metaKey)&&(k==='y'||(e.shiftKey&&k==='z'))){e.preventDefault();try{hot.redo();}catch(_){}}
});

/* deep link: ?new=daraz opens the form preset for a Daraz order */
(function(){
  try{
    var qs=new URLSearchParams(window.location.search);
    if(qs.get('new')==='hh'){
      setTimeout(function(){
        openOrderForm();
        var hh=COURIERS.find(function(c){return String(c.name).toLowerCase().indexOf('hungry')>-1;});
        if(hh) document.getElementById('f_courier').value=hh.id;
        document.getElementById('f_zone').value='Inside';
        document.getElementById('f_pay').value='COD';
      },150);
    }
    if(qs.get('new')==='daraz'){
      setTimeout(function(){
        openOrderForm();
        var dz=COURIERS.find(function(c){return String(c.name).toLowerCase()==='daraz';});
        if(dz) document.getElementById('f_courier').value=dz.id;
        var rm=document.getElementById('f_remarks');
        rm.placeholder='Daraz Order ID — e.g. 240712-9917742';
        document.getElementById('f_pay').value='Prepaid';   /* Daraz pays you, not customer-COD */
        setTimeout(function(){rm.focus();},80);
      },150);
    }
  }catch(e){}
})();

var _fb2=document.getElementById('addFormBtn2'); if(_fb2) _fb2.onclick=openOrderForm;

function makeBlankRow(){
  var blank={_id:0, code:'', order_date:new Date().toISOString().slice(0,10),
    customer:'', phone:'', address:'', sales_person_name:'', product_name:'',
    qty:1, sell_price:0, cost_price:0, cancel_charge:0,
    delivery_charge:0,   /* courier cost — enter manually per destination */
    zone:(DEF_ZONE==='outside'?'Outside':'Inside'),
    payment_type:DEF_PAY_UI, payment_status:'Unpaid',
    status:DEF_STATUS, courier_name:'', remarks:'', revenue:0, profit:0};
  blank._sel=false;
  return blank;
}
function addRowSameCustomer(srcRow){
  /* clone customer/phone/address/zone/courier from an existing row into a new blank line,
     so a customer buying several products becomes several linked rows (one delivery). */
  var src = srcRow || (function(){ var sel=hot.getSelectedLast?hot.getSelectedLast():null;
     if(sel){ var pr=hot.toPhysicalRow?hot.toPhysicalRow(sel[0]):sel[0]; return VIEW[pr]; } return null; })();
  if(!src){ addBlankRow(); return; }
  var blank=makeBlankRow();
  blank.customer=src.customer||''; blank.phone=src.phone||''; blank.address=src.address||'';
  blank.zone=src.zone||blank.zone; blank.courier_name=src.courier_name||'';
  blank.sales_person_name=src.sales_person_name||'';
  blank.order_date=src.order_date||blank.order_date;
  blank.payment_type=src.payment_type||blank.payment_type;
  blank.status=src.status||blank.status;
  /* link them: reuse the source group, or start a new one seeded from the source row's code */
  blank._group = src._group || ('G'+(src._id||Date.now()));
  if(!src._group){ src._group=blank._group; }   /* tag the original too so both show the 🔗 link */
  DATA.push(blank);
  CURFILTER='all'; refreshView(); updateChips(); updateRowCount();
  var last=newRowIndex();
  hot.selectCell(last, colIndex('product_name'));  /* jump straight to Product on the new line */
  hot.scrollViewportTo(last,0);
}
function colIndex(prop){ var cols=visibleCols(); for(var i=0;i<cols.length;i++){ if(cols[i].data===prop) return i; } return 4; }
function addBlankRow(){
  var blank=makeBlankRow();
  DATA.push(blank);
  CURFILTER='all'; refreshView(); updateChips();
  updateRowCount();
  var last=VIEW.length-1;
  hot.selectCell(last,colIndex('customer'));   // focus Customer on the new row
  hot.scrollViewportTo(last,0);
}

/* ===== bulk actions ===== */
function selRows(){return DATA.filter(function(o){return o._sel&&o._id;});}
function openPhoneSearch(){document.getElementById('mpBg').style.display='flex';document.getElementById('mpInput').focus();}
function closePhoneSearch(){document.getElementById('mpBg').style.display='none';}
function normPhone(p){return String(p||'').replace(/[^0-9]/g,'').replace(/^977/,'').replace(/^0+/,'');}
function runPhoneSearch(){
  var raw=document.getElementById('mpInput').value;
  var wanted=raw.split(/[\s,;]+/).map(normPhone).filter(function(x){return x.length>=6;});
  var uniq={}; wanted.forEach(function(p){uniq[p]=1;}); wanted=Object.keys(uniq);
  if(!wanted.length){alert('Paste at least one phone number.');return;}
  // clear current selection, then tick every order whose phone matches
  DATA.forEach(function(o){o._sel=false;});
  var found=0, hitset={}, matchedRows=[];
  wanted.forEach(function(w){
    var hit=false;
    DATA.forEach(function(o){
      if(!o._id)return;
      var op=normPhone(o.phone);
      if(op && (op===w || op.indexOf(w)>-1 || w.indexOf(op)>-1)){o._sel=true;hit=true;found++;matchedRows.push(o);}
    });
    hitset[w]=hit;
  });
  var missing=wanted.filter(function(w){return !hitset[w];});
  // show which matched / missed
  var res=document.getElementById('mpResult');
  var html='<div class="mp-stat"><b>'+found+'</b> order(s) matched from <b>'+wanted.length+'</b> number(s).</div>';
  if(missing.length){html+='<div class="mp-miss">⚠ No order found for: '+missing.map(function(m){return '<span>'+m+'</span>';}).join(' ')+'</div>';}
  res.innerHTML=html; res.style.display='block';
  document.getElementById('mpSummary').textContent=found+' selected';
  hot.render(); updateBulkBar();
  if(found){
    // reflect selection in the sheet: clear any search filter so all ticked rows are visible
    var sb=document.getElementById('sheetSearch'); if(sb){sb.value='';}
    hot.loadData(DATA);
    document.getElementById('rowCount').textContent=DATA.length+' rows';
    hot.render();
  }
}
function updateBulkBar(){var n=selRows().length,b=document.getElementById('bulkBar');
  if(b){b.style.display=n?'flex':'none';var el=document.getElementById('bulkN');if(el)el.textContent=n;}}
function clearSel(){DATA.forEach(function(o){o._sel=false;});hot.render();updateBulkBar();}
function markSel(status){
  var list=selRows(); if(!list.length)return;
  if(!confirm('Mark '+list.length+' order(s) as '+status+'?'))return;
  var i=0;
  (function next(){
    if(i>=list.length){clearSel();updateStats();hot.render();return;}
    var row=list[i++]; row.status=status;
    if(status==='delivered')row.payment_status='Paid';
    recalc(row);
    post(payload(row),function(err,res){
      if(!err&&res&&res.order){row.revenue=parseFloat(res.order.revenue)||0;row.profit=parseFloat(res.order.profit)||0;row.cost_price=parseFloat(res.order.cost_price)||0;}
      next();
    });
  })();
}
function labelsSel(){var ids=selRows().map(function(o){return o._id;});
  if(ids.length)window.open('labels.php?ids='+ids.join(','),'_blank');}

/* ===== column show/hide ===== */
(function(){
  var panel=document.getElementById('colPanel'), btn=document.getElementById('colBtn');
  Object.keys(HIDEABLE).forEach(function(k){
    var lab=document.createElement('label');
    lab.innerHTML='<input type="checkbox" '+(hiddenCols[k]?'':'checked')+'> '+HIDEABLE[k];
    lab.querySelector('input').onchange=function(){
      if(this.checked)delete hiddenCols[k]; else hiddenCols[k]=1;
      try{localStorage.setItem('sales_hidden_cols',JSON.stringify(hiddenCols));}catch(e){}
      hot.updateSettings({columns:visibleCols(),colHeaders:visibleCols().map(function(c){return c.title;})});
    };
    panel.appendChild(lab);
  });
  btn.onclick=function(e){e.stopPropagation();panel.classList.toggle('open');};
  document.addEventListener('click',function(e){if(!panel.contains(e.target))panel.classList.remove('open');});
})();

/* ===== paste import ===== */
function openImport(){document.getElementById('impText').value='';document.getElementById('impStatus').textContent='';var _m=document.getElementById('impMap');if(_m)_m.innerHTML='';document.getElementById('importModal').classList.add('open');document.body.classList.add('modal-open');}
function closeImport(){document.getElementById('importModal').classList.remove('open');document.body.classList.remove('modal-open');}
var IMP_SYN={
  customer:['customer','customer name','name','client','buyer','ग्राहक','ग्राहकको नाम'],
  phone:['phone','phone number','mobile','mobile number','number','contact','contact number','tel','फोन','मोबाइल'],
  address:['address','location','city','place','area','tole','ठेगाना'],
  sales_person:['sales person','salesperson','sales rep','sales agent','sales staff','agent','seller','sold by','rep'],
  product:['product','product name','item','item name','goods','सामान'],
  qty:['qty','quantity','pcs','piece','pieces','count','nos','no','परिमाण'],
  price:['price','rate','amount','sell price','selling price','unit price','मूल्य','दर'],
  delivery:['delivery','delivery charge','shipping','shipping charge','charge','freight','ढुवानी'],
  zone:['zone','area type','region','inside','outside',' इलाका']
};
var IMP_DEF={customer:0,phone:1,address:2,product:3,qty:4,price:5,delivery:6};
function impSplit(line){ return line.indexOf('\t')>-1?line.split('\t'):line.split(','); }
function impNorm(s){ return String(s==null?'':s).trim().toLowerCase().replace(/[._\-#*:()\[\]]/g,' ').replace(/\s+/g,' ').trim(); }
function impHeaderMap(fields){
  var map={},hits=0;
  fields.forEach(function(f,i){
    var n=impNorm(f); if(!n)return;
    for(var k in IMP_SYN){
      if(map[k]===undefined && IMP_SYN[k].indexOf(n)>-1){ map[k]=i; hits++; return; }
    }
  });
  return hits>=2 ? map : null;      /* need 2+ recognised headers to trust it */
}
/* infer which column is which from the DATA itself (no header row needed):
   - phone  = the column that looks like a 9/10-digit Nepali mobile
   - product= the column whose value matches a known product name (or is closest to one)
   - zone   = a column that is exactly Inside/Outside
   - qty    = a small integer (1–99) that isn't the phone
   - price  = the largest number that isn't the phone
   - customer/address = the remaining text columns (name before address) */
function impDetect(rows){
  if(!rows.length) return null;
  var W=Math.max.apply(null,rows.map(function(r){return r.length;}));
  if(W<3) return null;
  var prodNamesLC=(window.prodNames||[]).map(function(n){return n.toLowerCase();});
  var spNamesLC=(window.spNames||[]).map(function(n){return n.toLowerCase();});
  function isPhone(v){var d=String(v).replace(/[^0-9]/g,'');return d.length>=9&&d.length<=13;}
  function isZone(v){var t=String(v).trim().toLowerCase();return t==='inside'||t==='outside'||t==='valley'||t==='kathmandu';}
  function isProd(v){var t=String(v).trim().toLowerCase();if(!t)return false;
    if(prodNamesLC.indexOf(t)>-1)return true;
    return prodNamesLC.some(function(pn){return pn.indexOf(t)>-1||t.indexOf(pn)>-1;});}
  function isSP(v){var t=String(v).trim().toLowerCase();if(!t||!spNamesLC.length)return false;
    if(spNamesLC.indexOf(t)>-1)return true;
    return spNamesLC.some(function(sn){return sn.indexOf(t)>-1||t.indexOf(sn)>-1;});}
  function num(v){var n=parseFloat(String(v).replace(/[^0-9.]/g,''));return isNaN(n)?null:n;}
  // score each column across all rows
  var score=[];
  for(var c=0;c<W;c++){score[c]={phone:0,zone:0,prod:0,sp:0,intSmall:0,numBig:0,text:0,total:0};}
  rows.forEach(function(r){
    for(var c=0;c<W;c++){var v=r[c]; if(v===undefined||v==='')continue; score[c].total++;
      if(isPhone(v))score[c].phone++;
      else if(isZone(v))score[c].zone++;
      else if(isProd(v))score[c].prod++;
      else if(isSP(v))score[c].sp++;
      var n=num(v);
      if(n!==null && !isPhone(v)){ if(n>0&&n<=99&&String(v).indexOf('.')===-1)score[c].intSmall++; if(n>=100)score[c].numBig++; }
      if(n===null)score[c].text++;
    }
  });
  function pick(metric,exclude){var best=-1,bi=-1;for(var c=0;c<W;c++){if(exclude.indexOf(c)>-1)continue;
    if(score[c][metric]>best){best=score[c][metric];bi=c;}}return best>0?bi:-1;}
  var used=[],map={};
  var pc=pick('phone',used); if(pc>-1){map.phone=pc;used.push(pc);}
  var zc=pick('zone',used);  if(zc>-1){map.zone=zc;used.push(zc);}
  var prc=pick('prod',used); if(prc>-1){map.product=prc;used.push(prc);}
  var spc=pick('sp',used);   if(spc>-1 && score[spc].sp>=score[spc].total*0.5){map.sales_person=spc;used.push(spc);}
  var qc=pick('intSmall',used); if(qc>-1){map.qty=qc;used.push(qc);}
  var prc2=pick('numBig',used); if(prc2>-1){map.price=prc2;used.push(prc2);}
  // remaining text columns → customer first, then address (by position)
  var textCols=[];for(var c=0;c<W;c++){if(used.indexOf(c)>-1)continue;if(score[c].text>=score[c].total*0.5)textCols.push(c);}
  textCols.sort(function(a,b){return a-b;});
  if(textCols.length){map.customer=textCols[0];used.push(textCols[0]);}
  if(textCols.length>1){map.address=textCols[1];used.push(textCols[1]);}
  // need at least product + phone (or customer) to trust the detection
  return (map.product!==undefined && (map.phone!==undefined||map.customer!==undefined)) ? map : null;
}
function parseImport(text){
  var lines=String(text||'').split(/\r?\n/).filter(function(l){return l.trim()!=='';});
  var map=IMP_DEF, usedHeader=false, detected=false;
  if(lines.length){
    var hm=impHeaderMap(impSplit(lines[0]).map(function(x){return String(x).trim();}));
    if(hm){ map=hm; usedHeader=true; lines=lines.slice(1); }
    else {
      var sample=lines.slice(0,Math.min(8,lines.length)).map(function(l){return impSplit(l).map(function(x){return String(x).trim();});});
      var dm=impDetect(sample);
      if(dm){ map=dm; detected=true; }
    }
  }
  var out=[];
  lines.forEach(function(line){
    var f=impSplit(line).map(function(x){return String(x).trim();});
    function g(k){ var i=map[k]; return (i===undefined||i<0)?'':(f[i]||''); }
    var rec={customer:g('customer'),phone:g('phone'),address:g('address'),product:g('product'),
             sales_person:g('sales_person'),
             qty:parseInt(g('qty'))||1,price:parseFloat(g('price'))||0,delivery:parseFloat(g('delivery'))||0,
             zone:(function(){var z=String(g('zone')||'').trim().toLowerCase();return z==='outside'?'Outside':(z==='inside'?'Inside':'');})()};
    if(!rec.customer&&!rec.product)return;
    out.push(rec);
  });
  out._map=map; out._header=usedHeader; out._detected=detected;
  return out;
}
function impPreview(){
  var txt=document.getElementById('impText').value;
  var box=document.getElementById('impMap');
  if(!txt.trim()){ box.innerHTML=''; return; }
  var recs=parseImport(txt), m=recs._map;
  var order=Object.keys(m).sort(function(a,b){return m[a]-m[b];});
  var chips=order.map(function(k){return '<span class="impchip">'+(m[k]+1)+'. '+k+'</span>';}).join('');
  var first=recs[0];
  box.innerHTML='<div style="margin-bottom:5px">'+(recs._header
      ? '✅ <b>Header row detected</b> — using your column order:'
      : (recs._detected
          ? '✅ <b>Columns auto-detected</b> from your data:'
          : 'ℹ️ No header — using default order:'))+'</div>'+chips+
    '<div style="margin-top:6px">'+recs.length+' order(s) ready'+(first?
      ' · first: <b>'+esc4(first.customer||'—')+'</b> · '+esc4(first.product||'—')+' ×'+first.qty+
      (first.price?' @ '+first.price:'')+(first.phone?' · '+esc4(first.phone):'')+
      (first.sales_person?' · sold by '+esc4(first.sales_person):''):'')+'</div>';
}
function esc4(t){var d=document.createElement('div');d.textContent=t==null?'':t;return d.innerHTML;}
function runImport(){
  var recs=parseImport(document.getElementById('impText').value);
  var st=document.getElementById('impStatus');
  if(!recs.length){st.textContent='Nothing to import — paste at least one line.';return;}
  var hdr=recs._header?1:0;
  /* ---- validate BEFORE sending: catch product/customer problems with clear reasons ---- */
  var problems=[];
  recs.forEach(function(r,idx){
    var lineNo=idx+1+hdr;
    if(!r.customer){problems.push('Line '+lineNo+': customer name missing');return;}
    if(!r.product){problems.push('Line '+lineNo+': product missing');return;}
    if(!prodByName(r.product)){
      var sug=prodSuggest(r.product);
      problems.push('Line '+lineNo+': product "'+r.product+'" not found'+(sug?' — did you mean "'+sug+'"?':' (check spelling on the Products page)'));
    }
  });
  if(problems.length){
    st.innerHTML='<span style="color:var(--red)">⚠ Nothing imported — fix these first:</span><br>'+
      problems.slice(0,8).map(esc4).join('<br>')+(problems.length>8?'<br>…and '+(problems.length-8)+' more':'');
    return;
  }
  var i=0, ok=0, fail=0, errs=[];
  document.getElementById('impRunBtn').disabled=true;
  (function next(){
    if(i>=recs.length){
      st.innerHTML='Done: <b>'+ok+' created</b>'+(fail?(' · <span style="color:var(--red)">'+fail+' failed</span><br>'+errs.slice(0,5).map(esc4).join('<br>')):'.');
      document.getElementById('impRunBtn').disabled=false;
      CURFILTER='all'; refreshView(); updateStats();
      if(ok&&!fail)setTimeout(closeImport,1200);
      return;
    }
    var r=recs[i++], lineNo=i+hdr; st.textContent='Importing '+i+' / '+recs.length+'…';
    var p=prodByName(r.product), sp=spByName(r.sales_person);
    var body={op:'create',order_date:TODAY,customer:r.customer,phone:r.phone,address:r.address,
      sales_person_id:sp?sp.id:'',
      product_id:p?p.id:'',qty:r.qty,sell_price:r.price||(p?p.price:0),delivery_charge:r.delivery,
      zone:(r.zone||DEF_ZONE),payment_type:DEF_PAY_UI,status:DEF_STATUS,remarks:'imported'};
    post(body,function(err,res){
      if(err){fail++;errs.push('Line '+lineNo+': '+err);}
      else if(res&&res.order){ok++;DATA.push(toRow(res.order));}
      next();
    });
  })();
}
document.getElementById('importBtn').onclick=openImport;

/* ===== bulk-assign every unassigned order to Luprah (company sales) ===== */
function assignUnassignedToLuprah(){
  var n=DATA.filter(function(o){return o._id && !(String(o.sales_person_name||'').trim());}).length;
  if(!n){alert('No unassigned orders found — everything already has a sales person.');return;}
  if(!confirm('Assign all '+n+' unassigned order(s) to Luprah (company sales)?'))return;
  var btn=document.getElementById('assignLuprahBtn'); if(btn){btn.disabled=true;btn.textContent='Assigning…';}
  post({op:'assign_unassigned_to_company'},function(err,res){
    if(btn){btn.disabled=false;btn.textContent='🏢 Unassigned → Luprah';}
    if(err){alert('Could not assign: '+err);return;}
    DATA.forEach(function(o){ if(o._id && !(String(o.sales_person_name||'').trim())) o.sales_person_name='Luprah'; });
    refreshView(); updateStats();
    alert('Assigned '+(res.updated!=null?res.updated:n)+' order(s) to Luprah.');
  });
}
document.getElementById('assignLuprahBtn').onclick=assignUnassignedToLuprah;

document.getElementById('addRowBtn').onclick=addBlankRow;
var _ab2=document.getElementById('addRowBtn2'); if(_ab2) _ab2.onclick=addBlankRow;

/* footer row counter + mirrored save indicator */
function updateRowCount(){ var el=document.getElementById('rowCount'); if(el) el.textContent=DATA.length+' rows'; var e2=document.getElementById('rowCount2'); if(e2) e2.textContent=DATA.length; }

/* delete */
var delIdx=null;
function openDel(i){delIdx=i;var r=VIEW[i];document.getElementById('delCode').textContent=r?r.code:'';document.getElementById('delModal').classList.add('open');}
function closeDel(){document.getElementById('delModal').classList.remove('open');delIdx=null;}
function doDelete(){
  if(delIdx===null)return;
  var row=VIEW[delIdx]; closeDel();
  if(!row)return;
  post({op:'delete',id:row._id},function(err){
    if(err){alert('Could not delete: '+err);return;}
    var di=DATA.indexOf(row); if(di>-1)DATA.splice(di,1);
    refreshView(); updateStats();
  });
}

/* search */
document.getElementById('sheetSearch').oninput=function(){
  var q=this.value.toLowerCase().trim();
  if(!q){hot.loadData(DATA);document.getElementById('rowCount').textContent=DATA.length+' rows';var _r2=document.getElementById('rowCount2');if(_r2)_r2.textContent=DATA.length;return;}
  var f=DATA.filter(function(r){return ['code','customer','phone','address','product_name','sales_person_name','status','courier_name','remarks'].some(function(k){return(r[k]||'').toLowerCase().indexOf(q)>-1;});});
  hot.loadData(f);
  document.getElementById('rowCount').textContent=f.length+' of '+DATA.length+' rows';var _r3=document.getElementById('rowCount2');if(_r3)_r3.textContent=f.length;
};
/* arrived here from a link like search.php's "Open Sales →" carrying ?q= — apply that
   filter immediately instead of making the person retype what they already searched */
if(document.getElementById('sheetSearch').value.trim()!==''){
  document.getElementById('sheetSearch').dispatchEvent(new Event('input'));
}

/* CSV export */
document.getElementById('csvBtn').onclick=function(){
  var heads=['Order','Sales Person','Product','Date','Customer','Phone','Address','Zone','Quantity','Price','Delivery','Cancel Charge','Revenue','Payment Type','Status','Payment Received','Courier','Profit'];
  var keys=['code','sales_person_name','product_name','order_date','customer','phone','address','zone','qty','sell_price','delivery_charge','cancel_charge','revenue','payment_type','status','payment_status','courier_name','profit'];
  var lines=[heads.join(',')];
  DATA.forEach(function(r){lines.push(keys.map(function(k){var v=(r[k]==null?'':String(r[k]));return '"'+v.replace(/"/g,'""')+'"';}).join(','));});
  var blob=new Blob([lines.join('\n')],{type:'text/csv'});
  var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='sales_export.csv';a.click();
};
</script>

<?php require __DIR__.'/includes/footer.php'; ?>