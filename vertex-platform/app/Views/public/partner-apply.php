<?php /** @var array<string,mixed>|null $terms */ /** @var array<string,mixed>|null $signedIn */ /** @var string $limit */ /** @var string $types */
$f = static fn (string $k, string $d = ''): string => old($k, $d); ?>
<section class="section"><div class="container narrow">
    <p class="eyebrow">Referral Partner programme</p><h1>Apply to become a Referral Partner</h1>
    <?php if ($terms === null) : ?>
        <div class="panel"><p>Applications are not open yet. Please check back soon or <a href="/contact">contact us</a>.</p></div>
    <?php else : ?>
    <div class="panel">
        <p class="muted">Tell us about yourself. Applying does not create an account: our team reviews every application, and an approved partner receives a secure activation link.<?= $signedIn ? ' You are signed in, so this application will be linked to your existing account (' . e($signedIn['email']) . ').' : '' ?></p>
        <form method="post" action="/partners/apply" enctype="multipart/form-data" class="form-grid public-form" novalidate data-busy="Sending…"><?= csrf_field() ?>
            <div class="hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
            <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" required maxlength="120" autocomplete="name" value="<?= e($f('full_name', (string) ($signedIn['name'] ?? ''))) ?>"></div>
            <?php if ($signedIn === null) : ?><div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e($f('email')) ?>"></div><?php endif; ?>
            <div class="field"><label for="phone">Phone number</label><input id="phone" name="phone" type="tel" required maxlength="32" autocomplete="tel" value="<?= e($f('phone')) ?>"></div>
            <div class="field"><label for="organization">Business or organisation <span class="muted">(if applicable)</span></label><input id="organization" name="organization" maxlength="160" value="<?= e($f('organization')) ?>"></div>
            <div class="field"><label for="professional_info">Your professional or promotional background</label><textarea id="professional_info" name="professional_info" rows="5" required minlength="20" maxlength="3000"><?= e($f('professional_info')) ?></textarea></div>
            <div class="field"><label for="referral_source">How were you introduced to us?</label><textarea id="referral_source" name="referral_source" rows="3" required maxlength="1000"><?= e($f('referral_source')) ?></textarea></div>
            <div class="field"><label for="document">Profile photo or supporting document <span class="muted">(optional)</span></label><input id="document" type="file" name="document[]"><p class="hint">Up to <?= e($limit) ?>. Allowed: <?= e($types) ?>.</p></div>
            <details class="panel"><summary>Referral Partner Terms (version <?= e($terms['version']) ?>: <?= e($terms['title']) ?>)</summary><p class="prewrap"><?= e($terms['terms']) ?></p></details>
            <label class="check"><input type="checkbox" name="agree" value="1" required><span>I have read and agree to the Referral Partner Terms (version <?= e($terms['version']) ?>).</span></label>
            <p class="muted">We use these details only to review your application. A full privacy policy will be linked here before launch.</p>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Submit application</button></div>
        </form>
    </div>
    <?php endif; ?>
</div></section>
