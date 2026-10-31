<?php /** @var string $key */ /** @var array<string,mixed> $r */ $q = http_build_query(['from' => $r['from'], 'to' => $r['to']]); ?>
<div class="page-head"><div><p class="eyebrow"><a href="/reports">Reports</a></p><h1><?= e($r['title']) ?></h1><p class="muted"><?= e($r['about']) ?></p></div>
    <div class="actions no-print"><button class="btn btn-ghost" type="button" data-print>Print</button></div></div>
<form method="get" action="/reports/<?= e($key) ?>" class="toolbar no-print"><div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($r['from']) ?>"></div><div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($r['to']) ?>"></div><div class="field"><button class="btn btn-ghost" type="submit">Update</button></div></form>
<p class="muted">Period: <?= e(local_date($r['from'])) ?> to <?= e(local_date($r['to'])) ?>. Generated <?= e(local_time(gmdate('Y-m-d H:i:s'))) ?>.</p>
<?php if ($r['summary'] !== []) : ?><div class="stat-grid"><?php foreach ($r['summary'] as $l => $v) : ?><div class="stat"><span class="stat-n"><?= e($v) ?></span><span class="stat-l"><?= e($l) ?></span></div><?php endforeach; ?></div><?php endif; ?>
<?php foreach ($r['charts'] as $c) : if ($c['svg'] === '') { continue; } ?><div class="panel chart-panel"><h2><?= e($c['title']) ?></h2><?= $c['svg'] ?></div><?php endforeach; ?>
<?php foreach ($r['tables'] as $n => $t) : ?><div class="panel"><div class="page-head"><h2><?= e($t['title']) ?></h2><a class="btn btn-ghost btn-sm no-print" href="/reports/<?= e($key) ?>/csv?<?= e($q) ?>&amp;table=<?= (int) $n ?>">Download CSV</a></div>
    <div class="table-wrap"><table class="table"><thead><tr><?php foreach ($t['columns'] as $col) : ?><th><?= e($col) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($t['rows'] as $row) : ?><tr><?php foreach ($row as $cell) : ?><td><?= e($cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    <?php if ($t['rows'] === []) : ?><tr><td colspan="<?= count($t['columns']) ?>" class="muted">Nothing to show for this period.</td></tr><?php endif; ?></tbody></table></div></div><?php endforeach; ?>
