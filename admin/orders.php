<?php require_once __DIR__.'/../includes/functions.php'; require_admin();
if($_SERVER['REQUEST_METHOD']==='POST'){db()->prepare('UPDATE orders SET order_status=? WHERE id=?')->execute([$_POST['order_status'],(int)$_POST['id']]);}
$orders=db()->query('SELECT o.*,u.name uname FROM orders o LEFT JOIN users u ON u.id=o.user_id ORDER BY o.id DESC')->fetchAll();
require __DIR__.'/../includes/header.php'; ?>
<h2>Orders</h2><table class='table'><tr><th>#</th><th>User</th><th>Total</th><th>Payment</th><th>Status</th></tr><?php foreach($orders as $o): ?><tr><td><?=$o['id']?></td><td><?=e($o['uname']??'')?></td><td><?=money((float)$o['total'])?></td><td><?=e($o['payment_method'])?></td><td><form method='post'><input type='hidden' name='id' value='<?=$o['id']?>'><select name='order_status'><option><?=$o['order_status']?></option><option>processing</option><option>delivered</option></select><button>Update</button></form></td></tr><?php endforeach; ?></table>
<?php require __DIR__.'/../includes/footer.php'; ?>
