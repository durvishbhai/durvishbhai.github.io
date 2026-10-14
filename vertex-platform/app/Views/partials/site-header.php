<?php
use Vertex\Services\Auth;
$signedIn = Auth::user() !== null;
$menu = \Vertex\Services\CmsService::menu('header');
$menu = $menu !== [] ? $menu : [['label' => 'Services', 'url' => '/#services'], ['label' => 'Approach', 'url' => '/#approach'], ['label' => 'Request a quote', 'url' => '/request-quote']];
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
            <?php foreach ($menu as $item) : ?><a href="<?= e($item['url']) ?>"<?= str_starts_with($item['url'], 'http') ? ' rel="noopener"' : '' ?>><?= e($item['label']) ?></a>
            <?php endforeach; ?>
            <?php if ($signedIn) : ?>
                <a class="btn btn-primary btn-sm" href="/dashboard">Dashboard</a>
            <?php else : ?>
                <a class="btn btn-ghost btn-sm" href="/login">Sign in</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
