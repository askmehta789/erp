<?php
/* Merged into customers.php ("Customer Insights") — this file now just
   redirects so any old bookmarks/links keep working, carrying over the
   product filter (?pid=) if one was set. */
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
$pid = (int)($_GET['pid'] ?? 0);
header('Location: customers.php'.($pid ? ('?pid='.$pid) : ''));
exit;
