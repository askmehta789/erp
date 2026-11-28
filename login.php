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
<?php $storeName = (string)setting('store_name', APP_NAME); $v = fn($f) => @filemtime(__DIR__.'/assets/'.$f); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Sign in — <?= e($storeName) ?></title>
<meta name="theme-color" content="#0f172a">
<link rel="icon" type="image/png" sizes="32x32" href="assets/luprah-icon-32.png?v=<?= $v('luprah-icon-64.png') ?>">
<link rel="icon" type="image/png" sizes="64x64" href="assets/luprah-icon-64.png?v=<?= $v('luprah-icon-64.png') ?>">
<link rel="apple-touch-icon" sizes="180x180" href="assets/luprah-icon-180.png">
<link rel="manifest" href="manifest.json">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Montserrat:wght@500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}html,body{height:100%;margin:0}
:root{--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--bg:#f8fafc;--card:#fff;--brand:#f85606;--err-bg:#fef2f2;--err:#b91c1c}
@media(prefers-color-scheme:dark){:root{--ink:#e8edf6;--muted:#8aa0bd;--line:#1e2a44;--bg:#0b1220;--card:#111a2e;--err-bg:#2a0a14;--err:#fca5a5}}
body{font-family:Inter,system-ui,'Segoe UI',Roboto,Arial,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased}
.lg{min-height:100%;display:grid;grid-template-columns:1.05fr 1fr}
/* brand side */
.lg-side{position:relative;overflow:hidden;background:linear-gradient(150deg,#0b1220 0%,#111c33 55%,#1e293b 100%);color:#fff;padding:48px 56px;display:flex;flex-direction:column;justify-content:space-between}
.lg-side:before{content:'';position:absolute;width:520px;height:520px;right:-180px;top:-200px;border-radius:50%;background:radial-gradient(circle,rgba(248,86,6,.38),transparent 68%)}
.lg-side:after{content:'';position:absolute;width:420px;height:420px;left:-160px;bottom:-200px;border-radius:50%;background:radial-gradient(circle,rgba(59,130,246,.22),transparent 68%)}
.lg-side>*{position:relative;z-index:1}
.lg-brand{display:flex;align-items:center;gap:14px}
.lg-tile{width:52px;height:52px;border-radius:16px;background:#fff;display:grid;place-items:center;box-shadow:0 10px 24px rgba(0,0,0,.25)}
.lg-tile img{width:38px;height:38px}
.lg-word img{height:22px;display:block;filter:invert(1) brightness(2)}
.lg-word small{display:block;font-family:Montserrat,Inter,sans-serif;font-size:10px;font-weight:500;letter-spacing:.34em;text-transform:uppercase;opacity:.6;margin-top:7px}
.lg-hero h1{font-size:40px;line-height:1.12;font-weight:800;letter-spacing:-.025em;margin:0 0 14px;max-width:460px}
.lg-hero h1 em{font-style:normal;color:#ff8a4c}
.lg-hero p{font-size:15px;line-height:1.6;opacity:.7;margin:0;max-width:420px}
.lg-feats{display:grid;gap:12px;margin-top:30px;max-width:420px}
.lg-feat{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:14px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);font-size:13.5px;font-weight:600}
.lg-feat i{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:rgba(248,86,6,.16);color:#ff8a4c;flex:none}
.lg-foot{font-size:12px;opacity:.45}
/* form side */
.lg-main{display:flex;align-items:center;justify-content:center;padding:40px 24px}
.lg-card{width:100%;max-width:380px}
.lg-mob{display:none}
.lg-card h2{font-size:26px;font-weight:800;letter-spacing:-.02em;margin:0}
.lg-card .sub{color:var(--muted);font-size:14px;margin:6px 0 28px}
.lg-err{display:flex;gap:10px;align-items:flex-start;background:var(--err-bg);color:var(--err);border:1px solid color-mix(in srgb,var(--err) 25%,transparent);border-radius:12px;padding:11px 13px;font-size:13px;font-weight:600;margin-bottom:18px}
.lg-f{margin-bottom:16px}
.lg-f label{display:block;font-size:12.5px;font-weight:700;margin-bottom:7px}
.lg-in{display:flex;align-items:center;gap:10px;height:50px;padding:0 14px;border-radius:13px;border:1.5px solid var(--line);background:var(--card);transition:border-color .15s,box-shadow .15s}
.lg-in:focus-within{border-color:var(--brand);box-shadow:0 0 0 4px rgba(248,86,6,.12)}
.lg-in svg{color:var(--muted);flex:none}
.lg-in input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:inherit;font-size:15px;color:var(--ink);height:100%}
.lg-eye{border:0;background:transparent;color:var(--muted);cursor:pointer;padding:6px;margin-right:-6px;border-radius:8px;display:grid;place-items:center}
.lg-eye:hover{color:var(--ink)}
.lg-caps{display:none;font-size:12px;font-weight:600;color:#b45309;margin-top:7px}
.lg-btn{width:100%;height:52px;margin-top:8px;border:0;border-radius:13px;background:linear-gradient(140deg,#ff7a2f,#f85606);color:#fff;font:inherit;font-size:15px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;box-shadow:0 10px 24px rgba(248,86,6,.3);transition:transform .1s,filter .15s}
.lg-btn:hover{filter:brightness(1.05)}.lg-btn:active{transform:scale(.985)}
.lg-btn[disabled]{opacity:.55;cursor:not-allowed;box-shadow:none}
.lg-spin{width:18px;height:18px;border-radius:50%;border:2.5px solid rgba(255,255,255,.4);border-top-color:#fff;animation:lgs .7s linear infinite;display:none}
@keyframes lgs{to{transform:rotate(360deg)}}
.lg-btn.busy .lg-spin{display:block}.lg-btn.busy .lg-arrow{display:none}
.lg-note{display:flex;align-items:center;gap:8px;justify-content:center;color:var(--muted);font-size:12px;margin-top:22px}
/* phones: brand on top, form as a sheet */
@media(max-width:860px){
  .lg{grid-template-columns:1fr;background:linear-gradient(160deg,#0b1220,#1e293b)}
  .lg-side{display:none}
  .lg-main{align-items:stretch;padding:0;flex-direction:column}
  .lg-mob{display:flex;flex-direction:column;align-items:center;gap:14px;padding:calc(48px + env(safe-area-inset-top)) 24px 34px;color:#fff;position:relative;overflow:hidden;text-align:center}
  .lg-mob:before{content:'';position:absolute;width:360px;height:360px;right:-140px;top:-160px;border-radius:50%;background:radial-gradient(circle,rgba(248,86,6,.4),transparent 68%)}
  .lg-mob>*{position:relative}
  .lg-mob .lg-tile{width:64px;height:64px;border-radius:20px}.lg-mob .lg-tile img{width:46px;height:46px}
  .lg-mob .lg-word img{height:20px;margin:0 auto}
  .lg-card{max-width:none;flex:1;background:var(--bg);border-radius:28px 28px 0 0;padding:30px 22px calc(28px + env(safe-area-inset-bottom))}
}
</style></head>
<body>
<div class="lg">
  <aside class="lg-side">
    <div class="lg-brand"><span class="lg-tile"><img src="assets/luprah-mark.png" alt=""></span>
      <span class="lg-word"><img src="assets/luprah-wordmark.png?v=<?= $v('luprah-wordmark.png') ?>" alt="Luprah"><small>Trading Pvt. Ltd</small></span></div>
    <div class="lg-hero">
      <h1>Run your whole store from <em>one place</em>.</h1>
      <p>Orders, Daraz, couriers, stock, money and staff — always up to date, on your computer and your phone.</p>
      <div class="lg-feats">
        <div class="lg-feat"><i><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l-1 12H7L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg></i>Sales &amp; Daraz orders with live status sync</div>
        <div class="lg-feat"><i><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/></svg></i>Stock with real FIFO cost</div>
        <div class="lg-feat"><i><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/></svg></i>Profit, COD &amp; bank balances at a glance</div>
      </div>
    </div>
    <div class="lg-foot">© <?= date('Y') ?> <?= e($storeName) ?></div>
  </aside>

  <main class="lg-main">
    <div class="lg-mob">
      <span class="lg-tile"><img src="assets/luprah-mark.png" alt=""></span>
      <span class="lg-word"><img src="assets/luprah-wordmark.png?v=<?= $v('luprah-wordmark.png') ?>" alt="Luprah"><small>Trading Pvt. Ltd</small></span>
    </div>
    <form class="lg-card" method="post" id="lgForm" novalidate>
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <h2>Welcome back</h2>
      <p class="sub">Sign in to your Luprah ERP account</p>
      <?php if ($err): ?><div class="lg-err" role="alert"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg><span><?= e($err) ?></span></div><?php endif; ?>
      <div class="lg-f"><label for="lgU">Username</label>
        <div class="lg-in"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
          <input id="lgU" name="username" value="<?= e($uTry) ?>" required autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="Enter your username"<?= $uTry === '' ? ' autofocus' : '' ?>></div></div>
      <div class="lg-f"><label for="lgP">Password</label>
        <div class="lg-in"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/></svg>
          <input id="lgP" name="password" type="password" required autocomplete="current-password" placeholder="Enter your password"<?= $uTry !== '' ? ' autofocus' : '' ?>>
          <button type="button" class="lg-eye" id="lgEye" aria-label="Show password" title="Show password"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
        <div class="lg-caps" id="lgCaps">⇪ Caps Lock is on</div></div>
      <button class="lg-btn" id="lgBtn"<?= $locked ? ' disabled' : '' ?>><span class="lg-spin"></span><span class="lg-lbl"><?= $locked ? 'Locked — try again in 15 min' : 'Sign in' ?></span><svg class="lg-arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
      <div class="lg-note"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6l7-3z"/></svg>Secure sign-in · locks after 5 wrong tries</div>
    </form>
  </main>
</div>
<script>
(function(){
  var p=document.getElementById('lgP'),eye=document.getElementById('lgEye'),caps=document.getElementById('lgCaps'),f=document.getElementById('lgForm'),b=document.getElementById('lgBtn');
  eye.onclick=function(){var show=p.type==='password';p.type=show?'text':'password';eye.setAttribute('aria-label',show?'Hide password':'Show password');eye.title=eye.getAttribute('aria-label');eye.style.color=show?'var(--brand)':'';p.focus();};
  function capsCheck(e){if(e.getModifierState)caps.style.display=e.getModifierState('CapsLock')?'block':'none';}
  p.addEventListener('keydown',capsCheck);p.addEventListener('keyup',capsCheck);
  f.addEventListener('submit',function(e){
    var u=document.getElementById('lgU');
    if(!u.value.trim()){e.preventDefault();u.focus();return;}
    if(!p.value){e.preventDefault();p.focus();return;}
    b.classList.add('busy');b.querySelector('.lg-lbl').textContent='Signing in…';
  });
})();
</script>
</body></html>
