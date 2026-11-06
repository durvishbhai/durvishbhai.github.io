<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Your data</p><h1>Check a request</h1></div></section>
<section class="pub-section"><div class="container narrow"><form method="post" action="/data-deletion/status" class="panel form-grid" novalidate><?= csrf_field() ?>
    <p class="muted">Enter your reference number and the email address you used. If they match, we email you a private link to your request: this page never shows anything directly.</p>
    <div class="field"><label for="lr">Reference number</label><input id="lr" name="reference" required maxlength="24" placeholder="DEL-2026-ABC123"></div>
    <div class="field"><label for="le">Email address</label><input id="le" name="email" type="email" required maxlength="190"></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Email me the link</button></div></form></div></section>
