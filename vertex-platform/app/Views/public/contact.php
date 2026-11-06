<?php /** @var array<string,string> $inquiryTypes */
$f = static fn (string $k): string => old($k);
$site = (array) config('site', []);
$rows = [];
foreach ([['General inquiries', 'email', 'mailto:'], ['Sales', 'sales_email', 'mailto:'], ['Support', 'support_email', 'mailto:']] as [$label, $key, $pre]) {
    if (($site[$key] ?? '') !== '') { $rows[] = [$label, (string) $site[$key], $pre . $site[$key], 'mail']; }
}
if (($site['phone'] ?? '') !== '') { $rows[] = ['Business phone', (string) $site['phone'], 'tel:' . preg_replace('/[^0-9+]/', '', (string) $site['phone']), 'phone']; }
if (($site['whatsapp'] ?? '') !== '') { $rows[] = ['WhatsApp', (string) $site['whatsapp'], 'https://wa.me/' . preg_replace('/\D/', '', (string) $site['whatsapp']), 'chat']; }
$social = [];
foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'twitter' => 'X', 'youtube' => 'YouTube'] as $k => $l) { if (str_starts_with((string) ($site[$k] ?? ''), 'https://')) { $social[$l] = (string) $site[$k]; } }
$h = \Vertex\Services\SiteSections::get('contact', 'header'); $fm = \Vertex\Services\SiteSections::get('contact', 'form'); ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Contact</p><h1><?= st($h['title']) ?></h1><p class="lead"><?= st($h['intro']) ?></p></div></section>
<section class="pub-section"><div class="container contact-grid">
    <div class="panel"><h2><?= st($fm['heading']) ?></h2>
        <?= \Vertex\Core\View::partial('public-form-open', ['action' => '/contact']) ?>
            <div class="field"><label for="contact_name">Full name</label><input id="contact_name" name="contact_name" required maxlength="120" autocomplete="name" value="<?= e($f('contact_name')) ?>"></div>
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e($f('email')) ?>"></div>
            <div class="field"><label for="phone">Phone <span class="muted">(optional)</span></label><input id="phone" name="phone" type="tel" maxlength="32" autocomplete="tel" value="<?= e($f('phone')) ?>"></div>
            <div class="field"><label for="company_name">Organization <span class="muted">(optional)</span></label><input id="company_name" name="company_name" maxlength="160" autocomplete="organization" value="<?= e($f('company_name')) ?>"></div>
            <div class="field"><label for="inquiry_type">Inquiry type</label><select id="inquiry_type" name="inquiry_type" required><option value="">Choose…</option><?php foreach ($inquiryTypes as $c => $l) : ?><option value="<?= e($c) ?>"<?= $f('inquiry_type') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="subject">Subject</label><input id="subject" name="subject" required minlength="3" maxlength="160" value="<?= e($f('subject')) ?>"></div>
            <div class="field"><label for="description">Message</label><textarea id="description" name="description" rows="6" required minlength="10" maxlength="3000"><?= e($f('description')) ?></textarea></div>
            <?= \Vertex\Core\View::partial('public-form-consent') ?>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Send message</button></div>
        </form>
    </div>
    <aside class="side-card"><h3>Contact details</h3>
        <?php if ($rows === [] && $social === [] && !(($site['show_address'] ?? false) && ($site['address'] ?? '') !== '')) : ?><p class="muted">Use the form to reach us. Contact details will be shown here once they are added.</p><?php endif; ?>
        <ul class="contact-list"><?php foreach ($rows as [$label, $text, $href, $icon]) : ?><li><?= sicon($icon) ?><span><strong><?= e($label) ?></strong><br><a href="<?= e($href) ?>"><?= e($text) ?></a></span></li><?php endforeach; ?>
        <?php if (($site['show_address'] ?? false) && ($site['address'] ?? '') !== '') : ?><li><?= sicon('pin') ?><span><strong>Address</strong><br><?= nl2br(e((string) $site['address'])) ?></span></li><?php endif; ?></ul>
        <?php if ($social !== []) : ?><p class="social"><?php foreach ($social as $l => $u) : ?><a href="<?= e($u) ?>" rel="noopener noreferrer" target="_blank"><?= e($l) ?></a> <?php endforeach; ?></p><?php endif; ?>
        <hr><p><?= st($fm['project_note']) ?></p><p><a class="btn btn-ghost block" href="/request-quote">Project Requirements</a></p>
    </aside>
</div></section>
