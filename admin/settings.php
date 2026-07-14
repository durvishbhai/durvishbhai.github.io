<?php require_once __DIR__.'/../includes/functions.php'; require_admin();
$keys=['delivery_enable','delivery_charge','free_delivery_min','min_order_value','cod_enabled','phonepe_enabled','demo_payment_enabled','shiprocket_enabled'];
if($_SERVER['REQUEST_METHOD']==='POST'){
  foreach($keys as $k){$v=$_POST[$k]??'0';db()->prepare('INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$k,(string)$v]);}
}
$vals=[]; foreach(db()->query('SELECT `key`,`value` FROM settings')->fetchAll() as $r){$vals[$r['key']]=$r['value'];}
require __DIR__.'/../includes/header.php'; ?>
<h2>Core Settings</h2><form method='post'><?php foreach($keys as $k): ?><label><?=e($k)?><input class='input' name='<?=$k?>' value='<?=e($vals[$k]??'0')?>'></label><br><br><?php endforeach; ?><button class='btn'>Save Settings</button></form>
<?php require __DIR__.'/../includes/footer.php'; ?>
