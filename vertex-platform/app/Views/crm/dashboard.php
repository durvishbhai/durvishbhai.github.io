<?php
/** @var array<string,mixed> $crm */
$data = $crm; // NB: a view variable literally named $data would be shadowed by the renderer
$stages = $data['stages'];
$list = static function (array $rows, string $empty): void { ?>
    <?php if ($rows === []) : ?><p class="muted"><?= e($empty) ?></p><?php else : ?>
        <ul class="plain-list">
            <?php foreach ($rows as $r) : ?>
                <li><a href="/crm/enquiries/<?= e($r['public_id']) ?>"><strong><?= e($r['reference']) ?></strong></a> <?= e($r['company_name'] ?: $r['contact_name']) ?>
                    <?php if (!empty($r['next_followup_date'])) : ?><span class="muted"> &middot; due <?= e($r['next_followup_date']) ?><?= !empty($r['followup_note']) ? ': ' . e($r['followup_note']) : '' ?></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php }; ?>
<div class="page-head">
    <div><p class="eyebrow">CRM</p><h1>Enquiry pipeline</h1></div>
    <div class="action-row">
        <?php if (can('enquiries.create')) : ?><a class="btn btn-primary" href="/crm/enquiries/create">New enquiry</a><?php endif; ?>
        <a class="btn btn-ghost" href="/crm/enquiries">All enquiries</a>
        <?php if (can('crm.configure')) : ?><a class="btn btn-ghost" href="/admin/crm-stages">Configure stages</a><?php endif; ?>
    </div>
</div>
<p class="muted">Counts cover the enquiries you are allowed to see (<?= e($data['total']) ?> open and closed, not archived). Follow-up reminders appear here only; no email or message reminders are sent.</p>

<div class="stat-grid">
    <?php foreach ($stages as $s) : ?>
        <a class="stat" href="/crm/enquiries?stage=<?= e($s['code']) ?>"><span class="stat-n"><?= e($s['n']) ?></span><span class="stat-l"><?= e($s['name']) ?></span></a>
    <?php endforeach; ?>
</div>

<div class="grid-2">
    <div class="panel"><h2>Overdue follow-ups <span class="badge badge-deactivated"><?= e(count($data['overdue'])) ?></span></h2><?php $list($data['overdue'], 'Nothing overdue.'); ?></div>
    <div class="panel"><h2>Due today <span class="badge"><?= e(count($data['today'])) ?></span></h2><?php $list($data['today'], 'Nothing due today.'); ?></div>
    <div class="panel"><h2>Next 7 days <span class="badge"><?= e(count($data['upcoming'])) ?></span></h2><?php $list($data['upcoming'], 'No upcoming follow-ups.'); ?></div>
    <?php if (can('enquiries.view_all')) : ?>
        <div class="panel"><h2>Unassigned open enquiries <span class="badge badge-pending"><?= e(count($data['unassigned'])) ?></span></h2><?php $list($data['unassigned'], 'Every open enquiry has an owner.'); ?></div>
    <?php endif; ?>
    <div class="panel"><h2>Recent enquiries</h2><?php $list($data['recent'], 'No enquiries yet.'); ?></div>
</div>
