<?php /** @var array<string,mixed> $project */ /** @var string $token */ ?>
<p class="eyebrow"><a href="/projects/<?= e($project['public_id']) ?>"><?= e($project['code']) ?></a></p><h1>Request a change</h1>
<p class="muted">Tell us what you would like added or changed on <strong><?= e($project['title']) ?></strong>. We will tell you what it costs and whether it moves your delivery date. Nothing is charged until you accept our proposal.</p>
<form method="post" action="/projects/<?= e($project['public_id']) ?>/scope-request" class="panel form-grid" novalidate><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="field"><label for="d">What would you like added or changed?</label><textarea id="d" name="description" rows="6" required minlength="15" maxlength="3000"><?= e(old('description')) ?></textarea></div>
    <div class="field"><label for="w">Do you need it by a date? <span class="muted">(optional)</span></label><input id="w" name="wanted_by" type="date" value="<?= e(old('wanted_by')) ?>"></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Send request</button></div></form>
