<?php /** @var string $mode */ $bad = !empty($badLink); ?>
<section class="section"><div class="container narrow">
    <p class="eyebrow">Project delivery</p><h1>Book your project delivery appointment</h1>
    <?php if ($bad) : ?><div class="panel"><p><strong>This booking link is not valid.</strong> It may have expired or been switched off. Please contact our team for a new one<?= in_array($mode, ['otp', 'both'], true) ? ', or use your Project ID below' : '' ?>.</p></div><?php endif; ?>
    <?php if (in_array($mode, ['otp', 'both'], true)) : ?>
    <p class="lead">Enter your Project ID and the name registered with us. We will email a one-time code to the address on your account, so only you can continue.</p>
    <div class="panel">
        <form method="post" action="/delivery-appointment" class="form-grid public-form" novalidate><?= csrf_field() ?>
            <div class="hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
            <div class="field"><label for="project_id">Project ID <span class="req">*</span></label><input id="project_id" name="project_id" required maxlength="30" autocomplete="off" value="<?= e(old('project_id')) ?>" placeholder="e.g. KIS-2026-0001"></div>
            <div class="field"><label for="client_name">Client name <span class="req">*</span></label><input id="client_name" name="client_name" required maxlength="160" autocomplete="name" value="<?= e(old('client_name')) ?>"><p class="hint">The contact or company name on your account.</p></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Send me a code</button></div>
        </form>
    </div>
    <?php elseif ($mode === 'token') : ?>
    <div class="panel"><p>Please use the private booking link our team sent you. If you cannot find it, <a href="/contact">contact us</a>.</p></div>
    <?php else : ?>
    <div class="panel"><p>Booking without signing in is not available right now. Please <a href="/login">sign in</a> to your client dashboard, or <a href="/contact">contact us</a>.</p></div>
    <?php endif; ?>
    <p class="muted">Already have an account? <a href="/login">Sign in</a> and book from your dashboard.</p>
</div></section>
