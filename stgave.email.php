<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: stgave.email.php
 * =======================================================================================
 *   stgave_sync_email()  Synchroniseert het e-mailadres van de gave-contactpersoon
 *                        naar locatietype Gave (26) op de deelnemer.
 * =======================================================================================
 */

/**
 * =======================================================================================
 * COLOFON: stgave_sync_email
 * =======================================================================================
 * @description     Haalt het primaire e-mailadres op van de gave-contactpersoon en
 *                  zet dit als 'Gave' e-mail op de deelnemer (create of update).
 *                  Delegeert het schrijven aan email_civicrm_update() als die beschikbaar
 *                  is; anders directe APIv4 aanroep.
 *
 * @param int       $contact_id         Het contact ID van de deelnemer.
 * @param int|null  $gavecontact_id     Het contact ID van de gave-contactpersoon.
 * @param string    $privacy_voorkeuren Privacy-voorkeur van de deelnemer.
 * @return array                        Statusarray met actie en resultaat.
 * =======================================================================================
 */
function stgave_sync_email(int $contact_id, ?int $gavecontact_id, ?string $privacy_voorkeuren = NULL): array {

    $extdebug = 'stgave.email'; // Kanaal voor centrale debug-config
    $apidebug = FALSE;
    $extwrite = 1;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 3.0 SYNC EMAIL GAVECONTACT → DEELNEMER",    "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    if (empty($gavecontact_id)) {
        wachthond($extdebug, 1, "SKIP: Geen gavecontact_id opgegeven",              "[CID: $contact_id]");
        return ['actie' => 'skip', 'reden' => 'geen gavecontact_id'];
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 3.1 OPHALEN EMAIL VAN GAVECONTACT",         "[GC: $gavecontact_id]");
    wachthond($extdebug, 2, "########################################################################");

    // Haal het primaire e-mailadres op van de gave-contactpersoon (Home)
    $params_gave_email_bron = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['email'],
        'where'             => [
            ['contact_id',      '=', $gavecontact_id],
            ['location_type_id','=', 1],              // Home
        ],
        'limit'             => 1,
    ];
    wachthond($extdebug, 7, 'params_gave_email_bron',    $params_gave_email_bron);
    $result_gave_email_bron = civicrm_api4('Email', 'get', $params_gave_email_bron);
    wachthond($extdebug, 9, 'result_gave_email_bron',    $result_gave_email_bron);

    $new_email_gave = $result_gave_email_bron->first()['email'] ?? NULL;
    wachthond($extdebug, 3, 'new_email_gave',             $new_email_gave);

    if (empty($new_email_gave)) {
        wachthond($extdebug, 1, "SKIP: Geen e-mail gevonden bij gavecontact",        "[GC: $gavecontact_id]");
        return ['actie' => 'skip', 'reden' => 'geen email bij gavecontact'];
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 3.2 OPHALEN HUIDIG 'GAVE' EMAIL OP DEELNEMER", "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $params_email_gave = [
        'checkPermissions'      => FALSE,
        'debug'                 => $apidebug,
        'select'                => ['id', 'email'],
        'where'                 => [
            ['contact_id',              '=',  $contact_id],
            ['location_type_id:name',   '=',  'Gave'],
            # ['location_type_id',      '=',  26],
        ],
        'limit'                 => 1,
    ];
    wachthond($extdebug, 7, 'params_email_gave',          $params_email_gave);
    $result_email_gave = civicrm_api4('Email', 'get',     $params_email_gave);
    wachthond($extdebug, 9, 'result_email_gave',          $result_email_gave);

    $email_gave_id      = $result_email_gave->first()['id']    ?? NULL;
    $email_gave_current = $result_email_gave->first()['email'] ?? NULL;

    wachthond($extdebug, 3, 'email_gave_id',              $email_gave_id);
    wachthond($extdebug, 3, 'email_gave_current',         $email_gave_current);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 3.3 UPDATE OF CREATE GAVE EMAIL",           "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    // Delegeer aan email_civicrm_update() als de email-extensie beschikbaar is
    if (function_exists('email_civicrm_update')) {
        if ($extwrite == 1) {
            email_civicrm_update($contact_id, 'Gave', $email_gave_id, $email_gave_current, $new_email_gave);
        }
        wachthond($extdebug, 1, "Gave email gedelegeerd aan email_civicrm_update()", $new_email_gave);
        return ['actie' => 'delegated', 'email' => $new_email_gave];
    }

    // Fallback: directe APIv4 aanroep als email-extensie niet actief is
    if (in_array($privacy_voorkeuren, ['33', '44'])) {
        wachthond($extdebug, 1, "SKIP: privacy_voorkeur blokkeert e-mail aanmaken", "[pref: $privacy_voorkeuren]");
        return ['actie' => 'skip', 'reden' => 'privacy_voorkeur'];
    }

    if ($email_gave_current === $new_email_gave && $email_gave_id) {
        wachthond($extdebug, 1, "Gave email al in orde",                             $email_gave_current);
        return ['actie' => 'ok', 'email' => $email_gave_current];
    }

    if ($email_gave_id) {
        // UPDATE
        $params_email_gave_update = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'where'             => [
                ['id',          '=', $email_gave_id],
                ['contact_id',  '=', $contact_id],
            ],
            'values'            => [
                'email'                  => $new_email_gave,
                'location_type_id:name'  => 'Gave',
                'is_primary'             => FALSE,
            ],
        ];
        wachthond($extdebug, 7, 'params_email_gave_update',    $params_email_gave_update);
        if ($extwrite == 1) {
            $result_email_gave_update = civicrm_api4('Email', 'update', $params_email_gave_update);
        }
        wachthond($extdebug, 9, 'result_email_gave_update',    $result_email_gave_update ?? 'SKIPPED');
        wachthond($extdebug, 1, "Gave email geüpdated",        "$email_gave_current → $new_email_gave");
        return ['actie' => 'update', 'email' => $new_email_gave];
    }

    // CREATE
    $params_email_gave_create = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'values'            => [
            'contact_id'             => $contact_id,
            'email'                  => $new_email_gave,
            'location_type_id:name'  => 'Gave',
            'is_primary'             => FALSE,
        ],
    ];
    wachthond($extdebug, 7, 'params_email_gave_create',    $params_email_gave_create);
    if ($extwrite == 1) {
        $result_email_gave_create = civicrm_api4('Email', 'create', $params_email_gave_create);
    }
    wachthond($extdebug, 9, 'result_email_gave_create',    $result_email_gave_create ?? 'SKIPPED');
    wachthond($extdebug, 1, "Gave email aangemaakt",        $new_email_gave);
    return ['actie' => 'create', 'email' => $new_email_gave];
}
