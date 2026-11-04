<?php /** @var callable $s */ /** @var string $disclosure */ /** @var string $email */ /** @var string $whatsapp */ /** @var string $address */
$row = static function (string $label, string $value): void { if ($value !== '') { echo '<tr><th scope="row">' . e($label) . '</th><td>' . nl2br(e($value)) . '</td></tr>'; } };
?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Legal</p><h1>Legal Business Information</h1></div></section>
<section class="pub-section"><div class="container narrow"><div class="prose">
    <p><strong><?= e($disclosure) ?></strong></p>
    <p>The legal proprietor and the operational representative are different people: the proprietor owns the enterprise; the representative manages and operates <?= e($s('brand_name')) ?> day to day on the proprietor's authority.</p>
    <div class="table-wrap"><table class="table"><tbody>
        <?php $row('Brand', $s('brand_name') . ($s('abbreviation') !== '' ? ' (' . $s('abbreviation') . ')' : '')); ?>
        <?php $row('Legal enterprise', $s('legal_enterprise_name')); ?>
        <?php $row('Legal structure', $s('legal_structure')); ?>
        <?php $row('Business units', str_replace(';', ',', $s('business_units'))); ?>
        <?php $row('Legal proprietor', \Vertex\Services\ComplianceSettings::publicValue('proprietor_name', 'proprietor')); ?>
        <?php $op = \Vertex\Services\ComplianceSettings::publicValue('operator_name', 'operator'); $row('Operational representative', trim($op . ($op !== '' && $s('operator_role') !== '' ? ', ' . $s('operator_role') : ''))); ?>
        <?php $urn = \Vertex\Services\ComplianceSettings::publicValue('udyam_number', 'udyam'); $row('Udyam', $s('udyam_note') . ($urn !== '' ? ' Registration number: ' . $urn . '.' : '')); ?>
        <?php $sa = \Vertex\Services\ComplianceSettings::publicValue('shop_act_number', 'shop_act'); $row('Shop Act', $s('shop_act_note') . ($sa !== '' ? ' Registration number: ' . $sa . '.' : '')); ?>
        <?php $row('GST', $s('gst_status') . ($s('gstin') !== '' ? ': ' . $s('gstin') : '')); ?>
        <?php $row('Address', $address); ?>
        <?php $row('Business email', $email); ?>
        <?php $row('WhatsApp', $whatsapp); ?>
    </tbody></table></div>
    <p class="muted small">Details that are not listed have not been published by the business. For anything else, use <a href="/contact-and-grievance">Contact &amp; grievance information</a>.</p>
</div></div></section>
