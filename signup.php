<?php require_once __DIR__.'/includes/functions.php';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $st=db()->prepare('INSERT INTO users(name,email,phone,password_hash) VALUES(?,?,?,?)');
  $st->execute([trim($_POST['name']),trim($_POST['email']),trim($_POST['phone']),password_hash($_POST['password'], PASSWORD_DEFAULT)]);
  $_SESSION['user_id']=db()->lastInsertId(); redirect('/');
}
require __DIR__.'/includes/header.php'; ?>
<h2>Signup</h2><form method='post'><input class='input' name='name' required placeholder='Name'><br><br><input class='input' type='email' name='email' required><br><br><input class='input' name='phone' required><br><br><input class='input' type='password' name='password' required><br><br><button class='btn'>Create Account</button></form>
<?php require __DIR__.'/includes/footer.php'; ?>
