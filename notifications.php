<?php
require_once __DIR__.'/functions.php'; require_login();
ensure_notifications();

/* live poll endpoint — powers the bell badge, dropdown and toasts */
if (isset($_GET['poll'])) {
  header('Content-Type: application/json');
  $list = notifications_recent(15);
  echo json_encode([
    'unread' => notifications_unread(),
    'items'  => array_map(function($n){ return [
      'id'=>(int)$n['id'],'type'=>$n['type'],'message'=>$n['message'],
      'link'=>$n['link'],'created_at'=>$n['created_at'],'is_read'=>(int)$n['is_read'],
      'age'=>max(0,(int)($n['age_s'] ?? 0)),   /* seconds old, computed by the DB clock — timezone-proof */
    ]; }, $list),
  ]);
  exit;
}

/* AJAX: mark one / all read without leaving the page */
if (isset($_POST['ajax_read'])) {
  header('Content-Type: application/json');
  $tok = $_POST['csrf'] ?? '';
  if (!is_string($tok) || !hash_equals(csrf(), $tok)) { echo json_encode(['ok'=>false]); exit; }
  try {
    if ($_POST['ajax_read']==='all') q("UPDATE notifications SET is_read=1");
    else q("UPDATE notifications SET is_read=1 WHERE id=?", [(int)$_POST['ajax_read']]);
  } catch (Exception $e) {}
  echo json_encode(['ok'=>true,'unread'=>notifications_unread()]); exit;
}

/* click a notification: mark read then go to its link */
if (isset($_GET['read'])) {
  try { q("UPDATE notifications SET is_read=1 WHERE id=?", [(int)$_GET['read']]); } catch (Exception $e) {}
  $go = $_GET['go'] ?? 'notifications.php';
  /* allow only local pages we ship, with an optional safe query string */
  if (!preg_match('~^[a-z0-9_]+\.php(\?[a-zA-Z0-9_=&%\.\-]*)?$~', $go)) $go = 'notifications.php';
  header('Location: '.$go); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  try {
    if (($_POST['do']??'')==='read_all') q("UPDATE notifications SET is_read=1");
    if (($_POST['do']??'')==='clear')    q("DELETE FROM notifications");
  } catch (Exception $e) {}
  header('Location: notifications.php'); exit;
}

$PAGE_TITLE='Notifications';
$filter = $_GET['t'] ?? 'all';
$list = notifications_recent(200);
if ($filter==='unread') $list = array_values(array_filter($list, fn($n)=>!$n['is_read']));
elseif (in_array($filter,['order','delivered','ncm','comment','alert'],true)) $list = array_values(array_filter($list, fn($n)=>$n['type']===$filter));
require __DIR__.'/includes/header.php';

function notif_icon($t){ return ['order'=>'🛒','delivered'=>'✅','status'=>'🔄','ncm'=>'🚚','comment'=>'💬','alert'=>'⚠️','info'=>'🔔'][$t] ?? '🔔'; }
function notif_color($t){ return ['order'=>'#3b82f6','delivered'=>'#10b981','status'=>'#6366f1','ncm'=>'#f59e0b','comment'=>'#8b5cf6','alert'=>'#ef4444','info'=>'#64748b'][$t] ?? '#64748b'; }
function time_ago($sec){ $s=max(1,(int)$sec);
  if($s<60)return 'just now'; if($s<3600)return floor($s/60).'m ago'; if($s<86400)return floor($s/3600).'h ago';
  if($s<172800)return 'yesterday'; return floor($s/86400).'d ago'; }
$unreadCount = notifications_unread();
$tabs=['all'=>'All','unread'=>'Unread','order'=>'Orders','delivered'=>'Delivered','ncm'=>'NCM','comment'=>'Comments','alert'=>'Alerts'];
?>
<div class="page-head">
  <div><h1>🔔 Notifications</h1><p><?= $unreadCount ?> unread · order, delivery and courier updates</p></div>
  <div style="display:flex;gap:10px">
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="read_all"><button class="btn">✓ Mark all read</button></form>
    <form method="post" style="display:inline" onsubmit="return confirm('Clear all notifications?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="clear"><button class="btn btn-danger">🗑 Clear all</button></form>
  </div>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
  <?php foreach($tabs as $k=>$lbl): ?><a class="btn btn-sm <?= $filter===$k?'btn-primary':'' ?>" href="notifications.php?t=<?= $k ?>"><?= e($lbl) ?></a><?php endforeach; ?>
</div>

<div class="panel">
  <?php if($list): foreach($list as $n): $col=notif_color($n['type']); ?>
    <a class="notif-item<?= $n['is_read']?'':' unread' ?>" style="border-bottom:1px solid var(--border)" href="notifications.php?read=<?= (int)$n['id'] ?>&go=<?= urlencode($n['link']?:'notifications.php') ?>">
      <span class="ni-ico" style="background:<?= $col ?>1a;color:<?= $col ?>"><?= notif_icon($n['type']) ?></span>
      <span class="ni-body">
        <span class="nm"><?= e($n['message']) ?></span>
        <span class="nt"><?= e(time_ago($n['age_s'] ?? 0)) ?> · <?= e(date('d M, h:i A', strtotime($n['created_at']))) ?></span>
      </span>
      <?php if(!$n['is_read']): ?><span class="ni-dot" style="background:<?= $col ?>"></span><?php endif; ?>
    </a>
  <?php endforeach; else: ?>
    <div class="empty"><div style="font-size:30px;margin-bottom:8px">🔕</div>Nothing here<?= $filter!=='all'?' for this filter':'' ?> — you're all caught up!</div>
  <?php endif; ?>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
