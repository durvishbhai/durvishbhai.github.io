<?php /** @var array<string,string> $exports */ /** @var array<string,string> $imports */ /** @var list<array<string,mixed>> $history */ /** @var ?array<string,mixed> $report */ ?>
<p class="eyebrow">Administration</p>
<h1>Import &amp; export</h1>
<?php if ($report !== null) : ?>
    <div class="alert <?= (int) $report['failed'] === 0 ? 'alert-ok' : 'alert-error' ?>" role="status">
        <p><strong><?= $report['checkOnly'] ? 'Check finished (nothing was saved)' : 'Import finished' ?>:</strong> <?= e($report['file']) ?> &middot; <?= (int) $report['total'] ?> row(s), <?= (int) $report['imported'] ?> <?= $report['checkOnly'] ? 'valid' : 'imported' ?>, <?= (int) $report['failed'] ?> rejected<?= (int) $report['duplicates'] > 0 ? ' (' . (int) $report['duplicates'] . ' already exist)' : '' ?>.</p>
        <?php if ($report['errors'] !== []) : ?><ul><?php foreach (array_slice($report['errors'], 0, 50) as $err) : ?><li><?= e($err) ?></li><?php endforeach; ?></ul><?php if (count($report['errors']) > 50) : ?><p class="muted">...and <?= count($report['errors']) - 50 ?> more.</p><?php endif; ?><?php endif; ?>
    </div>
<?php endif; ?>
<div class="panel"><h2>Export to CSV</h2>
    <p class="muted">You get the same records you can see on screen (nothing more). Every export is written to the audit log. Up to 10,000 rows. To export a filtered list, open the list, filter it and use the address with <code>/admin/data/export/&lt;type&gt;?q=...</code>.</p>
    <?php if ($exports === []) : ?><p class="muted">You do not have permission to export.</p><?php endif; ?>
    <ul class="plain"><?php foreach ($exports as $k => $l) : ?><li><a class="btn btn-ghost btn-sm" href="/admin/data/export/<?= e($k) ?>">Download <?= e($l) ?></a></li><?php endforeach; ?></ul>
    <?php if (can('renewals.manage')) : ?><p><a href="/renewals/export">Domains &amp; hosting (CSV)</a></p><?php endif; ?>
</div>
<div class="panel"><h2>Import from CSV</h2>
    <?php if ($imports === []) : ?><p class="muted">You do not have permission to import.</p><?php else : ?>
    <p class="muted">Use the template so the column names match. Every row is validated exactly like the on-screen form and duplicates are skipped. <strong>Run &ldquo;Check only&rdquo; first</strong>: it saves nothing and lists every problem with its line number. At most 1,000 rows and 1 MB per file.</p>
    <?php foreach ($imports as $k => $l) : ?>
        <form method="post" action="/admin/data/import/<?= e($k) ?>" enctype="multipart/form-data" class="form-grid"><?= csrf_field() ?>
            <h3><?= e($l) ?> &middot; <a href="/admin/data/template/<?= e($k) ?>">template</a></h3>
            <div class="field"><label for="f-<?= e($k) ?>">CSV file</label><input id="f-<?= e($k) ?>" name="file" type="file" accept=".csv,text/csv" required></div>
            <div class="field"><label for="m-<?= e($k) ?>">Action</label><select id="m-<?= e($k) ?>" name="mode"><option value="check">Check only (saves nothing)</option><option value="import">Import the valid rows</option></select></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Run</button></div></form>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php if ($history !== []) : ?><div class="panel"><h2>Recent imports</h2><div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>What</th><th>File</th><th>Rows</th><th>Imported</th><th>Rejected</th><th>By</th></tr></thead><tbody>
    <?php foreach ($history as $h) : ?><tr><td><?= e(local_time($h['created_at'])) ?></td><td><?= e($h['kind']) ?></td><td><?= e($h['filename']) ?></td><td><?= (int) $h['total_rows'] ?></td><td><?= (int) $h['imported_rows'] ?></td><td><?= (int) $h['failed_rows'] ?></td><td><?= e($h['who']) ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
