<?php
use Vertex\Services\Auth;
use Vertex\Services\SiteMenu;
$signedIn = Auth::user() !== null;
$signup = !$signedIn && \Vertex\Services\Registration::enabled();
try {
    $menu = SiteMenu::header();
} catch (\Throwable) {
    $menu = [['label' => 'Home', 'url' => '/', 'children' => []], ['label' => 'Contact Us', 'url' => '/contact', 'children' => []]];
}
$company = \Vertex\Services\SiteText::company();
$path = \Vertex\Core\Request::capture()->path;
$active = static fn (string $u): bool => $u === '/' ? $path === '/' : ($path === $u || str_starts_with($path, $u . '/'));
?>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="/" aria-label="<?= e($company) ?> home">
            <img src="<?= e(asset('img/krazy-mark.png')) ?>" alt="" width="86" height="35">
            <span class="brand-word">Krazy <small>Integrated Solutions</small></span>
        </a>
        <?php if ($signedIn) : ?><a class="btn btn-ghost btn-sm header-login" href="/dashboard">Dashboard</a><?php else : ?><a class="btn btn-ghost btn-sm header-login" href="/login">Login</a><?php if ($signup) : ?><a class="btn btn-primary btn-sm header-login header-signup" href="/register">Sign up</a><?php endif; ?><?php endif; ?>
        <?= \Vertex\Core\View::partial('theme-toggle') ?>
        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
            <span class="sr-only">Menu</span><span class="bars" aria-hidden="true"></span>
        </button>
        <nav id="site-nav" class="site-nav" aria-label="Primary">
            <ul class="nav-list">
                <?php foreach ($menu as $item) : ?>
                    <?php if ($item['children'] !== []) : ?>
                        <li class="nav-item has-sub<?= $active($item['url']) ? ' is-active' : '' ?>">
                            <a href="<?= shref($item['url']) ?>"<?= $active($item['url']) ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
                            <button class="sub-toggle" type="button" aria-expanded="false" data-sub-toggle><span class="sr-only">Show <?= e($item['label']) ?> links</span><span aria-hidden="true">&#9662;</span></button>
                            <ul class="nav-sub"><?php foreach ($item['children'] as $c) : ?><li><a href="<?= shref($c['url']) ?>"><?= e($c['label']) ?></a></li><?php endforeach; ?></ul>
                        </li>
                    <?php else : ?>
                        <li class="nav-item<?= $active($item['url']) ? ' is-active' : '' ?>"><a href="<?= shref($item['url']) ?>"<?= $active($item['url']) ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
            <div class="nav-actions">
                <span class="nav-login"><?php if ($signedIn) : ?><a class="btn btn-ghost btn-sm" href="/dashboard">Dashboard</a><?php else : ?><a class="btn btn-ghost btn-sm" href="/login">Login</a><?php if ($signup) : ?> <a class="btn btn-ghost btn-sm" href="/register">Sign up</a><?php endif; ?><?php endif; ?></span>
                <a class="btn btn-primary btn-sm" href="/request-quote">Start Your Project</a>
            </div>
        </nav>
    </div>
</header>
