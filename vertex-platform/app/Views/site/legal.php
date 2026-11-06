<?php
/** @var array<string,mixed> $doc */ /** @var array<string,mixed> $v */ /** @var string $html */ /** @var list<array{id:string,text:string}> $toc */
$eff = $v['effective_date'] ? local_date((string) $v['effective_date'], 'j F Y') : local_date(substr((string) $v['published_at'], 0, 10), 'j F Y');
?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow"><?= e($doc['kind'] === 'statement' ? 'Statement' : 'Legal') ?></p><h1><?= e($v['title']) ?></h1>
    <p class="muted">Version <?= e($v['version_no']) ?> · Effective <?= e($eff) ?> · Last updated <?= e(local_date(substr((string) $v['published_at'], 0, 10), 'j F Y')) ?></p></div></section>
<section class="pub-section"><div class="container narrow">
    <?php if (count($toc) > 3) : ?><nav class="legal-toc" aria-label="On this page"><h2>On this page</h2><ol><?php foreach ($toc as $t) : ?><li><a href="#<?= e($t['id']) ?>"><?= e($t['text']) ?></a></li><?php endforeach; ?></ol></nav><?php endif; ?>
    <div class="prose policy legal-body"><?= $html ?></div>
    <p class="muted small">Questions about this document: <a href="/contact-and-grievance">Contact &amp; grievance information</a>. <a href="/legal-business-information">Who we are</a>.</p>
</div></section>
