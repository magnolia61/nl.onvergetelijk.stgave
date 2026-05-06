<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: stgave.php
 * =======================================================================================
 *   stgave_civicrm_config()    Boilerplate.
 *   stgave_civicrm_install()   Boilerplate.
 *   stgave_civicrm_enable()    Boilerplate.
 *   stgave_civicrm_post()      Trigger via participant post-hook.
 * =======================================================================================
 */

/**
 * =======================================================================================
 * EXTENSIE: nl.onvergetelijk.stgave
 * =======================================================================================
 * ### FUNCTIONELE OMSCHRIJVING
 * De Stgave-module beheert alle logica rondom St.Gave deelnemers:
 * - Detecteert ST.GAVE contacten via subtype of tag.
 * - Synchroniseert het telefoonnummer en e-mailadres van de gave-contactpersoon
 *   (relatietype 20) naar locatietype 'Gave' (26) op de deelnemer.
 * - Zorgt dat de 3 St.Gave line items aanwezig zijn op de contribution
 *   (Kampgeld St.Gave €175, Korting fonds €-55, Bijdrage St.Gave €-120).
 * - Zet de kampgeldregeling op 'ja_stgave' in de contribution.
 *
 * ### TECHNISCHE OMSCHRIJVING
 * Werkt via een centrale configure-functie (stgave_civicrm_configure) die:
 * - Aangeroepen wordt vanuit nl.onvergetelijk.core (vervangt CORE 4.9 A/B).
 * - Ook direct triggert via de Participant post-hook.
 * =======================================================================================
 */

require_once 'stgave.civix.php';
require_once 'stgave.helpers.php';
require_once 'stgave.relaties.php';    // stgave_get_relaties, stgave_ensure_childof
require_once 'stgave.telefoon.php';    // Gave Mobiel + OUD1 Mobiel
require_once 'stgave.email.php';       // Gave Email  + OUD1 Email
require_once 'stgave.lineitems.php';

use CRM_Stgave_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function stgave_civicrm_config(&$config): void {
    _stgave_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function stgave_civicrm_install(): void {
    _stgave_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function stgave_civicrm_enable(): void {
    _stgave_civix_civicrm_enable();
}

/**
 * =======================================================================================
 * COLOFON: stgave_civicrm_post
 * =======================================================================================
 * @description     Triggert de St.Gave motor bij het aanmaken of wijzigen van een
 *                  Participant record. Haalt de volledige part_array op via base_pid2part()
 *                  en roept stgave_civicrm_configure() aan. Dit zorgt ook dat relatie
 *                  permissions (type 20/21) worden gecheckt en gecorrigeerd.
 * =======================================================================================
 */
function stgave_civicrm_post(string $op, string $objectName, int $objectId, &$objectRef): void {

    static $processing_stgave_post = FALSE;
    if ($processing_stgave_post) { return; }

    if ($objectName !== 'Participant' || !in_array($op, ['create', 'edit'])) {
        return;
    }

    $extdebug = 'stgave.post'; // Kanaal voor centrale debug-config

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE POST HOOK PARTICIPANT",          "[OP: $op | PID: $objectId]");
    wachthond($extdebug, 2, "########################################################################");

    if (!function_exists('base_pid2part')) {
        wachthond($extdebug, 1, "SKIP: base_pid2part() niet beschikbaar",    "[PID: $objectId]");
        return;
    }

    $processing_stgave_post = TRUE;

    // try/finally garandeert dat de re-entry guard altijd vrijgegeven wordt,
    // ook als stgave_civicrm_configure() een exception gooit.
    try {
        $part_array = base_pid2part($objectId);
        wachthond($extdebug, 3, 'part_array (stgave post)',  ['id' => $part_array['id'] ?? NULL, 'contact_id' => $part_array['contact_id'] ?? NULL]);

        $contact_id = $part_array['contact_id'] ?? NULL;
        if (empty($contact_id)) {
            wachthond($extdebug, 1, "SKIP: Geen contact_id in part_array",   "[PID: $objectId]");
            return;
        }

        stgave_civicrm_configure($contact_id, $part_array);
    } finally {
        $processing_stgave_post = FALSE;
    }
}
