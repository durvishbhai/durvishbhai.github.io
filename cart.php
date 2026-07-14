<?php require_once __DIR__.'/includes/header.php'; $t=cart_totals(); ?>
<h2>Your Cart</h2>
<?php foreach($t['items'] as $i): ?><div class='card'><b><?=e($i['product']['name'])?></b> x <?=$i['qty']?> = <?=money($i['line_total'])?></div><?php endforeach; ?>
<div class='card'><p>Subtotal: <?=money($t['subtotal'])?></p><p>Delivery: <?=money($t['delivery'])?></p><h3>Total: <?=money($t['grand_total'])?></h3>
<a href='/checkout.php' class='btn'>Checkout</a></div>
<?php require_once __DIR__.'/includes/footer.php'; ?>
