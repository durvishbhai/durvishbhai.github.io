<?php /** @var list<array<string,mixed>> $rules */ /** @var ?array<string,mixed> $rule */ /** @var array<string,array<string,string>> $triggers */ /** @var array<string,array<string,mixed>> $roles */ /** @var list<string> $permissions */
use Vertex\Services\AutomationService;
$v = static fn (string $k, mixed $d = ''): string => old($k, (string) ($d ?? ''));
$targetOptions = static function (string $selected) use ($roles, $permissions): string {
    $o = '<option value="owner"' . ($selected === 'owner' ? ' selected' : '') . '>The person responsible for the item</option><option value="client"' . ($selected === 'client' ? ' selected' : '') . '>The client</option><optgroup label="Everyone with a role">';
    foreach ($roles as $slug => $d) { $o .= '<option value="role:' . e($slug) . '"' . ($selected === 'role:' . $slug ? ' selected' : '') . '>' . e($d['name']) . '</option>'; }
    $o .= '</optgroup><optgroup label="Everyone who can...">';
    foreach ($permissions as $p) { $o .= '<option value="permission:' . e($p) . '"' . ($selected === 'permission:' . $p ? ' selected' : '') . '>' . e($p) . '</option>'; }

    return $o . '</optgroup>';
}; ?>
<p class="eyebrow">Administration</p><h1>Workflow rules</h1>
<p class="muted">A rule watches for a situation and notifies people, and can escalate to others if nothing changes. Rules only notify: they never approve, pay or change anything. They are checked each time the scheduled task runs (<code>php bin/console.php schedule:run</code>).</p>
<div class="table-wrap"><table class="table"><thead><tr><th>Rule</th><th>When</th><th>Tell</th><th>Escalate</th><th>Last run</th></tr></thead><tbody>
<?php foreach ($rules as $r) : ?><tr><td><a href="/admin/automation/<?= e($r['public_id']) ?>"><strong><?= e($r['name']) ?></strong></a><?= (int) $r['enabled'] === 0 ? ' <span class="badge">off</span>' : '' ?></td>
    <td><?= e($triggers[$r['trigger_type']]['label'] ?? $r['trigger_type']) ?>: <?= (int) $r['threshold'] ?> <?= e($triggers[$r['trigger_type']]['unit'] ?? '') ?></td><td><?= e(AutomationService::targetLabel((string) $r['notify_target'])) ?></td>
    <td><?= $r['escalate_to'] ? e(AutomationService::targetLabel((string) $r['escalate_to'])) . ' after ' . (int) $r['escalate_after'] . ' more' : '-' ?></td><td><?= $r['last_run_at'] ? e(local_time($r['last_run_at'])) . ' (' . (int) $r['last_count'] . ' match' . ((int) $r['last_count'] === 1 ? '' : 'es') . ')' : 'never' ?></td></tr><?php endforeach; ?>
<?php if ($rules === []) : ?><tr><td colspan="5" class="muted">No rules yet.</td></tr><?php endif; ?></tbody></table></div>
<form method="post" action="/admin/automation<?= $rule ? '/' . e($rule['public_id']) : '' ?>" class="panel form-grid" novalidate><?= csrf_field() ?><h2><?= $rule ? 'Edit rule' : 'New rule' ?></h2>
    <div class="field"><label for="n">Name</label><input id="n" name="name" required maxlength="160" value="<?= e($v('name', $rule['name'] ?? '')) ?>"></div>
    <div class="field"><label for="tr">When</label><select id="tr" name="trigger_type"><?php foreach ($triggers as $k => $t) : ?><option value="<?= e($k) ?>"<?= $v('trigger_type', $rule['trigger_type'] ?? 'task_overdue') === $k ? ' selected' : '' ?>><?= e($t['label']) ?> (<?= e($t['unit']) ?>)</option><?php endforeach; ?></select></div>
    <div class="field"><label for="th">How long (number, in the unit shown above)</label><input id="th" name="threshold" required inputmode="numeric" value="<?= e($v('threshold', (string) ($rule['threshold'] ?? '1'))) ?>"></div>
    <div class="field"><label for="nt">Tell</label><select id="nt" name="notify_target"><?= $targetOptions($v('notify_target', $rule['notify_target'] ?? 'owner')) ?></select></div>
    <div class="field"><label for="et">If still true, also escalate to <span class="muted">(optional)</span></label><select id="et" name="escalate_to"><option value="">Nobody</option><?= $targetOptions($v('escalate_to', $rule['escalate_to'] ?? '')) ?></select></div>
    <div class="field"><label for="ea">…after this much longer</label><input id="ea" name="escalate_after" inputmode="numeric" value="<?= e($v('escalate_after', (string) ($rule['escalate_after'] ?? ''))) ?>"></div>
    <div class="field"><label class="check"><input type="checkbox" name="enabled" value="1"<?= !$rule || (int) $rule['enabled'] === 1 ? ' checked' : '' ?>> Rule is on</label></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $rule ? 'Save rule' : 'Create rule' ?></button></div></form>
<?php if ($rule) : ?><form method="post" action="/admin/automation/<?= e($rule['public_id']) ?>" class="inline-form" data-confirm="Delete this rule?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input name="confirm_password" type="password" placeholder="Your password" required autocomplete="current-password" aria-label="Your password"><button class="btn btn-danger btn-sm" type="submit">Delete rule</button></form><?php endif; ?>
