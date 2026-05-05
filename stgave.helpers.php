<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: stgave.helpers.php
 * =======================================================================================
 *   stgave_civicrm_configure()       Centrale orkestratie: voert alle St.Gave logica uit.
 *   stgave_is_gave_contact()         Detecteert of een contact een ST.GAVE deelnemer is.
 *   stgave_sync_regeling()           Zet de kampgeldregeling op 'ja_stgave' in de contribution.
 *   stgave_sync_participant_rol()    Zorgt dat de deelnemer beide rollen heeft: 7 + 16.
 * =======================================================================================
 */

/**
 * =======================================================================================
 * COLOFON: stgave_civicrm_configure
 * =======================================================================================
 * @description     Centrale St.Gave motor. Wordt aangeroepen vanuit nl.onvergetelijk.core
 *                  (als vervanging van de inline CORE 4.9 A/B code) en via de participant
 *                  post-hook. Orkestreert: relatie ophalen, telefoon sync, email sync,
 *                  line items, regeling.
 *
 * @param int       $contact_id     Het contact ID van de deelnemer.
 * @param array     $part_array     De volledige array vanuit base_pid2part() (optioneel).
 * @return array                    Statusarray met resultaten per onderdeel.
 * =======================================================================================
 */
function stgave_civicrm_configure(int $contact_id, array $part_array = []): array {

    static $processing_stgave = [];
    if (isset($processing_stgave[$contact_id])) {
        return ['status' => 'skip', 'reden' => 'al in verwerking'];
    }
    $processing_stgave[$contact_id] = TRUE;

    $extdebug = 'stgave.configure'; // Kanaal voor centrale debug-config

    $stgave_start = microtime(TRUE);
    watchdog('civicrm_timing', base_microtimer("START stgave_configure [CID: $contact_id]"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 0.0 START",                       "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $resultaat = [];

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 1.0 DETECTIE ST.GAVE CONTACT",    "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $is_gave = stgave_is_gave_contact($contact_id);
    wachthond($extdebug, 3, 'is_gave',                       $is_gave);

    if (!$is_gave) {
        wachthond($extdebug, 1, "SKIP: Contact is geen ST.GAVE deelnemer",          "[CID: $contact_id]");
        unset($processing_stgave[$contact_id]);
        return ['status' => 'skip', 'reden' => 'geen ST.GAVE contact'];
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 2.0 OPHALEN GAVECONTACT RELATIE", "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $gavecontact = stgave_get_gavecontact($contact_id);
    $gavecontact_id = $gavecontact['contact_id'] ?? NULL;
    wachthond($extdebug, 3, 'gavecontact',                   $gavecontact);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 3.0 PRIVACY VOORKEUR OPHALEN",    "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    // Haal privacy-voorkeur op voor gebruik bij telefoon/email sync
    $privacy_voorkeuren = NULL;
    if (function_exists('base_cid2cont')) {
        $cont_data          = base_cid2cont($contact_id);
        $privacy_voorkeuren = $cont_data['privacy_voorkeuren'] ?? NULL;
    }
    wachthond($extdebug, 3, 'privacy_voorkeuren',            $privacy_voorkeuren);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 4.0 SYNC TELEFOON",               "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $resultaat['telefoon'] = stgave_sync_telefoon($contact_id, $gavecontact_id, $privacy_voorkeuren);
    wachthond($extdebug, 3, 'resultaat_telefoon',            $resultaat['telefoon']);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 5.0 SYNC EMAIL",                  "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $resultaat['email'] = stgave_sync_email($contact_id, $gavecontact_id, $privacy_voorkeuren);
    wachthond($extdebug, 3, 'resultaat_email',               $resultaat['email']);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 5.5 SYNC PARTICIPANT ROL",        "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    if (!empty($part_array['id'])) {
        $resultaat['participant_rol'] = stgave_sync_participant_rol($part_array);
        wachthond($extdebug, 3, 'resultaat_participant_rol',  $resultaat['participant_rol']);
    } else {
        wachthond($extdebug, 1, "SKIP participant rol: geen part_array",             "[CID: $contact_id]");
        $resultaat['participant_rol'] = 'skip';
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 6.0 SYNC LINEITEMS",              "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    if (!empty($part_array['id'])) {
        $resultaat['lineitems'] = stgave_sync_lineitems($contact_id, $part_array);
        wachthond($extdebug, 3, 'resultaat_lineitems',       $resultaat['lineitems']);
    } else {
        wachthond($extdebug, 1, "SKIP lineitems: geen part_array meegegeven",       "[CID: $contact_id]");
        $resultaat['lineitems'] = 'skip';
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 7.0 SYNC REGELING",               "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    if (!empty($part_array['id'])) {
        $resultaat['regeling'] = stgave_sync_regeling($part_array);
        wachthond($extdebug, 3, 'resultaat_regeling',        $resultaat['regeling']);
    } else {
        wachthond($extdebug, 1, "SKIP regeling: geen part_array meegegeven",        "[CID: $contact_id]");
        $resultaat['regeling'] = 'skip';
    }

    watchdog('civicrm_timing', base_microtimer("EINDE stgave_configure [CID: $contact_id]"), NULL, WATCHDOG_DEBUG);
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE X.0 EINDE",                       "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    unset($processing_stgave[$contact_id]);
    return $resultaat;
}

/**
 * =======================================================================================
 * COLOFON: stgave_is_gave_contact
 * =======================================================================================
 * @description     Detecteert of een contact een ST.GAVE deelnemer is via:
 *                  1. Contact subtype 'Deelnemer_Gave'
 *                  2. Tag 'ST.GAVE' (tag_id = 141)
 *
 * @param int       $contact_id
 * @return bool
 * =======================================================================================
 */
function stgave_is_gave_contact(int $contact_id): bool {

    $extdebug = 'stgave.configure';
    $apidebug = FALSE;

    // Methode 1: contact subtype
    $params_cont = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['contact_sub_type'],
        'where'             => [['id', '=', $contact_id]],
    ];
    wachthond($extdebug, 7, 'params_cont (stgave detect)',   $params_cont);
    $result_cont = civicrm_api4('Contact', 'get',            $params_cont);
    wachthond($extdebug, 9, 'result_cont (stgave detect)',   $result_cont);

    $subtypes = $result_cont->first()['contact_sub_type'] ?? [];
    if (is_array($subtypes) && in_array('Deelnemer_Gave', $subtypes)) {
        wachthond($extdebug, 1, "ST.GAVE detectie: via subtype Deelnemer_Gave",     "[CID: $contact_id]");
        return TRUE;
    }

    // Methode 2: ST.GAVE tag (ID 141)
    $params_tag = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['row_count', 'id'],
        'where'             => [
            ['entity_id',  '=', $contact_id],
            ['tag_id',     '=', 141],           // Tag 141 = ST.GAVE
            ['entity_table','=','civicrm_contact'],
        ],
    ];
    wachthond($extdebug, 7, 'params_tag (stgave detect)',    $params_tag);
    $result_tag = civicrm_api4('EntityTag', 'get',           $params_tag);
    wachthond($extdebug, 9, 'result_tag (stgave detect)',    $result_tag);

    if ($result_tag->countMatched() > 0) {
        wachthond($extdebug, 1, "ST.GAVE detectie: via tag ST.GAVE (ID 141)",       "[CID: $contact_id]");
        return TRUE;
    }

    return FALSE;
}

/**
 * =======================================================================================
 * COLOFON: stgave_sync_regeling
 * =======================================================================================
 * @description     Zet het veld CONT_KAMPGELD.regeling (custom field 1514) op 'ja_stgave'
 *                  in de contribution van de deelnemer.
 *
 * @param array     $part_array     De volledige array vanuit base_pid2part().
 * @return array                    Statusarray.
 * =======================================================================================
 */
function stgave_sync_regeling(array $part_array): array {

    $extdebug = 'stgave.configure';
    $apidebug = FALSE;
    $extwrite = 1;

    $part_id = $part_array['id'] ?? NULL;
    if (empty($part_id)) {
        return ['actie' => 'skip', 'reden' => 'geen part_id'];
    }

    // Haal contribution ID op
    $contrib_id = NULL;
    if (function_exists('pecunia_get_contribid')) {
        $contrib_id = pecunia_get_contribid($part_array);
    } else {
        $contrib_id = stgave_get_contribid_fallback($part_id);
    }

    if (empty($contrib_id)) {
        wachthond($extdebug, 1, "SKIP regeling: geen contrib_id",                   "[PID: $part_id]");
        return ['actie' => 'skip', 'reden' => 'geen contrib_id'];
    }

    // Controleer huidige waarde van CONT_KAMPGELD.regeling
    $params_regeling_get = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['CONT_KAMPGELD.regeling'],
        'where'             => [['id', '=', $contrib_id]],
    ];
    wachthond($extdebug, 7, 'params_regeling_get',           $params_regeling_get);
    $result_regeling_get = civicrm_api4('Contribution', 'get', $params_regeling_get);
    wachthond($extdebug, 9, 'result_regeling_get',           $result_regeling_get);

    $huidige_regeling = $result_regeling_get->first()['CONT_KAMPGELD.regeling'] ?? NULL;
    wachthond($extdebug, 3, 'huidige_regeling',              $huidige_regeling);

    if ($huidige_regeling === 'ja_stgave') {
        wachthond($extdebug, 1, "Regeling al correct: ja_stgave",                   "[BID: $contrib_id]");
        return ['actie' => 'ok', 'regeling' => 'ja_stgave'];
    }

    // Update regeling naar 'ja_stgave'
    if (function_exists('base_api_wrapper')) {
        $resultaat_update = base_api_wrapper('Contribution', $contrib_id, [
            'CONT_KAMPGELD.regeling' => 'ja_stgave',
        ]);
    } else {
        $params_regeling_update = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'where'             => [['id', '=', $contrib_id]],
            'values'            => ['CONT_KAMPGELD.regeling' => 'ja_stgave'],
        ];
        wachthond($extdebug, 7, 'params_regeling_update',    $params_regeling_update);
        if ($extwrite == 1) {
            $resultaat_update = civicrm_api4('Contribution', 'update', $params_regeling_update);
        }
        wachthond($extdebug, 9, 'result_regeling_update',    $resultaat_update ?? 'SKIPPED');
    }

    wachthond($extdebug, 1, "Regeling gezet op 'ja_stgave'",                        "[BID: $contrib_id]");
    return ['actie' => 'update', 'regeling' => 'ja_stgave'];
}

/**
 * =======================================================================================
 * COLOFON: stgave_sync_participant_rol
 * =======================================================================================
 * @description     Zorgt dat een ST.GAVE deelnemer beide participant-rollen heeft:
 *                  - Rol 7  = Deelnemer  (standaard)
 *                  - Rol 16 = Deelnemer_Gave (stgave-specifiek)
 *                  Voegt alleen toe wat ontbreekt; verwijdert niets.
 *
 * @param array     $part_array     De volledige array vanuit base_pid2part().
 * @return array                    Statusarray met actie en nieuwe rollen.
 * =======================================================================================
 */
function stgave_sync_participant_rol(array $part_array): array {

    $extdebug = 'stgave.configure';
    $apidebug = FALSE;
    $extwrite = 1;

    $part_id = $part_array['id'] ?? NULL;
    if (empty($part_id)) {
        return ['actie' => 'skip', 'reden' => 'geen part_id'];
    }

    // Rol-IDs: 7 = Deelnemer, 16 = Deelnemer_Gave
    $rol_deelnemer      = 7;
    $rol_deelnemer_gave = 16;
    $vereiste_rollen    = [$rol_deelnemer, $rol_deelnemer_gave];

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE ROL 1.0 HUIDIG PARTICIPANT ROL OPHALEN",    "[PID: $part_id]");
    wachthond($extdebug, 2, "########################################################################");

    $params_part_get = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['id', 'role_id'],
        'where'             => [['id', '=', $part_id]],
    ];
    wachthond($extdebug, 7, 'params_part_get',               $params_part_get);
    $result_part_get = civicrm_api4('Participant', 'get',    $params_part_get);
    wachthond($extdebug, 9, 'result_part_get',               $result_part_get);

    // role_id is een multi-value veld: APIv4 geeft het terug als array
    $huidige_rollen = $result_part_get->first()['role_id'] ?? [];
    if (!is_array($huidige_rollen)) {
        // Fallback: sommige CiviCRM versies geven een string terug
        $huidige_rollen = array_filter(array_map('intval', explode(CRM_Core_DAO::VALUE_SEPARATOR, (string)$huidige_rollen)));
    }
    $huidige_rollen = array_map('intval', $huidige_rollen);
    wachthond($extdebug, 3, 'huidige_rollen',                $huidige_rollen);

    // Controleer of beide rollen al aanwezig zijn
    $ontbrekende_rollen = array_diff($vereiste_rollen, $huidige_rollen);
    wachthond($extdebug, 3, 'ontbrekende_rollen',            $ontbrekende_rollen);

    if (empty($ontbrekende_rollen)) {
        wachthond($extdebug, 1, "Participant rollen al correct: 7 + 16",             "[PID: $part_id]");
        return ['actie' => 'ok', 'rollen' => $huidige_rollen];
    }

    // Voeg ontbrekende rollen toe (bestaande bewaren)
    $nieuwe_rollen = array_values(array_unique(array_merge($huidige_rollen, $vereiste_rollen)));
    wachthond($extdebug, 3, 'nieuwe_rollen',                 $nieuwe_rollen);

    $params_part_update = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'where'             => [['id', '=', $part_id]],
        'values'            => ['role_id' => $nieuwe_rollen],
    ];
    wachthond($extdebug, 7, 'params_part_update',            $params_part_update);
    if ($extwrite == 1) {
        $result_part_update = civicrm_api4('Participant', 'update', $params_part_update);
    }
    wachthond($extdebug, 9, 'result_part_update',            $result_part_update ?? 'SKIPPED');
    wachthond($extdebug, 1, "Participant rollen gezet op 7 + 16",                    "[PID: $part_id]");

    return ['actie' => 'update', 'rollen' => $nieuwe_rollen];
}
