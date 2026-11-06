<?php /** @var ?array<string,mixed> $current */ /** @var bool $canManage */ /** @var list<array<string,mixed>> $versions */ $v = static fn (string $k): string => old($k); ?>
<div class="page-head"><div><p class="eyebrow">Referral partners</p><h1>Referral Partner Terms</h1></div><a class="btn btn-ghost" href="/referrals/applications">&larr; Applications</a></div>
<div class="panel"><?php if ($current) : ?><h2>Current: version <?= e($current['version']) ?> · <?= e($current['title']) ?></h2><p class="prewrap"><?= e($current['terms']) ?></p><?php else : ?><p>No terms are recorded, so the public application form is closed. Nothing is pre-filled: the platform does not invent legal wording.</p><?php endif; ?>
    <p class="muted">Previous versions: <?= $versions === [] ? 'none' : e(implode(', ', array_map(static fn ($x) => 'v' . $x['version'], $versions))) ?>. Applications keep the version their applicant agreed to.</p></div>
<?php if ($canManage) : ?><div class="panel"><h2>Record a new version</h2>
    <form method="post" action="/referrals/terms" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="tt">Title</label><input id="tt" name="title" required maxlength="160" value="<?= e($v('title')) ?>"></div>
        <div class="field"><label for="tx">Terms (your own signed wording)</label><textarea id="tx" name="terms" rows="8" required maxlength="20000"><?= e($v('terms')) ?></textarea></div>
        <div class="field"><label for="tpw">Your password</label><input id="tpw" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Record terms</button></div></form></div><?php endif; ?>
