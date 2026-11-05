<?php
/* daraz_link.php — JSON endpoint behind "Link / check by Daraz order ID".
   GET  ?action=check&id=123[&fin=0]  → fetch ONE order from Daraz + compare with the ERP (read-only)
   POST action=apply, id, csrf        → re-fetch that order and write Daraz's values into the ERP */
require_once __DIR__.'/daraz_common.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function dzl_out(array $a, int $code = 200) { http_response_code($code); echo json_encode($a); exit; }

if (!can_see('daraz_sales.php') && !can_see('daraz.php')) dzl_out(['ok'=>false,'error'=>'You do not have access to Daraz pages.'], 403);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id     = preg_replace('/\D+/', '', (string)($_POST['id'] ?? $_GET['id'] ?? ''));
if ($id === '') dzl_out(['ok'=>false,'error'=>'Enter a Daraz order number.'], 400);

try {
  if ($action === 'check') {
    $withFin = ($_GET['fin'] ?? '1') !== '0';
    $dz = daraz_fetch_order($id, $withFin);
    $cmp = daraz_compare($dz);
    dzl_out(['ok'=>true, 'order'=>[
      'id'=>$dz['order_id'], 'created'=>$dz['created'], 'customer'=>$dz['customer'], 'phone'=>$dz['phone'],
      'city'=>$dz['city'], 'payment'=>$dz['payment'], 'finance'=>$dz['finance'],
    ], 'compare'=>$cmp]);
  }
  if ($action === 'apply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $perm = order_perms();
    $dz = daraz_fetch_order($id, ($_POST['fin'] ?? '1') !== '0');
    $linked = (bool)daraz_erp_rows($id);
    if ($linked && !$perm['edit']) dzl_out(['ok'=>false,'error'=>'You do not have permission to edit orders.'], 403);
    if (!$linked && !$perm['add']) dzl_out(['ok'=>false,'error'=>'You do not have permission to add orders.'], 403);
    $res = daraz_apply($dz);
    dzl_out(['ok'=>true, 'result'=>$res, 'compare'=>daraz_compare(daraz_fetch_order($id, false))]);
  }
  dzl_out(['ok'=>false,'error'=>'Unknown action.'], 400);
} catch (Exception $e) {
  dzl_out(['ok'=>false,'error'=>$e->getMessage()], 200);
}
