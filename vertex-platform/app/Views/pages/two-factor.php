<?php /** @var bool $enabled */ /** @var bool $required */ /** @var ?array{secret:string,uri:string} $pending */ /** @var int $remaining */ /** @var ?list<string> $codes */ ?>
<p class="eyebrow"><a href="/account">My account</a></p>
<h1>Two-factor login</h1>
<p class="muted">Adds a 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password) to your password, so a stolen password alone cannot open your account.<?= $required ? ' <strong>It is required for your role.</strong>' : '' ?></p>

<?php if ($codes !== null) : ?>
<div class="panel"><h2>Your recovery codes</h2>
    <p>Each code works once if you lose your phone. <strong>Save them now (password manager or printed): they will not be shown again.</strong></p>
    <ul class="codes"><?php foreach ($codes as $c) : ?><li><code><?= e($c) ?></code></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if ($enabled) : ?>
<div class="panel"><h2>Status: on</h2>
    <p>You have <strong><?= (int) $remaining ?></strong> unused recovery code(s).</p>
    <form method="post" action="/account/two-factor/recovery" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="p1">Password</label><input id="p1" name="password" type="password" required autocomplete="current-password"></div>
        <div class="field"><label for="c1">Current code</label><input id="c1" name="code" inputmode="numeric" required autocomplete="one-time-code"></div>
        <div class="form-actions"><button class="btn btn-ghost" type="submit">Create new recovery codes</button></div></form>
    <?php if (!$required) : ?><h3>Turn off</h3>
    <form method="post" action="/account/two-factor/disable" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="p2">Password</label><input id="p2" name="password" type="password" required autocomplete="current-password"></div>
        <div class="field"><label for="c2">Current code or a recovery code</label><input id="c2" name="code" required autocomplete="one-time-code"></div>
        <div class="form-actions"><button class="btn btn-danger" type="submit">Turn off two-factor login</button></div></form><?php endif; ?>
</div>
<?php elseif ($pending !== null) : ?>
<div class="panel"><h2>Set up</h2>
    <ol><li>In your authenticator app choose <strong>Add account</strong> &rarr; <strong>Enter a setup key</strong> (or open <a href="<?= e($pending['uri']) ?>">this link</a> on the phone that has the app).</li>
        <li>Account: your email. Key (time based): <code class="key"><?= e(implode(' ', str_split($pending['secret'], 4))) ?></code></li>
        <li>Type the 6-digit code the app shows and your password below.</li></ol>
    <form method="post" action="/account/two-factor/enable" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="c3">6-digit code</label><input id="c3" name="code" inputmode="numeric" required autocomplete="one-time-code" autofocus></div>
        <div class="field"><label for="p3">Password</label><input id="p3" name="password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Turn on</button></div></form>
</div>
<?php else : ?>
<div class="panel"><h2>Status: off</h2>
    <form method="post" action="/account/two-factor/start"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Set up two-factor login</button></form></div>
<?php endif; ?>
