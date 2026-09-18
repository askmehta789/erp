<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
$u = current_user();
$isAdmin = role_rank($u['role'] ?? '') >= 3;

/* all editable settings, grouped for the form. field = [label, type, options?] */
$groups = [
  'Business Information' => [
    'store_name'    => ['Store / Company Name','text'],
    'store_tagline' => ['Tagline / Slogan','text'],
    'vendor_id'     => ['Vendor ID','text'],
    'store_pan'     => ['PAN / Registration No.','text'],
    'store_phone'   => ['Phone Number(s)','text'],
    'store_email'   => ['Email Address','text'],
    'store_website' => ['Website','text'],
    'help_line'     => ['Help Line','text'],
    'store_address' => ['Business Address','text'],
  ],
  'Finance & Tax' => [
    'currency'      => ['Currency Symbol','text'],
    'vat_percent'   => ['VAT / Tax %','number'],
    'usd_rate'      => ['USD → Rs. Rate','number'],
    'opening_cash'  => ['Opening Cash Balance (Rs.)','number'],
    'opening_bank'  => ['Opening Bank Balance (Rs.)','number'],
    'cancel_charge' => ['Default Cancel/Return Charge (Rs.)','number'],
  ],
  'Orders & Sales Defaults' => [
    'order_prefix'          => ['Order Code Prefix','text'],
    'default_order_status'  => ['Default New-Order Status','select',['pending','processing','shipped','delivered']],
    'default_payment_type'  => ['Default Payment Type','select',['COD','Prepaid']],
    'default_zone'          => ['Default Delivery Zone','select',['inside','outside']],
  ],
  'Delivery Charges' => [
    'delivery_inside'  => ['Inside Valley (Rs.)','number'],
    'delivery_outside' => ['Outside Valley (Rs.)','number'],
  ],
  'Courier / NCM' => [
    'ncm_api_key'        => ['NCM API Key','text'],
    'ncm_enabled'        => ['Enable NCM Sync','select',['yes','no']],
    'ncm_from_branch'    => ['NCM Pickup Branch (default)','text'],
    'ncm_default_weight' => ['Default Parcel Weight (kg)','number'],
    'wa_tracking_template' => ['Tracking Link for WhatsApp ({id} = NCM order id)','text'],
    'ncm_portal_tpl'     => ['NCM Portal Order URL ({id} = order no.; blank = portal login)','text'],
    'usd_rate' => ['USD Rate (Rs. per $1, for ads report)','number'],
    'ncm_return_policy'  => ['When NCM says "return" — mark the sale returned…','select',['confirm','auto','manual']],
    'ncm_aging_days'     => ['Aging Alert After (days)','number'],
    'ncm_atrisk_days'    => ['At-Risk Alert After (days)','number'],
  ],
  'Automation (cron, backups, weekly email)' => [
    'cron_token'   => ['Cron Secret Token (auto-generated)','text'],
    'report_email' => ['Weekly Report Email (blank = Business Email)','text'],
    'cod_alert_threshold' => ['COD Alert — warn when a courier holds more than (Rs., 0 = off)','number'],
    'reorder_lead_days'   => ['Reorder Alert — warn when stock covers fewer than (days)','number'],
    'gdrive_client_id'     => ['Google Drive — Client ID','text'],
    'gdrive_client_secret' => ['Google Drive — Client Secret','text'],
  ],
  'AI Assistant (works free; key = smarter)' => [
    'ai_api_key'    => ['AI API Key (Anthropic) — optional','text'],
    'ai_model'      => ['AI Model (default claude-3-5-haiku-20241022)','text'],
    'ai_auto_reply' => ['Auto-post AI replies to NCM','select',['no','yes']],
  ],
  'Appearance & Notifications' => [
    'brand_color'    => ['Brand Colour (hex, e.g. #3b82f6)','text'],
    'default_theme'  => ['Default Theme','select',['light','dark']],
    'enable_toasts'  => ['Pop-up Notifications','select',['yes','no']],
  ],
  'Categories (comma-separated suggestions)' => [
    'product_categories' => ['Product Categories','text'],
    'expense_categories' => ['Expense Categories','text'],
  ],
];

/* flatten whitelist */
$whitelist = [];
foreach ($groups as $g=>$fields) foreach ($fields as $k=>$m) $whitelist[]=$k;

/* ---- save / danger actions ---- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $act = $_POST['_action'] ?? 'save';

  if ($act === 'clean_blank') {
    try {
      $st = q("DELETE FROM orders WHERE COALESCE(customer,'')='' AND (product_id IS NULL OR product_id=0) AND COALESCE(sell_price,0)=0");
      $n = method_exists($st,'rowCount') ? $st->rowCount() : 0;
      log_activity("Cleaned $n empty orders",'Settings');
      flash("Removed $n empty order(s).");
    } catch (Exception $e) { flash('Error: '.$e->getMessage()); }
    header('Location: settings.php'); exit;
  }

  if ($act === 'gdrive_disconnect') {
    require_once __DIR__.'/gdrive_api.php';
    gdrive_disconnect();
    log_activity('Google Drive backup disconnected','Settings');
    flash('Google Drive disconnected. Backups stay local until you reconnect.');
    header('Location: settings.php'); exit;
  }

  if ($act === 'reset_data') {
    if (!$isAdmin) { flash('Only a Super Admin can reset data.'); header('Location: settings.php'); exit; }
    if (trim($_POST['confirm'] ?? '') !== 'RESET') { flash('Type RESET to confirm — nothing was deleted.'); header('Location: settings.php'); exit; }
    $keepCatalog = !empty($_POST['keep_catalog']);
    try {
      q("SET FOREIGN_KEY_CHECKS=0");
      foreach (['orders','purchases','expenses','ledger','notifications','activity_logs','batch_use','cod_ledger','daraz_orders','daraz_ads','hh_stock'] as $t) { try { q("TRUNCATE TABLE `$t`"); } catch (Exception $e) {} }
      try { q("TRUNCATE TABLE `stock_batches`"); } catch (Exception $e) {}
      if ($keepCatalog) { try { q("UPDATE products SET stock=0"); } catch (Exception $e) {} }   /* catalog kept → quantities zeroed, re-add via batches */
      if (!$keepCatalog) foreach (['products','customers','suppliers','employees'] as $t) { try { q("TRUNCATE TABLE `$t`"); } catch (Exception $e) {} }
      q("SET FOREIGN_KEY_CHECKS=1");
      flash('All data reset. Your login, settings and couriers were kept.');
    } catch (Exception $ex) { flash('Reset error: '.$ex->getMessage()); }
    header('Location: settings.php'); exit;
  }

  try {
    foreach ($whitelist as $k) {
      if ($k === 'cron_token') continue;   /* protected: managed automatically */
      $v = trim($_POST[$k] ?? '');
      q("INSERT INTO settings(skey,svalue) VALUES(?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)", [$k,$v]);
    }
    log_activity('Updated settings','Settings'); flash('Settings saved successfully.');
  } catch (Exception $ex) { flash('Error: '.$ex->getMessage()); }
  header('Location: settings.php'); exit;
}

if (trim((string)setting('cron_token','')) === '') {
  try { q("INSERT INTO settings(skey,svalue) VALUES('cron_token',?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)", [bin2hex(random_bytes(16))]); } catch (Exception $e) {}
}
$PAGE_TITLE='Settings'; require __DIR__.'/includes/header.php';

/* which fields render full-width */
$fullFields = ['store_name','store_address','store_tagline','ncm_api_key','ai_api_key','product_categories','expense_categories','gdrive_client_id','gdrive_client_secret'];
?>
<div class="page-head"><div><h1>⚙️ Settings</h1><p>Configure your store, finances, courier, AI and appearance</p></div></div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="save">
<?php foreach($groups as $title=>$fields): ?>
  <div class="panel" style="margin-bottom:18px">
    <div class="panel-head"><h2><?= e($title) ?></h2></div>
    <div class="panel-body">
      <div class="form-grid" style="grid-template-columns:1fr 1fr">
        <?php foreach($fields as $k=>$m): $full=in_array($k,$fullFields,true); $type=$m[1]; ?>
          <div<?= $full?' class="full"':'' ?>>
            <label><?= e($m[0]) ?></label>
            <?php if($type==='select'): $cur=setting($k, $m[2][0] ?? ''); ?>
              <select name="<?= e($k) ?>">
                <?php foreach($m[2] as $opt): ?><option value="<?= e($opt) ?>"<?= $cur===$opt?' selected':'' ?>><?= e(ucfirst($opt)) ?></option><?php endforeach; ?>
              </select>
            <?php elseif($k==='cron_token'): ?>
              <input name="<?= e($k) ?>" type="text" value="<?= e(setting($k)) ?>" readonly style="background:var(--surface-2);cursor:copy" onclick="this.select();document.execCommand('copy');" title="Click to copy">
            <?php elseif($k==='brand_color'): ?>
              <input name="<?= e($k) ?>" type="text" value="<?= e(setting($k)) ?>" placeholder="#3b82f6">
            <?php else: ?>
              <input name="<?= e($k) ?>" type="<?= $type==='number'?'number':'text' ?>"<?= $type==='number'?' step="any"':'' ?> value="<?= e(setting($k)) ?>">
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
  <button class="btn btn-primary" style="margin-bottom:24px">💾 Save All Settings</button>
</form>

<div class="grid cols-2" style="align-items:start">
  <div class="panel"><div class="panel-head"><h2>👤 Your Account</h2></div><div class="panel-body">
    <div class="info-row"><span class="it">Name</span><span class="iv"><?= e($u['name']) ?></span></div>
    <div class="info-row"><span class="it">Role</span><span class="iv"><?= e($u['role']) ?></span></div>
    <div class="info-row"><span class="it">Manage staff & roles</span><span class="iv"><a class="btn btn-sm" href="users.php">Open Users →</a></span></div>
  </div></div>
  <div class="panel"><div class="panel-head"><h2>🗂 System</h2></div><div class="panel-body">
    <div class="info-row"><span class="it">Activity / audit logs</span><span class="iv"><a class="btn btn-sm" href="activity.php">View Logs →</a></span></div>
    <div class="info-row"><span class="it">Notifications</span><span class="iv"><a class="btn btn-sm" href="notifications.php">Open →</a></span></div>
    <div class="info-row"><span class="it">Remove empty/blank orders</span><span class="iv">
      <form method="post" style="display:inline" onsubmit="return confirm('Delete all blank orders (no customer, product or price)?')">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="clean_blank">
        <button class="btn btn-sm">🧹 Clean up</button>
      </form></span></div>
    <div class="info-row"><span class="it">Theme</span><span class="iv"><button type="button" class="btn btn-sm" onclick="toggleTheme()">Toggle Dark / Light</button></span></div>
  </div></div>
</div>

<div class="panel" style="margin-top:20px">
  <div class="panel-head"><h2>⏱ Automation Setup (one-time)</h2></div>
  <div class="panel-body">
    <p class="muted" style="margin-bottom:10px">In <b>cPanel → Cron Jobs</b>, add a job running <b>every 10 minutes</b> with this command (replace <code>YOURUSER</code> with your cPanel username):</p>
    <div class="card" style="padding:10px 14px;font-family:monospace;font-size:12.5px;overflow-x:auto">/usr/local/bin/php /home/YOURUSER/public_html/erp/cron.php <?= e(setting('cron_token','')) ?></div>
    <p class="muted" style="margin:10px 0 0;font-size:12.5px">This enables: automatic NCM status sync (24/7), a daily database backup to <code>/erp/backups/</code> (kept 14 days), and the Monday weekly email report. You can also trigger it by URL: <code>https://luprah.online/erp/cron.php?token=<?= e(setting('cron_token','')) ?></code></p>
  </div>
</div>

<?php
require_once __DIR__.'/gdrive_api.php';
$gdConnected = trim((string)setting('gdrive_refresh_token','')) !== '';
$gdHasCreds  = trim((string)setting('gdrive_client_id','')) !== '' && trim((string)setting('gdrive_client_secret','')) !== '';
?>
<div class="panel" style="margin-top:20px">
  <div class="panel-head"><h2>☁️ Google Drive Backup <?= $gdConnected?'<span class="pill p-green" style="font-size:10.5px">Connected</span>':'<span class="pill p-amber" style="font-size:10.5px">Not connected</span>' ?></h2></div>
  <div class="panel-body">
    <?php if($gdConnected): ?>
      <p class="muted" style="margin-bottom:10px">Every daily backup uploads automatically to a folder called <b>Luprah ERP Backups</b> in your connected Google Drive — a copy that survives even if this hosting account ever has a problem.</p>
      <form method="post" onsubmit="return confirm('Disconnect Google Drive? Backups will stop uploading there until you reconnect.')">
        <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="gdrive_disconnect">
        <button class="btn btn-sm">🔌 Disconnect</button>
      </form>
    <?php elseif($gdHasCreds): ?>
      <p class="muted" style="margin-bottom:10px">Client ID and Secret are saved. Click below to sign in with the Google account you want backups sent to — a one-time step.</p>
      <a class="btn btn-primary" href="<?= e(gdrive_auth_url()) ?>">🔗 Connect Google Drive</a>
    <?php else: ?>
      <p class="muted" style="margin-bottom:10px">Not set up yet. Two things needed, both one-time:</p>
      <ol class="muted" style="font-size:12.5px;line-height:1.9;padding-left:20px;margin-bottom:6px">
        <li>Create a free Google Cloud project, enable the <b>Google Drive API</b>, and create an <b>OAuth Client ID</b> (type: Web application).</li>
        <li>Under "Authorized redirect URIs" add exactly: <code>https://luprah.online/erp/gdrive_auth.php</code></li>
        <li>Paste the Client ID and Client Secret it gives you into the <b>Automation</b> section above, click <b>Save All Settings</b>, then come back to this box to Connect.</li>
      </ol>
      <p class="muted" style="font-size:11.5px">Guide: <a href="https://developers.google.com/workspace/guides/create-credentials" target="_blank" rel="noopener">developers.google.com — Create credentials</a></p>
    <?php endif; ?>
  </div>
</div>

<?php if($isAdmin): ?>
<div class="panel" style="margin-top:20px;border:1px solid var(--red-bg)">
  <div class="panel-head"><h2 style="color:var(--red)">🛑 Danger Zone — Reset Data</h2></div>
  <div class="panel-body">
    <p class="muted" style="margin-bottom:12px">Permanently deletes orders, purchases, expenses, ledger, notifications and logs (and products/customers/suppliers unless you tick “keep catalogue”). Your <b>login, settings and couriers are kept</b>. Export a backup in phpMyAdmin first — this can't be undone.</p>
    <form method="post" onsubmit="return confirm('This permanently deletes your business data. Continue?')" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="reset_data">
      <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" name="keep_catalog" value="1" style="width:auto"> Keep products / customers / suppliers</label>
      <input name="confirm" placeholder="Type RESET to confirm" required style="width:200px;padding:8px 10px;border:1px solid var(--border);border-radius:8px">
      <button class="btn" style="background:var(--red);border-color:var(--red);color:#fff">Reset All Data</button>
    </form>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>