<?php
/* SECURITY: diagnostics can alter the DB — Super Admin only */
require_once __DIR__ . '/auth.php';
require_login();
if (!in_array(($_SESSION['user']['role'] ?? ''), ['Super Admin','Admin','Owner'], true)) { http_response_code(403); die('Admins only.'); }
?>
/* ============================================================
   MyStore ERP — Diagnostic & Repair (v2)
   Upload to your /erp/ folder, open in browser:
     https://yourdomain.com/erp/check.php
   Diagnoses the server + database, repairs missing tables AND
   columns, and runs a live "save test" on the Sales table.
   DELETE this file once your ERP works.
   ============================================================ */
error_reporting(E_ALL); ini_set('display_errors', '1');
require_once __DIR__ . '/config.php';

$pdo = null; $connErr = '';
try {
  $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Exception $e) { $connErr = $e->getMessage(); }

$requiredTables = ['users','suppliers','products','customers','couriers','orders','purchases','expenses','employees','ledger','activity_logs','settings'];

/* full column set the application reads/writes — Repair adds any that are missing */
$fullCols = [
 'orders' => [
   'code'=>"VARCHAR(30) NOT NULL DEFAULT ''",'order_date'=>"DATE NULL",'customer'=>"VARCHAR(140) NULL",
   'phone'=>"VARCHAR(40) NULL",'address'=>"VARCHAR(255) NULL",'product_id'=>"INT NULL",
   'qty'=>"INT NOT NULL DEFAULT 1",'sell_price'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",
   'cost_price'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",'delivery_charge'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",
   'cancel_charge'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",'zone'=>"ENUM('inside','outside') NOT NULL DEFAULT 'inside'",
   'payment_type'=>"ENUM('cod','prepaid','bank_transfer','wallet') NOT NULL DEFAULT 'cod'",
   'payment_status'=>"ENUM('unpaid','paid','partial','refunded') NOT NULL DEFAULT 'unpaid'",
   'status'=>"ENUM('pending','processing','shipped','delivered','cancelled','returned') NOT NULL DEFAULT 'pending'",
   'courier_id'=>"INT NULL",'remarks'=>"VARCHAR(255) NULL",'created_at'=>"DATETIME NULL DEFAULT CURRENT_TIMESTAMP"],
 'expenses' => [
   'expense_date'=>"DATE NULL",'description'=>"VARCHAR(255) NULL",'amount'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",
   'currency'=>"ENUM('Rs','USD') NOT NULL DEFAULT 'Rs'",'usd_amount'=>"DECIMAL(12,2) NULL",
   'pay_from'=>"ENUM('cash','bank') NOT NULL DEFAULT 'cash'",'category'=>"VARCHAR(60) NOT NULL DEFAULT 'Other'",
   'product'=>"VARCHAR(140) NULL",'created_at'=>"DATETIME NULL DEFAULT CURRENT_TIMESTAMP"],
 'products' => [
   'sku'=>"VARCHAR(60) NULL",'category'=>"VARCHAR(60) NULL",'cost'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",
   'price'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",'stock'=>"INT NOT NULL DEFAULT 0",'low_stock'=>"INT NOT NULL DEFAULT 10",
   'sold'=>"INT NOT NULL DEFAULT 0",'supplier'=>"VARCHAR(140) NULL",'created_at'=>"DATETIME NULL DEFAULT CURRENT_TIMESTAMP"],
 'customers' => [
   'phone'=>"VARCHAR(40) NULL",'email'=>"VARCHAR(120) NULL",'city'=>"VARCHAR(80) NULL",'address'=>"VARCHAR(255) NULL",
   'status'=>"ENUM('active','inactive') NOT NULL DEFAULT 'active'",'created_at'=>"DATETIME NULL DEFAULT CURRENT_TIMESTAMP"],
 'suppliers' => [
   'contact'=>"VARCHAR(120) NULL",'city'=>"VARCHAR(80) NULL",'category'=>"VARCHAR(60) NULL",
   'balance'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",'status'=>"ENUM('active','inactive') NOT NULL DEFAULT 'active'",
   'created_at'=>"DATETIME NULL DEFAULT CURRENT_TIMESTAMP"],
 'purchases' => [
   'code'=>"VARCHAR(30) NOT NULL DEFAULT ''",'supplier'=>"VARCHAR(140) NULL",'purchase_date'=>"DATE NULL",
   'items'=>"INT NOT NULL DEFAULT 0",'amount'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",
   'status'=>"ENUM('pending','confirmed','completed') NOT NULL DEFAULT 'pending'",'created_at'=>"DATETIME NULL DEFAULT CURRENT_TIMESTAMP"],
 'employees' => [
   'role'=>"VARCHAR(80) NULL",'department'=>"VARCHAR(60) NULL",'salary'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",
   'joined_date'=>"DATE NULL",'status'=>"ENUM('active','on_leave','inactive') NOT NULL DEFAULT 'active'",
   'created_at'=>"DATETIME NULL DEFAULT CURRENT_TIMESTAMP"],
 'ledger' => [
   'txn_date'=>"DATE NULL",'type'=>"ENUM('income','expense') NOT NULL DEFAULT 'expense'",'category'=>"VARCHAR(60) NULL",
   'description'=>"VARCHAR(255) NULL",'amount'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",'created_at'=>"DATETIME NULL DEFAULT CURRENT_TIMESTAMP"],
 'users' => [
   'email'=>"VARCHAR(120) NULL",'role'=>"VARCHAR(40) NOT NULL DEFAULT 'Staff'",
   'status'=>"VARCHAR(20) NOT NULL DEFAULT 'active'",'last_login'=>"DATETIME NULL",'created_at'=>"DATETIME NULL DEFAULT CURRENT_TIMESTAMP"],
 'couriers' => [
   'color'=>"VARCHAR(16) NOT NULL DEFAULT '#6366f1'",'status'=>"VARCHAR(20) NOT NULL DEFAULT 'active'",
   'created_at'=>"DATETIME NULL DEFAULT CURRENT_TIMESTAMP"],
];

$report = [];
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
  // create any missing tables
  $sql = file_get_contents(__DIR__ . '/database.sql');
  foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    if ($stmt === '' || stripos($stmt, 'SET FOREIGN_KEY') !== false) continue;
    $label = preg_replace('/\s+/', ' ', substr($stmt, 0, 52));
    try { $pdo->exec($stmt); $report[] = ['ok', $label]; }
    catch (Exception $e) { $report[] = ['fail', $label . ' — ' . $e->getMessage()]; }
  }
  // add any missing columns
  foreach ($fullCols as $tbl => $cols) {
    try {
      $have = [];
      foreach ($pdo->query("SHOW COLUMNS FROM `$tbl`") as $c) $have[$c['Field']] = true;
      foreach ($cols as $col => $ddl) {
        if (!isset($have[$col])) {
          try { $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $ddl"); $report[] = ['ok', "Added column $tbl.$col"]; }
          catch (Exception $e) { $report[] = ['fail', "ALTER $tbl.$col — " . $e->getMessage()]; }
        }
      }
    } catch (Exception $e) {}
  }
}

// state
$existing = []; $serverVer=''; $counts=[]; $salesTest=null; $writeTest=null;
if ($pdo) {
  try { $serverVer = $pdo->query("SELECT VERSION()")->fetchColumn(); } catch (Exception $e) {}
  foreach ($requiredTables as $t) {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $existing[$t]=true; } catch (Exception $e) { $existing[$t]=false; }
  }
  foreach (['users','products','orders','couriers','expenses'] as $t)
    if (!empty($existing[$t])) { try { $counts[$t]=(int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (Exception $e) {} }
  try { $pdo->query("SELECT o.*, p.name AS product_name, c.name AS courier_name FROM orders o LEFT JOIN products p ON p.id=o.product_id LEFT JOIN couriers c ON c.id=o.courier_id ORDER BY o.order_date DESC, o.id DESC LIMIT 1")->fetchAll(); $salesTest=true; }
  catch (Exception $e) { $salesTest=$e->getMessage(); }
  // LIVE SAVE TEST on orders (insert -> update -> delete a temp row)
  if (!empty($existing['orders'])) {
    try {
      $code='SELFTEST-'.time();
      $pdo->prepare("INSERT INTO orders(code,order_date,customer,phone,address,product_id,qty,sell_price,cost_price,delivery_charge,cancel_charge,zone,payment_type,payment_status,status,courier_id,remarks) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$code,date('Y-m-d'),'__diagnostic__','0','test',null,1,100,50,80,0,'inside','cod','unpaid','pending',null,'self-test']);
      $id=$pdo->lastInsertId();
      $pdo->prepare("UPDATE orders SET status='delivered', sell_price=120 WHERE id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM orders WHERE id=?")->execute([$id]);
      $writeTest=true;
    } catch (Exception $e) { $writeTest=$e->getMessage(); }
  }
}
$missing = array_keys(array_filter($existing, fn($v)=>!$v));
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ERP Diagnostic</title>
<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:760px;margin:30px auto;padding:0 18px;color:#1f2937;line-height:1.55}
h1{font-size:24px}h2{font-size:17px;margin-top:26px;border-bottom:1px solid #e5e7eb;padding-bottom:6px}
.box{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin:10px 0}
code{background:#eef2ff;padding:1px 6px;border-radius:5px;font-size:13px}
.btn{display:inline-block;background:#4f46e5;color:#fff;padding:12px 20px;border:0;border-radius:9px;font-weight:700;font-size:15px;cursor:pointer}
.ok{color:#16a34a}.bad{color:#dc2626}.muted{color:#6b7280}
table{border-collapse:collapse;width:100%}td{padding:5px 8px;border-bottom:1px solid #eee;font-size:14px}
.rep{font-family:ui-monospace,monospace;font-size:12.5px;white-space:pre-wrap}</style></head><body>
<h1>🛠️ ERP Diagnostic &amp; Repair</h1>

<h2>1. Server</h2>
<div class="box">
PHP version: <b><?= PHP_VERSION ?></b> <?= version_compare(PHP_VERSION,'7.4.0','>=')?'<span class="ok">(good)</span>':'<span class="bad">(set PHP 7.4+ in cPanel → MultiPHP Manager)</span>' ?><br>
PDO MySQL: <?= extension_loaded('pdo_mysql')?'<span class="ok">✔ loaded</span>':'<span class="bad">✗ missing</span>' ?><br>
MySQL server: <?= $serverVer?'<b>'.htmlspecialchars($serverVer).'</b>':'<span class="muted">—</span>' ?>
</div>

<h2>2. Database connection</h2>
<div class="box">
<?php if ($pdo): ?><span class="ok">✔ Connected to <code><?= htmlspecialchars(DB_NAME) ?></code> as <code><?= htmlspecialchars(DB_USER) ?></code></span>
<?php else: ?><span class="bad">✗ Could not connect.</span><br><span class="rep"><?= htmlspecialchars($connErr) ?></span><br><br>
Edit the 4 lines in <code>config.php</code> with your real database name, user and password, then <b>Save</b>. In cPanel → MySQL Databases, make sure the user is added to the database with <b>ALL PRIVILEGES</b>.
<?php endif; ?>
</div>

<?php if ($pdo): ?>
<h2>3. Tables</h2>
<div class="box"><table>
<?php foreach ($requiredTables as $t): ?>
<tr><td><code><?= $t ?></code></td><td><?= $existing[$t]?'<span class="ok">✔ exists</span>':'<span class="bad">✗ MISSING</span>' ?></td>
<td class="muted"><?= isset($counts[$t])?$counts[$t].' rows':'' ?></td></tr>
<?php endforeach; ?></table>
<?php if ($missing): ?><p class="bad"><b>Missing: <?= implode(', ',$missing) ?></b> — click Repair below.</p><?php endif; ?>
</div>

<h2>4. Sales — can it save? (live test)</h2>
<div class="box">
<?php if ($writeTest === true): ?>
  <span class="ok">✔ Orders save, update and delete correctly. Your Sales page should work.</span>
<?php elseif ($writeTest === null): ?>
  <span class="muted">Orders table not present yet — run Repair.</span>
<?php else: ?>
  <span class="bad">✗ Saving an order FAILED — this is why your sales data isn't updating:</span><br>
  <span class="rep"><?= htmlspecialchars((string)$writeTest) ?></span><br><br>
  <b>Click Repair below</b> (it adds the missing column shown above), then refresh this page.
<?php endif; ?>
<div class="muted" style="margin-top:8px">Read test: <?= $salesTest===true?'<span class="ok">✔ ok</span>':'<span class="bad">✗ '.htmlspecialchars((string)$salesTest).'</span>' ?></div>
</div>

<h2>5. Repair</h2>
<div class="box">
<p>Creates any missing tables and adds any missing columns. Safe to run repeatedly — it never deletes your data.</p>
<form method="post"><button class="btn" type="submit">⚙️ Repair database now</button></form>
<?php if ($report): ?>
  <h3 style="margin-top:18px">Repair report</h3>
  <div class="rep"><?php foreach ($report as $r): ?><?= $r[0]==='ok'?'✔ ':'✗ ' ?><?= htmlspecialchars($r[1])."\n" ?><?php endforeach; ?></div>
  <p style="margin-top:12px"><b>Now refresh this page</b> and check section 4 turns green, then open <code>sales.php</code>. When it works, <b>delete check.php</b>.</p>
<?php endif; ?>
</div>
<?php endif; ?>
<p class="muted" style="margin-top:30px">⚠️ Delete <code>check.php</code> once your ERP is working.</p>
</body></html>
