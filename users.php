<?php
require_once __DIR__.'/functions.php'; require_login(); require_page_access(); require_role(['Super Admin']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf(); $a=$_POST['_action']??'';
  try {
    if ($a==='save_access') {
      ensure_user_pages();
      $uid=(int)$_POST['uid'];
      $target=row("SELECT id,role FROM users WHERE id=?",[$uid]);
      if(!$target) flash('User not found.');
      elseif(role_rank($target['role'])>=3) flash('Super Admins always have full access.');
      else {
        $allowed=$_POST['pages'] ?? [];               /* checked boxes */
        q("DELETE FROM user_pages WHERE user_id=?",[$uid]);
        foreach (all_access_pages() as $pg=>$meta) {
          $isAllowed = in_array($pg,$allowed,true) ? 1 : 0;
          $def = role_rank($target['role']) >= page_min_rank($pg) ? 1 : 0;
          if ($isAllowed !== $def)                     /* store only differences from role default */
            q("INSERT INTO user_pages(user_id,page,allow) VALUES(?,?,?)",[$uid,$pg,$isAllowed]);
        }
        log_activity("Access updated for user #$uid",'Users');
        flash('Access saved.');
      }
      header('Location: users.php'); exit;
    }
    if ($a==='reset_access') {
      ensure_user_pages();
      q("DELETE FROM user_pages WHERE user_id=?",[(int)$_POST['uid']]);
      flash('Access reset to role defaults.');
      header('Location: users.php'); exit;
    }
    if ($a==='delete') {
      if ((int)$_POST['id'] === (int)current_user()['id']) flash('You cannot delete your own account.');
      else { q("DELETE FROM users WHERE id=?", [(int)$_POST['id']]); flash('User deleted.'); }
    } else {
      $name=trim($_POST['name']??''); $un=trim($_POST['username']??''); $em=trim($_POST['email']??'');
      $role=$_POST['role']??'Staff'; $st=$_POST['status']??'active'; $pw=$_POST['password']??'';
      if ($a==='update') {
        if ($pw!=='') q("UPDATE users SET name=?,username=?,email=?,role=?,status=?,password_hash=? WHERE id=?",
                        [$name,$un,$em,$role,$st,password_hash($pw,PASSWORD_DEFAULT),(int)$_POST['id']]);
        else q("UPDATE users SET name=?,username=?,email=?,role=?,status=? WHERE id=?",[$name,$un,$em,$role,$st,(int)$_POST['id']]);
        flash('User updated.');
      } else {
        if (strlen($pw)<6) flash('Password must be at least 6 characters.');
        else { q("INSERT INTO users(name,username,email,role,status,password_hash) VALUES(?,?,?,?,?,?)",
                 [$name,$un,$em,$role,$st,password_hash($pw,PASSWORD_DEFAULT)]); flash('User added.'); }
      }
    }
  } catch (Exception $ex) { flash('Error: that username may already exist.'); }
  header('Location: users.php'); exit;
}
function all_access_pages() {
  return [
    'index.php'=>['🏠','Dashboard','Core'], 'sales.php'=>['🛒','Sales','Core'],
    'ncm.php'=>['📮','NCM Courier','Delivery'], 'hungryhunter.php'=>['🛵','Hungry Hunter','Delivery'],
    'dropex.php'=>['🚴','Dropex','Delivery'],
    'daraz.php'=>['🛍','Daraz','Delivery'], 'couriers.php'=>['🚚','All Couriers','Delivery'],
    'cod.php'=>['💵','COD Ledger','Money'], 'banks.php'=>['🏛','Bank Accounts','Money'], 'payees.php'=>['📣','Ads & Vendors','Money'],
    'accounting.php'=>['📒','Accounting','Money'], 'analytics.php'=>['📈','Analytics','Money'],
    'expenses.php'=>['💸','Expenses','Money'], 'reports.php'=>['📊','Reports (classic)','Money'],
    'invoice.php'=>['🧾','Invoices','Core'], 'ncm_comments.php'=>['💬','NCM Comment Center','Delivery'],
    'products.php'=>['🏷️','Products','Inventory'], 'vendor_purchases.php'=>['🏭','Vendor Purchases','Inventory'],
    'inventory.php'=>['📦','Stock Batches','Inventory'],
    'customers.php'=>['👥','Customer Insights','People'], 'salary.php'=>['💵','Staff & Salary','People'], 'lunch_management.php'=>['🍱','Lunch Management','People'], 'hrm.php'=>['🧑‍💼','HRM','People'],
    'settings.php'=>['⚙️','Settings','System'], 'activity.php'=>['📜','Activity Logs','System'], 'labels.php'=>['🏷','Labels','System'],
  ];
}
$PAGE_TITLE='Users'; require __DIR__.'/includes/header.php';
$us=rows("SELECT id,name,username,email,role,status,last_login FROM users ORDER BY id");
?>
<div class="page-head"><div><h1>Users</h1><p>System users and access</p></div>
<button class="btn btn-primary" onclick="openForm()">+ Add User</button></div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>
<div class="card"><div class="toolbar"><input class="search-in" placeholder="Search…" onkeyup="filterTable(this)"></div>
<div class="table-wrap"><table class="tbl" id="mainTable"><thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Last Login</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($us as $r): ?>
<tr><td><b><?= e($r['name']) ?></b></td><td><?= e($r['username']) ?></td><td><?= e($r['email']) ?></td>
<td><?= pill($r['role']==='Super Admin'?'confirmed':($r['role']==='Manager'?'shipped':'pending')) ?> <span class="muted" style="font-size:12px"><?= e($r['role']) ?></span><?php ensure_user_pages(); if(role_rank($r['role'])<3 && user_page_overrides($r['id'])): ?> <span class="pill p-blue" style="font-size:9px">custom access</span><?php endif; ?></td>
<td class="muted"><?= e($r['last_login'] ?: 'never') ?></td><td><?= pill($r['status']) ?></td>
<td class="right nowrap"><?php
  $ovs = role_rank($r['role'])>=3 ? [] : user_page_overrides($r['id']);
  $eff = [];
  foreach (all_access_pages() as $pg=>$meta) {
    $eff[$pg] = role_rank($r['role'])>=3 ? 1 : (isset($ovs[$pg]) ? (int)$ovs[$pg] : (role_rank($r['role'])>=page_min_rank($pg)?1:0));
  }
?><button class="iact" title="Page access" data-uid="<?= (int)$r['id'] ?>" data-name="<?= e($r['name']) ?>" data-role="<?= e($r['role']) ?>" data-eff='<?= e(json_encode($eff)) ?>' data-custom="<?= $ovs?1:0 ?>" onclick="openAccess(this)">🔑</button>
<button class="iact" data-rec='<?= e(json_encode($r)) ?>' onclick="editRow(this)">✏️</button>
<form method="post" style="display:inline" onsubmit="return confirm('Delete this user?')"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="iact del">🗑</button></form></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<div class="modal-bg" id="modalBg"><form class="modal" method="post">
<div class="modal-head"><span id="mTitle">Add User</span><span class="mx" onclick="closeForm()">✕</span></div>
<input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" id="mAction" value="add"><input type="hidden" name="id" id="mId" value="">
<div class="modal-body">
<div class="full"><label>Name</label><input name="name"></div>
<div><label>Username</label><input name="username"></div>
<div><label>Email</label><input name="email"></div>
<div><label>Role</label><select name="role"><option>Super Admin</option><option>Manager</option><option>Accountant</option><option selected>Staff</option></select></div>
<div><label>Status</label><select name="status"><option>active</option><option>inactive</option></select></div>
<div class="full"><label>Password <span class="muted" style="font-weight:400">(leave blank when editing to keep current)</span></label><input name="password" type="password"></div>
</div><div class="modal-foot"><button type="button" class="btn" onclick="closeForm()">Cancel</button><button class="btn btn-primary">Save</button></div></form></div>
<!-- page access modal -->
<div class="modal-bg" id="accBg" style="z-index:99991"><form class="modal" method="post" style="width:560px;max-width:96vw">
  <div class="modal-head"><span id="accTitle">🔑 Page Access</span><span class="mx" onclick="closeAccess()">✕</span></div>
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="save_access"><input type="hidden" name="uid" id="accUid">
  <div style="padding:14px 20px;max-height:60vh;overflow-y:auto" id="accBody">
    <p class="muted" style="font-size:12px;margin-bottom:12px">Tick the pages this person can open. Unticked pages disappear from their menu and are blocked even by direct link. <b id="accRoleNote"></b></p>
    <?php
      $byGroup=[];
      foreach (all_access_pages() as $pg=>$m) $byGroup[$m[2]][$pg]=$m;
      foreach ($byGroup as $g=>$pages): ?>
      <div style="margin-bottom:12px">
        <div style="font-size:10px;font-weight:800;letter-spacing:.1em;color:var(--muted);text-transform:uppercase;margin-bottom:6px;display:flex;align-items:center;gap:8px"><?= e($g) ?>
          <a href="javascript:void(0)" onclick="accGroup(this,1)" style="font-size:10px">all</a>
          <a href="javascript:void(0)" onclick="accGroup(this,0)" style="font-size:10px">none</a></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 14px">
          <?php foreach ($pages as $pg=>$m): ?>
            <label style="display:flex;gap:9px;align-items:center;font-size:12.5px;font-weight:600;padding:6px 9px;border-radius:9px;background:var(--surface-2);cursor:pointer">
              <input type="checkbox" name="pages[]" value="<?= e($pg) ?>" class="accCb"> <?= $m[0] ?> <?= e($m[1]) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="modal-foot" style="justify-content:space-between">
    <button type="button" class="btn" onclick="accReset()" id="accResetBtn">↺ Reset to role defaults</button>
    <span><button type="button" class="btn" onclick="closeAccess()">Cancel</button> <button class="btn btn-primary">💾 Save Access</button></span>
  </div>
</form></div>
<form method="post" id="accResetForm" style="display:none">
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="_action" value="reset_access"><input type="hidden" name="uid" id="accResetUid">
</form>
<script>
function openAccess(btn){
  var uid=btn.getAttribute('data-uid'), name=btn.getAttribute('data-name'), role=btn.getAttribute('data-role');
  var eff=JSON.parse(btn.getAttribute('data-eff'));
  if(role==='Super Admin'){ alert('Super Admins always have full access to everything.'); return; }
  document.getElementById('accUid').value=uid;
  document.getElementById('accResetUid').value=uid;
  document.getElementById('accTitle').textContent='🔑 Page Access — '+name;
  document.getElementById('accRoleNote').textContent='Role: '+role+(btn.getAttribute('data-custom')==='1'?' (has custom access)':' (using role defaults)');
  document.querySelectorAll('.accCb').forEach(function(cb){ cb.checked = eff[cb.value]===1; });
  document.getElementById('accBg').classList.add('open'); document.body.classList.add('modal-open');
}
function closeAccess(){document.getElementById('accBg').classList.remove('open');document.body.classList.remove('modal-open');}
function accGroup(a,on){ a.closest('div').parentElement.querySelectorAll('.accCb').forEach(function(cb){cb.checked=!!on;}); }
function accReset(){ if(confirm('Remove custom access and return this user to their role defaults?')) document.getElementById('accResetForm').submit(); }
(function(){var m=document.getElementById('accBg');m.addEventListener('click',function(e){if(e.target===m)closeAccess();});})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>