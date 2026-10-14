<?php
$menu = \Vertex\Services\CmsService::menu('footer');
$site = (array) config('site', []);
$social = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'twitter' => 'X', 'youtube' => 'YouTube'];
?>
<footer class="site-footer">
    <div class="container footer-inner">
        <span>&copy; <?= e(date('Y')) ?> <?= e((string) (($site['company_name'] ?? '') !== '' ? $site['company_name'] : config('app.name'))) ?></span>
        <?php if (($site['footer_text'] ?? '') !== '') : ?><span class="footer-text"><?= e((string) $site['footer_text']) ?></span><?php endif; ?>
        <?php if (($site['email'] ?? '') !== '') : ?><a href="mailto:<?= e((string) $site['email']) ?>"><?= e((string) $site['email']) ?></a><?php endif; ?>
        <?php if (($site['phone'] ?? '') !== '') : ?><a href="tel:<?= e((string) preg_replace('/[^0-9+]/', '', (string) $site['phone'])) ?>"><?= e((string) $site['phone']) ?></a><?php endif; ?>
        <?php foreach ($menu as $item) : ?><a href="<?= e($item['url']) ?>"><?= e($item['label']) ?></a><?php endforeach; ?>
        <?php foreach ($social as $k => $label) : if (($site[$k] ?? '') !== '' && str_starts_with((string) $site[$k], 'https://')) : ?><a href="<?= e((string) $site[$k]) ?>" rel="noopener noreferrer" target="_blank"><?= e($label) ?></a><?php endif; endforeach; ?>
    </div>
</footer>
