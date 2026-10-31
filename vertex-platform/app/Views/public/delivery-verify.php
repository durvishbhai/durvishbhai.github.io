<section class="section"><div class="container narrow">
    <p class="eyebrow">Project delivery</p><h1>Enter your code</h1>
    <p class="lead">If the Project ID and name match one of our projects, we have emailed a 6-digit code to the address registered on the account. It works once and expires in <?= e((int) config('delivery.otp_minutes', 10)) ?> minutes.</p>
    <div class="panel">
        <form method="post" action="/delivery-appointment/verify" class="form-grid" novalidate><?= csrf_field() ?>
            <div class="field"><label for="code">6-digit code</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Continue</button> <a class="btn btn-ghost" href="/delivery-appointment">Start again</a></div>
        </form>
    </div>
    <p class="muted">Nothing is shown until the code is right. If no email arrives, check the spelling of the details, your spam folder, or contact our team.</p>
</div></section>
