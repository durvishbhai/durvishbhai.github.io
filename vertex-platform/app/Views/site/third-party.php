<?php /** @var array{active:list<array<string,mixed>>,planned:list<array<string,mixed>>,optional_cookies:bool} $view */
use Vertex\Services\Integrations;
$card = static function (array $r): void { ?>
    <article class="panel tp-card"><h3><?= e($r['name']) ?> <span class="badge"><?= e(Integrations::CATEGORIES[$r['category']] ?? $r['category']) ?></span></h3>
        <?php if ($r['purpose']) : ?><p><strong>What it does for us:</strong> <?= e($r['purpose']) ?></p><?php endif; ?>
        <?php if ($r['data_shared']) : ?><p><strong>Data involved:</strong> <?= e($r['data_shared']) ?></p><?php endif; ?>
        <?php if ($r['location_note']) : ?><p><strong>Where:</strong> <?= e($r['location_note']) ?></p><?php endif; ?>
        <p><strong>Cookies or browser storage:</strong> <?= $r['cookie_category'] === 'none' ? 'none set by this service on this website' : e(Integrations::COOKIE_CATEGORIES[$r['cookie_category']]) . ' (see <a href="/cookie-settings">Cookie Settings</a>)' ?></p>
        <p class="small muted"><?php if ($r['provider_url']) : ?><a href="<?= e($r['provider_url']) ?>" rel="noopener noreferrer" target="_blank">Provider website<span class="sr-only"> (opens in a new tab)</span></a><?php endif; ?>
            <?php if ($r['privacy_url']) : ?> · <a href="<?= e($r['privacy_url']) ?>" rel="noopener noreferrer" target="_blank">Its privacy policy<span class="sr-only"> (opens in a new tab)</span></a><?php endif; ?>
            <?= $r['last_reviewed'] ? ' · reviewed ' . e(local_date((string) $r['last_reviewed'])) : '' ?></p></article>
<?php }; ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Legal</p><h1>Third-Party Services</h1>
    <p class="muted">The outside services that help us run this website and our business, what they do and what data they handle. This page is kept up to date by the business.</p></div></section>
<section class="pub-section"><div class="container narrow">
    <h2>Services we use today</h2>
    <?php foreach ($view['active'] as $r) { $card($r); } ?>
    <?php if ($view['active'] === []) : ?><p class="muted">No third-party service is listed as in use at the moment.</p><?php endif; ?>
    <?php if (!$view['optional_cookies']) : ?><p class="panel-note"><strong>No analytics or advertising.</strong> We do not use Google Analytics, Meta Pixel or any other analytics, tracking or advertising service on this website. If that changes, it will be listed here first and will only run if you allow it in <a href="/cookie-settings">Cookie Settings</a>.</p><?php endif; ?>
    <?php if ($view['planned'] !== []) : ?><h2>Possible in the future (not used today)</h2><p class="muted">These are listed so you know what we may consider. <strong>None of them is in use now and no data is sent to them.</strong> We will update this page before any of them is switched on.</p>
        <?php foreach ($view['planned'] as $r) { $card($r); } ?><?php endif; ?>
    <p class="muted small">See also: <a href="/privacy-policy">Privacy Policy</a> · <a href="/cookie-policy">Cookie Policy</a> · <a href="/legal-business-information">Who we are</a>.</p>
</div></section>
