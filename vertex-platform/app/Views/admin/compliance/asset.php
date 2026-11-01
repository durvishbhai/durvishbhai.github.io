<?php /** @var ?array<string,mixed> $row */ use Vertex\Services\AssetRegister;
$v = static fn (string $k, string $d = ''): string => old($k, $row !== null ? (string) ($row[$k] ?? $d) : $d); ?>
<div class="page-head"><div><p class="eyebrow">Asset &amp; licence register</p><h1><?= $row ? e($row['name']) : 'Add an asset' ?></h1></div><a class="btn btn-ghost" href="/admin/compliance/assets">&larr; All assets</a></div>
<div class="panel"><form method="post" action="/admin/compliance/assets/<?= $row ? e((string) $row['id']) : 'new' ?>" class="form-grid" novalidate><?= csrf_field() ?>
    <div class="field"><label for="a-name">Name</label><input id="a-name" name="name" required maxlength="190" value="<?= e($v('name')) ?>"></div>
    <div class="field"><label for="a-kind">Kind</label><select id="a-kind" name="kind"><?php foreach (AssetRegister::KINDS as $k => $l) : ?><option value="<?= e($k) ?>"<?= $v('kind', 'other') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="a-loc">Where it is used (path or page)</label><input id="a-loc" name="location" maxlength="255" value="<?= e($v('location')) ?>"></div>
    <div class="field"><label for="a-src">Source (who made it, or where you got it)</label><input id="a-src" name="source" maxlength="255" value="<?= e($v('source')) ?>"></div>
    <div class="field"><label for="a-lic">Licence</label><input id="a-lic" name="licence" maxlength="190" value="<?= e($v('licence')) ?>"><p class="hint">For example the licence name and version, or "Own work". Do not guess: leave it empty if you do not know.</p></div>
    <div class="field"><label for="a-ev">Evidence (invoice, licence page, agreement)</label><input id="a-ev" name="evidence" maxlength="255" value="<?= e($v('evidence')) ?>"></div>
    <div class="field"><label for="a-st">Status</label><select id="a-st" name="status"><?php foreach (AssetRegister::STATUSES as $k => $l) : ?><option value="<?= e($k) ?>"<?= $v('status', 'unverified') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><p class="hint">"Confirmed" needs a source and a licence or evidence.</p></div>
    <div class="field"><label for="a-nt">Notes (private)</label><textarea id="a-nt" name="notes" rows="3" maxlength="4000"><?= e($v('notes')) ?></textarea></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div></form></div>
