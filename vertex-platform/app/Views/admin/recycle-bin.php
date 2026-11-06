<?php /** @var list<array{type:string,label:string,id:string,title:string,archived_at:string}> $items */ /** @var array<string,array<string,string>> $types */ /** @var string $type */ ?>
<p class="eyebrow">Administration</p>
<h1>Recycle bin</h1>
<p class="muted">Nothing is ever permanently deleted here: archived records stay in the database with their history. Restoring a record puts it back in its normal lists and is written to the audit log.</p>
<form method="get" action="/admin/recycle-bin" class="toolbar"><div class="field"><label for="type">Show</label><select id="type" name="type"><option value="">Everything</option><?php foreach ($types as $k => $t) : ?><option value="<?= e($k) ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?></select></div><div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div></form>
<div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>Record</th><th>Archived</th><th>Restore</th></tr></thead><tbody>
<?php foreach ($items as $it) : ?><tr><td><?= e($it['label']) ?></td><td><?= e($it['title']) ?></td><td><?= e(local_time($it['archived_at'])) ?></td>
    <td><?php if (can('recycle.restore')) : ?><form method="post" action="/admin/recycle-bin/restore" class="inline-form"><?= csrf_field() ?><input type="hidden" name="type" value="<?= e($it['type']) ?>"><input type="hidden" name="id" value="<?= e($it['id']) ?>">
        <input name="reason" placeholder="Reason" required minlength="3" maxlength="200" aria-label="Reason"><input name="confirm_password" type="password" placeholder="Your password" required autocomplete="current-password" aria-label="Your password"><button class="btn btn-ghost btn-sm" type="submit">Restore</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if ($items === []) : ?><tr><td colspan="4" class="muted">The recycle bin is empty.</td></tr><?php endif; ?></tbody></table></div>
