<?php /** @var list<array{project:array<string,mixed>,state:array<string,mixed>}> $rows */
use Vertex\Services\SupportPlans;
$rupees = static fn (int $minor): string => '₹' . number_format($minor / 100);
?>
<div class="page-head"><div><p class="eyebrow">My account</p><h1>Bug support</h1></div></div>
<p class="muted">Every finished project includes free support for the first 6 months after final delivery. After that you can keep your project covered with a subscription, or pay for each bug separately.</p>
<?php if ($rows === []) : ?><div class="panel"><p>You have no finished project yet.</p></div><?php endif; ?>
<?php foreach ($rows as $r) : $p = $r['project']; $s = $r['state']; ?>
<div class="panel" id="p-<?= e($p['public_id']) ?>">
    <h2><?= e($p['title']) ?></h2>
    <p><strong><?= e($s['label']) ?></strong><?php if ($s['awaiting']) : ?> · <a href="/my/invoices/<?= e($s['awaiting']['invoice_public_id']) ?>">Subscription invoice <?= e((string) $s['awaiting']['invoice_reference']) ?> is waiting for your payment</a><?php endif; ?></p>
    <?php if ($s['free_over'] || $s['active'] !== null) : ?>
        <?= \Vertex\Core\View::partial('support-options', ['project' => $p, 'state' => $s]) ?>
    <?php elseif ($s['free_end'] !== null) : ?>
        <p class="muted">Your free support ends on <?= e(local_date($s['free_end'])) ?>. We will ask you what you prefer on that day.</p>
    <?php endif; ?>
</div>
<?php endforeach; ?>
