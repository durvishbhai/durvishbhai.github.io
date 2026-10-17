<?php
/** @var array<string,mixed> $d */ /** @var list<array<string,mixed>> $versions */ /** @var array<string,mixed>|null $a */ /** @var array<string,mixed>|null $b */ /** @var bool $staff */
use Vertex\Services\FileStore;
$rows = [
    'Version' => static fn (array $v): string => 'V' . $v['version_no'] . ($v['label'] ? ' · ' . $v['label'] : ''),
    'Status' => static fn (array $v): string => (string) config("workflow.version_statuses.{$v['status']}", $v['status']),
    'File name' => static fn (array $v): string => (string) ($v['original_name'] ?? ($v['external_url'] ? 'External link' : '—')),
    'Size' => static fn (array $v): string => $v['file_id'] !== null ? FileStore::humanSize((int) $v['size_bytes']) : '—',
    'Type' => static fn (array $v): string => (string) ($v['mime'] ?? '—'),
    'Uploaded' => static fn (array $v): string => local_time($v['created_at']),
    'Published' => static fn (array $v): string => $v['published_at'] ? local_time($v['published_at']) : '—',
];
if ($staff) {
    $rows['Uploaded by'] = static fn (array $v): string => (string) ($v['uploader_name'] ?? '—');
    $rows['Checksum (SHA-256)'] = static fn (array $v): string => $v['sha256'] ? substr((string) $v['sha256'], 0, 16) . '…' : '—';
    $rows['Internal change notes'] = static fn (array $v): string => (string) ($v['change_notes'] ?? '—');
}
?>
<div class="page-head"><div><p class="eyebrow">Deliverable <?= e($d['code']) ?></p><h1>Compare versions</h1></div><a class="btn btn-ghost" href="/deliverables/<?= e($d['public_id']) ?>">&larr; <?= e($d['title']) ?></a></div>
<form method="get" class="toolbar">
    <div class="field"><label for="a">Older</label><select id="a" name="a"><?php foreach ($versions as $v) : ?><option value="<?= e($v['public_id']) ?>"<?= $a && $a['public_id'] === $v['public_id'] ? ' selected' : '' ?>>V<?= e($v['version_no']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="b">Newer</label><select id="b" name="b"><?php foreach ($versions as $v) : ?><option value="<?= e($v['public_id']) ?>"<?= $b && $b['public_id'] === $v['public_id'] ? ' selected' : '' ?>>V<?= e($v['version_no']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><button class="btn btn-ghost" type="submit">Compare</button></div></form>
<?php if ($a === null || $b === null) : ?><p class="muted">There is only one version to show.</p><?php else : ?>
<p class="muted">Metadata only: file contents are not compared. Rows that differ are highlighted.</p>
<div class="table-wrap"><table class="table"><thead><tr><th></th><th>V<?= e($a['version_no']) ?></th><th>V<?= e($b['version_no']) ?></th></tr></thead><tbody>
    <?php foreach ($rows as $label => $fn) : $x = $fn($a); $y = $fn($b); ?>
        <tr<?= $x !== $y ? ' class="diff"' : '' ?>><th scope="row"><?= e($label) ?></th><td class="prewrap"><?= e($x) ?></td><td class="prewrap"><?= e($y) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
