<?php $values = []; foreach (['client_type', 'contact_name', 'email', 'phone', 'company_name', 'website', 'tax_id', 'address'] as $f) { $values[$f] = old($f); } ?>
<p class="eyebrow">CRM</p><h1>New client</h1>
<p class="muted">Tip: if this person came from an enquiry, convert the Won enquiry instead so the history stays linked. If a client with the same email or phone exists you will be asked to confirm before a second record is created.</p>
<div class="panel"><form method="post" action="/clients" class="form-grid" novalidate><?= csrf_field() ?>
    <?= \Vertex\Core\View::partial('client-fields', ['values' => $values]) ?>
    <label class="check"><input type="checkbox" name="confirm_new" value="1"> This is a different client from any existing record with the same email or phone</label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Create client</button> <a class="btn btn-ghost" href="/clients">Cancel</a></div></form></div>
