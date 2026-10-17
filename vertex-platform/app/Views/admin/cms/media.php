<?php /** @var list<array<string,mixed>> $media */ /** @var bool $canEdit */ ?>
<div class="page-head"><div><p class="eyebrow">Website</p><h1>Media library</h1></div></div>
<?= \Vertex\Core\View::partial('cms-nav') ?>
<?php if ($canEdit) : ?><div class="panel"><h2>Upload a picture</h2><form method="post" action="/admin/cms/media" enctype="multipart/form-data" class="form-grid"><?= csrf_field() ?>
    <div class="field"><label for="m-file">Picture (JPEG, PNG, WebP or GIF, up to 5 MB)</label><input id="m-file" type="file" name="picture" accept="image/jpeg,image/png,image/webp,image/gif" required></div>
    <div class="field"><label for="m-alt">Description for people who cannot see it</label><input id="m-alt" name="alt" maxlength="200" value="<?= e(old('alt')) ?>"></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Upload</button></div></form></div><?php endif; ?>
<div class="cms-media-grid"><?php foreach ($media as $m) : ?><figure class="cms-media-item"><img src="<?= e(\Vertex\Services\CmsService::mediaUrl($m)) ?>" alt="<?= e((string) $m['alt_text']) ?>" width="160" loading="lazy"><figcaption><?= e($m['original_name']) ?><br><span class="muted"><?= (int) $m['width'] ?>×<?= (int) $m['height'] ?> · <?= e(number_format($m['size_bytes'] / 1024, 0)) ?> KB</span>
    <?php if ($canEdit) : ?><form method="post" action="/admin/cms/media/<?= e($m['public_id']) ?>/delete" class="inline-form"><?= csrf_field() ?><input name="confirm_password" type="password" required placeholder="Your password" aria-label="Your password" autocomplete="current-password"><button class="btn btn-ghost btn-sm" type="submit">Delete</button></form><?php endif; ?></figcaption></figure><?php endforeach; ?>
<?php if ($media === []) : ?><p class="muted">No pictures yet.</p><?php endif; ?></div>
