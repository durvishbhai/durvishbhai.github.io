<?php /** @var string $tab */ /** @var int $limit */ /** @var bool $zip */ /** @var list<string> $safety */
$limitText = $limit > 0 ? round($limit / 1048576) . ' MB' : 'not limited';
?>
<div class="page-head"><div><p class="eyebrow">Administration</p><h1>Backup &amp; restore</h1></div></div>
<nav class="tabs" aria-label="Backup sections">
    <a href="/admin/backup?tab=database"<?= $tab === 'database' ? ' class="is-active" aria-current="page"' : '' ?>>Database (.sql)</a>
    <a href="/admin/backup?tab=files"<?= $tab === 'files' ? ' class="is-active" aria-current="page"' : '' ?>>Files (.zip)</a>
</nav>

<?php if ($tab === 'database') : ?>
<div class="panel"><h2>Back up the database (.sql)</h2>
    <p>One file with the structure and <strong>all data</strong> of the platform: users, clients, projects, <strong>invoices, quotations, receipts, agreements</strong>, payments, website content, settings and the audit log. (Invoices, quotations and receipts are produced from this data, so they are all inside this file.)</p>
    <ul class="plain-list">
        <li>Secrets such as the Razorpay key secret are stored encrypted with your <code>APP_KEY</code> (in <code>vertex/.env</code>). Keep a copy of that key somewhere safe: without it those saved secrets cannot be opened after a restore.</li>
        <li>The file contains personal and financial information. Store it somewhere private, never email it, and delete old copies.</li>
        <li>Uploaded files (deliverables, payment proofs, website pictures) are not in the .sql file: use the <a href="/admin/backup?tab=files">Files (.zip)</a> tab for those.</li>
    </ul>
    <form method="post" action="/admin/backup/sql" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="bp">Your password</label><input id="bp" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Download database backup (.sql)</button></div></form>
</div>

<div class="panel panel-warn"><h2>Restore the database from a .sql backup</h2>
    <p><strong>This replaces ALL current data</strong> (clients, projects, invoices, payments, users, settings) with what is in the backup file. Anything created after the backup was made is lost. You will be signed out when it finishes.</p>
    <ul class="plain-list">
        <li>Only a .sql file downloaded from this page (or made by the system's own backup) is accepted. It is checked line by line before anything is touched, and any file that contains something a backup never contains is refused.</li>
        <li><strong>A safety copy of your current data is saved first</strong> (in <code>vertex/storage/backups</code>). If the restore fails half way, the previous data is put back automatically.</li>
        <li>If the backup is older than the program, the missing database updates are applied automatically afterwards.</li>
        <li>This server accepts uploads up to <strong><?= e($limitText) ?></strong>. For a bigger file, use phpMyAdmin → Import.</li>
        <li>Restore the matching Files (.zip) backup too if the files were lost or changed.</li>
    </ul>
    <form method="post" action="/admin/backup/restore-sql" enctype="multipart/form-data" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="rs-f">Backup file (.sql)</label><input id="rs-f" name="backup_file" type="file" accept=".sql" required></div>
        <div class="field"><label for="rs-t">Type the word RESTORE to confirm</label><input id="rs-t" name="confirm_text" required autocomplete="off" placeholder="RESTORE"></div>
        <div class="field"><label for="rs-p">Your password</label><input id="rs-p" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-danger" type="submit" data-confirm="Replace ALL current data with this backup? A safety copy of the current data is kept first.">Restore database</button></div></form>
    <?php if ($safety !== []) : ?><p class="muted small">Safety copies kept on the server (newest first): <?= e(implode(', ', $safety)) ?>. They are in <code>vertex/storage/backups</code>; download them with the File Manager if you want a copy off the server.</p><?php endif; ?>
</div>

<?php else : ?>
<div class="panel"><h2>Back up the files (.zip)</h2>
    <p>Every file that people uploaded to the platform: <strong>project deliverables, payment proofs, enquiry attachments</strong> and the <strong>website pictures</strong>, in one .zip with a checksum for each file.</p>
    <ul class="plain-list">
        <li>Invoices, quotations, receipts and agreements are not stored as files: they are rebuilt from the database whenever someone opens or downloads them. They are inside the <a href="/admin/backup?tab=database">database (.sql)</a> backup.</li>
        <li>Take both backups together: the database knows which files exist, the .zip holds the files.</li>
        <li>Large sites can take a while to zip. Keep the page open until the download starts.</li>
    </ul>
    <?php if (!$zip) : ?><p class="alert alert-error">The PHP "zip" extension is not enabled on this server (hPanel → PHP Configuration → Extensions). Enable it to use file backups.</p><?php endif; ?>
    <form method="post" action="/admin/backup/files" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="bf-p">Your password</label><input id="bf-p" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"<?= $zip ? '' : ' disabled' ?>>Download files backup (.zip)</button></div></form>
</div>

<div class="panel panel-warn"><h2>Restore the files from a .zip backup</h2>
    <p>Puts every file in the backup back where it belongs. A file that is already there and identical is left as it is; a file that is missing or different is replaced by the backup's copy. <strong>Files that are not in the backup are not deleted.</strong></p>
    <ul class="plain-list">
        <li>Only a .zip downloaded from this page is accepted. Each file is checked against its checksum before it replaces anything, website pictures must really be images, and nothing outside the upload folders can be written.</li>
        <li>This server accepts uploads up to <strong><?= e($limitText) ?></strong>. For a bigger archive, ask your host to raise the limit, or extract it with the File Manager into <code>vertex/storage/uploads</code> and <code>public_html/uploads/cms</code>.</li>
    </ul>
    <form method="post" action="/admin/backup/restore-files" enctype="multipart/form-data" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="rf-f">Backup file (.zip)</label><input id="rf-f" name="backup_file" type="file" accept=".zip" required></div>
        <div class="field"><label for="rf-t">Type the word RESTORE to confirm</label><input id="rf-t" name="confirm_text" required autocomplete="off" placeholder="RESTORE"></div>
        <div class="field"><label for="rf-p">Your password</label><input id="rf-p" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-danger" type="submit"<?= $zip ? '' : ' disabled' ?> data-confirm="Restore the files from this backup?">Restore files</button></div></form>
</div>
<?php endif; ?>
<p class="muted small">Only the Owner can do this. Every backup, download and restore is recorded in the audit log.</p>
