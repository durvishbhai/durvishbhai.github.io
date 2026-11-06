<?php

declare(strict_types=1);

use Vertex\Core\Env;

/** Appointments with the founder. Times and places are edited by the team in Administration → Appointments; these are the fixed rules around them. */
return [
    'host_name' => (string) Env::get('APPOINTMENT_HOST_NAME', 'Durvish Jawale'),
    'host_title' => (string) Env::get('APPOINTMENT_HOST_TITLE', 'Founder & CEO'),
    'min_days_ahead' => 1,    // the earliest date a visitor can ask for: tomorrow
    'max_days_ahead' => 60,   // and the latest
];
