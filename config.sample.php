<?php
/* ============================================================
   STEP 1 — Copy this file to config.php and edit the password line
   with your cPanel MySQL password.
   (DB name + user below are pre-filled from your account; verify
    they match cPanel → MySQL Databases, then set the password.)
   ============================================================ */
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');          // verify in cPanel → MySQL Databases
define('DB_USER', 'your_db_user');          // the MySQL user you created
define('DB_PASS', 'your_db_password');      // <-- put that user's password here

/* ---- App settings (safe defaults; also editable in Settings) ---- */
define('APP_NAME', 'Luprah Trading PVT.LTD');
define('CURRENCY', 'Rs.');
define('USD_RATE', 150);                     // 1 USD = X NPR (fallback)
date_default_timezone_set('Asia/Kathmandu');
