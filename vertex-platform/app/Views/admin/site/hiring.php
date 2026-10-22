<?php /** @var string $state */ /** @var array<string,string> $states */ /** @var list<array{title:string,summary:string,family:string}> $roles */ /** @var bool $canPublish */ /** @var ?int $newApplications */
$labels = ['open' => 'Open', 'closed' => 'Hiring closed', 'hidden' => 'Hidden']; ?>
<div class="page-head"><div><p class="eyebrow">Website</p><h1>Hiring</h1></div><?php if ($state !== 'hidden') : ?><a class="btn btn-ghost" href="/hiring" target="_blank" rel="noopener">View the page</a><?php endif; ?></div>
<?= \Vertex\Core\View::partial('cms-nav') ?>
<div class="panel"><h2>Hiring page status <span class="badge badge-<?= $state === 'open' ? 'active' : 'warn' ?>"><?= e($labels[$state]) ?></span></h2>
    <p class="muted">This switch controls the public Hiring page at <code>/hiring</code> straight away. Applications that were already received are kept and stay visible under Job applications.</p>
    <form method="post" action="/admin/site/hiring/state" class="form-grid"><?= csrf_field() ?>
        <fieldset class="field"><legend>Who sees what</legend>
            <?php foreach ($states as $k => $text) : ?><label class="check"><input type="radio" name="state" value="<?= e($k) ?>"<?= $state === $k ? ' checked' : '' ?><?= $canPublish ? '' : ' disabled' ?>><span><?= e($text) ?></span></label><?php endforeach; ?></fieldset>
        <?php if ($canPublish) : ?><div class="form-actions"><button class="btn btn-primary" type="submit">Save status</button></div><?php else : ?><p class="muted">You can view this, but changing it needs publish rights.</p><?php endif; ?></form></div>
<div class="panel"><h2>What visitors see</h2><ul class="plain-list">
    <li><strong>Open</strong>: the roles below, the salary note, how it works and the application form. A small "We're hiring" bar appears at the top of the other pages.</li>
    <li><strong>Hiring closed</strong>: the page stays, shows your "hiring closed" message and has no form. The top bar disappears.</li>
    <li><strong>Hidden</strong>: the page returns "not found" and is removed from the menu, footer and sitemap.</li></ul>
    <p class="muted">Every field of the application form is required (name, email, phone, role, experience, availability, skills, portfolio link, LinkedIn link, résumé, introduction and consent). Their names and the two drop-down lists are editable; the requirement is fixed.</p>
    <p class="muted">If you saved your own header menu under Menus, add or remove the Hiring link there too.</p></div>
<div class="panel"><h2>Roles shown on the page</h2>
    <?php if ($roles === []) : ?><p class="muted">No roles are listed, so the form cannot be used. Add roles in the wording editor.</p><?php else : ?><ul class="plain-list"><?php foreach ($roles as $r) : ?><li><strong><?= e($r['title']) ?></strong><?= $r['summary'] !== '' ? ' - ' . e($r['summary']) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
    <p><a class="btn btn-ghost btn-sm" href="/admin/site/sections/hiring">Edit roles, salary note, steps, the application form's field names and choices</a>
    <?php if ($newApplications !== null) : ?> <a class="btn btn-ghost btn-sm" href="/admin/careers">Job applications<?= $newApplications > 0 ? ' (' . (int) $newApplications . ' new)' : '' ?></a><?php endif; ?></p></div>
