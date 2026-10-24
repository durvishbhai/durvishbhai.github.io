<?php /** @var string $action */ ?>
<form method="post" action="<?= e($action) ?>" class="form-grid public-form" novalidate>
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
