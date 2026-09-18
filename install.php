<?php
require_once __DIR__ . '/db.php';
$done = false; $err = '';
$installed = false;
try { $installed = (int)val("SELECT COUNT(*) FROM users") > 0; } catch (Exception $e) { $installed = false; }

/* SECURITY: once installed, this file refuses to run at all */
if ($installed && $_SERVER['REQUEST_METHOD'] === 'POST') { http_response_code(403); die('Already installed. Delete install.php from the server.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['name'] ?? 'Admin');
  $user = trim($_POST['username'] ?? 'admin');
  $pass = $_POST['password'] ?? '';
  if (strlen($pass) < 6) { $err = 'Password must be at least 6 characters.'; }
  else {
    try {
      $sql = file_get_contents(__DIR__ . '/database.sql');
      $failed = [];
      foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '' || stripos($stmt, 'SET FOREIGN_KEY') !== false) continue;
        try { db()->exec($stmt); }
        catch (Exception $ex) { $failed[] = $ex->getMessage(); }
      }
      if ($failed) { throw new Exception('Some tables could not be created: ' . implode(' | ', array_slice($failed,0,4))); }
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $ex = row("SELECT id FROM users WHERE username=?", [$user]);
      if ($ex) q("UPDATE users SET name=?, password_hash=?, role='Super Admin', status='active' WHERE id=?", [$name, $hash, $ex['id']]);
      else q("INSERT INTO users(name,username,email,password_hash,role,status) VALUES(?,?,?,?,'Super Admin','active')",
             [$name, $user, $user . '@store.local', $hash]);
      $done = true;
    } catch (Exception $e) { $err = 'Install error: ' . $e->getMessage(); }
  }
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install — MyStore ERP</title><link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__."/assets/style.css") ?>"></head>
<body><div class="login-wrap"><div class="login-card">
<div class="brand" style="border-radius:12px"><span class="logo">🛠️</span> ERP Installer</div>
<?php if ($done): ?>
  <h2>✅ Installed!</h2>
  <p>Your database is ready and your admin account is created.</p>
  <div class="login-err" style="background:var(--amber-bg);color:var(--amber)">⚠️ Important: delete <b>install.php</b> from your server now (in File Manager) for security.</div>
  <a class="btn btn-primary" style="width:100%;justify-content:center;margin-top:14px" href="login.php">Go to Login →</a>
<?php else: ?>
  <h2>Set up your store</h2><p>This creates all database tables and your admin login.</p>
  <?php if ($err): ?><div class="login-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($installed): ?><div class="login-err" style="background:var(--amber-bg);color:var(--amber)">Users already exist — submitting will reset this admin account's password.</div><?php endif; ?>
  <form method="post">
    <label>Your Name</label><input name="name" value="Admin" required>
    <label>Admin Username</label><input name="username" value="admin" required>
    <label>Admin Password (min 6 chars)</label><input name="password" type="password" required>
    <button class="btn btn-primary" style="width:100%;justify-content:center;margin-top:18px">Install Now</button>
  </form>
<?php endif; ?>
</div></div></body></html>
