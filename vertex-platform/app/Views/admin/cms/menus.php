<?php /** @var string $header */ /** @var string $footer */ /** @var bool $canPublish */ ?>
<div class="page-head"><div><p class="eyebrow">Website</p><h1>Menus</h1></div></div>
<?= \Vertex\Core\View::partial('cms-nav') ?>
<p class="muted">One link per line: <code>Label | /address</code>. Addresses may start with <code>/</code>, <code>https://</code>, <code>mailto:</code> or <code>tel:</code>. Up to 12 links. If a menu is empty the built-in links are shown.</p>
<?php foreach (['header' => $header, 'footer' => $footer] as $loc => $text) : ?>
<div class="panel"><h2><?= e(ucfirst($loc)) ?> menu</h2><form method="post" action="/admin/cms/menus/<?= e($loc) ?>" class="form-grid"><?= csrf_field() ?>
    <div class="field"><label for="menu-<?= e($loc) ?>"><?= e(ucfirst($loc)) ?> links</label><textarea id="menu-<?= e($loc) ?>" name="items" rows="7" maxlength="3000"<?= $canPublish ? '' : ' disabled' ?>><?= e(old($loc . '_items', $text)) ?></textarea></div>
    <?php if ($canPublish) : ?><div class="form-actions"><button class="btn btn-primary" type="submit">Save <?= e($loc) ?> menu</button></div><?php endif; ?></form></div>
<?php endforeach; ?>
