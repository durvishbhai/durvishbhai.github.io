<?php /** @var array<string,mixed> $i */ /** @var list<array<string,mixed>> $items */ /** @var array<string,mixed> $bal */ /** @var list<array<string,mixed>> $payments */ /** @var string $back */
$biz = $i['business_snapshot'] ? (array) json_decode((string) $i['business_snapshot'], true) : \Vertex\Services\BusinessProfile::get(); $client = \Vertex\Core\Database::one('SELECT contact_name, company_name FROM clients WHERE id = ?', [$i['client_id']]) ?? []; ?>
<div class="doc-actions no-print"><a class="btn btn-ghost btn-sm" href="<?= e($back) ?>">&larr; Back</a> <button class="btn btn-primary btn-sm" type="button" data-print>Print or save as PDF</button></div>
<article class="doc">
    <header class="doc-head"><div><h1><?= e($i['status'] === 'draft' ? 'DRAFT INVOICE' : 'INVOICE') ?></h1><p class="doc-ref"><?= e($i['reference'] ?? '') ?></p><?php if ($i['status'] === 'void') : ?><p><strong>VOID</strong></p><?php endif; ?></div>
        <div class="doc-biz"><strong><?= e($biz['trade_name'] ?? '') ?></strong><?= !empty($biz['legal_name']) ? '<br>' . e($biz['legal_name']) : '' ?><?= !empty($biz['address']) ? '<br><span class="prewrap">' . e($biz['address']) . '</span>' : '' ?><?= !empty($biz['email']) ? '<br>' . e($biz['email']) : '' ?><?= !empty($biz['phone']) ? '<br>' . e($biz['phone']) : '' ?><?= !empty($biz['website']) ? '<br>' . e($biz['website']) : '' ?></div></header>
    <section class="doc-meta"><div><span class="muted">Billed to</span><br><strong><?= e($client['company_name'] ?: ($client['contact_name'] ?? '')) ?></strong></div><div><span class="muted">Issue date</span><br><?= $i['issue_date'] ? e(local_date($i['issue_date'])) : '—' ?></div><div><span class="muted">Due date</span><br><?= $i['due_date'] ? e(local_date($i['due_date'])) : '—' ?></div></section>
    <h2><?= e($i['title']) ?></h2>
    <?= \Vertex\Core\View::partial('billing-lines', ['items' => $items, 'subtotal' => (int) $i['subtotal_minor'], 'discount' => (int) $i['discount_minor'], 'total' => (int) $i['total_minor']]) ?>
    <p><strong>Paid:</strong> <?= e(money($bal['paid'])) ?> · <strong>Balance due:</strong> <?= e(money($bal['outstanding'])) ?></p>
    <?php if ($payments !== []) : ?><p class="muted">Payments received: <?php foreach ($payments as $k => $p) : ?><?= $k ? '; ' : '' ?><?= e(money(max(0, $p['gross_minor'] - $p['refunded_minor']))) ?> on <?= e(local_date($p['received_on'])) ?><?php endforeach; ?></p><?php endif; ?>
    <?php if ($i['client_notes']) : ?><p class="prewrap"><?= e($i['client_notes']) ?></p><?php endif; ?>
    <?php if (!empty($biz['payment_instructions'])) : ?><h3>How to pay</h3><p class="prewrap"><?= e($biz['payment_instructions']) ?></p><?php endif; ?>
    <p class="doc-tax"><?= e($biz['tax_statement'] ?? 'Tax is not applicable.') ?></p>
    <?php if (!empty($biz['invoice_footer'])) : ?><p class="muted"><?= e($biz['invoice_footer']) ?></p><?php endif; ?>
</article>
