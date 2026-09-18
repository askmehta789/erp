<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access(); require_role(['Super Admin','Manager']);
$PAGE_TITLE='Activity Logs'; require __DIR__.'/includes/header.php';
$logs=rows("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 300");
?>
<div class="page-head"><div><h1>Activity Logs</h1><p>Recent system activity (last 300)</p></div></div>
<div class="card"><div class="toolbar"><input class="search-in" placeholder="Search…" onkeyup="filterTable(this)"></div>
<div class="table-wrap"><table class="tbl" id="mainTable"><thead><tr><th>When</th><th>User</th><th>Action</th><th>Module</th></tr></thead><tbody>
<?php foreach($logs as $l): ?>
<tr><td class="muted num"><?= e($l['created_at']) ?></td><td><b><?= e($l['user_name']) ?></b></td><td><?= e($l['action']) ?></td><td><span class="pill p-grey"><?= e($l['module']) ?></span></td></tr>
<?php endforeach; if(!$logs) echo '<tr><td colspan="4"><div class="empty">No activity yet.</div></td></tr>'; ?>
</tbody></table></div></div>
<?php require __DIR__.'/includes/footer.php'; ?>
