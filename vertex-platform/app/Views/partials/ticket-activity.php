<?php /** @var list<array<string,mixed>> $activity */ /** @var array<string,mixed> $ticket */ /** @var bool $staff */
$kind = (string) $ticket['kind'];
$name = static fn (?string $s): string => $s === null ? '' : (string) config(($kind === 'bug' ? 'workflow.bug_statuses.' : 'workflow.support_statuses.') . $s, config('workflow.bug_eligibility.' . $s, $s));
$text = static fn (array $a): string => match ($a['event']) {
    'created' => 'Submitted', 'draft_saved' => 'Draft saved', 'status' => 'Status: ' . $name($a['to_value']), 'message' => 'Message',
    'severity' => 'Severity changed to ' . (string) config('workflow.bug_severity_short.' . $a['to_value'], $a['to_value']),
    'eligibility' => 'Warranty review: ' . (string) config('workflow.bug_eligibility.' . $a['to_value'], $a['to_value']),
    'cost_proposed' => 'Additional cost proposal sent', 'cost_approved' => 'Additional cost approved', 'cost_declined' => 'Additional cost declined', 'cost_proposal_withdrawn' => 'Cost proposal withdrawn',
    'assigned' => 'Assignment changed', 'priority' => 'Priority changed to ' . $a['to_value'], 'escalated' => 'Escalated', 'note' => 'Internal note', 'finding' => 'Technical finding',
    'internal_file' => 'Internal file added', 'cost_proposal_drafted' => 'Cost proposal drafted',
    default => ucfirst(str_replace('_', ' ', (string) $a['event'])),
};
?>
<ol class="timeline">
    <?php foreach ($activity as $a) : ?>
        <li><strong><?= e($text($a)) ?></strong> <span class="muted"><?= e(local_time($a['created_at'])) ?><?= !empty($a['actor_name']) ? ' · ' . e($a['actor_name']) : '' ?><?= $staff && ($a['visibility'] ?? '') === 'internal' ? ' · internal' : '' ?></span>
            <?php if (!empty($a['note'])) : ?><p class="prewrap"><?= e($a['note']) ?></p><?php endif; ?></li>
    <?php endforeach; ?>
</ol>
