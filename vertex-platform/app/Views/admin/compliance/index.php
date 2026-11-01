<?php /** @var list<array<string,mixed>> $docs */ /** @var array<string,string> $missing */ /** @var array<string,string> $groups */
$badge = static fn (string $c, string $t): string => '<span class="badge badge-d-' . $c . '">' . e($t) . '</span>';
?>
<div class="page-head"><div><p class="eyebrow">Administration</p><h1>Compliance &amp; legal management</h1></div></div>
<p class="muted">Owner only. Everything here changes the real public website. Documents are versioned: you work on a draft, preview it, then publish (now or at a set time). Earlier versions are kept and can be restored. These texts are drafts prepared for your business and <strong>must be reviewed by an Indian lawyer</strong> before you rely on them.</p>

<?php if ($missing !== []) : ?>
<div class="panel panel-warn"><h2>Facts only you can supply</h2>
    <p>These are empty. The legal documents cannot be published until they are filled in, and nothing has been invented in their place:</p>
    <ul><?php foreach ($missing as $k => $l) : ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
    <p><a class="btn btn-primary btn-sm" href="/admin/compliance/settings/business">Business identity</a> <a class="btn btn-ghost btn-sm" href="/admin/compliance/settings/contact">Legal contact</a></p></div>
<?php endif; ?>

<div class="panel"><h2>Legal documents</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Document</th><th>Live version</th><th>Draft</th><th>Public page</th></tr></thead><tbody>
    <?php foreach ($docs as $d) : $live = $d['live']; $draft = $d['draft']; ?>
        <tr><td><a href="/admin/compliance/documents/<?= e($d['doc_key']) ?>"><strong><?= e($d['title']) ?></strong></a><br><span class="muted small"><?= $d['requires_acceptance'] ? 'Clients accept updates' : '' ?><?= !$d['unpublishable'] ? ' · mandatory' : '' ?></span></td>
            <td><?php if ($live) : ?>v<?= e($live['version_no']) ?> · effective <?= e(local_date((string) ($live['effective_date'] ?: substr((string) $live['published_at'], 0, 10)))) ?><br><span class="muted small">published <?= e(local_time($live['published_at'])) ?></span><?php else : ?><?= $badge('request', 'Not published') ?><?php endif; ?></td>
            <td><?php if ($draft) : ?><?= $badge($draft['status'] === 'scheduled' ? 'confirmed' : 'request', ucfirst((string) $draft['status'])) ?> v<?= e($draft['version_no']) ?><?= $draft['status'] === 'scheduled' ? '<br><span class="muted small">' . e(local_time($draft['publish_at'])) . '</span>' : '' ?><?php else : ?>—<?php endif; ?></td>
            <td><a href="/<?= e($d['doc_key']) ?>">/<?= e($d['doc_key']) ?></a></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>

<div class="panel"><h2>Policy acceptance</h2><p class="muted">Material updates need clients' acceptance. <a href="/admin/compliance/acceptance">See who has accepted, send reminders or move the deadline</a>.</p></div>

<div class="panel"><h2>Settings</h2>
    <ul class="plain-list"><?php foreach ($groups as $g => $l) : ?><li><a href="/admin/compliance/settings/<?= e($g) ?>"><?= e($l) ?></a></li><?php endforeach; ?></ul>
    <p class="muted small">The public compliance pages (<a href="/legal-business-information">legal business information</a>, <a href="/contact-and-grievance">contact &amp; grievance</a>) are built from these settings.</p></div>
