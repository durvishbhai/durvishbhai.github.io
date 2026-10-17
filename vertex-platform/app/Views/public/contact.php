<?php $f = static fn (string $k): string => old($k); $services = (array) $services; ?>
<section class="section"><div class="container narrow">
    <p class="eyebrow">Contact</p><h1>Get in touch</h1>
    <div class="panel">
        <?= \Vertex\Core\View::partial('public-form-open', ['action' => '/contact']) ?>
            <div class="field"><label for="contact_name">Your name</label><input id="contact_name" name="contact_name" required maxlength="120" autocomplete="name" value="<?= e($f('contact_name')) ?>"></div>
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e($f('email')) ?>"></div>
            <div class="field"><label for="phone">Phone <span class="muted">(optional)</span></label><input id="phone" name="phone" type="tel" maxlength="32" value="<?= e($f('phone')) ?>"></div>
            <div class="field"><label for="company_name">Business / company <span class="muted">(optional)</span></label><input id="company_name" name="company_name" maxlength="160" value="<?= e($f('company_name')) ?>"></div>
            <div class="field"><label for="service">Topic <span class="muted">(optional)</span></label><select id="service" name="service"><option value="">General question</option><?php foreach ($services as $c => $l) : ?><option value="<?= e($c) ?>"<?= $f('service') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="description">Message</label><textarea id="description" name="description" rows="6" required minlength="10" maxlength="3000"><?= e($f('description')) ?></textarea></div>
            <?= \Vertex\Core\View::partial('public-form-consent') ?>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Send message</button></div>
        </form>
    </div>
</div></section>
