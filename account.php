<?php require_once __DIR__.'/includes/functions.php'; require_login(); $u=current_user();
if($_SERVER['REQUEST_METHOD']==='POST'){
 db()->prepare('INSERT INTO addresses(user_id,label,full_address,city,state,pincode,is_default) VALUES(?,?,?,?,?,?,?)')->execute([$u['id'],$_POST['label'],$_POST['full_address'],$_POST['city'],$_POST['state'],$_POST['pincode'], isset($_POST['is_default'])?1:0]);
}
$ads=db()->prepare('SELECT * FROM addresses WHERE user_id=? ORDER BY is_default DESC,id DESC');$ads->execute([$u['id']]);$addresses=$ads->fetchAll();
require __DIR__.'/includes/header.php'; ?>
<h2>My Account</h2><p><?=e($u['name'])?> (<?=e($u['email'])?>)</p>
<h3>Addresses</h3><?php foreach($addresses as $a): ?><div class='card'><?=e($a['label'])?> - <?=e($a['full_address'])?>, <?=e($a['city'])?> <?=e($a['pincode'])?></div><?php endforeach; ?>
<form method='post'><input class='input' name='label' placeholder='Home/Office' required><br><br><textarea class='input' name='full_address' required></textarea><br><br><div class='row'><input class='input' name='city' required><input class='input' name='state' required><input class='input' name='pincode' required></div><br><label><input type='checkbox' name='is_default'> Default</label><br><br><button class='btn'>Add Address</button></form>
<?php require __DIR__.'/includes/footer.php'; ?>
