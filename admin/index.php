<?php require_once __DIR__.'/../includes/functions.php'; require_admin();
$counts=['users'=>db()->query('SELECT COUNT(*) c FROM users')->fetch()['c'],'orders'=>db()->query('SELECT COUNT(*) c FROM orders')->fetch()['c'],'products'=>db()->query('SELECT COUNT(*) c FROM products')->fetch()['c']];
require __DIR__.'/../includes/header.php'; ?>
<h2>Admin Dashboard</h2><div class='row'><?php foreach($counts as $k=>$v): ?><div class='card'><h3><?=$v?></h3><p><?=ucfirst($k)?></p></div><?php endforeach; ?></div>
<p><a class='btn' href='/admin/products.php'>Products</a> <a class='btn' href='/admin/orders.php'>Orders</a> <a class='btn' href='/admin/users.php'>Users</a> <a class='btn' href='/admin/settings.php'>Settings</a></p>
<?php require __DIR__.'/../includes/footer.php'; ?>
