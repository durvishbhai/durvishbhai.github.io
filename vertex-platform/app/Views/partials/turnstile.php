<?php if (\Vertex\Services\Turnstile::enabled()) : ?>
<div class="field turnstile"><div class="cf-turnstile" data-sitekey="<?= e(\Vertex\Services\Turnstile::siteKey()) ?>"></div>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <noscript><p class="hint">Please turn on JavaScript to complete the security check.</p></noscript></div>
<?php endif; ?>
