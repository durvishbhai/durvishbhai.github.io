<section class="auth error-page offline">
    <div class="auth-card">
        <img class="maintenance-logo" src="<?= e(asset('img/krazy-mark.png')) ?>" alt="<?= e((string) config('app.name')) ?>" width="120">
        <p class="eyebrow">No connection</p>
        <h1>You are offline</h1>
        <p class="muted">We cannot reach the internet right now, so this page could not load. Check your Wi-Fi or mobile data, then try again. Nothing you have already saved is lost.</p>
        <p class="error-actions"><a class="btn btn-primary" href="/">Try again</a></p>
    </div>
</section>
