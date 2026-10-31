<?php /** @var array<string,mixed> $j */
use Vertex\Services\SiteText;
$types = ['freelance' => 'Freelance assignment', 'contract' => 'Contract', 'part_time' => 'Part-time', 'full_time' => 'Full-time', 'internship' => 'Internship', 'other' => 'Other']; ?>
<section class="pub-page-head"><div class="container narrow"><nav class="crumbs" aria-label="Breadcrumb"><a href="/careers">Careers</a> <span aria-hidden="true">/</span> <span><?= e($j['title']) ?></span></nav>
    <h1><?= e($j['title']) ?></h1><p class="chip-row"><?php foreach (array_filter([(string) $j['department'], $types[$j['engagement_type']] ?? '', $j['deadline'] ? 'Apply by ' . local_date((string) $j['deadline']) : '', (string) $j['compensation']]) as $c) : ?><span class="pub-chip"><?= e($c) ?></span><?php endforeach; ?></p></div></section>
<section class="pub-section"><div class="container narrow">
    <?php foreach (['responsibilities' => 'Responsibilities', 'deliverables' => 'Expected deliverables', 'skills' => 'Skills required'] as $col => $label) : if ($j[$col]) : ?><h2><?= e($label) ?></h2><ul class="check-list"><?php foreach (SiteText::items((string) $j[$col]) as $i) : ?><li><?= e($i) ?></li><?php endforeach; ?></ul><?php endif; endforeach; ?>
    <?php if ($j['instructions']) : ?><h2>How to apply</h2><div class="prose"><?= sr((string) $j['instructions']) ?></div><?php endif; ?>
    <p class="muted">Applications are subject to review and to the terms of this opportunity. Applying does not guarantee selection or payment.</p>
    <p><a class="btn btn-primary" href="/careers?role=<?= e(rawurlencode((string) $j['title'])) ?>#apply">Apply for this opening</a></p></div></section>
