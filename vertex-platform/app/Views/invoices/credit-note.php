<?php /** @var array<string,mixed> $n */ /** @var string $back */ /** @var string $pdf */
$biz = $n['business_snapshot'] ? (array) json_decode((string) $n['business_snapshot'], true) : \Vertex\Services\BusinessProfile::get(); ?>
<div class="doc-actions no-print"><a class="btn btn-ghost btn-sm" href="<?= e($back) ?>">&larr; Back</a> <a class="btn btn-ghost btn-sm" href="<?= e($pdf) ?>">Download PDF</a> <button class="btn btn-primary btn-sm" type="button" data-print>Print</button></div>
<article class="doc">
    <header class="doc-head"><div><h1>CREDIT NOTE</h1><p class="doc-ref"><?= e($n['reference']) ?></p><?php if ($n['status'] === 'void') : ?><p><strong>VOID</strong></p><?php endif; ?></div>
        <div class="doc-biz"><strong><?= e($biz['trade_name'] ?? '') ?></strong><?= !empty($biz['address']) ? '<br><span class="prewrap">' . e($biz['address']) . '</span>' : '' ?></div></header>
    <section class="doc-meta"><div><span class="muted">Credited to</span><br><strong><?= e($n['client_name']) ?></strong></div><div><span class="muted">Issued</span><br><?= e(local_date($n['issued_at'])) ?></div><div><span class="muted">Against invoice</span><br><?= e($n['invoice_reference']) ?></div></section>
    <p><strong>Reason:</strong> <?= e($n['reason']) ?></p>
    <table class="table"><tbody><tr><td>Credit amount</td><td class="num"><strong><?= e(money((int) $n['amount_minor'])) ?></strong></td></tr></tbody></table>
    <p class="muted">This credit note reduces the amount still owed on the invoice above. It is not a refund.</p>
    <p class="doc-tax"><?= e($biz['tax_statement'] ?? 'Tax is not applicable.') ?></p>
</article>
