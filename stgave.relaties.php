<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: stgave.relaties.php
 * =======================================================================================
 *   stgave_get_gavecontact()  Haalt de actieve gave-contactpersoon op via relatie type 20.
 * =======================================================================================
 */

/**
 * =======================================================================================
 * COLOFON: stgave_get_gavecontact
 * =======================================================================================
 * @description     Haalt de actieve gave-contactpersoon op via relatietype 20.
 *                  Verplaatst vanuit nl.onvergetelijk.core/core.php (CORE 4.9 A).
 *
 * @param int       $contact_id     Het contact ID van de deelnemer.
 * @return array|null               ['contact_id' => int, 'relid' => int] of NULL bij fout.
 * =======================================================================================
 */
function stgave_get_gavecontact(int $contact_id): ?array {

    $extdebug = 'stgave.relaties'; // Kanaal voor centrale debug-config
    $apidebug = FALSE;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 1.0 OPHALEN GAVE-CONTACTPERSOON RELATIE",    "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $params_get_rel_gavecontact = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => [
            'row_count', 'id', 'contact_id_a', 'contact_id_b', 'is_active',
        ],
        'where'             => [
            ['contact_id_a',        '=',  $contact_id],
            ['relationship_type_id','=',  20],         // Type 20 = gave-contactpersoon
            ['is_active',           '=',  TRUE],
        ],
    ];
    wachthond($extdebug, 7, 'params_get_rel_gavecontact',   $params_get_rel_gavecontact);
    $result_get_rel_gavecontact = civicrm_api4('Relationship', 'get', $params_get_rel_gavecontact);
    wachthond($extdebug, 9, 'result_get_rel_gavecontact',   $result_get_rel_gavecontact);

    $count = $result_get_rel_gavecontact->countMatched();

    if ($count === 1) {
        $gavecontact_id    = $result_get_rel_gavecontact[0]['contact_id_b'] ?? NULL;
        $gavecontact_relid = $result_get_rel_gavecontact[0]['id']           ?? NULL;
        wachthond($extdebug, 1, "PRIMA: 1 actieve gave-contactpersoon gevonden",
                  "[contact_b: $gavecontact_id | relid: $gavecontact_relid]");
        return ['contact_id' => $gavecontact_id, 'relid' => $gavecontact_relid];
    }

    if ($count > 1) {
        wachthond($extdebug, 1, "ERROR: Meer dan 1 actieve gave-contactpersoon gevonden", "[count: $count]");
        return NULL;
    }

    wachthond($extdebug, 1, "INFO: Geen actieve gave-contactpersoon gevonden (geen relatie type 20)", "[CID: $contact_id]");
    return NULL;
}
