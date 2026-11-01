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
        <?php $row('Legal proprietor', $s('proprietor_name')); ?>
        <?php $row('Operational representative', trim($s('operator_name') . ($s('operator_name') !== '' && $s('operator_role') !== '' ? ', ' . $s('operator_role') : ''))); ?>
        <?php $row('Udyam', $s('udyam_note') . ($s('udyam_number') !== '' ? ' Registration number: ' . $s('udyam_number') . '.' : '')); ?>
        <?php $row('Shop Act', $s('shop_act_note') . ($s('shop_act_number') !== '' ? ' Registration number: ' . $s('shop_act_number') . '.' : '')); ?>
        <?php $row('GST', $s('gst_status') . ($s('gstin') !== '' ? ': ' . $s('gstin') : '')); ?>
        <?php $row('Address', $address); ?>
        <?php $row('Business email', $email); ?>
        <?php $row('WhatsApp', $whatsapp); ?>
    </tbody></table></div>
    <p class="muted small">Details that are not listed have not been published by the business. For anything else, use <a href="/contact-and-grievance">Contact &amp; grievance information</a>.</p>
</div></div></section>
