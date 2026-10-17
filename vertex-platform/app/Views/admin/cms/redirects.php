<?php /** @var list<array<string,mixed>> $rows */ /** @var bool $canPublish */ ?>
<div class="page-head"><div><p class="eyebrow">Website</p><h1>Redirects</h1></div></div>
<?= \Vertex\Core\View::partial('cms-nav') ?>
<p class="muted">Visitors who open an old address are sent to the new one. Changing the address of a live page adds one automatically.</p>
<?php if ($canPublish) : ?><div class="panel"><h2>Add a redirect</h2><form method="post" action="/admin/cms/redirects" class="form-grid"><?= csrf_field() ?>
    <div class="field"><label for="r-from">From (path)</label><input id="r-from" name="from" required maxlength="190" placeholder="/old-page" value="<?= e(old('from')) ?>"></div>
    <div class="field"><label for="r-to">To (path or https:// address)</label><input id="r-to" name="to" required maxlength="500" placeholder="/new-page" value="<?= e(old('to')) ?>"></div>
    <div class="field"><label for="r-code">Kind</label><select id="r-code" name="code"><option value="301">Permanent (301)</option><option value="302">Temporary (302)</option></select></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save redirect</button></div></form></div><?php endif; ?>
<div class="table-wrap"><table class="table"><thead><tr><th>From</th><th>To</th><th>Kind</th><th>Used</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r) : ?><tr><td><code><?= e($r['from_path']) ?></code></td><td><code><?= e($r['to_url']) ?></code></td><td><?= (int) $r['status_code'] ?></td><td><?= (int) $r['hits'] ?></td>
    <td><?php if ($canPublish) : ?><form method="post" action="/admin/cms/redirects/<?= (int) $r['id'] ?>/delete" class="inline-form"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Remove</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if ($rows === []) : ?><tr><td colspan="5" class="muted">No redirects.</td></tr><?php endif; ?></tbody></table></div>
