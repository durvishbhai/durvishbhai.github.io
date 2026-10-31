<?php

declare(strict_types=1);

use Vertex\Core\Env;

/**
 * Project delivery appointments. Every value here is a default; the ones marked (settings) can be changed by the Owner in Integrations & settings
 * → Delivery appointments, and the daily capacity also on the Delivery appointments → Capacity page.
 */
return [
    'default_capacity' => (int) Env::get('DELIVERY_DEFAULT_CAPACITY', '2'),   // (settings) confirmed delivery appointments per day
    'notify_email' => (string) Env::get('DELIVERY_NOTIFY_EMAIL', ''),         // (settings) the team's notification address; empty = the sender address of the mail settings
    'public_mode' => (string) Env::get('DELIVERY_PUBLIC_MODE', 'otp'),         // (settings) off | otp | token | both
    'min_notice_days' => 1,          // (settings) the earliest date is never earlier than today + this
    'max_days_ahead' => 180,         // (settings) how far ahead a client can choose the project START date (delivery dates go up to 730 days ahead unless the project has a latest date)
    'client_cutoff_days' => 3,       // (settings) clients may change or cancel themselves until this many days before the date; later it is a request to the team
    'max_reschedules' => 2,          // (settings) how many times a client can move the appointment themselves
    'token_days' => 30,              // how long a direct booking link stays valid
    'otp_minutes' => 10,
    'otp_attempts' => 5,
    'grant_minutes' => 30,           // how long a verified public visitor can book before verifying again
];
