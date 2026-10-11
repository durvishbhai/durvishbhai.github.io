<?php

declare(strict_types=1);

use Vertex\Core\Env;

/*
 * Support Centre and warranty settings.
 *  - warranty_default_months is the INTENDED default from the business ("six months"); every project's own period is recorded by an
 *    administrator and may differ when the contract says so.
 *  - sla is EMPTY: no response/resolution target is promised or tracked until a separately approved SLA is configured. When filled
 *    in, targets are INTERNAL (dashboards only) and are never shown to clients.
 *      'sla' => ['first_response_hours' => 4]   // tickets with no staff reply that long are counted as "past target" on the dashboard
 */
return [
    'warranty_default_months' => max(1, (int) Env::get('WARRANTY_DEFAULT_MONTHS', 6)),
    'warranty_end_day_before' => (bool) Env::get('WARRANTY_END_DAY_BEFORE', false),
    'warranty_expiring_days' => max(1, (int) Env::get('WARRANTY_EXPIRING_DAYS', 30)),
    'client_reopen_days' => max(0, (int) Env::get('TICKET_CLIENT_REOPEN_DAYS', 30)),
    'sla' => [],
];
