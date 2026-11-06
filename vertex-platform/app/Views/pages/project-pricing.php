<?php
/** @var array<string,mixed> $project */ /** @var array<string,mixed> $ov */ /** @var string $level */
/** @var bool $canChangePriority */ /** @var bool $canRecord */ /** @var bool $canWithdraw */ /** @var bool $canDecide */ /** @var array<int,array<string,mixed>|null> $rulesNow */
use Vertex\Services\FieldRules;
use Vertex\Services\PricingService;

$base = '/projects/' . $project['public_id'];
$priorities = (array) config('workflow.priorities');
$money = static fn (?int $m): string => $m === null ? '—' : '₹' . FieldRules::formatMinor($m);
$signed = static fn (int $m): string => ($m > 0 ? '+' : ($m < 0 ? '−' : '')) . '₹' . FieldRules::formatMinor(abs($m));
$staff = (bool) $ov['staff'];
$cur = $ov['current'];
$pending = $ov['pending'];
$v = static fn (string $k, string $d = ''): string => old($k, $d);
?>
<div class="page-head"><div><p class="eyebrow">Project <?= e($project['code']) ?></p><h1><?= e($project['title']) ?></h1></div><a class="btn btn-ghost" href="/projects">&larr; Projects</a></div>
<?= \Vertex\Core\View::partial('project-tabs', ['project' => $project, 'tab' => 'pricing']) ?>

<div class="panel"><h2>Approved price</h2>
    <?php if ($cur === null) : ?>
        <p class="muted">No approved quotation has been recorded for this project yet<?= $staff ? ', so a priority change does not affect any price' : '' ?>.</p>
    <?php else : ?>
        <dl class="detail-list">
            <dt>Quotation</dt><dd><?= e($cur['quotation_ref'] ?? '—') ?></dd>
            <dt>Current approved total</dt><dd><strong><?= e($money((int) $cur['amount_minor'])) ?></strong> <span class="muted">(base <?= e($money((int) $cur['base_minor'])) ?> + priority charge <?= e($money((int) $cur['surcharge_minor'])) ?>)</span></dd>
            <dt>Project priority</dt><dd><span class="badge badge-prio-<?= e($project['priority']) ?>"><?= e($priorities[$project['priority']] ?? $project['priority']) ?></span></dd>
        </dl>
        <p class="muted">The approved price never changes silently. It changes only through a revision the client has approved; the original is always kept below. Nothing on this page collects payment or issues an invoice.</p>
    <?php endif; ?>
</div>

<?php if ($pending !== null) : $snap = json_decode((string) $pending['rule_snapshot_json'], true) ?: []; ?>
<div class="panel panel-attention"><h2>Priority change awaiting the client's approval</h2>
    <p>Change priority from <strong><?= e($priorities[$pending['from_priority']]) ?></strong> to <strong><?= e($priorities[$pending['to_priority']]) ?></strong>.</p>
    <dl class="detail-list">
        <dt>Priority charge now</dt><dd><?= e($money((int) $pending['current_surcharge_minor'])) ?></dd>
        <dt>Priority charge if approved</dt><dd><?= e($money((int) $pending['proposed_surcharge_minor'])) ?></dd>
        <dt>Change to the approved total</dt><dd><strong><?= e($signed((int) $pending['adjustment_minor'])) ?></strong> → new total <?= e($money((int) $pending['base_minor'] + (int) $pending['proposed_surcharge_minor'])) ?></dd>
        <?php if ($staff && !empty($snap['staff_note'])) : ?><dt>Team note</dt><dd><?= e($snap['staff_note']) ?></dd><?php endif; ?>
        <?php if ($staff) : ?><dt>Rule used</dt><dd><?= e(PricingService::describe(['surcharge_type' => $snap['type'] ?? 'none', 'fixed_minor' => $snap['fixed_minor'] ?? 0, 'percent_bps' => $snap['percent_bps'] ?? 0])) ?><?= isset($snap['rule_version']) ? ' (rule v' . e($snap['rule_version']) . ')' : '' ?></dd><?php endif; ?>
        <dt>Proposed</dt><dd><?= e(local_time($pending['requested_at'])) ?><?= $staff && $pending['requested_by_name'] ? ' by ' . e($pending['requested_by_name']) : '' ?></dd>
    </dl>
    <p class="muted">Until the client approves, the project priority and the approved price stay exactly as they are.</p>
    <?php if ($canDecide) : ?>
        <form method="post" action="<?= e($base) ?>/pricing/requests/<?= e($pending['public_id']) ?>/decision" class="form-grid" data-confirm="Record your decision?"><?= csrf_field() ?>
            <div class="field"><label for="dc">Comment (optional)</label><input id="dc" name="comment" maxlength="255"></div>
            <div class="form-actions"><button class="btn btn-primary" name="decision" value="approve" type="submit">Approve this change</button>
                <button class="btn btn-ghost" name="decision" value="reject" type="submit">Decline</button></div></form>
    <?php endif; ?>
    <?php if ($canWithdraw) : ?>
        <form method="post" action="<?= e($base) ?>/pricing/requests/<?= e($pending['public_id']) ?>/withdraw" class="inline-form" data-confirm="Withdraw this proposal?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Withdraw proposal</button></form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($canChangePriority) : ?>
<div class="panel"><h2>Change project priority</h2>
    <p class="muted">This is the PROJECT priority. Task priorities are separate and never affect price.<?= $cur !== null ? ' Because a quotation is approved, a priority that changes the priority charge becomes a proposal the client must approve.' : '' ?></p>
    <?php if ($staff && can('pricing.view')) : ?>
        <p class="muted">Current rules for this service:
            <?php foreach (array_keys($priorities) as $i => $p) : ?><?= $i > 0 ? ' · ' : ' ' ?><?= e($priorities[$p]) ?>: <?= e(PricingService::describe($rulesNow[$i])) ?><?php endforeach; ?></p>
    <?php endif; ?>
    <form method="post" action="<?= e($base) ?>/priority" class="form-grid" novalidate><?= csrf_field() ?><input type="hidden" name="return" value="pricing">
        <div class="field"><label for="np">New priority</label><select id="np" name="priority"><?php foreach ($priorities as $c => $l) : if ($c === $project['priority']) { continue; } ?><option value="<?= e($c) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="nn">Note for the client (optional)</label><input id="nn" name="note" maxlength="255"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $cur !== null ? 'Calculate and propose' : 'Change priority' ?></button></div></form></div>
<?php endif; ?>

<?php if ($canRecord) : ?>
<div class="panel"><h2>Record the approved quotation</h2>
    <p class="muted">Enter the figures from the signed-off quotation. This can be done once; afterwards the price changes only through client-approved revisions. (Quotations are not generated by this system yet, so the reference is typed in.)</p>
    <form method="post" action="<?= e($base) ?>/pricing/baseline" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="qr">Quotation reference</label><input id="qr" name="quotation_ref" required maxlength="60" value="<?= e($v('quotation_ref')) ?>"></div>
        <div class="field"><label for="qb">Base price (₹, before any priority charge)</label><input id="qb" name="base" required inputmode="decimal" value="<?= e($v('base')) ?>"></div>
        <div class="field"><label for="qs">Priority charge already included (₹)</label><input id="qs" name="surcharge" inputmode="decimal" value="<?= e($v('surcharge', '0')) ?>"></div>
        <div class="field"><label for="qp">Your password</label><input id="qp" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Record approved quotation</button></div></form></div>
<?php endif; ?>

<div class="panel"><h2>Price history</h2>
    <?php if ($ov['revisions'] === []) : ?><p class="muted">No price revisions yet.</p><?php else : ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>What</th><th>Base</th><th>Priority charge</th><th>Total</th><th>Recorded</th></tr></thead><tbody>
            <?php foreach ($ov['revisions'] as $r) : ?>
                <tr><td><?= e($r['revision_no']) ?></td><td><?= e($r['kind'] === 'original' ? 'Approved quotation' : 'Priority change') ?><br><span class="muted"><?= e($r['note'] ?? '') ?></span></td>
                    <td><?= e($money((int) $r['base_minor'])) ?></td><td><?= e($money((int) $r['surcharge_minor'])) ?></td><td><strong><?= e($money((int) $r['amount_minor'])) ?></strong></td>
                    <td><?= e(local_time($r['created_at'])) ?><?= $staff && $r['author'] ? '<br><span class="muted">' . e($r['author']) . '</span>' : '' ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
</div>

<?php $past = array_values(array_filter($ov['requests'], static fn (array $r): bool => $r['status'] !== 'pending_client')); ?>
<?php if ($past !== []) : ?>
<div class="panel"><h2>Earlier proposals</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Change</th><th>Adjustment</th><th>Outcome</th><th>When</th></tr></thead><tbody>
        <?php foreach ($past as $r) : ?>
            <tr><td><?= e($priorities[$r['from_priority']]) ?> → <?= e($priorities[$r['to_priority']]) ?></td><td><?= e($signed((int) $r['adjustment_minor'])) ?></td>
                <td><span class="badge badge-pr-<?= e($r['status']) ?>"><?= e(status_label((string) $r['status'])) ?></span><?= $r['decision_note'] ? '<br><span class="muted">' . e($r['decision_note']) . '</span>' : '' ?></td>
                <td><?= e(local_time($r['decided_at'] ?? $r['requested_at'])) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div></div>
<?php endif; ?>
