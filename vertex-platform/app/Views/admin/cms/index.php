<?php /** @var list<array<string,mixed>> $pages */ /** @var bool $canEdit */ ?>
<div class="page-head"><div><p class="eyebrow">Website</p><h1>Pages</h1></div><a class="btn btn-ghost" href="/" target="_blank" rel="noopener">View website</a></div>
<?= \Vertex\Core\View::partial('cms-nav') ?>
<p class="muted">The page with the address <code>home</code> replaces the front page once it is published. Until then the built-in front page is shown. Draft pages are visible only to you, through Preview.</p>
<div class="table-wrap"><table class="table"><thead><tr><th>Title</th><th>Address</th><th>Status</th><th>Updated</th><th></th></tr></thead><tbody>
<?php foreach ($pages as $p) : ?><tr><td><a href="/admin/cms/<?= e($p['public_id']) ?>"><?= e($p['title']) ?></a></td><td><code><?= $p['slug'] === 'home' ? '/' : '/' . e($p['slug']) ?></code></td>
    <td><span class="badge badge-sys-<?= $p['status'] === 'published' ? 'ok' : 'warn' ?>"><?= e($p['status']) ?></span><?= $p['status'] === 'scheduled' && $p['publish_at'] ? ' ' . e(local_time($p['publish_at'])) : '' ?></td>
    <td><?= e(local_time($p['updated_at'])) ?> (v<?= (int) $p['current_version'] ?>)</td><td><a class="btn btn-ghost btn-sm" href="/admin/cms/<?= e($p['public_id']) ?>/preview" target="_blank" rel="noopener">Preview</a></td></tr><?php endforeach; ?>
<?php if ($pages === []) : ?><tr><td colspan="5" class="muted">No pages yet.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($canEdit) : ?><div class="panel"><h2>New page</h2><form method="post" action="/admin/cms" class="form-grid"><?= csrf_field() ?>
    <div class="field"><label for="pt">Title</label><input id="pt" name="title" required minlength="2" maxlength="200" value="<?= e(old('title')) ?>"></div>
    <div class="field"><label for="ps">Address (optional)</label><input id="ps" name="slug" maxlength="119" placeholder="about or services/web-design" value="<?= e(old('slug')) ?>"><p class="hint">Letters, digits and dashes. Use <code>home</code> for the front page. Leave empty to make it from the title.</p></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Create draft</button></div></form></div><?php endif; ?>
