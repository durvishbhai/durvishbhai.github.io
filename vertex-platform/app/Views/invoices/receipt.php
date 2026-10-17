<?php /** @var array<string,mixed> $p */ /** @var ?array<string,mixed> $i */ /** @var string $back */ /** @var string $pdf */
$biz = $i !== null && $i['business_snapshot'] ? (array) json_decode((string) $i['business_snapshot'], true) : \Vertex\Services\BusinessProfile::get();
$net = max(0, (int) $p['gross_minor'] - (int) $p['refunded_minor']); ?>
<div class="doc-actions no-print"><a class="btn btn-ghost btn-sm" href="<?= e($back) ?>">&larr; Back</a> <a class="btn btn-ghost btn-sm" href="<?= e($pdf) ?>">Download PDF</a> <button class="btn btn-primary btn-sm" type="button" data-print>Print</button></div>
<article class="doc">
    <header class="doc-head"><div><h1>PAYMENT RECEIPT</h1><p class="doc-ref"><?= e($p['reference']) ?></p><?php if ($p['status'] === 'reversed') : ?><p><strong>REVERSED</strong></p><?php endif; ?></div>
        <div class="doc-biz"><strong><?= e($biz['trade_name'] ?? '') ?></strong><?= !empty($biz['address']) ? '<br><span class="prewrap">' . e($biz['address']) . '</span>' : '' ?></div></header>
    <section class="doc-meta"><div><span class="muted">Received from</span><br><strong><?= e($p['client_name']) ?></strong></div><div><span class="muted">Received on</span><br><?= e(local_date($p['received_on'])) ?></div><div><span class="muted">Method</span><br><?= e(ucwords(str_replace('_', ' ', (string) $p['method']))) ?></div><div><span class="muted">Against</span><br><?= e($i !== null ? (string) $i['reference'] : (string) ($p['invoice_ref'] ?? '-')) ?></div></section>
    <table class="table"><tbody><tr><td>Amount received</td><td class="num"><strong><?= e(money((int) $p['gross_minor'])) ?></strong></td></tr>
        <?php if ((int) $p['refunded_minor'] > 0) : ?><tr><td>Refunded</td><td class="num">-<?= e(money((int) $p['refunded_minor'])) ?></td></tr><tr><td>Net received</td><td class="num"><strong><?= e(money($net)) ?></strong></td></tr><?php endif; ?></tbody></table>
    <p class="muted">Payment reference: <?= e($p['external_ref']) ?></p>
    <p class="doc-tax"><?= e($biz['tax_statement'] ?? 'Tax is not applicable.') ?></p>
</article>
