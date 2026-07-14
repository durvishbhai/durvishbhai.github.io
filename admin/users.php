<?php require_once __DIR__.'/../includes/functions.php'; require_admin(); $users=db()->query('SELECT id,name,email,phone,is_admin,status,created_at FROM users ORDER BY id DESC')->fetchAll(); require __DIR__.'/../includes/header.php'; ?>
<h2>Users</h2><table class='table'><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th></tr><?php foreach($users as $u): ?><tr><td><?=$u['id']?></td><td><?=e($u['name'])?></td><td><?=e($u['email'])?></td><td><?=$u['is_admin']?'Admin':'Customer'?></td></tr><?php endforeach; ?></table>
<?php require __DIR__.'/../includes/footer.php'; ?>
