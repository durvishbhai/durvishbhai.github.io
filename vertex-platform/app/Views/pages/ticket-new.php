<?php /** @var list<array<string,mixed>> $projects */ /** @var string $token */ /** @var string $limit */ /** @var string $types */
$v = static fn (string $k, string $d = ''): string => old($k, $d); ?>
<div class="page-head"><div><p class="eyebrow">Support Centre</p><h1>Create a support ticket</h1></div><a class="btn btn-ghost" href="/support">&larr; Support Centre</a></div>
<div class="panel">
    <p class="muted">Something broken on your website? Use <a href="/support/bugs/new">Report a bug</a> instead, so we get the details we need.</p>
    <form method="post" action="/support/tickets" enctype="multipart/form-data" class="form-grid" novalidate data-busy="Sending…"><?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label for="tc">What is it about?</label><select id="tc" name="category" required><option value="">Choose a category</option>
            <?php foreach (config('workflow.ticket_categories') as $c => $l) : if ($c === 'bug_report') { continue; } ?><option value="<?= e($c) ?>"<?= $v('category') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="tp">Project (optional)</label><select id="tp" name="project"><option value="">Not about a specific project</option>
            <?php foreach ($projects as $p) : ?><option value="<?= e($p['public_id']) ?>"<?= $v('project') === $p['public_id'] ? ' selected' : '' ?>><?= e($p['code'] . ' · ' . $p['title']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="ts">Subject</label><input id="ts" name="subject" required minlength="5" maxlength="200" value="<?= e($v('subject')) ?>"></div>
        <div class="field"><label for="td">Message</label><textarea id="td" name="description" rows="6" required minlength="10" maxlength="5000"><?= e($v('description')) ?></textarea></div>
        <div class="field"><label for="tf">Attachments (optional, up to 3)</label><input id="tf" type="file" name="files[]" multiple><p class="hint">Up to <?= e($limit) ?> each. Allowed: <?= e($types) ?>.</p></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Create ticket</button></div>
    </form>
</div>
