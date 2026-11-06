<?php /** @var list<array<string,mixed>> $stages */ /** @var array<int,int> $usage */ ?>
<div class="page-head"><div><p class="eyebrow">Administration</p><h1>CRM pipeline stages</h1></div><a class="btn btn-ghost" href="/crm">CRM dashboard</a></div>
<p class="muted">Rename, reorder or deactivate open stages. Won and Lost carry fixed meaning and cannot be deactivated; stages are never deleted. A stage with enquiries in it cannot be deactivated.</p>
<form method="post" action="/admin/crm-stages" data-confirm="Save pipeline changes?">
    <?= csrf_field() ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Name</th><th>Meaning</th><th>Position</th><th>Active</th><th>Enquiries</th></tr></thead>
        <tbody>
        <?php foreach ($stages as $s) : $id = (int) $s['id']; ?>
            <tr>
                <td><input name="stage[<?= e($id) ?>][name]" value="<?= e($s['name']) ?>" maxlength="60" required aria-label="Name of <?= e($s['name']) ?>"></td>
                <td><span class="badge badge-stage-<?= e($s['kind']) ?>"><?= e(status_label((string) $s['kind'])) ?></span></td>
                <td><input name="stage[<?= e($id) ?>][position]" type="number" min="1" max="999" value="<?= e($s['position']) ?>" required aria-label="Position"></td>
                <td><input type="checkbox" name="stage[<?= e($id) ?>][active]" value="1"<?= $s['is_active'] ? ' checked' : '' ?><?= $s['kind'] !== 'open' ? ' disabled' : '' ?> aria-label="Active"><?php if ($s['kind'] !== 'open') : ?><input type="hidden" name="stage[<?= e($id) ?>][active]" value="1"><?php endif; ?></td>
                <td><?= e($usage[$id] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save stages</button></div>
</form>
