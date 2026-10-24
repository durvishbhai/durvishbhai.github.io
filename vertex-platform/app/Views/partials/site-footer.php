<?php
use Vertex\Services\SiteMenu;
use Vertex\Services\SiteText;
try {
    $cols = SiteMenu::footerColumns();
    $extra = SiteMenu::footerExtra();
} catch (\Throwable) {
    $cols = [];
    $extra = [];
}
$site = (array) config('site', []);
$company = SiteText::company();
$social = [];
foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'twitter' => 'X', 'youtube' => 'YouTube'] as $k => $l) {
    if (str_starts_with((string) ($site[$k] ?? ''), 'https://')) { $social[$l] = (string) $site[$k]; }
}
$desc = (string) ($site['footer_description'] ?? '');
?>
<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-brand">
            <a class="brand" href="/" aria-label="<?= e($company) ?> home"><img src="<?= e(asset('img/krazy-mark.png')) ?>" alt="" width="86" height="35" loading="lazy"><span class="brand-word">Krazy <small>Integrated Solutions</small></span></a>
            <?php if ($desc !== '') : ?><p><?= e($desc) ?></p><?php endif; ?>
            <?php if ($social !== []) : ?><p class="social"><?php foreach ($social as $l => $u) : ?><a href="<?= e($u) ?>" rel="noopener noreferrer" target="_blank"><?= e($l) ?></a><?php endforeach; ?></p><?php endif; ?>
            <?php if (($site['email'] ?? '') !== '') : ?><p class="small"><a href="mailto:<?= e((string) $site['email']) ?>"><?= e((string) $site['email']) ?></a></p><?php endif; ?>
        </div>
        <?php foreach ($cols as $c) : ?>
            <nav class="footer-col" aria-label="<?= e($c['title']) ?>"><h2><?= e($c['title']) ?></h2><ul><?php foreach ($c['links'] as $l) : ?><li><a href="<?= shref($l['url']) ?>"><?= e($l['label']) ?></a></li><?php endforeach; ?></ul></nav>
        <?php endforeach; ?>
    </div>
    <div class="container footer-bottom">
        <p>
            <?= e(trim((string) ($site['copyright'] ?? '')) !== '' ? SiteText::tokens((string) $site['copyright']) : '© ' . date('Y') . ' ' . $company) ?>
            <?php if (($site['footer_text'] ?? '') !== '') : ?> · <?= e((string) $site['footer_text']) ?><?php endif; ?>
        </p>
        <?php if (($site['company_details'] ?? '') !== '') : ?><p class="small"><?= nl2br(e((string) $site['company_details'])) ?></p><?php endif; ?>
        <p class="small"><?php foreach ($extra as $l) : ?><a href="<?= shref($l['url']) ?>"><?= e($l['label']) ?></a> · <?php endforeach; ?><a href="/sitemap">Sitemap</a></p>
    </div>
</footer>
