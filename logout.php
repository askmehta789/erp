<?php
require_once __DIR__ . '/auth.php';
if (current_user()) log_activity('Logged out', 'Auth');
$_SESSION = [];
session_destroy();
header('Location: login.php');
