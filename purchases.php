<?php require_once __DIR__.'/functions.php'; require_login(); require_page_access();
ensure_purchase_products();
if (($_POST['_entity'] ?? '') === 'suppliers') handle_crud('suppliers');   /* embedded suppliers module */
handle_crud('purchases');
$PAGE_TITLE='Purchasing'; require __DIR__.'/includes/header.php';
render_crud('purchases','Purchasing','Purchase orders to suppliers');
render_crud('suppliers','🏭 Suppliers','vendors & payables','embed');
require __DIR__.'/includes/footer.php';