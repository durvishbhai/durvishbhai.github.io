<?php
use Vertex\Services\Auth;
$signedIn = Auth::user() !== null;
?>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="/" aria-label="Vertex Integrated Solutions home">
            <img src="<?= e(asset('img/vertex-mark.png')) ?>" alt="" width="44" height="38">
            <span class="brand-word">Vertex <small>Integrated Solutions</small></span>
        </a>
        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
            <span class="sr-only">Menu</span><span class="bars" aria-hidden="true"></span>
        </button>
        <nav id="site-nav" class="site-nav" aria-label="Primary">
            <a href="/#services">Services</a>
            <a href="/#approach">Approach</a>
            <?php if ($signedIn) : ?>
                <a class="btn btn-primary btn-sm" href="/dashboard">Dashboard</a>
            <?php else : ?>
                <a class="btn btn-ghost btn-sm" href="/login">Sign in</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
