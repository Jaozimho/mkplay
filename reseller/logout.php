<?php
session_start();
unset($_SESSION['reseller_user_id'], $_SESSION['reseller_csrf']);
header('Location: login.php');
exit;
