<?php /** @var ?array<string,mixed> $user */ /** @var string $intro */ /** @var string $retention */ /** @var int $days */ ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Your data</p><h1>Data Deletion Request</h1><p class="lead"><?= e($intro) ?></p></div></section>
<section class="pub-section"><div class="container narrow">
    <div class="panel"><h2>What happens to your request</h2>
        <ol><li>You confirm your identity (we email a link to the address the data is held under; if you are signed in, this is automatic).</li>
            <li>Our team reviews it<?= $days > 0 ? ' and aims to answer within ' . e($days) . ' days' : '' ?>. We may ask for clarification.</li>
            <li>We delete what we do not need, anonymise what we must keep but need not tie to you, and keep what the law or a contract requires.</li>
            <li>You receive a written summary of exactly what was deleted, anonymised and kept, and why.</li></ol>
        <p class="muted"><strong>What we must keep:</strong> <?= e($retention) ?></p></div>
    <form method="post" action="/data-deletion" class="panel form-grid public-form" novalidate><?= csrf_field() ?>
        <div class="hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
        <?php if ($user) : ?><p>You are signed in as <strong><?= e($user['email']) ?></strong>. The request will be made for this account.</p>
        <?php else : ?>
        <div class="field"><label for="dn">Full name <span class="req">*</span></label><input id="dn" name="name" required maxlength="120" autocomplete="name" value="<?= e(old('name')) ?>"></div>
        <div class="field"><label for="de">Email address the data is held under <span class="req">*</span></label><input id="de" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e(old('email')) ?>"><p class="hint">We email a confirmation link to this address. It is the only way we accept the request.</p></div>
        <?php endif; ?>
        <div class="field"><label for="dr">Reason (optional)</label><textarea id="dr" name="reason" rows="3" maxlength="1000"><?= e(old('reason')) ?></textarea><p class="hint">You do not have to give one. If there is something specific you want removed, tell us.</p></div>
        <div class="field"><label class="check"><input type="checkbox" name="confirm" value="1" required> <span>I am the person this data is about, or I am authorised to act for them.</span></label></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Submit the request</button> <a class="btn btn-ghost" href="/data-deletion/status">Check a request</a></div>
    </form>
    <p class="muted small"><a href="/privacy-policy">Privacy Policy</a> · <a href="/contact-and-grievance">Contact &amp; grievance information</a></p>
</div></section>
