<div class="page-head"><div><p class="eyebrow">Administration</p><h1>Backup</h1></div></div>
<div class="panel"><h2>Download the database (.sql)</h2>
    <p>One file with the structure and <strong>all data</strong> of the platform: users, clients, projects, invoices, payments, website content, settings and the audit log. It is a standard SQL file: to restore it, open <strong>phpMyAdmin → your database → Import</strong> and choose the file (this replaces the tables it contains).</p>
    <ul class="plain-list">
        <li>Pictures you uploaded to the website and project files are <strong>not</strong> inside the .sql file; download them from the File Manager (<code>public_html/uploads</code> and <code>vertex/storage/uploads</code>) if you need them too.</li>
        <li>Secrets such as the Razorpay key secret are stored encrypted with your <code>APP_KEY</code>. Keep a copy of the key somewhere safe: without it those saved secrets cannot be opened after a restore.</li>
        <li>The file contains personal and financial information. Store it somewhere private, never email it, and delete old copies.</li>
    </ul>
    <form method="post" action="/admin/backup/sql" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="bp">Your password</label><input id="bp" name="confirm_password" type="password" required autocomplete="current-password"><p class="hint">Needed because the file contains everything.</p></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Download backup (.sql)</button></div></form>
    <p class="muted small">Every download is recorded in the audit log. Only the Owner can do this.</p></div>
