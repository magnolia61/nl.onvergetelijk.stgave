<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: stgave.telefoon.php
 * =======================================================================================
 *   stgave_sync_telefoon()  Synchroniseert het telefoonnummer van de gave-contactpersoon
 *                           naar locatietype Gave (26) op de deelnemer.
 * =======================================================================================
 */

/**
 * =======================================================================================
 * COLOFON: stgave_sync_telefoon
 * =======================================================================================
 * @description     Haalt het primaire mobiele nummer op van de gave-contactpersoon en
 *                  zet dit als 'Gave' telefoon op de deelnemer (create of update).
 *                  Verplaatst en verbeterd vanuit nl.onvergetelijk.core/core.php (CORE 4.9 B).
 *
 * @param int       $contact_id         Het contact ID van de deelnemer.
 * @param int|null  $gavecontact_id     Het contact ID van de gave-contactpersoon.
 * @param string    $privacy_voorkeuren Privacy-voorkeur van de deelnemer (bijv. "33", "44").
 * @return array                        Statusarray met actie en resultaat.
 * =======================================================================================
 */
function stgave_sync_telefoon(int $contact_id, ?int $gavecontact_id, ?string $privacy_voorkeuren = NULL): array {

    $extdebug = 'stgave.telefoon'; // Kanaal voor centrale debug-config
    $apidebug = FALSE;
    $extwrite = 1;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 2.0 SYNC TELEFOON GAVECONTACT → DEELNEMER", "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    if (empty($gavecontact_id)) {
        wachthond($extdebug, 1, "SKIP: Geen gavecontact_id opgegeven",         "[CID: $contact_id]");
        return ['actie' => 'skip', 'reden' => 'geen gavecontact_id'];
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 2.1 OPHALEN TELEFOON VAN GAVECONTACT",       "[GC: $gavecontact_id]");
    wachthond($extdebug, 2, "########################################################################");

    // Haal het primaire mobiele nummer op van de gave-contactpersoon (Home Mobiel)
    $params_gave_phone_bron = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['phone'],
        'where'             => [
            ['contact_id',      '=', $gavecontact_id],
            ['location_type_id','=', 1],              // Home
            ['phone_type_id',   '=', 2],              // Mobiel
        ],
        'limit'             => 1,
    ];
    wachthond($extdebug, 7, 'params_gave_phone_bron',   $params_gave_phone_bron);
    $result_gave_phone_bron = civicrm_api4('Phone', 'get', $params_gave_phone_bron);
    wachthond($extdebug, 9, 'result_gave_phone_bron',   $result_gave_phone_bron);

    $new_phone_gave = $result_gave_phone_bron->first()['phone'] ?? NULL;
    wachthond($extdebug, 3, 'new_phone_gave',            $new_phone_gave);

    if (empty($new_phone_gave)) {
        wachthond($extdebug, 1, "SKIP: Geen telefoon gevonden bij gavecontact",  "[GC: $gavecontact_id]");
        return ['actie' => 'skip', 'reden' => 'geen telefoon bij gavecontact'];
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 2.2 OPHALEN HUIDIG 'GAVE' TELEFOON OP DEELNEMER", "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $params_phone_gave = [
        'checkPermissions'      => FALSE,
        'debug'                 => $apidebug,
        'select'                => [
            'row_count', 'id', 'phone', 'contact_id.do_not_phone',
        ],
        'where'                 => [
            ['contact_id',              '=',  $contact_id],
            ['location_type_id:name',   '=',  "Gave"],
            # ['location_type_id',      '=',  26],
        ],
    ];
    wachthond($extdebug, 7, 'params_phone_gave',         $params_phone_gave);
    $result_phone_gave = civicrm_api4('Phone', 'get',    $params_phone_gave);
    wachthond($extdebug, 9, 'result_phone_gave',         $result_phone_gave);

    $phone_gave_id    = $result_phone_gave->first()['id']                      ?? NULL;
    $phone_gave_phone = $result_phone_gave->first()['phone']                   ?? NULL;
    $phone_gave_donot = $result_phone_gave->first()['contact_id.do_not_phone'] ?? FALSE;

    wachthond($extdebug, 3, 'phone_gave_id',             $phone_gave_id);
    wachthond($extdebug, 3, 'phone_gave_phone',          $phone_gave_phone);
    wachthond($extdebug, 3, 'phone_gave_donot',          $phone_gave_donot);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 2.3 UPDATE OF CREATE GAVE TELEFOON",        "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    // UPDATE: record bestaat al, maar nummer is anders
    if ($phone_gave_id && $phone_gave_phone !== $new_phone_gave) {

        if ($phone_gave_donot) {
            wachthond($extdebug, 1, "SKIP: do_not_phone staat aan voor deelnemer",  "[CID: $contact_id]");
            return ['actie' => 'skip', 'reden' => 'do_not_phone'];
        }

        $params_phone_gave_update = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'where'             => [
                ['id',          '=', $phone_gave_id],
                ['contact_id',  '=', $contact_id],
            ],
            'values'            => [
                'phone'                  => $new_phone_gave,
                'location_type_id:name'  => 'Gave',
                'phone_type_id:name'     => 'Mobile',
                'is_primary'             => TRUE,
            ],
        ];
        wachthond($extdebug, 7, 'params_phone_gave_update',    $params_phone_gave_update);
        if ($extwrite == 1) {
            $result_phone_gave_update = civicrm_api4('Phone', 'update', $params_phone_gave_update);
        }
        wachthond($extdebug, 9, 'result_phone_gave_update',    $result_phone_gave_update ?? 'SKIPPED');
        wachthond($extdebug, 1, "Gave telefoon geüpdated",     "$phone_gave_phone → $new_phone_gave");
        return ['actie' => 'update', 'phone' => $new_phone_gave];
    }

    // CREATE: nog geen 'Gave' telefoon op deelnemer
    if (empty($phone_gave_id) && !empty($new_phone_gave)) {

        if (in_array($privacy_voorkeuren, ['33', '44'])) {
            wachthond($extdebug, 1, "SKIP: privacy_voorkeur blokkeert aanmaken telefoon", "[pref: $privacy_voorkeuren]");
            return ['actie' => 'skip', 'reden' => 'privacy_voorkeur'];
        }

        $params_phone_gave_create = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'values'            => [
                'contact_id'             => $contact_id,
                'phone'                  => $new_phone_gave,
                'location_type_id:name'  => 'Gave',
                'phone_type_id:name'     => 'Mobile',
                'is_primary'             => TRUE,
            ],
        ];
        wachthond($extdebug, 7, 'params_phone_gave_create',    $params_phone_gave_create);
        if ($extwrite == 1) {
            $result_phone_gave_create = civicrm_api4('Phone', 'create', $params_phone_gave_create);
        }
        wachthond($extdebug, 9, 'result_phone_gave_create',    $result_phone_gave_create ?? 'SKIPPED');
        wachthond($extdebug, 1, "Gave telefoon aangemaakt",    $new_phone_gave);
        return ['actie' => 'create', 'phone' => $new_phone_gave];
    }

    wachthond($extdebug, 1, "Gave telefoon al in orde",         $phone_gave_phone);
    return ['actie' => 'ok', 'phone' => $phone_gave_phone];
}
