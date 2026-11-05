<?php /** @var array<string,mixed> $i */ /** @var list<array<string,mixed>> $items */ /** @var array<string,mixed> $bal */ /** @var list<array<string,mixed>> $payments */ /** @var string $back */
$biz = $i['business_snapshot'] ? (array) json_decode((string) $i['business_snapshot'], true) : \Vertex\Services\BusinessProfile::get();
$client = \Vertex\Core\Database::one('SELECT contact_name, company_name FROM clients WHERE id = ?', [$i['client_id']]) ?? [];
$company = trim((string) ($client['company_name'] ?? '')); $contact = trim((string) ($client['contact_name'] ?? ''));
$discount = (int) $i['discount_minor'] + (int) ($i['coupon_discount_minor'] ?? 0);
$qty = static fn (array $it): string => rtrim(rtrim(number_format(((int) $it['quantity_x100']) / 100, 2, '.', ''), '0'), '.');
$rows = $items; while (count($rows) < 4) { $rows[] = null; }
$terms = \Vertex\Services\BusinessProfile::terms('invoice', $biz);
$badge = $i['status'] === 'void' ? 'VOID' : ($i['status'] === 'draft' ? 'DRAFT' : '');
?>
<div class="doc-actions no-print"><a class="btn btn-ghost btn-sm" href="<?= e($back) ?>">&larr; Back</a> <button class="btn btn-primary btn-sm" type="button" data-print>Print or save as PDF</button></div>
<article class="kd-sheet">
    <svg class="kd-swoosh" viewBox="0 0 220 80" aria-hidden="true" focusable="false"><defs><linearGradient id="kd-g1" x1="0" x2="1" y1="0" y2="0"><stop offset="0" stop-color="#281f7d"/><stop offset="1" stop-color="#5a80d0"/></linearGradient><linearGradient id="kd-g2" x1="0" x2="1" y1="0" y2="0"><stop offset="0" stop-color="#281f7d"/><stop offset="1" stop-color="#4d66b8"/></linearGradient></defs>
        <path d="M17.3 46.1C18.7 25.2 28.8 9 45 0H197.3C169.2 10.8 118.8 19.8 77.4 27C50.4 32.4 28.8 37.8 17.3 46.1Z" fill="url(#kd-g1)"/><path d="M18 53.3C34.2 37.8 57.6 31.7 84.6 28.1C57.6 35.3 36 50.4 20.2 76.3Z" fill="url(#kd-g2)"/></svg>
    <div class="kd-tab" aria-hidden="true"></div>
    <header class="kd-head">
        <div class="kd-brand"><img src="<?= e(asset('img/krazy-doc-logo.png')) ?>" alt="KIS Krazy Integrated Solutions logo" width="86" height="86"><p>Krazy<br>Integrated<br>Solutions</p></div>
        <div class="kd-title"><h1>INVOICE</h1><?php if ($badge !== '') : ?><p class="kd-badge"><?= e($badge) ?></p><?php endif; ?></div>
    </header>
    <section class="kd-meta">
        <div class="kd-to"><h2>ISSUED TO:</h2><p><strong><?= e($company !== '' ? $company : $contact) ?></strong></p><?php if ($company !== '' && $contact !== '') : ?><p class="kd-small"><?= e($contact) ?></p><?php endif; ?><p class="kd-small"><?= e((string) $i['title']) ?></p></div>
        <div class="kd-no"><h2>INVOICE NO: <span><?= e((string) ($i['reference'] ?? 'DRAFT')) ?></span></h2>
            <dl><dt>DATE:</dt><dd><?= $i['issue_date'] ? e(local_date($i['issue_date'], 'j M Y')) : '—' ?></dd><?php if ($i['due_date']) : ?><dt>DUE DATE:</dt><dd><?= e(local_date($i['due_date'], 'j M Y')) ?></dd><?php endif; ?></dl></div>
    </section>
    <table class="kd-table">
        <caption class="sr-only">Invoice items</caption>
        <thead><tr><th scope="col" class="kd-no-col">NO.</th><th scope="col">ITEM DESCRIPTION</th><th scope="col" class="kd-r">PRICE</th><th scope="col" class="kd-c">VALIDITY/QTY</th><th scope="col" class="kd-r">SUBTOTAL</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $n => $it) : ?>
            <tr><td class="kd-no-col"><?= e(str_pad((string) ($n + 1), 2, '0', STR_PAD_LEFT)) ?>.</td><td><?= $it === null ? '' : e((string) $it['description']) ?></td><td class="kd-r"><?= $it === null ? '' : e(money((int) $it['unit_minor'])) ?></td><td class="kd-c"><?= $it === null ? '' : e($qty($it)) ?></td><td class="kd-r"><?= $it === null ? '' : e(money((int) $it['total_minor'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="kd-totals">
        <dl><dt>Subtotal</dt><dd><?= e(money((int) $i['subtotal_minor'])) ?></dd><dt>Discount</dt><dd><?= $discount > 0 ? '-' : '' ?><?= e(money($discount)) ?></dd></dl>
        <p class="kd-grand"><span>GRAND TOTAL</span><span><?= e(money((int) $i['total_minor'])) ?></span></p>
        <?php if ($i['status'] !== 'draft') : ?><dl class="kd-after"><?php if ((int) ($i['credited_minor'] ?? 0) > 0) : ?><dt>Credit notes</dt><dd>-<?= e(money((int) $i['credited_minor'])) ?></dd><?php endif; ?><dt>Paid</dt><dd><?= e(money($bal['paid'])) ?></dd><dt class="kd-strong">Balance due</dt><dd class="kd-strong"><?= e(money($bal['outstanding'])) ?></dd></dl><?php endif; ?>
    </div>
    <section class="kd-foot">
        <div class="kd-terms"><h2>Terms &amp; conditions</h2><ul><?php foreach ($terms as $t) : ?><li><?= e($t) ?></li><?php endforeach; ?></ul></div>
        <div class="kd-side">
            <?php if (!empty($biz['payment_instructions'])) : ?><h2>How to pay</h2><p class="prewrap"><?= e((string) $biz['payment_instructions']) ?></p><?php endif; ?>
            <?php if ($i['client_notes']) : ?><h2>Notes</h2><p class="prewrap"><?= e((string) $i['client_notes']) ?></p><?php endif; ?>
        </div>
    </section>
    <footer class="kd-bar">This is a computer-generated invoice and does not require a physical signature.</footer>
</article>
