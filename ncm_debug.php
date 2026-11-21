<?php
/* NCM diagnostic — Super Admin only. Shows EXACTLY what NCM's API returns for one order,
   how the ERP parses it, and the verdict. Use: ncm_debug.php?id=23881334 */
require_once __DIR__.'/functions.php'; require_once __DIR__.'/ncm_api.php';
require_login();
if (role_rank(current_user()['role'] ?? '') < 3) { http_response_code(403); exit('Super Admin only'); }
$PAGE_TITLE='NCM Debug';
$id = trim((string)($_GET['id'] ?? ''));
$rawHist=null; $histErr=''; $items=[]; $verdict=''; $rawCmt=null; $cmtErr=''; $local=null;
if ($id!=='') {
  try { $rawHist = ncm()->statusHistory((int)$id); } catch (Exception $e) { $histErr=$e->getMessage(); }
  if (is_array($rawHist)) { $items = ncm_hist_items($rawHist); $verdict = ncm_timeline_verdict($items); }
  try { $rawCmt = ncm()->comments((int)$id); } catch (Exception $e) { $cmtErr=$e->getMessage(); }
  try { $local = row("SELECT id,code,customer,status,payment_status,ncm_order_id,ncm_status,ncm_return_flag FROM orders WHERE ncm_order_id=? LIMIT 1",[$id]); } catch (Exception $e) {}
}
require __DIR__.'/includes/header.php';
?>
<div class="page-head"><div><h1>🔬 NCM Debug</h1><p>Raw API responses for one NCM order — for support/diagnosis</p></div></div>
<div class="panel" style="padding:16px">
  <form method="get" style="display:flex;gap:8px;max-width:420px">
    <input name="id" value="<?= e($id) ?>" placeholder="NCM order id e.g. 23881334" style="flex:1;border:1px solid #dfe4f2;border-radius:10px;padding:9px 12px;font:inherit">
    <button class="btn btn-primary">Inspect</button>
  </form>
  <?php if($id!==''): ?>
    <h3 style="margin:16px 0 6px">Local order in ERP</h3>
    <pre style="background:#101528;color:#cdd6f4;border-radius:10px;padding:12px;font-size:12px;overflow-x:auto"><?= e($local?json_encode($local,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE):'not found by ncm_order_id') ?></pre>
    <h3 style="margin:16px 0 6px">Parsed timeline (<?= count($items) ?> steps) → verdict: <b style="color:<?= $verdict==='vendor'?'#c0392b':($verdict==='customer'?'#12a06a':'#b45309') ?>"><?= e($verdict?:'—') ?></b></h3>
    <pre style="background:#101528;color:#cdd6f4;border-radius:10px;padding:12px;font-size:12px;overflow-x:auto"><?= e($items?json_encode($items,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE):'(no steps parsed)') ?></pre>
    <h3 style="margin:16px 0 6px">RAW statusHistory response <?= $histErr?'(ERROR)':'' ?></h3>
    <pre style="background:#101528;color:#cdd6f4;border-radius:10px;padding:12px;font-size:12px;overflow-x:auto"><?= e($histErr ?: json_encode($rawHist,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
    <h3 style="margin:16px 0 6px">RAW comments response <?= $cmtErr?'(ERROR)':'' ?></h3>
    <pre style="background:#101528;color:#cdd6f4;border-radius:10px;padding:12px;font-size:12px;overflow-x:auto"><?= e($cmtErr ?: json_encode($rawCmt,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
    <p class="muted" style="margin-top:10px">📸 Screenshot this whole page (both RAW sections) and share it for exact diagnosis.</p>
  <?php endif; ?>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
