<?php /** @var array<string,mixed> $values */ $v = static fn (string $k): string => (string) ($values[$k] ?? ''); ?>
<div class="field"><label for="client_type">Type</label><select id="client_type" name="client_type"><option value="individual"<?= $v('client_type') !== 'business' ? ' selected' : '' ?>>Individual</option><option value="business"<?= $v('client_type') === 'business' ? ' selected' : '' ?>>Business</option></select></div>
<div class="field"><label for="contact_name">Contact name</label><input id="contact_name" name="contact_name" required maxlength="120" value="<?= e($v('contact_name')) ?>"></div>
<div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required maxlength="190" value="<?= e($v('email')) ?>"></div>
<div class="field"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" maxlength="32" value="<?= e($v('phone')) ?>"></div>
<div class="field"><label for="company_name">Company <span class="muted">(required for businesses)</span></label><input id="company_name" name="company_name" maxlength="160" value="<?= e($v('company_name')) ?>"></div>
<div class="field"><label for="website">Website</label><input id="website" name="website" maxlength="190" value="<?= e($v('website')) ?>"></div>
<div class="field"><label for="tax_id">Tax / GST number</label><input id="tax_id" name="tax_id" maxlength="40" value="<?= e($v('tax_id')) ?>"></div>
<div class="field"><label for="address">Address</label><input id="address" name="address" maxlength="500" value="<?= e($v('address')) ?>"></div>
