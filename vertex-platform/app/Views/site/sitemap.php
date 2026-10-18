<?php /** @var array<string,list<array{0:string,1:string}>> $groups */ ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Sitemap</p><h1>Sitemap</h1><p class="lead">Every page on this website.</p></div></section>
<section class="pub-section"><div class="container"><div class="sitemap-grid"><?php foreach ($groups as $title => $links) : ?><div><h2><?= e($title) ?></h2><ul class="plain"><?php foreach ($links as [$label, $url]) : ?><li><a href="<?= e($url) ?>"><?= e($label) ?></a></li><?php endforeach; ?></ul></div><?php endforeach; ?></div></div></section>
