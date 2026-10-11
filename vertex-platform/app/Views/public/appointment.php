<?php $f = static fn (string $k): string => old($k); ?>
<section class="section"><div class="container narrow">
    <p class="eyebrow">Appointment request</p><h1>Request an appointment</h1>
    <p class="lead">Tell us when suits you. A team member will contact you to confirm: this form does not book a slot by itself.</p>
    <div class="panel">
        <?= \Vertex\Core\View::partial('public-form-open', ['action' => '/book-appointment']) ?>
            <div class="field"><label for="contact_name">Your name</label><input id="contact_name" name="contact_name" required maxlength="120" autocomplete="name" value="<?= e($f('contact_name')) ?>"></div>
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e($f('email')) ?>"></div>
            <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" required maxlength="32" autocomplete="tel" value="<?= e($f('phone')) ?>"></div>
            <div class="field"><label for="company_name">Business / company <span class="muted">(optional)</span></label><input id="company_name" name="company_name" maxlength="160" value="<?= e($f('company_name')) ?>"></div>
            <div class="field"><label for="preferred_slot">Preferred date and time</label><input id="preferred_slot" name="preferred_slot" required maxlength="120" placeholder="e.g. Tuesday afternoon, or 14 Oct 4 pm" value="<?= e($f('preferred_slot')) ?>"></div>
            <div class="field"><label for="description">What would you like to discuss?</label><textarea id="description" name="description" rows="4" required minlength="5" maxlength="1000"><?= e($f('description')) ?></textarea></div>
            <?= \Vertex\Core\View::partial('public-form-consent') ?>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Send request</button></div>
        </form>
    </div>
</div></section>
