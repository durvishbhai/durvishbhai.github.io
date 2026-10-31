<?php /** @var string $token */ /** @var array<string,string> $services */ $v = static fn (string $k): string => old($k); ?>
<div class="page-head"><div><p class="eyebrow">Partner portal</p><h1>Submit a referral</h1></div><a class="btn btn-ghost" href="/partner">&larr; Partner portal</a></div>
<div class="panel"><p class="muted">Only refer people who are interested in hearing from us. Our team reviews every referral, and you will see its status here. Referring someone does not by itself earn commission: commission depends on the programme terms and on the client's verified payments.</p>
    <form method="post" action="/partner/submit" class="form-grid" novalidate data-busy="Sending…"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label for="pn">Prospect's name</label><input id="pn" name="prospect_name" required maxlength="120" value="<?= e($v('prospect_name')) ?>"></div>
        <div class="field"><label for="pe">Email</label><input id="pe" name="email" type="email" maxlength="190" value="<?= e($v('email')) ?>"></div>
        <div class="field"><label for="pp">Phone</label><input id="pp" name="phone" type="tel" maxlength="32" value="<?= e($v('phone')) ?>"><p class="hint">Give at least an email address or a phone number.</p></div>
        <div class="field"><label for="pb">Business name <span class="muted">(if applicable)</span></label><input id="pb" name="business_name" maxlength="160" value="<?= e($v('business_name')) ?>"></div>
        <div class="field"><label for="ps">Service of interest</label><select id="ps" name="service" required><option value="">Choose</option><?php foreach ($services as $c => $l) : ?><option value="<?= e($c) ?>"<?= $v('service') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="pd">Project description</label><textarea id="pd" name="description" rows="4" required minlength="10" maxlength="3000"><?= e($v('description')) ?></textarea></div>
        <div class="field"><label for="pbud">Estimated budget (₹, if known)</label><input id="pbud" name="estimated_budget" inputmode="decimal" value="<?= e($v('estimated_budget')) ?>"></div>
        <div class="field"><label for="pnotes">Additional notes</label><textarea id="pnotes" name="notes" rows="3" maxlength="2000"><?= e($v('notes')) ?></textarea></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Submit referral</button></div></form></div>
