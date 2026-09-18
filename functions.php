<?php
require_once __DIR__ . '/auth.php';

function e($s)     { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n) { return CURRENCY . ' ' . number_format((float)$n, 0); }
function usd($npr) { $r=(float)setting('usd_rate', defined('USD_RATE')?USD_RATE:140); return '$'.number_format((float)$npr/max(1,$r), 2); }
function slug($s)  { return ucwords(str_replace('_', ' ', (string)$s)); }

/* status colours: delivered=green, shipped=yellow, processing/pending=neutral, cancelled/returned=red */
function status_class($s) {
  $s = strtolower((string)$s);
  if (in_array($s, ['delivered','paid','active','completed'], true)) return 'p-green';
  if ($s === 'shipped') return 'p-yellow';
  if ($s === 'confirmed') return 'p-blue';
  if (in_array($s, ['cancelled','returned','inactive','unpaid','low','overdue','expense'], true)) return 'p-red';
  if ($s === 'income') return 'p-green';
  return 'p-grey';
}
function pill($s) { return '<span class="pill ' . status_class($s) . '">' . e(slug($s)) . '</span>'; }

/* order calculations — revenue is PRODUCT income only; delivery is separate */
function order_revenue($o) {
  $st = $o['status'] ?? '';
  return $st === 'delivered' ? ((float)($o['sell_price'] ?? 0) * (int)($o['qty'] ?? 0)) : 0;
}
function order_profit($o) {
  $st = $o['status'] ?? '';
  if ($st === 'delivered') {
    $gross = ((float)($o['sell_price'] ?? 0) - (float)($o['cost_price'] ?? 0)) * (int)($o['qty'] ?? 0);
    return $gross - (float)($o['delivery_charge'] ?? 0);   // net of delivery charge
  }
  if (in_array($st, ['cancelled','returned'], true)) return -1 * (float)($o['cancel_charge'] ?? 0);
  return 0;
}

function nav_items() {
  return [
    ['index.php','🏠','Dashboard'],
    ['sales.php','🛒','Sales'],
    ['grp','delivery','🚚','Delivery', [
      ['ncm.php','📮','NCM Courier'],
      ['ncm_command.php','🚚','NCM Command Center'],
      ['hungryhunter.php','🛵','Hungry Hunter'],
      ['dropex.php','🚴','Dropex'],
      ['daraz.php','🛍','Daraz'],
      ['couriers.php','🚚','All Couriers'],
    ]],
    ['grp','money','💰','Money', [
      ['money_dashboard.php','💰','Money Dashboard'],
      ['cod.php','💵','COD Ledger'],
      ['payees.php','📣','Ads & Vendors'],
      ['banks.php','🏦','Bank Accounts'],
      ['expenses.php','💸','Expenses'],
    ]],
    ['grp','analytics','📈','Analytics', [
      ['analytics.php','📈','Overview'],
      ['analytics_report.php','📋','Daily & Monthly Report'],
      ['analytics_returns.php','↩️','Returns & Cancellations'],
      ['analytics_customers.php','👥','Customer Insights'],
      ['analytics_branches.php','🚚','Branch Performance'],
    ]],
    ['grp','inventory','📦','Inventory', [
      ['products.php','🏷️','Products'],
      ['purchases.php','🛍️','Purchasing'],
      ['vendor_purchases.php','🏭','Vendor Purchases'],
    ]],
    ['grp','people','👥','People', [
      ['customers.php','👥','Customers'],
      ['salary.php','💵','Staff & Salary'],
      ['users.php','👤','Users'],
    ]],
    ['grp','system','⚙️','System', [
      ['settings.php','⚙️','Settings'],
      ['activity.php','📜','Activity Logs'],
    ]],
  ];
}

/* ===================================================================
   GENERIC CRUD ENGINE
   =================================================================== */
function entities() {
  return [
   'products' => ['table'=>'products','title'=>'Product','order'=>'name',
     'list'=>['name'=>['Product'],'sku'=>['SKU'],'category'=>['Category'],'cost'=>['Cost','money'],
              'price'=>['Price','money'],'stock'=>['Stock'],'sold'=>['Sold']],
     'fields'=>[['name','Product Name','text',null,true],['sku','SKU','text'],
       ['category','Category','combo',['Electronics','Apparel','Footwear','Accessories','Home and Kitchen','Beauty','Toys','Sports']],
       ['cost','Cost (Rs.)','number'],['price','Sell Price (Rs.)','number'],
       ['stock','Stock','number'],['low_stock','Reorder At','number'],['supplier','Supplier','text']]],
   'customers' => ['table'=>'customers','title'=>'Customer','order'=>'name',
     'list'=>['name'=>['Customer'],'phone'=>['Phone'],'city'=>['City'],'address'=>['Address'],'status'=>['Status','pill']],
     'fields'=>[['name','Full Name','text',null,true],['phone','Phone','text'],['email','Email','text'],
       ['city','City','text'],['address','Address','text'],['status','Status','select',['active','inactive']]]],
   'suppliers' => ['table'=>'suppliers','title'=>'Supplier','order'=>'name',
     'list'=>['name'=>['Supplier'],'contact'=>['Contact'],'city'=>['City'],'category'=>['Category'],
              'balance'=>['Payable','money'],'status'=>['Status','pill']],
     'fields'=>[['name','Supplier Name','text',null,true],['contact','Contact Person','text'],['city','City','text'],
       ['category','Category','combo',['Electronics','Apparel','Footwear','Accessories','Home and Kitchen','Beauty','Toys','Sports']],
       ['balance','Payable (Rs.)','number'],['status','Status','select',['active','inactive']]]],
   'purchases' => ['table'=>'purchases','title'=>'Purchase','order'=>'purchase_date DESC','code'=>'PUR','code_datewise'=>'purchase_date',
     'list'=>['code'=>['PO ID'],'supplier'=>['Supplier'],'products'=>['Products'],'purchase_date'=>['Date'],'items'=>['Items'],
              'amount'=>['Amount','money'],'status'=>['Status','pill']],
     'fields'=>[['supplier','Supplier','text',null,true],
       ['products','Products (e.g. Hairline ×100, VitiGO ×50)','text'],
       ['purchase_date','Date','date'],
       ['items','# Items','number'],['amount','Amount (Rs.)','number'],
       ['status','Status','select',['pending','confirmed','completed']]]],
   'employees' => ['table'=>'employees','title'=>'Employee','order'=>'name',
     'list'=>['name'=>['Employee'],'role'=>['Role'],'department'=>['Dept'],'salary'=>['Salary','money'],
              'joined_date'=>['Joined'],'status'=>['Status','pill']],
     'fields'=>[['name','Name','text',null,true],['role','Job Title','text'],
       ['department','Department','select',['Sales','Finance','HR','Inventory','Logistics','Management']],
       ['salary','Salary (Rs.)','number'],['joined_date','Joined','date'],
       ['status','Status','select',['active','on_leave','inactive']]]],
   'ledger' => ['table'=>'ledger','title'=>'Transaction','order'=>'txn_date DESC',
     'list'=>['txn_date'=>['Date'],'type'=>['Type','pill'],'category'=>['Category'],'description'=>['Description'],'amount'=>['Amount','money']],
     'fields'=>[['txn_date','Date','date'],['type','Type','select',['income','expense']],
       ['category','Category','text'],['description','Description','text',null,true],['amount','Amount (Rs.)','number']]],
  ];
}
function entity_def($name) { $e = entities(); return $e[$name] ?? null; }

function handle_crud($name) {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
  $def = entity_def($name); if (!$def) return;
  check_csrf();
  $action = $_POST['_action'] ?? '';
  $table = $def['table'];
  try {
    if ($action === 'delete') {
      q("DELETE FROM `$table` WHERE id=?", [(int)$_POST['id']]);
      log_activity('Deleted ' . $def['title'] . ' #' . (int)$_POST['id'], ucfirst($name));
      flash($def['title'] . ' deleted.');
    } else {
      $cols = []; $vals = [];
      foreach ($def['fields'] as $f) { $k = $f[0]; $v = $_POST[$k] ?? ''; if (($f[2] ?? '') === 'number') $v = (float)$v; $cols[] = $k; $vals[] = $v; }
      if ($action === 'update') {
        $set = implode(',', array_map(fn($c) => "`$c`=?", $cols));
        $vals[] = (int)$_POST['id'];
        q("UPDATE `$table` SET $set WHERE id=?", $vals);
        log_activity('Updated ' . $def['title'] . ' #' . (int)$_POST['id'], ucfirst($name));
        flash($def['title'] . ' updated.');
      } else {
        if (!empty($def['code'])) {
          if (!empty($def['code_datewise'])) {
            /* date-embedded PO id: PUR-20260730-01, -02, ... resets each day —
               readable at a glance, and groups naturally with the date-sorted list */
            $dcol = $def['code_datewise'];
            $dval = trim((string)($_POST[$dcol] ?? '')) ?: date('Y-m-d');
            $seq  = (int)val("SELECT COUNT(*)+1 FROM `$table` WHERE `$dcol`=?", [$dval]);
            $codeVal = $def['code'].'-'.str_replace('-','',$dval).'-'.str_pad((string)$seq,2,'0',STR_PAD_LEFT);
          } else {
            $n = (int)val("SELECT COALESCE(MAX(id),2000) FROM `$table`") + 1;
            $codeVal = $def['code'] . '-' . $n;
          }
          array_unshift($cols, 'code'); array_unshift($vals, $codeVal);
        }
        $ph = implode(',', array_fill(0, count($cols), '?'));
        q("INSERT INTO `$table`(`" . implode('`,`', $cols) . "`) VALUES($ph)", $vals);
        log_activity('Added ' . $def['title'], ucfirst($name));
        flash($def['title'] . ' added.');
      }
    }
  } catch (Exception $ex) { flash('Error: ' . $ex->getMessage()); }
  header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

function render_field($f, $rec, $combo = []) {
  $k = $f[0]; $l = $f[1]; $type = $f[2] ?? 'text'; $opts = $f[3] ?? null; $full = $f[4] ?? false;
  $val = $rec[$k] ?? '';
  $h = '<div class="' . ($full ? 'full' : '') . '"><label>' . e($l) . '</label>';
  if ($type === 'select') {
    $h .= '<select name="' . $k . '">';
    foreach ($opts as $o) $h .= '<option' . ($o == $val ? ' selected' : '') . '>' . e($o) . '</option>';
    $h .= '</select>';
  } elseif ($type === 'combo') {
    $listId = $k . '_dl';
    $sugg = $combo[$k] ?? ($opts ?: []);
    $h .= '<input name="' . $k . '" value="' . e($val) . '" list="' . $listId . '" autocomplete="off" placeholder="Type a new one or pick…">';
    $h .= '<datalist id="' . $listId . '">';
    foreach ($sugg as $o) $h .= '<option value="' . e($o) . '">';
    $h .= '</datalist>';
  } else {
    $it = $type === 'number' ? 'number' : ($type === 'date' ? 'date' : 'text');
    $h .= '<input name="' . $k . '" type="' . $it . '" value="' . e($val) . '"' . ($type === 'number' ? ' step="any"' : '') . '>';
  }
  return $h . '</div>';
}

function render_crud($name, $pageTitle, $subtitle, $embed=false) {
  /* self-contained modal JS (guarded — safe if emitted twice or if app.js also defines it) */
  echo '<script>if(!window.crudOpen){window.crudOpen=function(n){var m=document.getElementById("mbg_"+n);if(!m)return;document.getElementById("mAction_"+n).value="add";document.getElementById("mId_"+n).value="";document.getElementById("mTitle_"+n).textContent="Add";m.querySelectorAll("[name]").forEach(function(el){if(["csrf","_action","_entity","id"].indexOf(el.name)<0){if(el.tagName==="SELECT")el.selectedIndex=0;else el.value="";}});m.classList.add("open");document.body.classList.add("modal-open");};window.crudEdit=function(n,btn){var m=document.getElementById("mbg_"+n);if(!m)return;var r=JSON.parse(btn.getAttribute("data-rec"));document.getElementById("mAction_"+n).value="update";document.getElementById("mId_"+n).value=r.id;document.getElementById("mTitle_"+n).textContent="Edit";m.querySelectorAll("[name]").forEach(function(el){if(r[el.name]!==undefined&&r[el.name]!==null)el.value=r[el.name];});m.classList.add("open");document.body.classList.add("modal-open");};window.crudClose=function(n){var m=document.getElementById("mbg_"+n);if(m)m.classList.remove("open");document.body.classList.remove("modal-open");};}</script>';
  $def = entity_def($name);
  $rs = rows("SELECT * FROM `{$def['table']}` ORDER BY " . $def['order']);
  if ($embed) {
    echo '<div class="page-head" style="margin-top:26px"><div><h2 style="font-size:17px">' . $pageTitle . '</h2><p>' . e($subtitle) . '</p></div>';
  } else {
    echo '<div class="page-head"><div><h1>' . e($pageTitle) . '</h1><p>' . e($subtitle) . '</p></div>';
  }
  echo '<button class="btn btn-primary" onclick="crudOpen(\'' . $name . '\')">+ Add ' . e($def['title']) . '</button></div>';
  if ($f = flash()) echo '<div class="flash">' . e($f) . '</div>';
  echo '<div class="card"><div class="toolbar"><input class="search-in" placeholder="Search…" onkeyup="filterTable(this)"></div><div class="table-wrap"><table class="tbl" id="tbl_' . $name . '"><thead><tr>';
  foreach ($def['list'] as $col) echo '<th>' . e($col[0]) . '</th>';
  echo '<th></th></tr></thead><tbody>';
  if (!$rs) echo '<tr><td colspan="' . (count($def['list']) + 1) . '"><div class="empty">No records yet — click “Add”.</div></td></tr>';
  foreach ($rs as $r) {
    echo '<tr>';
    foreach ($def['list'] as $key => $col) {
      $fmt = $col[1] ?? null; $v = $r[$key];
      $v = $fmt === 'money' ? money($v) : ($fmt === 'pill' ? pill($v) : e($v));
      echo '<td>' . $v . '</td>';
    }
    echo '<td class="right nowrap">';
    echo '<button class="iact" data-rec="' . e(json_encode($r)) . '" onclick="crudEdit(\''.$name.'\',this)" title="Edit">✏️</button>';
    echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this ' . strtolower($def['title']) . '?\')">';
    echo '<input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<button class="iact del" title="Delete">🗑</button></form></td></tr>';
  }
  echo '</tbody></table></div></div>';
  echo '<div class="modal-bg" id="mbg_'.$name.'"><form class="modal" method="post"><div class="modal-head"><span id="mTitle_'.$name.'">Add ' . e($def['title']) . '</span><span class="mx" onclick="crudClose(\''.$name.'\')">✕</span></div>';
  echo '<input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="_action" id="mAction_'.$name.'" value="add"><input type="hidden" name="_entity" value="'.$name.'"><input type="hidden" name="id" id="mId_'.$name.'" value="">';
  echo '<div class="modal-body">';
  $combo = [];
  foreach ($def['fields'] as $f) {
    if (($f[2] ?? '') === 'combo') {
      $col = $f[0];
      try {
        $existing = array_column(rows("SELECT DISTINCT `$col` AS v FROM `{$def['table']}` WHERE `$col` IS NOT NULL AND `$col` <> '' ORDER BY `$col`"), 'v');
      } catch (Exception $e) { $existing = []; }
      $combo[$col] = array_values(array_unique(array_merge($f[3] ?? [], $existing)));
    }
  }
  foreach ($def['fields'] as $f) echo render_field($f, [], $combo);
  echo '</div><div class="modal-foot"><button type="button" class="btn" onclick="crudClose(\''.$name.'\')">Cancel</button><button class="btn btn-primary">Save</button></div></form></div>';
}

/* ============================================================
   Notifications
   ============================================================ */
function ensure_notifications() {
  static $done = false; if ($done) return; $done = true;
  try {
    q("CREATE TABLE IF NOT EXISTS notifications(
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(30) DEFAULT 'info',
        message VARCHAR(255) NOT NULL,
        link VARCHAR(120) DEFAULT '',
        is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
      )");
  } catch (Exception $e) {}
}
function notify($message, $type = 'info', $link = '', $dedupeHours = 12) {
  ensure_notifications();
  try {
    if ($dedupeHours > 0) {
      $dup = (int)val("SELECT COUNT(*) FROM notifications WHERE message=? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)", [$message, $dedupeHours]);
      if ($dup) return;
    }
    q("INSERT INTO notifications(type,message,link) VALUES(?,?,?)", [$type, $message, $link]);
  } catch (Exception $e) {}
}
function notifications_unread() { ensure_notifications(); try { return (int)val("SELECT COUNT(*) FROM notifications WHERE is_read=0"); } catch (Exception $e) { return 0; } }
function notifications_recent($limit = 12) { ensure_notifications(); try { return rows("SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_s FROM notifications ORDER BY id DESC LIMIT " . (int)$limit); } catch (Exception $e) { return []; } }

/* ============================================================
   Role-based menu permissions
   Super Admin / Admin = full access (rank 3)
   Manager = rank 2 · Staff/others = rank 1
   ============================================================ */
function role_rank($role) {
  $r = strtolower(trim((string)$role));
  if (in_array($r, ['super admin','superadmin','admin','owner'], true)) return 3;
  if ($r === 'manager') return 2;
  return 1;
}
function page_min_rank($page) {
  $map = [
    'users.php'=>3, 'settings.php'=>3, 'activity.php'=>3,
    'accounting.php'=>2, 'salary.php'=>2, 'cod.php'=>2, 'banks.php'=>2, 'daraz.php'=>2, 'expenses.php'=>2, 'reports.php'=>2, 'analytics.php'=>2, 'analytics_returns.php'=>2, 'analytics_customers.php'=>2, 'analytics_branches.php'=>2, 'analytics_report.php'=>2, 'suppliers.php'=>2, 'hrm.php'=>2, 'purchases.php'=>2, 'inventory.php'=>2, 'money_dashboard.php'=>2,
    'ncm_debug.php'=>3,
    'ncm.php'=>1, 'ncm_comments.php'=>1, 'sales.php'=>1, 'couriers.php'=>1,   /* staff can use the courier & sales pages */
  ];
  return $map[$page] ?? 1;
}
function ensure_user_pages() { static $ok=false; if($ok) return;
  try { q("CREATE TABLE IF NOT EXISTS user_pages(
    user_id INT NOT NULL, page VARCHAR(60) NOT NULL, allow TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY(user_id,page))"); } catch (Exception $e) {}
  $ok=true;
}
function user_page_overrides($uid) {
  static $cache=[];
  $uid=(int)$uid;
  if(!isset($cache[$uid])){
    ensure_user_pages();
    $cache[$uid]=[];
    try { foreach(rows("SELECT page,allow FROM user_pages WHERE user_id=?",[$uid]) as $r) $cache[$uid][$r['page']]=(int)$r['allow']; } catch (Exception $e) {}
  }
  return $cache[$uid];
}
function can_see($page) {
  $u = current_user();
  $rank = role_rank($u['role'] ?? '');
  if ($rank >= 3) return true;                          /* Super Admin: always everything */
  $ov = user_page_overrides($u['id'] ?? 0);
  if (isset($ov[$page])) return (bool)$ov[$page];       /* explicit per-user override */
  return $rank >= page_min_rank($page);                 /* role default */
}

/* ============================================================
   Stock helpers — deduct on delivery, restore on reversal
   ============================================================ */
function adjust_stock($productId, $delta) {
  $productId = (int)$productId; $delta = (int)$delta;
  if (!$productId || !$delta) return;
  try { q("UPDATE products SET stock = GREATEST(0, COALESCE(stock,0) + ?) WHERE id=?", [$delta, $productId]); } catch (Exception $e) {}
}
/* apply the stock effect of a status change for one order line */
function stock_on_status_change($productId, $qty, $oldStatus, $newStatus, $orderId = 0) {
  /* FIFO batches: consume oldest stock first; order cost becomes the real batch cost */
  fifo_status_change((int)$orderId, (int)$productId, (int)$qty, $oldStatus, $newStatus);
}

/* Render ONLY the add/edit modal for an entity (reuses app.js openForm/editRow) */
function render_crud_modal($name) {
  /* self-contained modal JS (guarded — safe if emitted twice or if app.js also defines it) */
  echo '<script>if(!window.crudOpen){window.crudOpen=function(n){var m=document.getElementById("mbg_"+n);if(!m)return;document.getElementById("mAction_"+n).value="add";document.getElementById("mId_"+n).value="";document.getElementById("mTitle_"+n).textContent="Add";m.querySelectorAll("[name]").forEach(function(el){if(["csrf","_action","_entity","id"].indexOf(el.name)<0){if(el.tagName==="SELECT")el.selectedIndex=0;else el.value="";}});m.classList.add("open");document.body.classList.add("modal-open");};window.crudEdit=function(n,btn){var m=document.getElementById("mbg_"+n);if(!m)return;var r=JSON.parse(btn.getAttribute("data-rec"));document.getElementById("mAction_"+n).value="update";document.getElementById("mId_"+n).value=r.id;document.getElementById("mTitle_"+n).textContent="Edit";m.querySelectorAll("[name]").forEach(function(el){if(r[el.name]!==undefined&&r[el.name]!==null)el.value=r[el.name];});m.classList.add("open");document.body.classList.add("modal-open");};window.crudClose=function(n){var m=document.getElementById("mbg_"+n);if(m)m.classList.remove("open");document.body.classList.remove("modal-open");};}</script>';
  $def = entity_def($name);
  echo '<div class="modal-bg" id="mbg_'.$name.'"><form class="modal" method="post"><div class="modal-head"><span id="mTitle_'.$name.'">Add ' . e($def['title']) . '</span><span class="mx" onclick="crudClose(\''.$name.'\')">✕</span></div>';
  echo '<input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="_action" id="mAction_'.$name.'" value="add"><input type="hidden" name="_entity" value="'.$name.'"><input type="hidden" name="id" id="mId_'.$name.'" value="">';
  echo '<div class="modal-body">';
  $combo = [];
  foreach ($def['fields'] as $f) {
    if (($f[2] ?? '') === 'combo') {
      $col = $f[0];
      try { $existing = array_column(rows("SELECT DISTINCT `$col` AS v FROM `{$def['table']}` WHERE `$col` IS NOT NULL AND `$col` <> '' ORDER BY `$col`"), 'v'); }
      catch (Exception $e) { $existing = []; }
      $combo[$col] = array_values(array_unique(array_merge($f[3] ?? [], $existing)));
    }
  }
  foreach ($def['fields'] as $f) echo render_field($f, [], $combo);
  echo '</div><div class="modal-foot"><button type="button" class="btn" onclick="crudClose(\''.$name.'\')">Cancel</button><button class="btn btn-primary">Save</button></div></form></div>';
}

/* enforce page access server-side (menus hide; this blocks direct URLs) */
function require_page_access() {
  $page = basename($_SERVER['PHP_SELF']);
  if (!can_see($page)) { http_response_code(403); die('Access denied for your role.'); }
}

if(!function_exists('wa_phone')){ function wa_phone($p){ $ph=preg_replace('/[^0-9]/','',(string)$p); $ph=ltrim($ph,'0'); if(strpos($ph,'977')!==0)$ph='977'.$ph; return $ph; } }