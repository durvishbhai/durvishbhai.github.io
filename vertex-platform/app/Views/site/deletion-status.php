<?php /** @var array<string,mixed> $r */ /** @var list<array<string,mixed>> $events */ /** @var ?array<string,mixed> $result */ /** @var string $token */
use Vertex\Services\DataDeletion;
$open = in_array($r['status'], ['awaiting_verification', 'verified', 'needs_clarification', 'approved'], true); ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Your data</p><h1>Request <?= e($r['reference']) ?></h1>
    <p class="lead">Status: <strong><?= e(DataDeletion::STATUS[$r['status']] ?? $r['status']) ?></strong></p></div></section>
<section class="pub-section"><div class="container narrow">
    <?php if ($r['status'] === 'needs_clarification' && $r['decision_note']) : ?><div class="panel panel-warn"><h2>We need more information</h2><p class="prewrap"><?= e($r['decision_note']) ?></p></div><?php endif; ?>
    <?php if ($r['status'] === 'rejected') : ?><div class="panel panel-warn"><h2>Not approved</h2><p class="prewrap"><?= e((string) $r['decision_note']) ?></p><p class="muted">If you disagree, you can contact us or <a href="/data-deletion">submit a new request</a>. You can also complain to the relevant data protection authority.</p></div><?php endif; ?>
    <div class="panel"><h2>What has happened</h2><ol class="timeline"><?php foreach ($events as $e) : ?><li><strong><?= e(ucfirst(str_replace('_', ' ', (string) $e['event']))) ?></strong> <span class="muted small"><?= e(local_time($e['created_at'], 'd M Y, H:i')) ?></span><?= $e['note'] ? '<br>' . e($e['note']) : '' ?></li><?php endforeach; ?></ol></div>
    <?php if ($result) : ?><div class="panel"><h2>Summary of what was done</h2>
        <h3>Deleted</h3><ul><?php foreach ($result['deleted'] as $k => $v) : ?><li><?= e($k) ?> (<?= e($v) ?>)</li><?php endforeach; ?><?php if ($result['deleted'] === []) : ?><li>Nothing needed deleting.</li><?php endif; ?></ul>
        <h3>Anonymised (kept without identifying you)</h3><ul><?php foreach ($result['anonymised'] as $k => $v) : ?><li><?= e($k) ?><?= is_int($v) && $v > 1 ? ' (' . e($v) . ')' : '' ?></li><?php endforeach; ?><?php if ($result['anonymised'] === []) : ?><li>Nothing.</li><?php endif; ?></ul>
        <h3>Kept because the law or a contract requires it</h3><ul><?php foreach ($result['retained'] as $x) : ?><li><strong><?= e($x['what']) ?><?= $x['count'] ? ' (' . e($x['count']) . ')' : '' ?>:</strong> <?= e($x['reason']) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (in_array($r['status'], ['verified', 'needs_clarification'], true)) : ?>
    <form method="post" action="/data-deletion/status/<?= e($token) ?>" class="panel form-grid"><?= csrf_field() ?><h2>Reply to us</h2>
        <div class="field"><label for="rn">Your message</label><textarea id="rn" name="note" rows="3" maxlength="1000"></textarea></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit" name="action" value="reply">Send</button></div></form><?php endif; ?>
    <?php if ($open) : ?><form method="post" action="/data-deletion/status/<?= e($token) ?>" data-confirm="Withdraw this request?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit" name="action" value="withdraw">Withdraw this request</button></form><?php endif; ?>
</div></section>
