<?php
/**
 * Shared enquiry form fields.
 * @var array<string,mixed> $values current values (already merged with old input)
 * @var bool $staff   show staff-only fields (priority)
 */
$services = (array) config('workflow.services');
$budgets = (array) config('workflow.budget_ranges');
$priorities = (array) config('workflow.priorities');
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
?>
<div class="field"><label for="contact_name">Contact name</label><input id="contact_name" name="contact_name" required maxlength="120" value="<?= e($v('contact_name')) ?>"></div>
<div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required maxlength="190" value="<?= e($v('email')) ?>"></div>
<div class="field"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" maxlength="32" value="<?= e($v('phone')) ?>"></div>
<div class="field"><label for="company_name">Business / company <span class="muted">(optional)</span></label><input id="company_name" name="company_name" maxlength="160" value="<?= e($v('company_name')) ?>"></div>
<div class="field"><label for="service">Service</label>
    <select id="service" name="service" required>
        <option value="">Choose…</option>
        <?php foreach ($services as $code => $label) : ?><option value="<?= e($code) ?>"<?= $v('service') === $code ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></div>
<div class="field"><label for="description">Project description</label><textarea id="description" name="description" rows="6" required maxlength="5000"><?= e($v('description')) ?></textarea></div>
<div class="field"><label for="budget_range">Budget range <span class="muted">(optional)</span></label>
    <select id="budget_range" name="budget_range"><option value="">Prefer not to say</option>
        <?php foreach ($budgets as $code => $label) : ?><option value="<?= e($code) ?>"<?= $v('budget_range') === $code ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></div>
<div class="field"><label for="expected_deadline">Expected deadline <span class="muted">(optional)</span></label><input id="expected_deadline" name="expected_deadline" type="date" value="<?= e($v('expected_deadline')) ?>"></div>
<?php if (!empty($staff)) : ?>
    <div class="field"><label for="priority">Priority</label>
        <select id="priority" name="priority"><?php foreach ($priorities as $code => $label) : ?><option value="<?= e($code) ?>"<?= ($v('priority') ?: 'normal') === $code ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
<?php endif; ?>
