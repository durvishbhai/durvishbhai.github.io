<?php
use Vertex\Services\SiteHiring;

try {
    $show = SiteHiring::isOpen() && !str_starts_with(\Vertex\Core\Request::capture()->path, '/hiring');
    $titles = $show ? SiteHiring::roleTitles() : [];
} catch (\Throwable) {
    $show = false;
    $titles = [];
}
if ($show && $titles !== []) : ?>
<div class="announce" role="region" aria-label="Hiring announcement"><span class="pulse-dot" aria-hidden="true"></span> We're hiring: <?= e(implode(', ', array_slice($titles, 0, 4))) ?>. <a href="/hiring">Apply now &rarr;</a></div>
<?php endif; ?>
