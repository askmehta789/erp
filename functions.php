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

/* Delivery-partner pages that can be individually hidden from the sidebar
   (Settings → Delivery Partners) when a business stops using that courier —
   without deleting any of its historical data. 'couriers.php' (the generic
   courier manager) is intentionally never toggle-able here. */
function delivery_partner_pages() {
  return [
    'ncm.php'         => ['📮','NCM Courier'],
    'ncm_command.php' => ['🚚','NCM Command Center'],
    'hungryhunter.php'=> ['🛵','Hungry Hunter'],
    'dropex.php'      => ['🚴','Dropex'],
    'daraz.php'       => ['🛍','Daraz'],
  ];
}
function disabled_delivery_pages() {
  $raw = (string)setting('disabled_delivery_pages','');
  return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($p)=>$p!==''));
}
function delivery_page_enabled($page) {
  return !in_array($page, disabled_delivery_pages(), true);
}
function delivery_disabled_banner($page) {
  if (delivery_page_enabled($page)) return '';
  $label = delivery_partner_pages()[$page][1] ?? $page;
  return '<div class="flash" style="background:var(--amber-bg,#fef3c7);color:#8a5a00">'
    .'⚠️ <b>'.e($label).'</b> is hidden from your menu because it\'s marked disabled in '
    .'<a href="settings.php#delivery-partners" style="color:#8a5a00;font-weight:800;text-decoration:underline">Settings → Delivery Partners</a>'
    .' — you\'re only seeing this page because you came here directly. Data below still works normally.</div>';
}

function nav_items() {
  $deliveryKids = [];
  foreach (delivery_partner_pages() as $page=>[$icon,$label]) {
    if (delivery_page_enabled($page)) $deliveryKids[] = [$page,$icon,$label];
  }
  $deliveryKids[] = ['couriers.php','🚚','All Couriers'];
  return [
    ['index.php','🏠','Dashboard'],
    ['sales.php','🛒','Sales'],
    ['sales_dashboard.php','🏆','Sales Team'],
    ['assistant.php','🤖','AI Assistant'],
    ['grp','delivery','🚚','Delivery', $deliveryKids],
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
      ['analytics_branches.php','🚚','Branch Performance'],
    ]],
    ['grp','inventory','📦','Inventory', [
      ['products.php','🏷️','Products'],
      ['vendor_purchases.php','🏭','Vendor Purchases'],
    ]],
    ['grp','people','👥','People', [
      ['customers.php','👥','Customer Insights'],
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
       ['stock','Stock','number'],['low_stock','Reorder At','number'],['supplier','Supplier','text'],
       ['image','Product Image (PNG only)','image',null,true]]],
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
   'employees' => ['table'=>'employees','title'=>'Employee','order'=>'name',
     'list'=>['name'=>['Employee'],'role'=>['Role'],'department'=>['Dept'],'salary'=>['Salary','money'],
              'phone'=>['Phone'],'email'=>['Email'],'joined_date'=>['Joined'],'status'=>['Status','pill']],
     'fields'=>[['name','Name','text',null,true],['role','Job Title','text'],
       ['department','Department','select',['Sales','Finance','HR','Inventory','Logistics','Management']],
       ['salary','Salary (Rs.)','number'],['lunch_rate','Lunch Allowance (Rs/day)','number'],
       ['phone','Phone Number','text'],['office_phone','Office Assigned Phone','text'],
       ['email','Email','text'],['pan_number','PAN Number','text'],
       ['bank_name','Bank Name','text'],['bank_account','Bank Account Number','text'],
       ['address','Address','text',null,true],
       ['joined_date','Joined','date'],
       ['status','Status','select',['active','on_leave','inactive']]]],
   'ledger' => ['table'=>'ledger','title'=>'Transaction','order'=>'txn_date DESC',
     'list'=>['txn_date'=>['Date'],'type'=>['Type','pill'],'category'=>['Category'],'description'=>['Description'],'amount'=>['Amount','money']],
     'fields'=>[['txn_date','Date','date'],['type','Type','select',['income','expense']],
       ['category','Category','text'],['description','Description','text',null,true],['amount','Amount (Rs.)','number']]],
  ];
}
function entity_def($name) { $e = entities(); return $e[$name] ?? null; }

/* Handles an <input type="file" name="$fieldKey" accept="image/png"> upload for
   the generic CRUD engine. Validates the file is a REAL png (not just a
   renamed extension) via getimagesize(), stores it under uploads/$subdir/
   with a random name, and returns the relative path to save in the DB —
   or null if no new file was chosen (caller should then leave the column
   untouched on update, or NULL on insert). Throws on an invalid upload so
   handle_crud()'s existing catch block reports it via flash(). */
function handle_image_field_upload($fieldKey, $subdir) {
  if (empty($_FILES[$fieldKey]) || ($_FILES[$fieldKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
  $f = $_FILES[$fieldKey];
  if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('Image upload failed (error code ' . $f['error'] . ').');
  if ($f['size'] > 4 * 1024 * 1024) throw new Exception('Image must be 4MB or smaller.');
  $info = @getimagesize($f['tmp_name']);
  if (!$info || $info[2] !== IMAGETYPE_PNG) throw new Exception('Only PNG images are allowed for the product photo.');
  $dir = __DIR__ . '/uploads/' . $subdir;
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $rel = 'uploads/' . $subdir . '/' . bin2hex(random_bytes(8)) . '.png';
  if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/' . $rel)) throw new Exception('Could not save the uploaded image.');
  return $rel;
}

function handle_crud($name) {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
  $def = entity_def($name); if (!$def) return;
  check_csrf();
  $action = $_POST['_action'] ?? '';
  $table = $def['table'];
  try {
    if ($action === 'delete') {
      $old = row("SELECT * FROM `$table` WHERE id=?", [(int)$_POST['id']]);
      foreach ($def['fields'] as $f) {
        if (($f[2] ?? '') === 'image' && !empty($old[$f[0]])) { @unlink(__DIR__ . '/' . $old[$f[0]]); }
      }
      q("DELETE FROM `$table` WHERE id=?", [(int)$_POST['id']]);
      log_activity('Deleted ' . $def['title'] . ' #' . (int)$_POST['id'], ucfirst($name));
      flash($def['title'] . ' deleted.');
    } else {
      $cols = []; $vals = []; $imageCol = null; $oldImagePath = null;
      foreach ($def['fields'] as $f) if (($f[2] ?? '') === 'image') $imageCol = $f[0];
      if ($action === 'update' && $imageCol !== null) {
        $oldImagePath = (string)val("SELECT `$imageCol` FROM `$table` WHERE id=?", [(int)$_POST['id']]);
      }
      foreach ($def['fields'] as $f) {
        $k = $f[0]; $type = $f[2] ?? '';
        if ($type === 'image') continue;
        $v = $_POST[$k] ?? ''; if ($type === 'number') $v = (float)$v; $cols[] = $k; $vals[] = $v;
      }
      if ($imageCol !== null) {
        $uploaded = handle_image_field_upload($imageCol, $name);
        if ($uploaded !== null) {
          $cols[] = $imageCol; $vals[] = $uploaded;
          if ($oldImagePath && $oldImagePath !== $uploaded) @unlink(__DIR__ . '/' . $oldImagePath);
        }
      }
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
  } elseif ($type === 'image') {
    $h .= '<div class="img-field-wrap" style="display:flex;align-items:center;gap:12px">'
        . '<img id="preview_' . $k . '" src="' . e($val) . '" style="' . ($val ? '' : 'display:none;') . 'width:56px;height:56px;object-fit:contain;background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:4px">'
        . '<input name="' . $k . '" type="file" accept="image/png" onchange="var p=document.getElementById(\'preview_' . $k . '\');if(this.files&&this.files[0]){p.src=URL.createObjectURL(this.files[0]);p.style.display=\'\';}">'
        . '</div><span class="muted" style="font-size:11px">PNG only — shown fit-to-frame, never stretched or cropped.</span>';
  } else {
    $it = $type === 'number' ? 'number' : ($type === 'date' ? 'date' : 'text');
    $h .= '<input name="' . $k . '" type="' . $it . '" value="' . e($val) . '"' . ($type === 'number' ? ' step="any"' : '') . '>';
  }
  return $h . '</div>';
}

function render_crud($name, $pageTitle, $subtitle, $embed=false) {
  /* self-contained modal JS (guarded — safe if emitted twice or if app.js also defines it) */
  echo '<script>if(!window.crudOpen){window.crudOpen=function(n){var m=document.getElementById("mbg_"+n);if(!m)return;document.getElementById("mAction_"+n).value="add";document.getElementById("mId_"+n).value="";document.getElementById("mTitle_"+n).textContent="Add";m.querySelectorAll("[name]").forEach(function(el){if(["csrf","_action","_entity","id"].indexOf(el.name)<0){if(el.type==="file"){el.value="";var p=document.getElementById("preview_"+el.name);if(p){p.style.display="none";p.src="";}}else if(el.tagName==="SELECT")el.selectedIndex=0;else el.value="";}});m.classList.add("open");document.body.classList.add("modal-open");};window.crudEdit=function(n,btn){var m=document.getElementById("mbg_"+n);if(!m)return;var r=JSON.parse(btn.getAttribute("data-rec"));document.getElementById("mAction_"+n).value="update";document.getElementById("mId_"+n).value=r.id;document.getElementById("mTitle_"+n).textContent="Edit";m.querySelectorAll("[name]").forEach(function(el){if(el.type==="file"){el.value="";var p=document.getElementById("preview_"+el.name);if(p){if(r[el.name]){p.src=r[el.name];p.style.display="";}else{p.style.display="none";p.src="";}}return;}if(r[el.name]!==undefined&&r[el.name]!==null)el.value=r[el.name];});m.classList.add("open");document.body.classList.add("modal-open");};window.crudClose=function(n){var m=document.getElementById("mbg_"+n);if(m)m.classList.remove("open");document.body.classList.remove("modal-open");};}</script>';
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
  echo '<div class="modal-bg" id="mbg_'.$name.'"><form class="modal" method="post" enctype="multipart/form-data"><div class="modal-head"><span id="mTitle_'.$name.'">Add ' . e($def['title']) . '</span><span class="mx" onclick="crudClose(\''.$name.'\')">✕</span></div>';
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
/* Notification categories a user can individually turn off in
   Settings → Notifications, mapped to the 'type' values notify() is
   called with across the app. */
function notification_types() {
  return [
    'order'     => ['🛒','New Orders'],
    'status'    => ['🔄','Order Status Changes'],
    'delivered' => ['✅','Deliveries'],
    'ncm'       => ['🚚','NCM Courier Sync'],
    'comment'   => ['💬','NCM Comments & AI Replies'],
    'alert'     => ['⚠️','Alerts (aging orders, stop-ads, follow-ups)'],
    'warning'   => ['🔶','Warnings (COD holding limit)'],
    'info'      => ['🔔','System Info (backups, etc.)'],
  ];
}
function disabled_notif_types() {
  $raw = (string)setting('disabled_notif_types','');
  return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($t)=>$t!==''));
}
function notif_type_enabled($type) {
  if (strtolower((string)setting('notifications_enabled','yes')) === 'no') return false;
  return !in_array($type, disabled_notif_types(), true);
}
function notify($message, $type = 'info', $link = '', $dedupeHours = 12) {
  if (!notif_type_enabled($type)) return;
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
    'accounting.php'=>2, 'salary.php'=>2, 'lunch_management.php'=>2, 'cod.php'=>2, 'banks.php'=>2, 'daraz.php'=>2, 'expenses.php'=>2, 'reports.php'=>2, 'analytics.php'=>2, 'analytics_returns.php'=>2, 'analytics_customers.php'=>2, 'analytics_branches.php'=>2, 'analytics_report.php'=>2, 'suppliers.php'=>2, 'hrm.php'=>2, 'vendor_purchases.php'=>2, 'inventory.php'=>2, 'money_dashboard.php'=>2, 'assistant.php'=>2,
    'ncm_debug.php'=>3,
    'ncm.php'=>1, 'ncm_comments.php'=>1, 'sales.php'=>1, 'couriers.php'=>1, 'sales_dashboard.php'=>1,   /* staff can use the courier & sales pages */
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
  echo '<script>if(!window.crudOpen){window.crudOpen=function(n){var m=document.getElementById("mbg_"+n);if(!m)return;document.getElementById("mAction_"+n).value="add";document.getElementById("mId_"+n).value="";document.getElementById("mTitle_"+n).textContent="Add";m.querySelectorAll("[name]").forEach(function(el){if(["csrf","_action","_entity","id"].indexOf(el.name)<0){if(el.type==="file"){el.value="";var p=document.getElementById("preview_"+el.name);if(p){p.style.display="none";p.src="";}}else if(el.tagName==="SELECT")el.selectedIndex=0;else el.value="";}});m.classList.add("open");document.body.classList.add("modal-open");};window.crudEdit=function(n,btn){var m=document.getElementById("mbg_"+n);if(!m)return;var r=JSON.parse(btn.getAttribute("data-rec"));document.getElementById("mAction_"+n).value="update";document.getElementById("mId_"+n).value=r.id;document.getElementById("mTitle_"+n).textContent="Edit";m.querySelectorAll("[name]").forEach(function(el){if(el.type==="file"){el.value="";var p=document.getElementById("preview_"+el.name);if(p){if(r[el.name]){p.src=r[el.name];p.style.display="";}else{p.style.display="none";p.src="";}}return;}if(r[el.name]!==undefined&&r[el.name]!==null)el.value=r[el.name];});m.classList.add("open");document.body.classList.add("modal-open");};window.crudClose=function(n){var m=document.getElementById("mbg_"+n);if(m)m.classList.remove("open");document.body.classList.remove("modal-open");};}</script>';
  $def = entity_def($name);
  echo '<div class="modal-bg" id="mbg_'.$name.'"><form class="modal" method="post" enctype="multipart/form-data"><div class="modal-head"><span id="mTitle_'.$name.'">Add ' . e($def['title']) . '</span><span class="mx" onclick="crudClose(\''.$name.'\')">✕</span></div>';
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

/* ============================================================
   Expenses ↔ Bank linkage — shared by expenses.php AND salary.php.
   A salary payment/advance/bonus paid from a bank account now
   mirrors into the expenses table (category 'Salary') through
   this exact same function, so it's never a separate, untracked
   bank-only transaction — it always shows up as a real expense.
   ============================================================ */
function ensure_expense_bank_columns() {
  static $done=false; if($done) return; $done=true;
  try { q("ALTER TABLE expenses ADD COLUMN IF NOT EXISTS account_id INT NULL"); } catch (Exception $e) {}
  try { q("ALTER TABLE expenses ADD COLUMN IF NOT EXISTS bank_txn_id INT NULL"); } catch (Exception $e) {}
}
function expense_bank_sync(array $e, ?int $accountId): ?int {
  ensure_expense_bank_columns();
  $old=(int)($e['bank_txn_id'] ?? 0);
  if(!$accountId){ if($old) q("DELETE FROM bank_txns WHERE id=?",[$old]); return null; }
  $cat = 'Expense — '.($e['category']??'Other');
  $rem = trim((string)($e['description']??''));
  if($old){
    q("UPDATE bank_txns SET account_id=?, txn_date=?, direction='out', amount=?, category=?, remarks=? WHERE id=?",
      [$accountId,$e['expense_date'],(float)$e['amount'],$cat,$rem,$old]);
    return $old;
  }
  q("INSERT INTO bank_txns(account_id,txn_date,direction,amount,category,remarks) VALUES(?,?,'out',?,?,?)",
    [$accountId,$e['expense_date'],(float)$e['amount'],$cat,$rem]);
  return (int)db()->lastInsertId();
}

/* extra Staff Directory profile fields — PAN, bank, contact details */
function ensure_employee_profile_fields() {
  static $done=false; if($done) return; $done=true;
  foreach ([
    'phone'         => "VARCHAR(20) NULL",
    'office_phone'  => "VARCHAR(20) NULL",
    'email'         => "VARCHAR(120) NULL",
    'pan_number'    => "VARCHAR(30) NULL",
    'bank_name'     => "VARCHAR(100) NULL",
    'bank_account'  => "VARCHAR(50) NULL",
    'address'       => "VARCHAR(255) NULL",
  ] as $col=>$def) {
    try { q("ALTER TABLE employees ADD COLUMN IF NOT EXISTS `$col` $def"); } catch (Exception $e) {}
  }
}

/* "Luprah" — a special Sales-Person option for orders that belong to the
   company itself (house orders, walk-ins, no individual dealer), not to a real
   staff member. It lives in the same employees/department='Sales' list so it's
   selectable everywhere a salesperson is, but is flagged is_company=1 so the
   Sales Team leaderboard (sales_dashboard.php) can exclude it from rankings. */
function ensure_company_salesperson() {
  static $done=false; if($done) return; $done=true;
  try { q("ALTER TABLE employees ADD COLUMN IF NOT EXISTS is_company TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
  try {
    $id = val("SELECT id FROM employees WHERE is_company=1 LIMIT 1");
    if (!$id) {
      $dup = val("SELECT id FROM employees WHERE name='Luprah'");
      if ($dup) q("UPDATE employees SET is_company=1, department='Sales', status='active' WHERE id=?", [$dup]);
      else q("INSERT INTO employees(name,department,status,is_company) VALUES('Luprah','Sales','active',1)");
    }
  } catch (Exception $e) {}
}

/* ============================================================
   Lunch Management — shared by salary.php (inline Daily Lunch
   panel) and lunch_management.php (the full module). One set of
   rules lives here so the two pages can never drift apart, and
   one salary_entries row per employee/month (tagged source =
   'lunch_mgmt') carries the synced total, so it's never double
   counted alongside a manually-added Lunch entry.
   ============================================================ */
function ensure_lunch_system() {
  static $done=false; if($done) return; $done=true;
  try { q("ALTER TABLE employees ADD COLUMN IF NOT EXISTS lunch_rate DECIMAL(10,2) NOT NULL DEFAULT 150"); } catch (Exception $e) {}
  try { q("CREATE TABLE IF NOT EXISTS lunch_orders(
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    order_date DATE NOT NULL,
    order_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    attendance VARCHAR(10) NOT NULL DEFAULT 'present',   /* present | leave */
    lunch_ordered TINYINT(1) NOT NULL DEFAULT 1,         /* did they order lunch that day */
    note VARCHAR(160) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_emp_date(employee_id, order_date),
    INDEX(employee_id))"); } catch (Exception $e) {}
  try { q("ALTER TABLE lunch_orders ADD COLUMN IF NOT EXISTS attendance VARCHAR(10) NOT NULL DEFAULT 'present'"); } catch (Exception $e) {}
  try { q("ALTER TABLE lunch_orders ADD COLUMN IF NOT EXISTS lunch_ordered TINYINT(1) NOT NULL DEFAULT 1"); } catch (Exception $e) {}
  try { q("ALTER TABLE salary_entries ADD COLUMN IF NOT EXISTS source VARCHAR(20) NULL"); } catch (Exception $e) {}
  /* seed defaults once so the settings table (not scattered PHP fallbacks) stays the one source of truth */
  foreach (['lunch_leave_days'=>'4','lunch_month_days'=>'30','lunch_pay_unused'=>'yes','lunch_over_allowance'=>'review'] as $k=>$v) {
    try { q("INSERT IGNORE INTO settings(skey,svalue) VALUES(?,?)", [$k,$v]); } catch (Exception $e) {}
  }
}

function lunch_pay_unused()          { return strtolower((string)setting('lunch_pay_unused','yes')) !== 'no'; }
function lunch_leave_days_setting()  { return (int)setting('lunch_leave_days', 4); }
function lunch_month_days_setting()  { return (int)setting('lunch_month_days', 30); }
function lunch_eligible_days_setting() { return max(0, lunch_month_days_setting() - lunch_leave_days_setting()); }
function lunch_over_allowance_setting() { $v=strtolower((string)setting('lunch_over_allowance','review')); return $v==='ignore' ? 'ignore' : 'review'; }

/* the business rule, for one employee on one day — the single source of truth */
function lunch_day_calc($rate, $attendance, $lunchOrdered, $orderAmount) {
  $rate = (float)$rate; $orderAmount = max(0,(float)$orderAmount);
  if ($attendance !== 'present') {
    return ['allowance'=>0.0,'actual'=>0.0,'adjustment'=>0.0,'extra'=>0.0,'status'=>'leave'];
  }
  if (!$lunchOrdered) {
    $adj = lunch_pay_unused() ? $rate : 0.0;
    return ['allowance'=>$rate,'actual'=>0.0,'adjustment'=>round($adj,2),'extra'=>0.0,'status'=>'ok'];
  }
  if ($orderAmount > $rate) {
    $status = lunch_over_allowance_setting() === 'ignore' ? 'ok' : 'review';
    return ['allowance'=>$rate,'actual'=>$orderAmount,'adjustment'=>0.0,'extra'=>round($orderAmount-$rate,2),'status'=>$status];
  }
  return ['allowance'=>$rate,'actual'=>$orderAmount,'adjustment'=>round($rate-$orderAmount,2),'extra'=>0.0,'status'=>'ok'];
}

/* per-employee monthly totals for a date range — drives both pages' summaries */
function lunch_monthly_agg($mStart, $mEnd, $empFilter = 0) {
  ensure_lunch_system();
  $sql = "SELECT lo.*, e.lunch_rate FROM lunch_orders lo JOIN employees e ON e.id=lo.employee_id
          WHERE lo.order_date BETWEEN ? AND ?".($empFilter ? " AND lo.employee_id=".(int)$empFilter : "");
  $out = [];
  foreach (rows($sql, [$mStart, $mEnd]) as $r) {
    $eid = (int)$r['employee_id'];
    if (!isset($out[$eid])) $out[$eid] = ['days_present'=>0,'days_leave'=>0,'ordered'=>0.0,'allowance'=>0.0,'adjustment'=>0.0,'extra'=>0.0,'review'=>0,'entries'=>0];
    $c = lunch_day_calc($r['lunch_rate'], $r['attendance'], (int)$r['lunch_ordered'], $r['order_amount']);
    $out[$eid]['entries']++;
    if ($r['attendance'] === 'present') $out[$eid]['days_present']++; else $out[$eid]['days_leave']++;
    if ($c['status'] === 'review') $out[$eid]['review']++;
    $out[$eid]['ordered']    += (float)$r['lunch_ordered'] ? (float)$r['order_amount'] : 0.0;
    $out[$eid]['allowance']  += $c['allowance'];
    $out[$eid]['adjustment'] += $c['adjustment'];
    $out[$eid]['extra']      += $c['extra'];
  }
  return $out;
}

/* keep exactly one 'lunch' salary_entries row per employee/month in sync with
   the logged lunch data — tagged so it's never mistaken for a manual entry,
   and re-running this always replaces (never stacks on top of) itself */
function lunch_sync_salary_entry($employeeId, $ym) {
  ensure_lunch_system();
  $employeeId = (int)$employeeId;
  if (!$employeeId || !preg_match('/^\d{4}-\d{2}$/', $ym)) return 0.0;
  $mStart = $ym.'-01'; $mEnd = date('Y-m-t', strtotime($mStart));
  $agg = lunch_monthly_agg($mStart, $mEnd, $employeeId);
  $amount = round((float)($agg[$employeeId]['adjustment'] ?? 0), 2);
  q("DELETE FROM salary_entries WHERE employee_id=? AND ym=? AND type='lunch' AND source='lunch_mgmt'", [$employeeId, $ym]);
  if ($amount > 0.009) {
    q("INSERT INTO salary_entries(employee_id,ym,type,amount,entry_date,note,source) VALUES(?,?,?,?,?,?,?)",
      [$employeeId, $ym, 'lunch', $amount, date('Y-m-d'), 'Auto-synced from Lunch Management', 'lunch_mgmt']);
  }
  return $amount;
}