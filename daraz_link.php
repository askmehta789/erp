<?php
/* daraz_link.php — JSON endpoint behind "Link / check by Daraz order ID".
   Orders are booked by hand in the ERP; Daraz only supplies STATUS and CHARGES.
   GET  ?action=check&id=DARAZ[&fin=0]      → fetch ONE Daraz order, compare with its linked ERP
                                              order (or suggest ERP orders to link it to)
   POST action=link,   erp_id, id, csrf      → link a hand-booked ERP order to that Daraz number
   POST action=unlink, erp_id, csrf          → remove the link
   POST action=apply,  id, csrf              → sync status + Daraz charges into the linked order */
require_once __DIR__.'/daraz_common.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function dzl_out(array $a, int $code = 200) { http_response_code($code); echo json_encode($a); exit; }

if (!can_see('daraz_sales.php') && !can_see('daraz.php')) dzl_out(['ok'=>false,'error'=>'You do not have access to Daraz pages.'], 403);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id     = preg_replace('/\D+/', '', (string)($_POST['id'] ?? $_GET['id'] ?? ''));
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

try {
  if ($action === 'check') {
    if ($id === '') dzl_out(['ok'=>false,'error'=>'Enter a Daraz order number.']);
    $dz = daraz_fetch_order($id, ($_GET['fin'] ?? '1') !== '0');
    $cmp = daraz_compare($dz);
    dzl_out(['ok'=>true, 'order'=>[
      'id'=>$dz['order_id'], 'created'=>$dz['created'], 'customer'=>$dz['customer'], 'phone'=>$dz['phone'],
      'city'=>$dz['city'], 'payment'=>$dz['payment'], 'finance'=>$dz['finance'],
    ], 'compare'=>$cmp, 'candidates'=>$cmp['linked'] ? [] : daraz_link_candidates($dz)]);
  }

  if (!$isPost) dzl_out(['ok'=>false,'error'=>'Unknown action.'], 400);
  check_csrf();
  if (!order_perms()['edit']) dzl_out(['ok'=>false,'error'=>'You do not have permission to edit orders.'], 403);

  if ($action === 'link') {
    $erpId = (int)($_POST['erp_id'] ?? 0);
    if (!$erpId && trim((string)($_POST['erp_code'] ?? '')) !== '') {
      $erpId = (int)val("SELECT id FROM orders WHERE code=? LIMIT 1", [trim((string)$_POST['erp_code'])]);
      if (!$erpId) dzl_out(['ok'=>false,'error'=>'No ERP order with code "'.trim((string)$_POST['erp_code']).'".']);
    }
    $r = daraz_link_order($erpId, $id);
    dzl_out(['ok'=>true, 'linked'=>$r]);
  }
  if ($action === 'unlink') {
    dzl_out(['ok'=>true, 'code'=>daraz_unlink_order((int)($_POST['erp_id'] ?? 0))]);
  }
  if ($action === 'apply') {
    if ($id === '') dzl_out(['ok'=>false,'error'=>'Missing Daraz order number.']);
    $dz = daraz_fetch_order($id, ($_POST['fin'] ?? '1') !== '0');
    $res = daraz_apply($dz);
    dzl_out(['ok'=>true, 'result'=>$res]);
  }
  dzl_out(['ok'=>false,'error'=>'Unknown action.'], 400);
} catch (Exception $e) {
  dzl_out(['ok'=>false,'error'=>$e->getMessage()]);
}
