<?php /** @var array<string,list<array<string,mixed>>> $groups */ /** @var string $q */
use Vertex\Services\SiteSections;
$h = SiteSections::get('faq', 'header'); $ql = mb_strtolower($q); ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">FAQ</p><h1><?= st($h['title']) ?></h1><p class="lead"><?= st($h['intro']) ?></p>
    <form method="get" action="/faq" class="faq-search" role="search"><label class="sr-only" for="faq-q">Search the questions</label><input id="faq-q" name="q" type="search" placeholder="Search the questions" value="<?= e($q) ?>" maxlength="80" data-faq-search autocomplete="off"><button class="btn btn-ghost" type="submit">Search</button></form></div></section>
<section class="pub-section"><div class="container narrow" data-faq-list>
<?php $shown = 0; foreach ($groups as $cat => $items) : $rows = array_values(array_filter($items, static fn (array $f): bool => $ql === '' || str_contains(mb_strtolower($f['question'] . ' ' . $f['answer']), $ql))); if ($rows === []) { continue; } ?>
    <h2 class="group-title" data-faq-group><?= e($cat) ?></h2>
    <?php foreach ($rows as $f) : $shown++; ?><details class="faq-item" data-faq-item><summary><?= st((string) $f['question']) ?></summary><div class="prose"><?= sr((string) $f['answer']) ?></div></details><?php endforeach; ?>
<?php endforeach; ?>
<?php if ($shown === 0) : ?><div class="empty-card"><p><?= $groups === [] ? 'Questions and answers will appear here.' : 'No question matches your search.' ?></p><p><a class="btn btn-primary" href="/contact">Ask us directly</a></p></div><?php endif; ?>
<p class="empty-state" data-faq-none hidden>No question matches your search.</p></div></section>
