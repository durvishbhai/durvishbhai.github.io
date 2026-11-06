<?php
$t = \Vertex\Services\ConsentRules::texts();
$marketing = \Vertex\Services\ComplianceSettings::bool('marketing_enabled');
$errs = $consentErrors ?? [];
?>
<fieldset class="consent-set"><legend class="sr-only">Consent and confirmation</legend>
<label class="check"><input type="checkbox" name="consent" value="1" required><span><?= $t['privacy'] ?></span></label>
<label class="check"><input type="checkbox" name="age_confirm" value="1" required><span><?= $t['age'] ?></span></label>
<?php if ($marketing) : ?><label class="check"><input type="checkbox" name="marketing_consent" value="1"><span><?= $t['marketing'] ?> <span class="muted">(optional)</span></span></label><?php endif; ?>
</fieldset>
<?= \Vertex\Core\View::partial('turnstile') ?>
<p class="muted">We only use these details to respond to your request. <a href="/privacy-policy">Privacy Policy</a></p>
