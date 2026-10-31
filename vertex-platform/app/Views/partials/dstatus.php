<?php /** @var string $s */ /** @var string $kind */ ?>
<span class="badge badge-<?= e($kind) ?>-<?= e($s) ?>"><?= e(config(($kind === 'rstatus' ? 'workflow.revision_statuses.' : ($kind === 'vstatus' ? 'workflow.version_statuses.' : 'workflow.deliverable_statuses.')) . $s, $s)) ?></span>
