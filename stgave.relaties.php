<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: stgave.relaties.php
 * =======================================================================================
 *   stgave_get_relaties()          Haalt gavecontact_id + ouder_id op in één aanroep.
 *   stgave_get_gavecontact()       Backwards-compat alias → roept stgave_get_relaties() aan.
 *   stgave_ensure_childof()        Maakt 'Child of' (type 1) aan als die ontbreekt.
 *   stgave_ensure_stgave_ouder()   Maakt 'StGave Ouder via' (type 21) aan als die ontbreekt.
 *   _stgave_ensure_rel_permission() Interne helper: zet is_permission_b_a op relatie als nodig.
 * =======================================================================================
 */

/**
 * =======================================================================================
 * COLOFON: stgave_get_relaties
 * =======================================================================================
 * @description     Centrale data-laag voor ST.GAVE relaties. Retourneert:
 *
 *                  gavecontact_id  — contactpersoon via relatietype 20 (StGave Deelnemer via).
 *                                    contact_a = deelnemer, contact_b = contactpersoon.
 *
 *                  ouder_id        — bepaald via twee stappen:
 *                    STAP 1: "Child of" (type 1) rechtstreeks van deelnemer (contact_a).
 *                    STAP 2 (fallback): "StGave Ouder via" (type 21) via de contactpersoon:
 *                              contact_b = contactpersoon, contact_a = ouder.
 *                              Alleen gebruikt bij exact 1 resultaat.
 *
 *                  ouder_bron      — 'child_of' | 'stgave_ouder_via' | NULL
 *
 * @param int       $contact_id     Het contact ID van de deelnemer.
 * @return array                    [
 *                                    'gavecontact_id'  => int|null,
 *                                    'gavecontact_relid' => int|null,
 *                                    'ouder_id'        => int|null,
 *                                    'ouder_bron'      => string|null,
 *                                  ]
 * =======================================================================================
 */
function stgave_get_relaties(int $contact_id): array {

    $extdebug = 'stgave.relaties';
    $apidebug = FALSE;

    $relaties = [
        'gavecontact_id'    => NULL,
        'gavecontact_relid' => NULL,
        'ouder_id'          => NULL,
        'ouder_bron'        => NULL,
    ];

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 1.0 OPHALEN RELATIES",                        "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    // -----------------------------------------------------------------------
    // 1.1 Gavecontact via type 20 (StGave Deelnemer via)
    // -----------------------------------------------------------------------
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 1.1 GAVECONTACT VIA TYPE 20",                 "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $params_rel_type20 = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['id', 'contact_id_a', 'contact_id_b', 'is_permission_b_a'],
        'where'             => [
            ['contact_id_a',          '=',  $contact_id],
            ['relationship_type_id',  '=',  20],    // StGave Deelnemer via
            ['is_active',             '=',  TRUE],
        ],
    ];
    wachthond($extdebug, 7, 'params_rel_type20',          $params_rel_type20);
    $result_rel_type20 = civicrm_api4('Relationship', 'get', $params_rel_type20);
    wachthond($extdebug, 9, 'result_rel_type20',          $result_rel_type20);

    $count20 = $result_rel_type20->count();

    if ($count20 >= 1) {
        $rec20 = $result_rel_type20->first();
        $relaties['gavecontact_id']    = $rec20['contact_id_b'];
        $relaties['gavecontact_relid'] = $rec20['id'];
        if ($count20 > 1) {
            wachthond($extdebug, 1, "WAARSCHUWING: $count20 actieve gave-contactpersonen (type 20)", "[CID: $contact_id]");
        }
        wachthond($extdebug, 1, "Gavecontact gevonden",   "[GC: {$relaties['gavecontact_id']}]");
        // Zorg dat contactpersoon (b) deelnemer (a) kan inzien + wijzigen
        _stgave_ensure_rel_permission($rec20['id'], (int)($rec20['is_permission_b_a'] ?? 0), 2, $extdebug, $apidebug, 1);
    } else {
        wachthond($extdebug, 1, "Geen gavecontact gevonden (type 20)",                "[CID: $contact_id]");
    }

    // -----------------------------------------------------------------------
    // 1.2 Ouder via 'Child of' (type 1) — primair
    // -----------------------------------------------------------------------
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 1.2 OUDER VIA CHILD OF (TYPE 1)",             "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $params_rel_childof = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['id', 'contact_id_b'],
        'where'             => [
            ['contact_id_a',          '=',  $contact_id],
            ['relationship_type_id',  '=',  1],     // Child of
            ['is_active',             '=',  TRUE],
        ],
        'orderBy'           => ['id' => 'DESC'],    // Nieuwste relatie als er meerdere zijn
        'limit'             => 1,
    ];
    wachthond($extdebug, 7, 'params_rel_childof',         $params_rel_childof);
    $result_rel_childof = civicrm_api4('Relationship', 'get', $params_rel_childof);
    wachthond($extdebug, 9, 'result_rel_childof',         $result_rel_childof);

    if ($result_rel_childof->count() === 1) {
        $relaties['ouder_id']   = $result_rel_childof->first()['contact_id_b'];
        $relaties['ouder_bron'] = 'child_of';
        wachthond($extdebug, 1, "Ouder gevonden via Child of",  "[OUD: {$relaties['ouder_id']}]");
    }

    // -----------------------------------------------------------------------
    // 1.3 Ouder via 'StGave Ouder via' (type 21) — fallback
    // -----------------------------------------------------------------------
    if (empty($relaties['ouder_id']) && !empty($relaties['gavecontact_id'])) {

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### STGAVE 1.3 OUDER VIA STGAVE OUDER VIA (TYPE 21) FALLBACK", "[GC: {$relaties['gavecontact_id']}]");
        wachthond($extdebug, 2, "########################################################################");

        // contact_a = ouder, contact_b = contactpersoon bij type 21
        $params_rel_type21 = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'select'            => ['id', 'contact_id_a'],
            'where'             => [
                ['contact_id_b',          '=',  $relaties['gavecontact_id']],
                ['relationship_type_id',  '=',  21],    // StGave Ouder via
                ['is_active',             '=',  TRUE],
            ],
        ];
        wachthond($extdebug, 7, 'params_rel_type21',      $params_rel_type21);
        $result_rel_type21 = civicrm_api4('Relationship', 'get', $params_rel_type21);
        wachthond($extdebug, 9, 'result_rel_type21',      $result_rel_type21);

        $count21 = $result_rel_type21->count();
        wachthond($extdebug, 3, 'count type21 ouders', $count21);

        if ($count21 === 1) {
            $relaties['ouder_id']   = $result_rel_type21->first()['contact_id_a'];
            $relaties['ouder_bron'] = 'stgave_ouder_via';
            wachthond($extdebug, 1, "Ouder gevonden via StGave Ouder via", "[OUD: {$relaties['ouder_id']}]");
        } elseif ($count21 === 0) {
            wachthond($extdebug, 1, "SKIP: Geen ouder via type 21",        "[GC: {$relaties['gavecontact_id']}]");
        } else {
            wachthond($extdebug, 1, "SKIP: $count21 ouders via type 21 — niet uniek bepaalbaar", "[GC: {$relaties['gavecontact_id']}]");
        }
    }

    wachthond($extdebug, 3, 'relaties',                   $relaties);
    return $relaties;
}

/**
 * =======================================================================================
 * COLOFON: stgave_get_gavecontact
 * =======================================================================================
 * @description     Backwards-compat alias voor stgave_get_relaties(). Retourneert alleen
 *                  de gavecontact-sleutels in het oude formaat ['contact_id', 'relid'].
 *
 * @param int       $contact_id
 * @return array|null
 * =======================================================================================
 */
function stgave_get_gavecontact(int $contact_id): ?array {
    $rel = stgave_get_relaties($contact_id);
    if (empty($rel['gavecontact_id'])) {
        return NULL;
    }
    return ['contact_id' => $rel['gavecontact_id'], 'relid' => $rel['gavecontact_relid']];
}

/**
 * =======================================================================================
 * COLOFON: stgave_ensure_childof
 * =======================================================================================
 * @description     Zorgt dat er een actieve 'Child of' (type 1) relatie bestaat tussen
 *                  deelnemer ($contact_id, contact_a) en ouder ($ouder_id, contact_b).
 *                  Maakt de relatie aan als die nog niet bestaat.
 *
 * @param int       $contact_id     Het contact ID van de deelnemer (kind).
 * @param int       $ouder_id       Het contact ID van de ouder.
 * @return string                   'ok' | 'create' | 'skip'
 * =======================================================================================
 */
function stgave_ensure_childof(int $contact_id, int $ouder_id): string {

    $extdebug = 'stgave.relaties';
    $apidebug = FALSE;
    $extwrite = 1;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 1.4 ENSURE CHILD OF RELATIE",      "[CID: $contact_id → OUD: $ouder_id]");
    wachthond($extdebug, 2, "########################################################################");

    // Controleer of de relatie al bestaat (actief of inactief)
    $params_childof_check = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['id', 'is_active', 'is_permission_b_a'],
        'where'             => [
            ['contact_id_a',          '=', $contact_id],
            ['contact_id_b',          '=', $ouder_id],
            ['relationship_type_id',  '=', 1],          // Child of
        ],
        'limit'             => 1,
    ];
    wachthond($extdebug, 7, 'params_childof_check',       $params_childof_check);
    $result_childof_check = civicrm_api4('Relationship', 'get', $params_childof_check);
    wachthond($extdebug, 9, 'result_childof_check',       $result_childof_check);

    if ($result_childof_check->count() > 0) {
        $bestaand = $result_childof_check->first();
        if ($bestaand['is_active']) {
            wachthond($extdebug, 1, "Child of al actief aanwezig",          "[REL: {$bestaand['id']}]");
            // Controleer en herstel permission
            _stgave_ensure_rel_permission($bestaand['id'], (int)($bestaand['is_permission_b_a'] ?? 0), 2, $extdebug, $apidebug, $extwrite);
            return 'ok';
        }
        // Inactieve relatie opnieuw activeren + permission zetten
        $params_childof_reactivate = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'where'             => [['id', '=', $bestaand['id']]],
            'values'            => [
                'is_active'          => TRUE,
                'is_permission_b_a'  => 2,    // ouder (b) kan deelnemer (a) wijzigen
            ],
        ];
        wachthond($extdebug, 7, 'params_childof_reactivate',   $params_childof_reactivate);
        if ($extwrite == 1) {
            civicrm_api4('Relationship', 'update', $params_childof_reactivate);
        }
        wachthond($extdebug, 1, "Child of geheractiveerd + permission gezet", "[REL: {$bestaand['id']}]");
        return 'create';
    }

    // Aanmaken nieuwe Child of relatie met permission
    $params_childof_create = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'values'            => [
            'contact_id_a'          => $contact_id,    // kind (a)
            'contact_id_b'          => $ouder_id,      // ouder (b)
            'relationship_type_id'  => 1,              // Child of
            'is_active'             => TRUE,
            'is_permission_b_a'     => 2,              // ouder (b) kan deelnemer (a) inzien + wijzigen
        ],
    ];
    wachthond($extdebug, 7, 'params_childof_create',      $params_childof_create);
    if ($extwrite == 1) {
        $result_childof_create = civicrm_api4('Relationship', 'create', $params_childof_create);
        wachthond($extdebug, 9, 'result_childof_create',  $result_childof_create);
    }
    wachthond($extdebug, 1, "Child of aangemaakt (permission b_a=2)",   "[CID: $contact_id → OUD: $ouder_id]");
    return 'create';
}

/**
 * =======================================================================================
 * COLOFON: stgave_ensure_stgave_ouder
 * =======================================================================================
 * @description     Zorgt dat er een actieve 'StGave Ouder via' (type 21) relatie bestaat
 *                  tussen de ouder ($ouder_id, contact_a) en de gave-contactpersoon
 *                  ($gavecontact_id, contact_b).
 *
 *                  Typisch scenario: de ouder is gevonden via 'Child of' (type 1) maar
 *                  heeft nog geen type 21 relatie naar de gave-contactpersoon. De extensie
 *                  maakt die dan automatisch aan zodat de driehoek compleet is:
 *                    Deelnemer → (20) → Contactpersoon
 *                    Ouder     → (21) → Contactpersoon  ← deze functie
 *                    Deelnemer → (1)  → Ouder
 *
 * @param int       $ouder_id           Het contact ID van de ouder.
 * @param int       $gavecontact_id     Het contact ID van de gave-contactpersoon.
 * @return string                       'ok' | 'create' | 'skip'
 * =======================================================================================
 */
function stgave_ensure_stgave_ouder(int $ouder_id, int $gavecontact_id): string {

    $extdebug = 'stgave.relaties';
    $apidebug = FALSE;
    $extwrite = 1;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 1.5 ENSURE STGAVE OUDER VIA (TYPE 21)", "[OUD: $ouder_id → GC: $gavecontact_id]");
    wachthond($extdebug, 2, "########################################################################");

    // Controleer of de relatie al bestaat (actief of inactief)
    $params_type21_check = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['id', 'is_active', 'is_permission_b_a'],
        'where'             => [
            ['contact_id_a',          '=', $ouder_id],        // ouder = a
            ['contact_id_b',          '=', $gavecontact_id],  // contactpersoon = b
            ['relationship_type_id',  '=', 21],               // StGave Ouder via
        ],
        'limit'             => 1,
    ];
    wachthond($extdebug, 7, 'params_type21_check',        $params_type21_check);
    $result_type21_check = civicrm_api4('Relationship', 'get', $params_type21_check);
    wachthond($extdebug, 9, 'result_type21_check',        $result_type21_check);

    if ($result_type21_check->count() > 0) {
        $bestaand = $result_type21_check->first();
        if ($bestaand['is_active']) {
            wachthond($extdebug, 1, "StGave Ouder via al actief aanwezig",  "[REL: {$bestaand['id']}]");
            // Controleer en herstel permission
            _stgave_ensure_rel_permission($bestaand['id'], (int)($bestaand['is_permission_b_a'] ?? 0), 2, $extdebug, $apidebug, $extwrite);
            return 'ok';
        }
        // Inactieve relatie opnieuw activeren + permission zetten
        $params_type21_reactivate = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'where'             => [['id', '=', $bestaand['id']]],
            'values'            => [
                'is_active'          => TRUE,
                'is_permission_b_a'  => 2,    // contactpersoon (b) kan ouder (a) wijzigen
            ],
        ];
        wachthond($extdebug, 7, 'params_type21_reactivate',    $params_type21_reactivate);
        if ($extwrite == 1) {
            civicrm_api4('Relationship', 'update', $params_type21_reactivate);
        }
        wachthond($extdebug, 1, "StGave Ouder via geheractiveerd + permission gezet", "[REL: {$bestaand['id']}]");
        return 'create';
    }

    // Aanmaken nieuwe StGave Ouder via relatie met permission
    $params_type21_create = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'values'            => [
            'contact_id_a'          => $ouder_id,           // ouder (a)
            'contact_id_b'          => $gavecontact_id,     // contactpersoon (b)
            'relationship_type_id'  => 21,                  // StGave Ouder via
            'is_active'             => TRUE,
            'is_permission_b_a'     => 2,                   // contactpersoon (b) kan ouder (a) inzien + wijzigen
        ],
    ];
    wachthond($extdebug, 7, 'params_type21_create',       $params_type21_create);
    if ($extwrite == 1) {
        $result_type21_create = civicrm_api4('Relationship', 'create', $params_type21_create);
        wachthond($extdebug, 9, 'result_type21_create',   $result_type21_create);
    }
    wachthond($extdebug, 1, "StGave Ouder via aangemaakt (permission b_a=2)", "[OUD: $ouder_id → GC: $gavecontact_id]");
    return 'create';
}

/**
 * =======================================================================================
 * COLOFON: _stgave_ensure_rel_permission
 * =======================================================================================
 * @description     Interne helper. Controleert of is_permission_b_a de gewenste waarde
 *                  heeft op een bestaande relatie. Werkt bij als dat niet het geval is.
 *
 *                  Waarden: 0 = Geen | 1 = Inzien | 2 = Inzien + wijzigen
 *
 * @param int       $rel_id         Relationship ID.
 * @param int       $huidig         Huidige waarde van is_permission_b_a.
 * @param int       $gewenst        Gewenste waarde (default 2 = inzien + wijzigen).
 * @return void
 * =======================================================================================
 */
function _stgave_ensure_rel_permission(int $rel_id, int $huidig, int $gewenst, string $extdebug, bool $apidebug, int $extwrite): void {
    if ($huidig === $gewenst) {
        wachthond($extdebug, 3, "Permission b_a al correct ($gewenst)",   "[REL: $rel_id]");
        return;
    }
    wachthond($extdebug, 3, "Permission b_a bijwerken: $huidig → $gewenst", "[REL: $rel_id]");
    $params_perm_update = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'where'             => [['id', '=', $rel_id]],
        'values'            => ['is_permission_b_a' => $gewenst],
    ];
    wachthond($extdebug, 7, 'params_perm_update',     $params_perm_update);
    if ($extwrite == 1) {
        civicrm_api4('Relationship', 'update', $params_perm_update);
    }
    wachthond($extdebug, 1, "Permission b_a bijgewerkt naar $gewenst",   "[REL: $rel_id]");
}
