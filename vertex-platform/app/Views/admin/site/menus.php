<?php /** @var array<string,string> $locations */ /** @var array<string,string> $texts */ /** @var array<string,bool> $saved */ /** @var bool $canPublish */ ?>
<div class="page-head"><div><p class="eyebrow">Website</p><h1>Menus</h1></div></div>
<?= \Vertex\Core\View::partial('cms-nav') ?>
<p class="muted">One link per line: <code>Label | /address</code>. Put <code>- </code> before a line to place it in a dropdown under the link above it. Use <code>Services | @services</code> to fill that dropdown with your published services automatically. Addresses may start with <code>/</code>, <code>https://</code>, <code>mailto:</code> or <code>tel:</code>. Up to 12 main links.</p>
<p class="muted">While a menu is not saved here, the built-in links are used and they hide pages that are not published yet (for example a policy that is still a draft).</p>
<?php foreach ($locations as $loc => $label) : ?>
<div class="panel"><h2><?= e($label) ?> <?= $saved[$loc] ? '<span class="badge badge-active">custom</span>' : '<span class="badge">built-in links</span>' ?></h2>
    <form method="post" action="/admin/site/menus/<?= e($loc) ?>" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="m-<?= e($loc) ?>">Links</label><textarea id="m-<?= e($loc) ?>" name="items" rows="7" maxlength="3000" placeholder="Leave empty to show nothing, or press Use built-in links"<?= $canPublish ? '' : ' disabled' ?>><?= e($texts[$loc]) ?></textarea></div>
        <?php if ($canPublish) : ?><div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div><?php endif; ?></form>
    <?php if ($canPublish && $saved[$loc]) : ?><form method="post" action="/admin/site/menus/<?= e($loc) ?>/reset" class="inline-form"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Use built-in links</button></form><?php endif; ?></div>
<?php endforeach; ?>
