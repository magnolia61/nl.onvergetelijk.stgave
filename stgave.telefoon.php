<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: stgave.telefoon.php
 * =======================================================================================
 *   stgave_sync_telefoon()           Synchroniseert telefoon van beide broncontacten naar
 *                                    de deelnemer: gavecontact → Gave Mobiel,
 *                                    ouder → OUD1 Mobiel.
 *   _stgave_sync_telefoon_target()   Interne helper: dedup + update/create per locatietype.
 * =======================================================================================
 */

/**
 * =======================================================================================
 * COLOFON: stgave_sync_telefoon
 * =======================================================================================
 * @description     Haalt in één Phone.get de Home Mobiel op van zowel de gave-contactpersoon
 *                  als de ouder. Synchroniseert vervolgens:
 *                  - Gavecontact's mobiel → locatietype 'Gave' (26) op de deelnemer.
 *                  - Ouder's mobiel       → locatietype 'OUD1' (11) op de deelnemer.
 *
 *                  Eventuele duplicaten per locatietype worden opgeruimd (nieuwste behouden).
 *
 * @param int       $contact_id         Het contact ID van de deelnemer.
 * @param array     $relaties           Resultaat van stgave_get_relaties():
 *                                        ['gavecontact_id' => int|null, 'ouder_id' => int|null, ...]
 * @param string|null $privacy_voorkeuren  Privacy-voorkeur (blokkeert aanmaken Gave telefoon).
 * @return array                        ['gave' => string, 'oud1' => string]
 * =======================================================================================
 */
function stgave_sync_telefoon(int $contact_id, array $relaties, ?string $privacy_voorkeuren = NULL): array {

    $extdebug = 'stgave.telefoon';
    $apidebug = FALSE;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 2.0 SYNC TELEFOON GAVE + OUD1",               "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $gavecontact_id = $relaties['gavecontact_id'] ?? NULL;
    $ouder_id       = $relaties['ouder_id']       ?? NULL;

    // Verzamel alle bron-contact-IDs (filter lege waarden)
    $source_ids = array_values(array_filter([$gavecontact_id, $ouder_id]));
    wachthond($extdebug, 3, 'source_ids',             $source_ids);

    if (empty($source_ids)) {
        wachthond($extdebug, 1, "SKIP: Geen broncontacten beschikbaar",               "[CID: $contact_id]");
        return ['gave' => 'skip', 'oud1' => 'skip'];
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 2.1 OPHALEN TELEFOON VAN BRONCONTACTEN",      "[sources: " . implode(', ', $source_ids) . "]");
    wachthond($extdebug, 2, "########################################################################");

    // Één query voor alle broncontacten (Home Mobiel)
    $params_bron_phones = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['contact_id', 'phone'],
        'where'             => [
            ['contact_id',      'IN', $source_ids],
            ['location_type_id','=',  1],    // Home
            ['phone_type_id',   '=',  2],    // Mobiel
        ],
    ];
    wachthond($extdebug, 7, 'params_bron_phones',     $params_bron_phones);
    $result_bron_phones = civicrm_api4('Phone', 'get', $params_bron_phones);
    wachthond($extdebug, 9, 'result_bron_phones',     $result_bron_phones);

    // Indexeer op contact_id
    $phones_by_cid = [];
    foreach ($result_bron_phones as $rec) {
        $phones_by_cid[$rec['contact_id']] = $rec['phone'];
    }
    wachthond($extdebug, 3, 'phones_by_cid',          $phones_by_cid);

    $gave_phone  = $gavecontact_id ? ($phones_by_cid[$gavecontact_id] ?? NULL) : NULL;
    $ouder_phone = $ouder_id       ? ($phones_by_cid[$ouder_id]       ?? NULL) : NULL;
    wachthond($extdebug, 3, 'gave_phone',             $gave_phone);
    wachthond($extdebug, 3, 'ouder_phone',            $ouder_phone);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 2.2 SYNC GAVE MOBIEL",                        "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    // Gave Mobiel: privacy-check van toepassing
    if ($gave_phone && in_array($privacy_voorkeuren, ['33', '44'])) {
        wachthond($extdebug, 1, "SKIP Gave: privacy_voorkeur blokkeert",              "[pref: $privacy_voorkeuren]");
        $res_gave = 'skip_privacy';
    } else {
        $res_gave = _stgave_sync_telefoon_target($contact_id, 'Gave', $gave_phone, $privacy_voorkeuren);
    }
    wachthond($extdebug, 3, 'res_gave telefoon',      $res_gave);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 2.3 SYNC OUD1 MOBIEL",                        "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    // OUD1 Mobiel: geen privacy-check (ouder-relatie)
    $res_oud1 = _stgave_sync_telefoon_target($contact_id, 'OUD1', $ouder_phone, NULL);
    wachthond($extdebug, 3, 'res_oud1 telefoon',      $res_oud1);

    return ['gave' => $res_gave, 'oud1' => $res_oud1];
}

/**
 * =======================================================================================
 * COLOFON: _stgave_sync_telefoon_target
 * =======================================================================================
 * @description     Interne helper. Ruimt duplicaten op voor het opgegeven locatietype op de
 *                  deelnemer (behoudt nieuwste) en doet vervolgens update of create.
 *                  Bij $new_phone = NULL: alleen opruimen, geen create.
 *
 * @param int       $contact_id     Deelnemer contact ID.
 * @param string    $loctype_name   Naam van het locatietype ('Gave' of 'OUD1').
 * @param string|null $new_phone    Het nieuwe telefoonnummer (bron).
 * @param string|null $privacy      Privacy-voorkeur (alleen relevant voor create Gave).
 * @return string                   'ok' | 'update' | 'create' | 'skip' | 'skip_donot'
 * =======================================================================================
 */
function _stgave_sync_telefoon_target(int $contact_id, string $loctype_name, ?string $new_phone, ?string $privacy): string {

    $extdebug = 'stgave.telefoon';
    $apidebug = FALSE;
    $extwrite = 1;

    // Haal alle bestaande records voor dit locatietype op (DESC = nieuwste eerst)
    $params_target_get = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['id', 'phone', 'contact_id.do_not_phone'],
        'where'             => [
            ['contact_id',            '=', $contact_id],
            ['location_type_id:name', '=', $loctype_name],
            ['phone_type_id',         '=', 2],    // Mobiel
        ],
        'orderBy'           => ['id' => 'DESC'],
    ];
    wachthond($extdebug, 7, "params_target_get [$loctype_name]",   $params_target_get);
    $result_target_get = civicrm_api4('Phone', 'get',              $params_target_get);
    wachthond($extdebug, 9, "result_target_get [$loctype_name]",   $result_target_get);

    $alle     = $result_target_get->getArrayCopy();
    $huidig   = $alle[0] ?? NULL;
    $target_id    = $huidig['id']                      ?? NULL;
    $target_phone = $huidig['phone']                   ?? NULL;
    $do_not_phone = $huidig['contact_id.do_not_phone'] ?? FALSE;

    // Opruimen duplicaten (alles behalve het nieuwste record)
    if (count($alle) > 1) {
        $dup_ids = array_column(array_slice($alle, 1), 'id');
        wachthond($extdebug, 3, "[$loctype_name] duplicaten verwijderen", $dup_ids);
        $params_dup_delete = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'where'             => [
                ['id',         'IN', $dup_ids],
                ['contact_id', '=',  $contact_id],
            ],
        ];
        wachthond($extdebug, 7, "params_dup_delete [$loctype_name]",   $params_dup_delete);
        if ($extwrite == 1) {
            civicrm_api4('Phone', 'delete', $params_dup_delete);
        }
        wachthond($extdebug, 1, count($dup_ids) . " dubbele $loctype_name-telefoons verwijderd", "[CID: $contact_id]");
    }

    if (empty($new_phone)) {
        wachthond($extdebug, 1, "SKIP [$loctype_name]: geen brontelefoon beschikbaar", "[CID: $contact_id]");
        return 'skip';
    }

    if ($do_not_phone) {
        wachthond($extdebug, 1, "SKIP [$loctype_name]: do_not_phone staat aan",       "[CID: $contact_id]");
        return 'skip_donot';
    }

    // UPDATE: record bestaat, nummer is gewijzigd
    if ($target_id && $target_phone !== $new_phone) {
        $params_update = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'where'             => [['id', '=', $target_id], ['contact_id', '=', $contact_id]],
            'values'            => [
                'phone'                  => $new_phone,
                'location_type_id:name'  => $loctype_name,
                'phone_type_id:name'     => 'Mobile',
            ],
        ];
        wachthond($extdebug, 7, "params_update [$loctype_name]",   $params_update);
        if ($extwrite == 1) {
            civicrm_api4('Phone', 'update', $params_update);
        }
        wachthond($extdebug, 1, "[$loctype_name] telefoon geüpdated",   "$target_phone → $new_phone");
        return 'update';
    }

    // OK: record bestaat en nummer klopt al
    if ($target_id && $target_phone === $new_phone) {
        wachthond($extdebug, 1, "[$loctype_name] telefoon al in orde",  $target_phone);
        return 'ok';
    }

    // CREATE: nog geen record voor dit locatietype
    if (empty($target_id)) {
        if ($loctype_name === 'Gave' && in_array($privacy, ['33', '44'])) {
            wachthond($extdebug, 1, "SKIP [$loctype_name]: privacy_voorkeur blokkeert aanmaken", "[pref: $privacy]");
            return 'skip_privacy';
        }
        $params_create = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'values'            => [
                'contact_id'             => $contact_id,
                'phone'                  => $new_phone,
                'location_type_id:name'  => $loctype_name,
                'phone_type_id:name'     => 'Mobile',
            ],
        ];
        wachthond($extdebug, 7, "params_create [$loctype_name]",   $params_create);
        if ($extwrite == 1) {
            civicrm_api4('Phone', 'create', $params_create);
        }
        wachthond($extdebug, 1, "[$loctype_name] telefoon aangemaakt",  $new_phone);
        return 'create';
    }

    return 'skip';
}
