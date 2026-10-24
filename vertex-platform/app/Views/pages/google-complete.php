<?php /** @var array{sub:string,email:string,name:string} $g */ /** @var bool $approval */ ?>
<section class="auth">
    <div class="auth-card reveal">
        <h1>Finish signing up</h1>
        <p class="muted">Google confirmed <strong><?= e($g['email']) ?></strong>. We only need your phone number to create your client account.<?= !empty($approval) ? ' Our team approves each new account before it can sign in.' : '' ?></p>
        <form method="post" action="/auth/google/complete" novalidate>
            <?= csrf_field() ?>
            <div class="field"><label for="name">Full name</label><input id="name" name="name" required maxlength="120" autocomplete="name" value="<?= e(old('name', $g['name'])) ?>"></div>
            <div class="field"><label for="phone">Phone number <span class="muted">(required)</span></label><input id="phone" name="phone" type="tel" required minlength="7" maxlength="20" placeholder="+91 90000 00000" autocomplete="tel" value="<?= e(old('phone')) ?>"></div>
            <button class="btn btn-primary btn-block" type="submit">Create my account</button>
        </form>
        <p class="auth-links"><a href="/login">Cancel</a></p>
    </div>
</section>
