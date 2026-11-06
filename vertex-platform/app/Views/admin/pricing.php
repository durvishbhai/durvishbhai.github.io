<?php
/** @var array<string,array<string,array<string,mixed>>> $rules */ /** @var list<array<string,mixed>> $history */ /** @var bool $canManage */
use Vertex\Services\FieldRules;
use Vertex\Services\PricingService;

$services = ['*' => 'All services (default)'] + (array) config('workflow.services');
$priorities = (array) config('workflow.priorities');
$v = static fn (string $k, string $d = ''): string => old($k, $d);
?>
<div class="page-head"><div><p class="eyebrow">Administration</p><h1>Priority pricing</h1></div></div>
<p class="muted">Surcharges applied when a project's priority is raised or lowered after its quotation has been approved. These are rules only: a change never edits an approved quotation or invoice and never charges anything. Every change creates a new version; the client must approve the resulting price change. A service-specific rule overrides the default for that service. Until an administrator sets rates here, every priority carries no surcharge.</p>

<div class="table-wrap"><table class="table">
    <thead><tr><th>Service</th><?php foreach ($priorities as $label) : ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($services as $code => $label) : ?>
        <tr><td><strong><?= e($label) ?></strong></td>
            <?php foreach (array_keys($priorities) as $p) : $rule = $rules[$code][$p] ?? null; ?>
                <td><?php if ($rule === null) : ?><span class="muted">Uses default</span><?php else : ?><?= e(PricingService::describe($rule)) ?> <span class="muted">v<?= e($rule['version']) ?></span><?php endif; ?></td>
            <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody></table></div>

<?php if ($canManage) : ?>
<div class="panel"><h2>Change a rule</h2>
    <form method="post" action="/admin/pricing" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="ps">Service</label><select id="ps" name="service"><?php foreach ($services as $c => $l) : ?><option value="<?= e($c) ?>"<?= $v('service') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="pp">Priority</label><select id="pp" name="priority"><?php foreach ($priorities as $c => $l) : ?><option value="<?= e($c) ?>"<?= $v('priority') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="pt">Surcharge</label><select id="pt" name="type"><option value="none"<?= $v('type') === 'none' ? ' selected' : '' ?>>None</option><option value="percent"<?= $v('type') === 'percent' ? ' selected' : '' ?>>Percentage of the base price</option><option value="fixed"<?= $v('type') === 'fixed' ? ' selected' : '' ?>>Fixed amount</option></select></div>
        <div class="field"><label for="pperc">Percentage (if percentage)</label><input id="pperc" name="percent" inputmode="decimal" placeholder="e.g. 15 or 12.5" value="<?= e($v('percent')) ?>"></div>
        <div class="field"><label for="pfix">Fixed amount in ₹ (if fixed)</label><input id="pfix" name="fixed" inputmode="decimal" placeholder="e.g. 2500" value="<?= e($v('fixed')) ?>"></div>
        <div class="field"><label for="prs">Reason for the change</label><input id="prs" name="reason" required maxlength="255" value="<?= e($v('reason')) ?>"></div>
        <div class="field"><label for="ppw">Your password</label><input id="ppw" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save new version</button></div></form></div>
<?php endif; ?>

<div class="panel"><h2>Version history</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>Service</th><th>Priority</th><th>Rule</th><th>Version</th><th>By</th><th>Reason</th></tr></thead><tbody>
        <?php foreach ($history as $h) : ?>
            <tr><td><?= e(local_time($h['created_at'])) ?></td><td><?= e($services[$h['service_category']] ?? $h['service_category']) ?></td><td><?= e($priorities[$h['priority']] ?? $h['priority']) ?></td>
                <td><?= e(PricingService::describe($h)) ?></td><td>v<?= e($h['version']) ?><?= $h['current_flag'] ? ' <span class="badge badge-active">current</span>' : '' ?></td><td><?= e($h['author'] ?? 'System default') ?></td><td><?= e($h['reason'] ?? '') ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div></div>
