<?php
require_once __DIR__ . '/../functions.php';
require_login();
$cur = basename($_SERVER['PHP_SELF']);
$u = current_user();
$PAGE_TITLE = $PAGE_TITLE ?? APP_NAME;

$storeName = setting('store_name', APP_NAME);
$vendorId  = setting('vendor_id', '');
$bizPhone  = setting('store_phone', '');
$bizEmail  = setting('store_email', '');
$helpLine  = setting('help_line', '01-5970736');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,interactive-widget=resizes-content">
<title><?= e($PAGE_TITLE) ?> — <?= e($storeName) ?></title>
<link rel="icon" type="image/png" href="assets/luprah-logo.png">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#3b82f6">
<link rel="apple-touch-icon" href="assets/luprah-logo.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/../assets/style.css') ?>">
<?php $brandColor = trim(setting('brand_color','')); if ($brandColor !== ''): ?>
<style>:root{--brand:<?= e($brandColor) ?>;--topbar:<?= e($brandColor) ?>;}</style>
<?php endif; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>
<body>
<script>/* apply default theme when the user has no saved preference */
(function(){try{if(localStorage.getItem('erp_dark')===null){var d=<?= json_encode(strtolower(setting('default_theme','light'))==='dark') ?>;if(d)document.body.classList.add('dark');}}catch(e){}})();</script>
<?php $notifsOn = strtolower(setting('notifications_enabled','yes')) !== 'no'; ?>
<?php if ($notifsOn && strtolower(setting('enable_toasts','yes')) !== 'no'): ?><div class="toast-wrap" id="toastWrap"></div><?php endif; ?>
<script>window.ERP_CSRF=<?= json_encode(csrf()) ?>;</script>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="biz">
      <div class="biz-logo"><img src="assets/luprah-logo.png" alt="Luprah" class="brand-logo"> <span class="brand-name"><?= e($storeName) ?></span></div>
      <?php $tag = trim(setting('store_tagline','')); if ($tag !== ''): ?><div class="biz-tag"><?= e($tag) ?></div><?php endif; ?>
    </div>
    <nav class="nav" id="sideNav">
      <?php foreach (nav_items() as $n): ?>
        <?php if ($n[0]==='grp'):
          [$_,$gid,$gicon,$glabel,$kids]=$n;
          $vis=array_values(array_filter($kids, fn($k)=>can_see($k[0])));
          if(!$vis) continue;
          $hasCur=false; foreach($vis as $k){ if($cur===$k[0]) $hasCur=true; }
        ?>
          <div class="sg<?= $hasCur?' open cur':'' ?>" data-g="<?= e($gid) ?>">
            <div class="sg-h" onclick="sgToggle(this.parentElement)">
              <span class="ico"><?= $gicon ?></span><?= e($glabel) ?>
              <span class="sg-car">▸</span>
            </div>
            <div class="sg-b">
              <?php foreach ($vis as $k): ?>
                <a class="<?= $cur === $k[0] ? 'active' : '' ?>" href="<?= e($k[0]) ?>"><span class="ico"><?= $k[1] ?></span><?= e($k[2]) ?></a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php else: if (!can_see($n[0])) continue; ?>
          <a class="<?= $cur === $n[0] ? 'active' : '' ?>" href="<?= e($n[0]) ?>"><span class="ico"><?= $n[1] ?></span><?= e($n[2]) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <script>
    function sgToggle(g){
      g.classList.toggle('open');
      try{
        var st=JSON.parse(localStorage.getItem('navOpen')||'{}');
        st[g.getAttribute('data-g')]=g.classList.contains('open')?1:0;
        localStorage.setItem('navOpen',JSON.stringify(st));
      }catch(e){}
    }
    (function(){
      try{
        var st=JSON.parse(localStorage.getItem('navOpen')||'{}');
        document.querySelectorAll('#sideNav .sg').forEach(function(g){
          var k=g.getAttribute('data-g');
          if(g.classList.contains('cur')) return;      /* group of current page always open */
          if(st.hasOwnProperty(k)) g.classList.toggle('open', !!st[k]);
        });
      }catch(e){}
    })();
    </script>
  </aside>
  <div class="backdrop" id="backdrop" onclick="toggleSidebar()"></div>

  <div class="main">
    <header class="topbar">
      <button class="hamb" onclick="toggleSidebar()">☰</button>
      <div class="tb-help">
        <span class="l1">📞 Help Line: <?= e($helpLine) ?></span>
        <span class="l2"><?= e($storeName) ?><?= $bizPhone ? ' · '.e($bizPhone) : '' ?></span>
      </div>
      <div class="spacer"></div>
      <?php if ($notifsOn): ?>
      <div class="notif-wrap">
        <button class="tb-icon notif-bell" id="notifBell" onclick="toggleNotif(event)" title="Notifications">
          <span class="bell-ico">🔔</span><span class="notif-badge" id="notifBadge" style="display:none">0</span>
        </button>
        <div class="notif-panel" id="notifPanel">
          <div class="notif-head">
            <span>Notifications <span class="nh-count" id="nhCount"></span></span>
            <span style="display:flex;gap:10px">
              <a href="#" id="nMarkAll" onclick="markAllNotif(event)">Mark all read</a>
              <a href="notifications.php">View all</a>
            </span>
          </div>
          <div id="notifList"><div class="notif-empty">Loading…</div></div>
        </div>
      </div>
      <?php endif; ?>
      <button class="tb-icon" onclick="toggleTheme()" id="themeBtn" title="Dark mode">🌙</button>
      <a class="tb-add" href="sales.php" title="New order">+</a>
      <div class="profile">
        <a class="avatar" href="profile.php" title="My profile" style="text-decoration:none"><?= e(strtoupper(substr($u['name'],0,1))) ?></a>
        <div class="who"><b><?= e($u['name']) ?></b><br><span><?= e(ucfirst($u['role'])) ?></span></div>
      </div>
      <a class="tb-logout" href="logout.php">Logout</a>
    </header>
    <main class="content">