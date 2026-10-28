<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
if (current_user()) { header('Location: index.php'); exit; }

/* ---- brute-force rate limit: 5 failed tries in 15 min → locked.
   Tracked in the DB (by IP AND by username), not in $_SESSION — a session-only
   counter resets the moment a client stops sending/reuses a fresh cookie jar,
   which makes the lock trivially bypassable. This survives that. ---- */
try { q("CREATE TABLE IF NOT EXISTS login_attempts(
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  username VARCHAR(80) NOT NULL DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(ip), INDEX(username), INDEX(created_at))"); } catch (Exception $e) {}
try { q("DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"); } catch (Exception $e) {}

$ip   = $_SERVER['REMOTE_ADDR'] ?? '?';
$uTry = trim($_POST['username'] ?? '');
$byIp   = (int)val("SELECT COUNT(*) FROM login_attempts WHERE ip=?", [$ip]);
$byUser = $uTry!=='' ? (int)val("SELECT COUNT(*) FROM login_attempts WHERE username=?", [$uTry]) : 0;
$locked = max($byIp, $byUser) >= 5;

$err = '';
if (isset($_GET['expired'])) $err = 'Session expired — please sign in again.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf();
  if ($locked) {
    $err = 'Too many failed attempts. Please wait 15 minutes and try again.';
  } else {
    $u = $uTry;
    $p = $_POST['password'] ?? '';
    $user = row("SELECT * FROM users WHERE username=? AND status='active'", [$u]);
    /* verify against a dummy hash when user not found — same timing, no enumeration */
    $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    if (password_verify($p, $hash) && $user) {
      session_regenerate_id(true);                       // fresh session id on privilege change
      unset($user['password_hash']);
      $_SESSION['user'] = $user;
      $_SESSION['csrf'] = bin2hex(random_bytes(32));     // fresh CSRF token too
      try { q("DELETE FROM login_attempts WHERE ip=? OR username=?", [$ip, $u]); } catch (Exception $e) {}
      q("UPDATE users SET last_login=NOW() WHERE id=?", [$user['id']]);
      log_activity('Logged in', 'Auth');
      header('Location: index.php'); exit;
    }
    try { q("INSERT INTO login_attempts(ip,username) VALUES(?,?)", [$ip, $u]); } catch (Exception $e) {}
    log_activity("Failed login for '".substr($u,0,40)."' from $ip", 'Auth');
    $err = 'Invalid username or password.';
  }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — <?= e(setting('store_name', APP_NAME)) ?></title>
<link rel="icon" type="image/png" href="assets/luprah-logo.png">
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__."/assets/style.css") ?>"></head>
<body><div class="login-wrap"><form class="login-card" method="post">
  <input type="hidden" name="csrf" value="<?= csrf() ?>">
  <div class="brand" style="border-radius:12px"><img src="assets/luprah-logo.png" alt="" style="height:30px;vertical-align:middle"> <?= e(setting('store_name', APP_NAME)) ?></div>
  <h2>Sign in</h2><p>Log in to access your dashboard</p>
  <?php if ($err): ?><div class="login-err"><?= e($err) ?></div><?php endif; ?>
  <label>Username</label><input name="username" autofocus required autocomplete="username">
  <label>Password</label><input name="password" type="password" required autocomplete="current-password">
  <button class="btn btn-primary" style="width:100%;justify-content:center;margin-top:20px"<?= $locked?' disabled':'' ?>>Sign In →</button>
</form></div></body></html>
