<?php /** @var array<string,array<string,mixed>> $policies */ /** @var bool $canManage */ ?>
<p class="eyebrow">Administration</p><h1>Support SLA</h1>
<p class="muted">How fast each ticket priority must get a first reply and be resolved (calendar hours from the moment the ticket is submitted). A missed target is flagged on the ticket and the people who manage tickets are told; after the escalation delay the ticket is escalated automatically. Changing this affects tickets submitted from now on.</p>
<form method="post" action="/admin/sla" class="panel" novalidate><?= csrf_field() ?>
<div class="table-wrap"><table class="table"><thead><tr><th>Priority</th><th>First reply within (hours)</th><th>Resolve within (hours)</th><th>Escalate after a miss (hours)</th></tr></thead><tbody>
<?php foreach ($policies as $p => $r) : ?><tr><td><strong><?= e(ucfirst($p)) ?></strong></td>
    <td><input name="p[<?= e($p) ?>][first]" inputmode="numeric" required value="<?= e((string) $r['first_response_hours']) ?>"<?= $canManage ? '' : ' disabled' ?> aria-label="First reply hours for <?= e($p) ?>"></td>
    <td><input name="p[<?= e($p) ?>][resolution]" inputmode="numeric" required value="<?= e((string) $r['resolution_hours']) ?>"<?= $canManage ? '' : ' disabled' ?> aria-label="Resolution hours for <?= e($p) ?>"></td>
    <td><input name="p[<?= e($p) ?>][escalate]" inputmode="numeric" required value="<?= e((string) $r['escalate_after_hours']) ?>"<?= $canManage ? '' : ' disabled' ?> aria-label="Escalation hours for <?= e($p) ?>"></td></tr><?php endforeach; ?></tbody></table></div>
<?php if ($canManage) : ?><div class="form-grid"><div class="field"><label for="pw">Your password</label><input id="pw" name="confirm_password" type="password" required autocomplete="current-password"></div><div class="form-actions"><button class="btn btn-primary" type="submit">Save SLA</button></div></div><?php endif; ?></form>
