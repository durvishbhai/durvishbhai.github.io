<?php /** @var list<array<string,mixed>> $items rows from the database (quantity_x100, unit_minor, description) */
$typed = old_items();
$rows = [];
if ($typed !== null) {
    $rows = $typed;
} else {
    foreach ($items as $it) {
        $rows[] = ['description' => (string) $it['description'], 'quantity' => \Vertex\Services\BillingItems::qty((int) $it['quantity_x100']), 'unit' => number_format((int) $it['unit_minor'] / 100, 2, '.', '')];
    }
}
$n = max(5, count($rows) + 3);
for ($k = count($rows); $k < min($n, 50); $k++) { $rows[] = ['description' => '', 'quantity' => '', 'unit' => '']; } ?>
<fieldset class="field"><legend>Lines</legend>
    <div class="table-wrap"><table class="table"><thead><tr><th>Description</th><th class="num">Quantity</th><th class="num">Price each (₹)</th></tr></thead><tbody>
        <?php foreach ($rows as $k => $r) : ?><tr><td><input name="items[<?= (int) $k ?>][description]" maxlength="300" aria-label="Line <?= (int) $k + 1 ?> description" value="<?= e($r['description']) ?>"></td>
            <td><input name="items[<?= (int) $k ?>][quantity]" inputmode="decimal" aria-label="Line <?= (int) $k + 1 ?> quantity" value="<?= e($r['quantity']) ?>" placeholder="1"></td>
            <td><input name="items[<?= (int) $k ?>][unit]" inputmode="decimal" aria-label="Line <?= (int) $k + 1 ?> price" value="<?= e($r['unit']) ?>"></td></tr><?php endforeach; ?></tbody></table></div>
    <p class="muted">Empty lines are ignored. Save to get three more empty lines. Amounts are in rupees; no tax is added.</p></fieldset>
