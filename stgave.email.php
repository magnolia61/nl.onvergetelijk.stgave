<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: stgave.email.php
 * =======================================================================================
 *   stgave_sync_email()           Synchroniseert e-mail van beide broncontacten naar
 *                                 de deelnemer: gavecontact → Gave Email,
 *                                 ouder → OUD1 Email.
 *   _stgave_sync_email_target()   Interne helper: dedup + update/create per locatietype.
 * =======================================================================================
 */

/**
 * =======================================================================================
 * COLOFON: stgave_sync_email
 * =======================================================================================
 * @description     Haalt in één Email.get het primaire Home-adres op van zowel de
 *                  gave-contactpersoon als de ouder. Synchroniseert vervolgens:
 *                  - Gavecontact's email → locatietype 'Gave' (26) op de deelnemer.
 *                  - Ouder's email       → locatietype 'OUD1' (11) op de deelnemer.
 *
 *                  Eventuele duplicaten per locatietype worden opgeruimd (nieuwste behouden).
 *                  Delegatie aan email_civicrm_update() (alleen voor 'Gave') wordt intern
 *                  afgehandeld door _stgave_sync_email_target() ná het dedup-blok.
 *
 * @param int       $contact_id         Het contact ID van de deelnemer.
 * @param array     $relaties           Resultaat van stgave_get_relaties():
 *                                        ['gavecontact_id' => int|null, 'ouder_id' => int|null, ...]
 * @param string|null $privacy_voorkeuren  Privacy-voorkeur (blokkeert aanmaken Gave email).
 * @return array                        ['gave' => string, 'oud1' => string]
 * =======================================================================================
 */
function stgave_sync_email(int $contact_id, array $relaties, ?string $privacy_voorkeuren = NULL): array {

    $extdebug = 'stgave.email';
    $apidebug = FALSE;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 3.0 SYNC EMAIL GAVE + OUD1",                  "[CID: $contact_id]");
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
    wachthond($extdebug, 1, "### STGAVE 3.1 OPHALEN EMAIL VAN BRONCONTACTEN",         "[sources: " . implode(', ', $source_ids) . "]");
    wachthond($extdebug, 2, "########################################################################");

    // Één query voor alle broncontacten (Home, primary eerst)
    $params_bron_emails = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['contact_id', 'email', 'is_primary'],
        'where'             => [
            ['contact_id',      'IN', $source_ids],
            ['location_type_id','=',  1],    // Home
        ],
        'orderBy'           => ['is_primary' => 'DESC'],
    ];
    wachthond($extdebug, 7, 'params_bron_emails',     $params_bron_emails);
    $result_bron_emails = civicrm_api4('Email', 'get', $params_bron_emails);
    wachthond($extdebug, 9, 'result_bron_emails',     $result_bron_emails);

    // Indexeer op contact_id — is_primary DESC zorgt dat primary als eerste komt
    $emails_by_cid = [];
    foreach ($result_bron_emails as $rec) {
        if (!isset($emails_by_cid[$rec['contact_id']])) {
            $emails_by_cid[$rec['contact_id']] = $rec['email'];
        }
    }
    wachthond($extdebug, 3, 'emails_by_cid',          $emails_by_cid);

    $gave_email  = $gavecontact_id ? ($emails_by_cid[$gavecontact_id] ?? NULL) : NULL;
    $ouder_email = $ouder_id       ? ($emails_by_cid[$ouder_id]       ?? NULL) : NULL;
    wachthond($extdebug, 3, 'gave_email',             $gave_email);
    wachthond($extdebug, 3, 'ouder_email',            $ouder_email);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 3.2 SYNC GAVE EMAIL",                         "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    // Gave Email: altijd via _stgave_sync_email_target() zodat dedup altijd loopt.
    // Delegatie aan email_civicrm_update() wordt intern afgehandeld ná het opruimen,
    // met privacy-check voor het CREATE-pad.
    $res_gave = _stgave_sync_email_target($contact_id, 'Gave', $gave_email, $privacy_voorkeuren);
    wachthond($extdebug, 3, 'res_gave email',         $res_gave);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 3.3 SYNC OUD1 EMAIL",                         "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    // OUD1 Email: altijd direct via API (email_civicrm_update is Gave-specifiek)
    $res_oud1 = _stgave_sync_email_target($contact_id, 'OUD1', $ouder_email, NULL);
    wachthond($extdebug, 3, 'res_oud1 email',         $res_oud1);

    return ['gave' => $res_gave, 'oud1' => $res_oud1];
}

/**
 * =======================================================================================
 * COLOFON: _stgave_sync_email_target
 * =======================================================================================
 * @description     Interne helper. Ruimt duplicaten op voor het opgegeven locatietype op de
 *                  deelnemer (behoudt nieuwste) en doet vervolgens update of create.
 *                  Bij $new_email = NULL: alleen opruimen, geen create.
 *
 * @param int       $contact_id     Deelnemer contact ID.
 * @param string    $loctype_name   Naam van het locatietype ('Gave' of 'OUD1').
 * @param string|null $new_email    Het nieuwe e-mailadres (bron).
 * @param string|null $privacy      Privacy-voorkeur (alleen relevant voor create Gave).
 * @return string                   'ok' | 'update' | 'create' | 'skip' | 'skip_privacy' | 'delegated'
 * =======================================================================================
 */
function _stgave_sync_email_target(int $contact_id, string $loctype_name, ?string $new_email, ?string $privacy): string {

    $extdebug = 'stgave.email';
    $apidebug = FALSE;
    $extwrite = 1;

    // Haal alle bestaande records voor dit locatietype op (DESC = nieuwste eerst)
    $params_target_get = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['id', 'email'],
        'where'             => [
            ['contact_id',            '=', $contact_id],
            ['location_type_id:name', '=', $loctype_name],
        ],
        'orderBy'           => ['id' => 'DESC'],
    ];
    wachthond($extdebug, 7, "params_target_get [$loctype_name]",   $params_target_get);
    $result_target_get = civicrm_api4('Email', 'get',              $params_target_get);
    wachthond($extdebug, 9, "result_target_get [$loctype_name]",   $result_target_get);

    $alle         = $result_target_get->getArrayCopy();
    $huidig       = $alle[0] ?? NULL;
    $target_id    = $huidig['id']    ?? NULL;
    $target_email = $huidig['email'] ?? NULL;

    // Opruimen duplicaten (alles behalve het nieuwste record)
    if (count($alle) > 1) {
        $dup_ids = array_column(array_slice($alle, 1), 'id');
        wachthond($extdebug, 3, "[$loctype_name] email duplicaten verwijderen", $dup_ids);
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
            civicrm_api4('Email', 'delete', $params_dup_delete);
        }
        wachthond($extdebug, 1, count($dup_ids) . " dubbele $loctype_name-emails verwijderd", "[CID: $contact_id]");
    }

    if (empty($new_email)) {
        wachthond($extdebug, 1, "SKIP [$loctype_name]: geen bron-email beschikbaar",  "[CID: $contact_id]");
        return 'skip';
    }

    // Gave Email: delegeer aan email_civicrm_update() als die beschikbaar is.
    // Dedup is altijd al uitgevoerd hierboven; delegatie vindt ná het opruimen plaats
    // zodat $target_id / $target_email actueel zijn. Privacy-check bewaakt CREATE-pad.
    if ($loctype_name === 'Gave' && function_exists('email_civicrm_update')) {
        if (empty($target_id) && in_array($privacy, ['33', '44'])) {
            wachthond($extdebug, 1, "SKIP [Gave]: privacy_voorkeur blokkeert aanmaken (delegate)", "[pref: $privacy]");
            return 'skip_privacy';
        }
        email_civicrm_update($contact_id, 'Gave', $target_id, $target_email, $new_email);
        wachthond($extdebug, 1, "Gave email gedelegeerd aan email_civicrm_update()",   $new_email);
        return 'delegated';
    }

    // UPDATE: record bestaat, email is gewijzigd
    if ($target_id && $target_email !== $new_email) {
        $params_update = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'where'             => [['id', '=', $target_id], ['contact_id', '=', $contact_id]],
            'values'            => [
                'email'                  => $new_email,
                'location_type_id:name'  => $loctype_name,
            ],
        ];
        wachthond($extdebug, 7, "params_update [$loctype_name]",   $params_update);
        if ($extwrite == 1) {
            civicrm_api4('Email', 'update', $params_update);
        }
        wachthond($extdebug, 1, "[$loctype_name] email geüpdated", "$target_email → $new_email");
        return 'update';
    }

    // OK: record bestaat en email klopt al
    if ($target_id && $target_email === $new_email) {
        wachthond($extdebug, 1, "[$loctype_name] email al in orde", $target_email);
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
                'email'                  => $new_email,
                'location_type_id:name'  => $loctype_name,
                'is_primary'             => FALSE,
            ],
        ];
        wachthond($extdebug, 7, "params_create [$loctype_name]",   $params_create);
        if ($extwrite == 1) {
            civicrm_api4('Email', 'create', $params_create);
        }
        wachthond($extdebug, 1, "[$loctype_name] email aangemaakt", $new_email);
        return 'create';
    }

    return 'skip';
}
