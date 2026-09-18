<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
if (current_user()) { header('Location: index.php'); exit; }

/* ---- brute-force rate limit: 5 failed tries → 15-minute lock (per session+IP) ---- */
$ip = $_SERVER['REMOTE_ADDR'] ?? '?';
$_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? [];
$attempts =& $_SESSION['login_attempts'];
$attempts = array_filter($attempts, fn($t) => $t > time() - 900);   // keep last 15 min
$locked = count($attempts) >= 5;

$err = '';
if (isset($_GET['expired'])) $err = 'Session expired — please sign in again.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf();
  if ($locked) {
    $err = 'Too many failed attempts. Please wait 15 minutes and try again.';
  } else {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $user = row("SELECT * FROM users WHERE username=? AND status='active'", [$u]);
    /* verify against a dummy hash when user not found — same timing, no enumeration */
    $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    if (password_verify($p, $hash) && $user) {
      session_regenerate_id(true);                       // fresh session id on privilege change
      unset($user['password_hash']);
      $_SESSION['user'] = $user;
      $_SESSION['csrf'] = bin2hex(random_bytes(32));     // fresh CSRF token too
      $_SESSION['login_attempts'] = [];
      q("UPDATE users SET last_login=NOW() WHERE id=?", [$user['id']]);
      log_activity('Logged in', 'Auth');
      header('Location: index.php'); exit;
    }
    $attempts[] = time();
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
