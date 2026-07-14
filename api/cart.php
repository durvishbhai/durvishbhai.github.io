<?php require_once __DIR__.'/../includes/functions.php';
$pid=(int)($_POST['product_id']??0);$qty=max(1,(int)($_POST['qty']??1));
if($pid){$_SESSION['cart'][$pid]=($_SESSION['cart'][$pid]??0)+$qty;}
redirect($_SERVER['HTTP_REFERER']??'/cart.php');
