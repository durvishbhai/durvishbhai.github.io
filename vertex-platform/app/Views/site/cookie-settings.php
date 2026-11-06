<?php /** @var ?array<string,mixed> $cur */ /** @var list<array<string,string>> $inventory */ /** @var array<string,string> $desc */
use Vertex\Services\CookieConsent;
$on = static fn (string $k): bool => $cur !== null && !empty($cur[$k]);
?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Legal</p><h1>Cookie Settings</h1>
    <p class="muted"><?= $cur ? 'Your choice was saved ' . e(date('j F Y', (int) $cur['at'])) . '.' : 'You have not made a choice yet: only strictly necessary cookies are used.' ?> Optional categories are off unless you switch them on.</p></div></section>
<section class="pub-section"><div class="container narrow">
    <form method="post" action="/cookie-settings" class="panel cookie-form" data-cookie-page-form>
        <?php foreach (CookieConsent::CATEGORIES as $key => $label) : ?>
        <div class="cookie-cat"><label class="check"><input type="checkbox" name="<?= e($key) ?>" value="1"<?= $key === 'necessary' ? ' checked disabled' : ($on($key) ? ' checked' : '') ?>> <span><strong><?= e($label) ?></strong><?= $key === 'necessary' ? ' <span class="muted">(always on)</span>' : '' ?></span></label>
            <p class="muted small"><?= e($desc[$key]) ?></p></div>
        <?php endforeach; ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit" name="action" value="custom">Save my choices</button>
            <button class="btn btn-ghost" type="submit" name="action" value="accept_all">Accept All</button>
            <button class="btn btn-ghost" type="submit" name="action" value="reject_all">Reject Non-Essential (withdraw consent)</button></div>
    </form>
    <h2>What is stored on your device</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Name</th><th scope="col">Category</th><th scope="col">Purpose</th><th scope="col">Lasts</th><th scope="col">Set by</th></tr></thead><tbody>
    <?php foreach ($inventory as $c) : ?><tr><td><code><?= e($c['name']) ?></code><br><span class="muted small"><?= e($c['type']) ?></span></td><td><?= e(CookieConsent::CATEGORIES[$c['category']] ?? $c['category']) ?></td><td><?= e($c['purpose']) ?></td><td><?= e($c['duration']) ?></td><td><?= e($c['provider']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <p class="muted">More detail: <a href="/cookie-policy">Cookie Policy</a> · <a href="/third-party-services">Third-party services</a> · <a href="/privacy-policy">Privacy Policy</a>.</p>
</div></section>
