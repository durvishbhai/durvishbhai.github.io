<?php $f = static fn (string $k): string => old($k); $services = (array) $services; $budgets = (array) $budgets; ?>
<section class="section"><div class="container narrow">
    <p class="eyebrow">Project requirements</p><h1>Tell us about your project</h1>
    <p class="lead">Share what you need and we will get back to you. This is a request, not an order: no payment is taken.</p>
    <div class="panel">
        <?= \Vertex\Core\View::partial('public-form-open', ['action' => '/request-quote']) ?>
            <div class="field"><label for="contact_name">Your name</label><input id="contact_name" name="contact_name" required maxlength="120" autocomplete="name" value="<?= e($f('contact_name')) ?>"></div>
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e($f('email')) ?>"></div>
            <div class="field"><label for="phone">Phone <span class="muted">(optional)</span></label><input id="phone" name="phone" type="tel" maxlength="32" autocomplete="tel" value="<?= e($f('phone')) ?>"></div>
            <div class="field"><label for="company_name">Business / company <span class="muted">(optional)</span></label><input id="company_name" name="company_name" maxlength="160" autocomplete="organization" value="<?= e($f('company_name')) ?>"></div>
            <div class="field"><label for="service">What do you need?</label><select id="service" name="service" required><option value="">Choose…</option><?php foreach ($services as $c => $l) : ?><option value="<?= e($c) ?>"<?= $f('service') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="description">Project description</label><textarea id="description" name="description" rows="7" required minlength="20" maxlength="5000"><?= e($f('description')) ?></textarea></div>
            <div class="field"><label for="budget_range">Budget range <span class="muted">(optional)</span></label><select id="budget_range" name="budget_range"><option value="">Prefer not to say</option><?php foreach ($budgets as $c => $l) : ?><option value="<?= e($c) ?>"<?= $f('budget_range') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="expected_deadline">When do you need it by? <span class="muted">(optional)</span></label><input id="expected_deadline" name="expected_deadline" type="date" value="<?= e($f('expected_deadline')) ?>"></div>
            <?= \Vertex\Core\View::partial('public-form-consent') ?>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Send request</button></div>
        </form>
    </div>
</div></section>
