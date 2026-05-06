<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: stgave.helpers.php
 * =======================================================================================
 *   stgave_civicrm_configure()       Centrale orkestratie: voert alle St.Gave logica uit.
 *   stgave_is_gave_contact()         Detecteert of een contact een ST.GAVE deelnemer is.
 *   stgave_sync_regeling()           Zet regeling (ja_stgave), toeristenbelasting (nee/stgave)
 *                                    en contribution source op de juiste waarden.
 *                                    CONT_KAMPGELD.regeling wordt gezet door pecunia nadat
 *                                    pecunia_status_stgave() onze line items herkent.
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

    watchdog('civicrm_timing', base_microtimer("START stgave_configure [CID: $contact_id]"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 0.0 START",                       "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $resultaat = [];

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 1.0 DETECTIE ST.GAVE CONTACT",    "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $is_gave = stgave_is_gave_contact($part_array);

    wachthond($extdebug, 3, 'is_gave',                       $is_gave);

    if (!$is_gave) {
        wachthond($extdebug, 1, "SKIP: Contact is geen ST.GAVE deelnemer",          "[CID: $contact_id]");
        unset($processing_stgave[$contact_id]);
        return ['status' => 'skip', 'reden' => 'geen ST.GAVE contact'];
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 2.0 OPHALEN RELATIES",             "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $relaties       = stgave_get_relaties($contact_id);
    $gavecontact_id = $relaties['gavecontact_id'] ?? NULL;
    $ouder_id       = $relaties['ouder_id']       ?? NULL;
    wachthond($extdebug, 3, 'relaties',                      $relaties);

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

    $resultaat['telefoon'] = stgave_sync_telefoon($contact_id, $relaties, $privacy_voorkeuren);
    wachthond($extdebug, 3, 'resultaat_telefoon',            $resultaat['telefoon']);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 5.0 SYNC EMAIL",                  "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $resultaat['email'] = stgave_sync_email($contact_id, $relaties, $privacy_voorkeuren);
    wachthond($extdebug, 3, 'resultaat_email',               $resultaat['email']);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 6.0 ENSURE CHILD OF RELATIE",     "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    if (!empty($ouder_id)) {
        $resultaat['childof'] = stgave_ensure_childof($contact_id, $ouder_id);
        wachthond($extdebug, 3, 'resultaat_childof',         $resultaat['childof']);
    } else {
        wachthond($extdebug, 1, "SKIP ensure_childof: geen ouder_id",               "[CID: $contact_id]");
        $resultaat['childof'] = 'skip';
    }

    // Type 21 (StGave Ouder via) aanmaken als ouder via Child of gevonden is maar
    // nog geen type 21 heeft naar de gave-contactpersoon
    if (!empty($ouder_id) && !empty($gavecontact_id) && ($relaties['ouder_bron'] ?? NULL) === 'child_of') {
        $resultaat['stgave_ouder'] = stgave_ensure_stgave_ouder($ouder_id, $gavecontact_id);
        wachthond($extdebug, 3, 'resultaat_stgave_ouder',   $resultaat['stgave_ouder']);
    } else {
        wachthond($extdebug, 1, "SKIP ensure_stgave_ouder: ouder via type 21 of geen ids", "[CID: $contact_id]");
        $resultaat['stgave_ouder'] = 'skip';
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 7.0 SYNC PARTICIPANT ROL",        "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    if (!empty($part_array['id'])) {
        $resultaat['participant_rol'] = stgave_sync_participant_rol($part_array);
        wachthond($extdebug, 3, 'resultaat_participant_rol',  $resultaat['participant_rol']);
    } else {
        wachthond($extdebug, 1, "SKIP participant rol: geen part_array",             "[CID: $contact_id]");
        $resultaat['participant_rol'] = 'skip';
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 8.0 SYNC LINEITEMS",              "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    if (!empty($part_array['id'])) {
        $resultaat['lineitems'] = stgave_sync_lineitems($contact_id, $part_array);
        wachthond($extdebug, 3, 'resultaat_lineitems',       $resultaat['lineitems']);
    } else {
        wachthond($extdebug, 1, "SKIP lineitems: geen part_array meegegeven",       "[CID: $contact_id]");
        $resultaat['lineitems'] = 'skip';
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE CONFIGURE 9.0 SYNC REGELING",               "[CID: $contact_id]");
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

    // We laten php aan het eind van de request zelf unsetten
    // unset($processing_stgave[$contact_id]);
    return $resultaat;
}

/**
 * =======================================================================================
 * COLOFON: stgave_is_gave_contact
 * =======================================================================================
 * @description     Detecteert of een specifieke inschrijving via St.Gave loopt.
 *                  
 *                  ACTIEVE CRITERIA (Huidig event / Inschrijving):
 *                  1. Participant rol bevat 'Deelnemer_Gave' (ID 16).
 *                  2. Regeling staat op 'ja_stgave' (PART_KAMPGELD.regeling).
 *                  3. De factuur bevat St.Gave line-items.
 *
 *                  GEÏGNOREERDE CRITERIA (Contactniveau / Historie):
 *                  4. Contact subtype 'Deelnemer_Gave'.
 *                  5. Tag 'ST.GAVE' (ID 141).
 *                  (Reden: 4 en 5 hangen aan het contact en kunnen uit eerdere jaren 
 *                  stammen. Die persoon is dan 'kandidaat', maar gaat dit specifieke 
 *                  jaar misschien regulier mee. Handmatig overrulen naar St.Gave kan 
 *                  altijd via criterium 2).
 *
 * @param array     $part_array     De volledige array vanuit base_pid2part().
 * @return bool
 * =======================================================================================
 */
function stgave_is_gave_contact(array $part_array): bool {

    $extdebug   = 'stgave.configure';
    $apidebug   = FALSE;
    $part_id    = $part_array['id'] ?? NULL;

    if (empty($part_id)) {
        return false;
    }

    // --- CRITERIUM 1: PARTICIPANT ROL (16 = Deelnemer_Gave) ---
    $roles = $part_array['role_id'] ?? [];
    if (!is_array($roles)) {
        $roles = array_filter(explode(CRM_Core_DAO::VALUE_SEPARATOR, (string)$roles));
    }
    if (in_array(16, $roles) || in_array('16', $roles)) {
        wachthond($extdebug, 1, "St.Gave detectie: TRUE via Participant Rol", "[Rol 16]");
        return true;
    }

    // --- CRITERIUM 2: REGELING (ja_stgave) ---
    $regeling = $part_array['part_kampgeld_regeling'] ?? NULL;
    if (empty($regeling)) {
        // Veiligheidscheck via APIv4 als hij nog niet in de base_pid2part cache zat
        $api_part = civicrm_api4('Participant', 'get', [
            'checkPermissions'  => FALSE,
            'select'            => ['PART_KAMPGELD.regeling'],
            'where'             => [['id', '=', $part_id]],
        ])->first();
        $regeling = $api_part['PART_KAMPGELD.regeling'] ?? NULL;
    }
    if ($regeling === 'ja_stgave') {
        wachthond($extdebug, 1, "St.Gave detectie: TRUE via handmatige regeling", "[ja_stgave]");
        return true;
    }

    // --- CRITERIUM 3: LINE ITEMS ---
    $gave_values = [301, 302, 443, 472, 473, 497, 498, 523];
    $line_items = civicrm_api4('LineItem', 'get', [
        'checkPermissions'  => FALSE,
        'select'            => ['price_field_value_id'],
        'where'             => [
            ['entity_table',         '=', 'civicrm_participant'],
            ['entity_id',            '=', $part_id],
            ['price_field_value_id', 'IN', $gave_values]
        ],
        'limit'             => 1
    ]);
    if ($line_items->count() > 0) {
        wachthond($extdebug, 1, "St.Gave detectie: TRUE via Line Items", "[Kassabon]");
        return true;
    }

    wachthond($extdebug, 3, "St.Gave detectie: FALSE. Geen match op actieve criteria.", "[PID: $part_id]");
    return false;
}

/**
 * =======================================================================================
 * COLOFON: stgave_sync_regeling
 * =======================================================================================
 * @description     Zet voor een ST.GAVE deelnemer:
 *                  1. PART_KAMPGELD.regeling = 'ja_stgave' op de participant.
 *                  2. PART_KAMPGELD.toeristenbelasting = 3 ('Nee, via St.Gave') op de participant.
 *                  3. source = 'Aanmelden St.Gave' op de contribution (als dat nog niet klopt).
 *
 *                  CONT_KAMPGELD.regeling wordt NIET door stgave gezet — pecunia doet dat
 *                  automatisch nadat pecunia_status_stgave() de stgave line items herkent
 *                  bij de Participant.update die hier getriggerd wordt.
 *
 * @param array     $part_array     De volledige array vanuit base_pid2part().
 * @return array                    Statusarray per onderdeel.
 * =======================================================================================
 */
function stgave_sync_regeling(array $part_array): array {

    $extdebug = 'stgave.configure';
    $apidebug = FALSE;
    $extwrite = 1;

    $part_id  = $part_array['id'] ?? NULL;
    if (empty($part_id)) {
        return ['actie' => 'skip', 'reden' => 'geen part_id'];
    }

    $contact_id = $contact_id ?? $part_array['contact_id'] ?? NULL;

    $resultaat = ['participant' => 'skip', 'contribution_source' => 'skip'];

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 9.1 SYNC REGELING + TOERISTBEL: PARTICIPANT", "[PID: $part_id]");
    wachthond($extdebug, 2, "########################################################################");
/*
    // Haal huidige waarden op
    $params_part_get = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['PART_KAMPGELD.regeling', 'PART_KAMPGELD.toeristenbelasting'],
        'where'             => [['id', '=', $part_id]],
    ];
    wachthond($extdebug, 7, 'params_part_get',                  $params_part_get);
    $result_part_get = civicrm_api4('Participant', 'get',       $params_part_get);
    wachthond($extdebug, 9, 'result_part_get',                  $result_part_get);

    $huidig_regeling   = $result_part_get->first()['PART_KAMPGELD.regeling']       ?? NULL;
    $huidig_toeristbel = $result_part_get->first()['PART_KAMPGELD.toeristenbelasting']  ?? NULL;
    wachthond($extdebug, 3, 'huidig_regeling',                  $huidig_regeling);
    wachthond($extdebug, 3, 'huidig_toeristbel',                $huidig_toeristbel);

    $part_values = [];
    if ($huidig_regeling !== 'ja_stgave') {
        $part_values['PART_KAMPGELD.regeling'] = 'ja_stgave';
    }
    // Toeristenbelasting: 3 = 'Nee, via St.Gave'
    if ((int)$huidig_toeristbel !== 3) {
        $part_values['PART_KAMPGELD.toeristenbelasting'] = 3;
    }

    if (!empty($part_values)) {
        wachthond($extdebug, 3, 'part_values (te updaten)',     $part_values);
        $params_part_update = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'where'             => [['id', '=', $part_id]],
            'values'            => $part_values,
        ];
        wachthond($extdebug, 7, 'params_part_update',           $params_part_update);
        if ($extwrite == 1) {
            $result_part_update = civicrm_api4('Participant', 'update', $params_part_update);
            wachthond($extdebug, 9, 'result_part_update',       $result_part_update);
        }
        wachthond($extdebug, 1, "Participant regeling/toeristbel bijgewerkt",    "[PID: $part_id]");
        $resultaat['participant'] = 'update';
    } else {
        wachthond($extdebug, 1, "Participant regeling/toeristbel al correct",    "[PID: $part_id]");
        $resultaat['participant'] = 'ok';
    }
*/
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 9.2 SYNC SOURCE: CONTRIBUTION",           "[PID: $part_id]");
    wachthond($extdebug, 2, "########################################################################");

    // Haal contrib_id op — pecunia eerst, daarna eigen fallback
    $contrib_id = NULL;
    if (function_exists('pecunia_get_contribid')) {
        $contrib_id = pecunia_get_contribid($part_array);
    }
    if (empty($contrib_id) && function_exists('stgave_get_contribid_fallback')) {
        $contrib_id = stgave_get_contribid_fallback($part_id);
    }

    if (empty($contrib_id)) {
        wachthond($extdebug, 1, "SKIP contribution source: geen contrib_id",     "[PID: $part_id]");
        return $resultaat;
    }

    // Ophalen huidige bron
    $params_cont_get = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['source'],
        'where'             => [['id', '=', $contrib_id]],
    ];
    wachthond($extdebug, 7, 'params_cont_get',                   $params_cont_get);
    $result_cont_get = civicrm_api4('Contribution', 'get',        $params_cont_get);
    wachthond($extdebug, 9, 'result_cont_get',                   $result_cont_get);

    $huidig_source = $result_cont_get->first()['source'] ?? NULL;

    // Update bron indien nodig
    if ($huidig_source !== 'Aanmelden St.Gave') {
        $params_cont_update = [
            'checkPermissions'  => FALSE,
            'where'             => [['id', '=', $contrib_id]],
            'values'            => ['source' => 'Aanmelden St.Gave'],
        ];
        wachthond($extdebug, 7, 'params_cont_update (source)',   $params_cont_update);
        if ($extwrite == 1) {
            civicrm_api4('Contribution', 'update', $params_cont_update);
        }
        wachthond($extdebug, 1, "Contribution source bijgewerkt naar 'Aanmelden St.Gave'", "[BID: $contrib_id]");
        $resultaat['contribution_source'] = 'update';
    } else {
        $resultaat['contribution_source'] = 'ok';
        wachthond($extdebug, 3, "Contribution source al correct",                "[BID: $contrib_id]");
    }

    if (function_exists('stgave_sync_lineitems')) {
        
        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### STGAVE 9.3 START FINANCIËLE REPARATIE-CHECK",    "[BID: $contrib_id]");
        wachthond($extdebug, 2, "########################################################################");

        // De sync-functie controleert nu zelf op saldo-onbalans en gereset betalingen[cite: 3]
        $resultaat['lineitems'] = stgave_sync_lineitems($contact_id, $part_array);
    }

    return $resultaat;
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
        'reload'            => FALSE,
        'debug'             => $apidebug,
        'where'             => [['id', '=', $part_id]],
        'values'            => ['role_id' => $nieuwe_rollen],
    ];
    wachthond($extdebug, 7, 'params_part_update',            $params_part_update);
    $result_part_update = civicrm_api4('Participant', 'update', $params_part_update);
    wachthond($extdebug, 9, 'result_part_update',            $result_part_update ?? 'SKIPPED');
    wachthond($extdebug, 1, "Participant rollen gezet op 7 + 16",                    "[PID: $part_id]");

    return ['actie' => 'update', 'rollen' => $nieuwe_rollen];
}
