<?php /** @var array<string,mixed> $doc */ /** @var array<string,mixed> $v */ /** @var string $html */ ?>
<div class="page-head"><div><p class="eyebrow">Compliance &amp; legal</p><h1><?= e($doc['title']) ?> · version <?= e($v['version_no']) ?></h1></div><a class="btn btn-ghost" href="/admin/compliance/documents/<?= e($doc['doc_key']) ?>">&larr; Back to the document</a></div>
<div class="panel"><p>Status: <strong><?= e(ucfirst((string) $v['status'])) ?></strong><?= $v['published_at'] ? ' · published ' . e(local_time($v['published_at'])) : '' ?><?= $v['effective_date'] ? ' · effective ' . e(local_date((string) $v['effective_date'])) : '' ?></p>
    <?php if ($v['change_summary']) : ?><p class="muted">Summary: <?= e($v['change_summary']) ?></p><?php endif; ?>
    <form method="post" action="/admin/compliance/documents/<?= e($doc['doc_key']) ?>/versions/<?= e($v['version_no']) ?>/restore" data-confirm="Copy this version into the draft, replacing the current draft?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Restore into the draft</button></form></div>
<div class="panel"><div class="prose policy legal-body"><?= $html ?></div></div>
