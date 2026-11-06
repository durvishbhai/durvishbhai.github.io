<?php /** @var string $s */ /** @var string $kind */
$label = (string) config(($kind === 'bug' ? 'workflow.bug_statuses.' : 'workflow.support_statuses.') . $s, $s); ?>
<span class="badge badge-tk-<?= e($s) ?>"><?= e($label) ?></span>
