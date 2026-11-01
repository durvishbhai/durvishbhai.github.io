<?php
use Vertex\Services\ComplianceSettings;
use Vertex\Services\CookieConsent;
$desc = \Vertex\Http\Controllers\CookieController::descriptions();
$scripts = \Vertex\Services\CookieRegister::scripts();
?>
<div id="kis-cookie-banner" class="cookie-banner" role="dialog" aria-labelledby="kis-cb-title" aria-describedby="kis-cb-text" hidden
     data-version="<?= e((string) CookieConsent::version()) ?>" data-days="<?= e((string) max(1, ComplianceSettings::int('cookie_valid_days'))) ?>" data-scripts="<?= e((string) json_encode($scripts)) ?>">
    <div class="container cookie-inner">
        <div class="cookie-copy"><h2 id="kis-cb-title"><?= e(ComplianceSettings::get('cookie_banner_title')) ?></h2>
            <p id="kis-cb-text"><?= e(ComplianceSettings::get('cookie_banner_text')) ?> <a href="/cookie-policy">Cookie Policy</a></p></div>
        <div class="cookie-actions">
            <button type="button" class="btn btn-primary" data-consent="accept_all">Accept All</button>
            <button type="button" class="btn btn-ghost" data-consent="reject_all">Reject Non-Essential</button>
            <button type="button" class="btn btn-ghost" data-consent-customize>Customize</button>
        </div>
    </div>
</div>
<dialog id="kis-cookie-dialog" class="cookie-dialog" aria-labelledby="kis-cd-title">
    <form method="post" action="/cookie-settings" class="cookie-form" data-cookie-form>
        <h2 id="kis-cd-title">Cookie settings</h2>
        <p class="muted">Optional categories are off until you switch them on. You can change this at any time from "Cookie Settings" in the footer.</p>
        <?php foreach (CookieConsent::CATEGORIES as $key => $label) : ?>
        <div class="cookie-cat">
            <label class="check"><input type="checkbox" name="<?= e($key) ?>" value="1" data-cat="<?= e($key) ?>"<?= $key === 'necessary' ? ' checked disabled' : '' ?>> <span><strong><?= e($label) ?></strong><?= $key === 'necessary' ? ' <span class="muted">(always on)</span>' : '' ?></span></label>
            <p class="muted small"><?= e($desc[$key]) ?></p>
        </div>
        <?php endforeach; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" name="action" value="custom" data-consent="custom">Save my choices</button>
            <button type="submit" class="btn btn-ghost" name="action" value="accept_all" data-consent="accept_all">Accept All</button>
            <button type="submit" class="btn btn-ghost" name="action" value="reject_all" data-consent="reject_all">Reject Non-Essential</button>
            <button type="button" class="btn btn-ghost" data-consent-close>Close</button>
        </div>
    </form>
</dialog>
