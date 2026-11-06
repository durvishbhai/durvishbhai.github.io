<?php /** @var array<string,array{title:string,about:string,perm:string}> $reports */ ?>
<p class="eyebrow">Insight</p><h1>Reports</h1>
<p class="muted">Every report shows only what you are allowed to see. Each has a CSV download and a print view.</p>
<?php if ($reports === []) : ?><p class="muted">You do not have access to any report.</p><?php endif; ?>
<div class="card-grid"><?php foreach ($reports as $k => $r) : ?><a class="panel report-card" href="/reports/<?= e($k) ?>"><h2><?= e($r['title']) ?></h2><p class="muted"><?= e($r['about']) ?></p></a><?php endforeach; ?></div>
