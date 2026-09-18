<?php
require_once __DIR__.'/functions.php'; require_login();
$PAGE_TITLE='My Profile';
$u = current_user();
$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $cur=$_POST['current']??''; $new=$_POST['new']??''; $conf=$_POST['confirm']??'';
  $dbu=row("SELECT * FROM users WHERE id=?",[(int)$u['id']]);
  if(!$dbu || !password_verify($cur,$dbu['password_hash'])) $err='Current password is incorrect.';
  elseif(strlen($new)<8) $err='New password must be at least 8 characters.';
  elseif($new!==$conf) $err='New passwords do not match.';
  elseif(password_verify($new,$dbu['password_hash'])) $err='New password must be different from the current one.';
  else {
    q("UPDATE users SET password_hash=? WHERE id=?",[password_hash($new,PASSWORD_DEFAULT),(int)$u['id']]);
    session_regenerate_id(true);
    log_activity('Changed own password','Profile');
    $msg='Password updated successfully.';
  }
}
require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>👤 My Profile</h1><p>Your account & security</p></div></div>

<div class="grid cols-2" style="align-items:start">
  <div class="panel"><div class="panel-head"><h2>Account</h2></div><div class="panel-body">
    <div class="info-row"><span class="it">Name</span><span class="iv"><?= e($u['name']) ?></span></div>
    <div class="info-row"><span class="it">Username</span><span class="iv"><?= e($u['username']) ?></span></div>
    <div class="info-row"><span class="it">Role</span><span class="iv"><span class="pill p-blue"><?= e($u['role']) ?></span></span></div>
    <div class="info-row"><span class="it">Email</span><span class="iv"><?= e($u['email']?:'—') ?></span></div>
  </div></div>

  <div class="panel"><div class="panel-head"><h2>🔒 Change Password</h2></div><div class="panel-body">
    <?php if($msg): ?><div class="flash" style="margin-bottom:14px"><?= e($msg) ?></div><?php endif; ?>
    <?php if($err): ?><div class="flash" style="background:var(--red-bg);color:var(--red);margin-bottom:14px"><?= e($err) ?></div><?php endif; ?>
    <form method="post" class="form-grid" style="grid-template-columns:1fr">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <div><label>Current Password</label><input type="password" name="current" required autocomplete="current-password"></div>
      <div><label>New Password <span class="muted" style="font-weight:400">(min 8 characters)</span></label><input type="password" name="new" required minlength="8" autocomplete="new-password"></div>
      <div><label>Confirm New Password</label><input type="password" name="confirm" required minlength="8" autocomplete="new-password"></div>
      <div><button class="btn btn-primary">Update Password</button></div>
    </form>
  </div></div>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
