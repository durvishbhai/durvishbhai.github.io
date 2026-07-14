<?php require_once __DIR__.'/includes/functions.php'; require_login(); $u=current_user(); $t=cart_totals();
if($t['subtotal'] < (float)setting('min_order_value',0)) die('Minimum order value not met.');
$st=db()->prepare('SELECT * FROM addresses WHERE user_id=? ORDER BY is_default DESC,id DESC');$st->execute([$u['id']]);$addresses=$st->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'){
 $payment=$_POST['payment_method']; $address=(int)$_POST['address_id'];
 $status = $payment==='cod' ? 'placed' : 'pending_payment';
 db()->beginTransaction();
 db()->prepare('INSERT INTO orders(user_id,address_id,subtotal,delivery_charge,total,payment_method,payment_status,order_status) VALUES(?,?,?,?,?,?,?,?)')->execute([$u['id'],$address,$t['subtotal'],$t['delivery'],$t['grand_total'],$payment,$payment==='cod'?'unpaid':'pending',$status]);
 $oid=(int)db()->lastInsertId();
 $ins=db()->prepare('INSERT INTO order_items(order_id,product_id,product_name,price,qty,total) VALUES(?,?,?,?,?,?)');
 foreach($t['items'] as $i){$p=$i['product'];$ins->execute([$oid,$p['id'],$p['name'],$p['sale_price'],$i['qty'],$i['line_total']]);}
 db()->commit(); unset($_SESSION['cart']); redirect('/order_success.php?id='.$oid);
}
require __DIR__.'/includes/header.php'; ?>
<h2>Checkout</h2><p>Total: <?=money($t['grand_total'])?></p>
<form method='post'><select name='address_id' class='input' required><?php foreach($addresses as $a): ?><option value='<?=$a['id']?>'><?=e($a['label'])?> - <?=e($a['city'])?></option><?php endforeach; ?></select><br><br>
<select name='payment_method' class='input'><?php if((int)setting('cod_enabled',1)): ?><option value='cod'>Cash on Delivery</option><?php endif; ?><?php if((int)setting('phonepe_enabled',1)): ?><option value='phonepe'>PhonePe</option><?php endif; ?><?php if((int)setting('demo_payment_enabled',1)): ?><option value='demo'>Demo Payment</option><?php endif; ?></select><br><br><button class='btn'>Place order</button></form>
<?php require __DIR__.'/includes/footer.php'; ?>
