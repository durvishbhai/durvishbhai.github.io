<p class="eyebrow"><a href="/coupons">Coupons</a></p><h1>New coupon</h1>
<form method="post" action="/coupons" class="panel form-grid" novalidate><?= csrf_field() ?>
<?= \Vertex\Core\View::partial('coupon-fields', ['c' => null, 'campaigns' => $campaigns, 'services' => $services]) ?>
<div class="form-actions"><button class="btn btn-primary" type="submit">Create coupon</button></div></form>
