<?php require_once __DIR__.'/../includes/functions.php'; require_admin();
if($_SERVER['REQUEST_METHOD']==='POST'){
 db()->prepare('INSERT INTO products(category_id,name,slug,description,mrp,sale_price,stock,image,status) VALUES(?,?,?,?,?,?,?,?,?)')->execute([(int)$_POST['category_id'],$_POST['name'],strtolower(preg_replace('/[^a-z0-9]+/i','-',$_POST['name'])),$_POST['description'],$_POST['mrp'],$_POST['sale_price'],$_POST['stock'],$_POST['image'],isset($_POST['status'])?1:0]);
}
$ps=db()->query('SELECT p.*,c.name cname FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.id DESC')->fetchAll();
$cats=db()->query('SELECT * FROM categories ORDER BY name')->fetchAll();
require __DIR__.'/../includes/header.php'; ?>
<h2>Products</h2><table class='table'><tr><th>ID</th><th>Name</th><th>Category</th><th>Price</th></tr><?php foreach($ps as $p): ?><tr><td><?=$p['id']?></td><td><?=e($p['name'])?></td><td><?=e($p['cname']??'')?></td><td><?=money((float)$p['sale_price'])?></td></tr><?php endforeach; ?></table>
<h3>Add Product</h3><form method='post'><select name='category_id' class='input'><?php foreach($cats as $c): ?><option value='<?=$c['id']?>'><?=e($c['name'])?></option><?php endforeach; ?></select><br><br><input class='input' name='name' required><br><br><textarea class='input' name='description'></textarea><br><br><div class='row'><input class='input' name='mrp' type='number' step='0.01' required><input class='input' name='sale_price' type='number' step='0.01' required><input class='input' name='stock' type='number' required></div><br><br><input class='input' name='image' placeholder='/uploads/..'><br><br><label><input type='checkbox' name='status' checked> Active</label><br><br><button class='btn'>Save</button></form>
<?php require __DIR__.'/../includes/footer.php'; ?>
