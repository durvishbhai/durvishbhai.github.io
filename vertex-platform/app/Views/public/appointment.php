<?php
/** @var list<array<string,mixed>> $slots */ /** @var list<array<string,mixed>> $places */ /** @var array<string,string> $prefill */ /** @var string $minDate */ /** @var string $maxDate */
$prefill = $prefill ?? [];
$f = static fn (string $k): string => old($k) !== '' ? old($k) : ($prefill[$k] ?? '');
$type = old('meeting_type');
$same = old('contact_name') === '' && old('whatsapp') === '' ? true : old('whatsapp_same') === '1';
$host = \Vertex\Services\AppointmentConfig::host();
?>
<section class="section"><div class="container narrow">
    <p class="eyebrow">Book an appointment</p><h1>Book an appointment with <?= e((string) config('appointments.host_name', 'Durvish Jawale')) ?></h1>
    <p class="lead"><?= e($host) ?>. Choose a time that suits you. <strong>This form sends a request: your appointment is not booked until our team contacts you and confirms it.</strong></p>
    <?php if ($slots === []) : ?>
        <div class="panel"><p>No appointment times are open at the moment. Please <a href="/contact">send us a message</a> and we will get back to you.</p></div>
    <?php else : ?>
    <div class="panel">
        <?= \Vertex\Core\View::partial('public-form-open', ['action' => '/book-appointment']) ?>
            <div class="grid-2">
                <div class="field"><label for="contact_name">Full name <span class="req">*</span></label><input id="contact_name" name="contact_name" required maxlength="120" autocomplete="name" value="<?= e($f('contact_name')) ?>"></div>
                <div class="field"><label for="email">Email address <span class="req">*</span></label><input id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e($f('email')) ?>"></div>
                <div class="field"><label for="phone">Phone number <span class="req">*</span></label><input id="phone" name="phone" type="tel" required maxlength="32" autocomplete="tel" value="<?= e($f('phone')) ?>"></div>
                <div class="field" data-whatsapp-field><label for="whatsapp">WhatsApp number <span class="req">*</span></label><input id="whatsapp" name="whatsapp" type="tel" maxlength="32" value="<?= e(old('whatsapp')) ?>"></div>
            </div>
            <div class="field"><label class="check"><input type="checkbox" name="whatsapp_same" value="1" data-whatsapp-same<?= $same ? ' checked' : '' ?>> My WhatsApp number is the same as my phone number</label></div>
            <div class="grid-2">
                <div class="field"><label for="company_name">Company / organization <span class="muted">(if applicable)</span></label><input id="company_name" name="company_name" maxlength="160" autocomplete="organization" value="<?= e($f('company_name')) ?>"></div>
                <div class="field"><label for="occupation">Your occupation <span class="req">*</span></label><input id="occupation" name="occupation" required maxlength="120" value="<?= e(old('occupation')) ?>"></div>
            </div>
            <div class="field"><label for="description">Purpose of the meeting <span class="req">*</span></label><textarea id="description" name="description" rows="4" required minlength="5" maxlength="1000"><?= e(old('description')) ?></textarea></div>
            <?php
            $w0 = $slots[0];
            $clock = static fn (int $m): string => \Vertex\Services\AppointmentConfig::clock(\Vertex\Services\AppointmentConfig::hhmm($m));
            $ws = \Vertex\Services\AppointmentConfig::mins((string) $w0['start_time']);
            $we = \Vertex\Services\AppointmentConfig::mins((string) $w0['end_time']);
            $step = max(1, (int) $w0['step_minutes']);
            ?>
            <div data-appt>
                <div class="grid-2">
                    <div class="field"><label for="meeting_date">Date for the meeting <span class="req">*</span></label><input id="meeting_date" name="meeting_date" type="date" required min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>" value="<?= e(old('meeting_date')) ?>"></div>
                    <div class="field"><label for="slot">Available timings <span class="req">*</span></label><select id="slot" name="slot" required><?php foreach ($slots as $s) : ?><option value="<?= e((string) $s['id']) ?>"<?= old('slot') === (string) $s['id'] ? ' selected' : '' ?>><?= e((string) $s['text']) ?><?= (int) $s['weekdays'] !== 127 ? ' · ' . e((string) $s['days']) : '' ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="grid-2">
                    <div class="field"><label for="start_time">From <span class="req">*</span></label><select id="start_time" name="start_time" required><option value="">Select start time</option><?php for ($t = $ws; $t + $step <= $we; $t += $step) : $v = \Vertex\Services\AppointmentConfig::hhmm($t); ?><option value="<?= e($v) ?>"<?= old('start_time') === $v ? ' selected' : '' ?>><?= e($clock($t)) ?></option><?php endfor; ?></select></div>
                    <div class="field"><label for="end_time">To <span class="req">*</span></label><select id="end_time" name="end_time" required><option value="">Select end time</option><?php for ($t = $ws + $step; $t <= $we; $t += $step) : $v = \Vertex\Services\AppointmentConfig::hhmm($t); ?><option value="<?= e($v) ?>"<?= old('end_time') === $v ? ' selected' : '' ?>><?= e($clock($t)) ?></option><?php endfor; ?></select></div>
                </div>
                <p class="hint" data-appt-info>Choose a date, then any start and end time inside the available timing. A time someone else has already booked on that date is not offered.</p>
            </div>
            <fieldset class="field"><legend>Meeting type <span class="req">*</span></legend>
                <div class="check-grid"><label class="check"><input type="radio" name="meeting_type" value="online" required data-meeting-type<?= $type === 'online' ? ' checked' : '' ?>> Online</label><label class="check"><input type="radio" name="meeting_type" value="in_person" required data-meeting-type<?= $type === 'in_person' ? ' checked' : '' ?>> In-person</label></div></fieldset>
            <div class="field" data-place-field><label for="place">Place for the in-person meeting <span class="req">*</span></label>
                <select id="place" name="place"><option value="">Select a place</option>
                    <?php foreach ($places as $pl) : ?><option value="<?= e((string) $pl['id']) ?>"<?= old('place') === (string) $pl['id'] ? ' selected' : '' ?>><?= e((string) $pl['name']) ?></option><?php endforeach; ?>
                    <option value="<?= e(\Vertex\Services\AppointmentConfig::LATER) ?>"<?= old('place') === \Vertex\Services\AppointmentConfig::LATER ? ' selected' : '' ?>><?= e(\Vertex\Services\AppointmentConfig::LATER_LABEL) ?></option></select></div>
            <?= \Vertex\Core\View::partial('public-form-consent') ?>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Submit appointment request</button></div>
        </form>
    </div>
    <?php endif; ?>
</div></section>
