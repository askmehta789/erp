<?php
require_once __DIR__ . '/db.php';

/* ---- hardened session ---- */
if (session_status() === PHP_SESSION_NONE) {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $secure,      // cookie only over HTTPS when available
    'httponly' => true,         // JS cannot read the session cookie
    'samesite' => 'Lax',        // blocks cross-site POSTs riding the cookie
  ]);
  session_start();
}

/* ---- security headers on every page ---- */
if (!headers_sent()) {
  header('X-Frame-Options: SAMEORIGIN');                 // no clickjacking iframes
  header('X-Content-Type-Options: nosniff');             // no MIME sniffing
  header('Referrer-Policy: same-origin');                // don't leak URLs offsite
  header('X-XSS-Protection: 0');                         // rely on escaping, not legacy filter
  header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
}

/* ---- idle timeout: auto-logout after 8h inactivity ---- */
if (!empty($_SESSION['user'])) {
  $idle = time() - (int)($_SESSION['last_seen'] ?? time());
  if ($idle > 8*3600) { session_unset(); session_destroy(); header('Location: login.php?expired=1'); exit; }
  $_SESSION['last_seen'] = time();
}

function current_user() { return $_SESSION['user'] ?? null; }
function require_login() { if (!current_user()) { header('Location: login.php'); exit; } }
function require_role($roles) {
  $u = current_user();
  if (!$u || !in_array($u['role'], (array)$roles, true)) { http_response_code(403); die('Access denied for your role.'); }
}
function csrf() { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function check_csrf() {
  $sent = $_POST['csrf'] ?? '';
  $have = $_SESSION['csrf'] ?? '';
  if ($have === '' || !is_string($sent) || !hash_equals($have, $sent)) {   // timing-safe compare
    http_response_code(403);
    die('Security check failed (CSRF). Please go back and try again.');
  }
}
function log_activity($action, $module) {
  $u = current_user();
  try {
    q("INSERT INTO activity_logs(user_id,user_name,action,module) VALUES(?,?,?,?)",
       [$u['id'] ?? null, $u['name'] ?? 'System', $action, $module]);
  } catch (Exception $e) {}
}
function flash($msg = null) {
  if ($msg !== null) { $_SESSION['flash'] = $msg; return; }
  if (!empty($_SESSION['flash'])) { $m = $_SESSION['flash']; unset($_SESSION['flash']); return $m; }
  return null;
}
