<?php require_once __DIR__.'/includes/header.php';
$cats=db()->query('SELECT * FROM categories WHERE status=1 ORDER BY sort_order,name')->fetchAll();
$products=db()->query('SELECT * FROM products WHERE status=1 ORDER BY id DESC LIMIT 12')->fetchAll(); ?>
<h2>Fast Grocery Delivery</h2><p>Fresh produce, spices, dairy and more.</p>
<div class='row'><?php foreach($cats as $c): ?><span class='card'><?=e($c['name'])?></span><?php endforeach; ?></div>
<h3>Popular Products</h3><div class='grid'><?php foreach($products as $p): ?><article class='card'><img src='<?=e($p['image']?:'/assets/img/logo.jpg')?>'><h4><?=e($p['name'])?></h4><p><?=money((float)$p['sale_price'])?></p><form method='post' action='/api/cart.php'><input type='hidden' name='product_id' value='<?=$p['id']?>'><input type='hidden' name='qty' value='1'><button class='btn'>Add</button></form></article><?php endforeach; ?></div>
<?php require_once __DIR__.'/includes/footer.php'; ?>
