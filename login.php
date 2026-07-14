<?php require_once __DIR__.'/includes/functions.php';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $st=db()->prepare('SELECT * FROM users WHERE email=? AND status=1 LIMIT 1');$st->execute([trim($_POST['email'])]);$u=$st->fetch();
 if($u && password_verify($_POST['password'],$u['password_hash'])){$_SESSION['user_id']=$u['id'];redirect('/');}
 $err='Invalid credentials';
}
require __DIR__.'/includes/header.php'; ?>
<h2>Login</h2><?php if(!empty($err)): ?><p><?=$err?></p><?php endif; ?>
<form method='post'><input class='input' name='email' type='email' placeholder='Email' required><br><br><input class='input' name='password' type='password' required><br><br><button class='btn'>Login</button></form><p>New? <a href='/signup.php'>Create account</a></p>
<?php require __DIR__.'/includes/footer.php'; ?>
