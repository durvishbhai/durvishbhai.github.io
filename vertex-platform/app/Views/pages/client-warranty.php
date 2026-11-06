<?php /** @var list<array<string,mixed>> $items */ ?>
<div class="page-head"><div><p class="eyebrow">Support Centre</p><h1>Website warranty</h1></div><a class="btn btn-ghost" href="/support">&larr; Support Centre</a></div>
<?php foreach ($items as $i) : ?>
<div class="panel"><h2><?= e($i['project']['title']) ?> <span class="badge badge-w-<?= e($i['status']) ?>"><?= e($i['label']) ?></span></h2>
    <?php if ($i['status'] === 'pending_confirmation') : ?><p>Your warranty start date has not been confirmed yet, so we cannot show an end date. We will confirm it with you.</p>
    <?php elseif ($i['status'] === 'not_applicable') : ?><p>A bug-fixing warranty does not apply to this project.</p>
    <?php elseif ($i['status'] === 'suspended') : ?><p>This warranty is currently suspended. Please contact us if you have questions.</p>
    <?php else : ?><dl class="detail-list"><dt>Warranty period</dt><dd><?= e($i['duration_months']) ?> month(s)</dd><dt>Starts</dt><dd><?= e(local_date($i['start_date'])) ?><?= $i['basis'] ? ' · ' . e(config("workflow.warranty_start_bases.{$i['basis']}")) : '' ?></dd>
        <dt>Last covered day</dt><dd><?= e(local_date($i['end_date'])) ?><?= $i['days_left'] !== null ? ' · ' . e($i['days_left']) . ' day(s) left' : '' ?></dd></dl><?php endif; ?>
    <?php if (!empty($i['terms_text'])) : ?><details><summary>Warranty terms<?= $i['terms_title'] ? ': ' . e($i['terms_title']) : '' ?></summary><p class="prewrap"><?= e($i['terms_text']) ?></p></details><?php endif; ?>
    <?php if (!empty($i['project_terms'])) : ?><details><summary>Terms for this project</summary><p class="prewrap"><?= e($i['project_terms']) ?></p></details><?php endif; ?>
    <p class="muted">Being inside the warranty period does not by itself decide whether a particular issue is covered. Our team reviews each bug report against your agreement and explains the outcome.</p>
    <p><a class="btn btn-ghost btn-sm" href="/support/bugs/new">Report a bug</a></p></div>
<?php endforeach; ?>
<?php if ($items === []) : ?><div class="panel"><p class="muted">No projects found.</p></div><?php endif; ?>
