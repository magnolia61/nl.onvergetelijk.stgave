<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: stgave.lineitems.php
 * =======================================================================================
 *   stgave_sync_lineitems()         Zorgt dat de 3 St.Gave line items op de contribution staan.
 *                                   Maakt contribution aan als die ontbreekt.
 *   stgave_maak_contribution()      Maakt een nieuwe €0 contribution aan voor een ST.GAVE deelnemer.
 *   stgave_get_contribid_fallback() Fallback contrib-lookup via LineItem als pecunia ontbreekt.
 * =======================================================================================
 */

/**
 * =======================================================================================
 * DEFINITIELIJST: St.Gave price field waarden (price set 9 = "Kampgeld")
 * =======================================================================================
 *
 *  Kampgeld St.Gave    price_field_id=51  price_field_value_id=497  €  175  fin.type=4
 *  Korting uit fonds   price_field_id=28  price_field_value_id=443  € -55   fin.type=19
 *  Bijdrage St.Gave    price_field_id=101 price_field_value_id=523  € -120  fin.type=23
 *
 * Netto: € 0,00
 * =======================================================================================
 */

/**
 * =======================================================================================
 * COLOFON: stgave_sync_lineitems
 * =======================================================================================
 * @description     Controleert of de 3 St.Gave line items aanwezig zijn op de contribution
 *                  van de deelnemer. Mist een item, dan wordt het aangemaakt.
 *                  Wordt alleen uitgevoerd als er een actieve participant + contribution is.
 *
 * @param int       $contact_id     Het contact ID van de deelnemer.
 * @param array     $part_array     De volledige array vanuit base_pid2part().
 * @return array                    Statusarray per line item.
 * =======================================================================================
 */
function stgave_sync_lineitems(int $contact_id, array $part_array): array {

    $extdebug = 'stgave.lineitems'; // Kanaal voor centrale debug-config
    $apidebug = FALSE;
    $extwrite = 1;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 4.0 SYNC LINEITEMS ST.GAVE",                "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $part_id = $part_array['id'] ?? NULL;
    if (empty($part_id)) {
        wachthond($extdebug, 1, "SKIP: Geen participant ID in part_array",           "[CID: $contact_id]");
        return ['actie' => 'skip', 'reden' => 'geen part_id'];
    }

    // Haal contribution ID op via de pecunia helper (hergebruik bestaande logica)
    $contrib_id = NULL;
    if (function_exists('pecunia_get_contribid')) {
        $contrib_id = pecunia_get_contribid($part_array);
    } else {
        $contrib_id = stgave_get_contribid_fallback($part_id);
    }

    wachthond($extdebug, 3, 'contrib_id',                    $contrib_id);

    if (empty($contrib_id)) {
        wachthond($extdebug, 1, "Geen contribution gevonden — aanmaken voor ST.GAVE deelnemer", "[PID: $part_id]");
        $contrib_id = stgave_maak_contribution($contact_id, $part_array, $extdebug, $apidebug, $extwrite);
        if (empty($contrib_id)) {
            wachthond($extdebug, 1, "SKIP: Aanmaken contribution mislukt",              "[PID: $part_id]");
            return ['actie' => 'skip', 'reden' => 'contrib aanmaken mislukt'];
        }
        wachthond($extdebug, 1, "Contribution aangemaakt",                              "[BID: $contrib_id]");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 4.1 OPHALEN BESTAANDE ST.GAVE LINE ITEMS", "[BID: $contrib_id]");
    wachthond($extdebug, 2, "########################################################################");

    // De 3 verwachte St.Gave line items (price_field_id => [value_id, label, amount, fin_type])
    $verwachte_items = [
        51  => ['value_id' => 497, 'label' => 'Kampgeld St.Gave',       'amount' =>  175, 'financial_type_id' =>  4],
        28  => ['value_id' => 443, 'label' => 'Korting 55 euro',         'amount' =>  -55, 'financial_type_id' => 19],
        101 => ['value_id' => 523, 'label' => '120 euro vanuit St.Gave', 'amount' => -120, 'financial_type_id' => 23],
    ];

    $params_lineitem_get = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => [
            'id', 'price_field_id', 'price_field_value_id', 'label', 'unit_price', 'qty',
        ],
        'where'             => [
            ['contribution_id', '=', $contrib_id],
            ['entity_table',    '=', 'civicrm_participant'],
            ['entity_id',       '=', $part_id],
            ['price_field_id',  'IN', [51, 28, 101]],
        ],
    ];
    wachthond($extdebug, 7, 'params_lineitem_get',           $params_lineitem_get);
    $result_lineitem_get = civicrm_api4('LineItem', 'get',   $params_lineitem_get);
    wachthond($extdebug, 9, 'result_lineitem_get',           $result_lineitem_get);

    // Bouw een lookup: price_field_id => bestaand line item record
    $bestaande_items = [];
    foreach ($result_lineitem_get as $li) {
        $bestaande_items[$li['price_field_id']] = $li;
    }
    wachthond($extdebug, 3, 'bestaande_items (indexed op pfid)', $bestaande_items);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 4.2 CREATE ONTBREKENDE ST.GAVE LINE ITEMS", "[BID: $contrib_id]");
    wachthond($extdebug, 2, "########################################################################");

    $resultaten = [];

    foreach ($verwachte_items as $price_field_id => $spec) {

        if (isset($bestaande_items[$price_field_id])) {
            $bestaand    = $bestaande_items[$price_field_id];
            $waarde_ok   = (int)$bestaand['price_field_value_id'] === $spec['value_id'];
            $bedrag_ok   = (float)$bestaand['unit_price']          === (float)$spec['amount'];

            if ($waarde_ok && $bedrag_ok) {
                wachthond($extdebug, 1, "Line item al correct",
                          "[pfid: $price_field_id | val: {$spec['value_id']} | €{$spec['amount']}]");
                $resultaten[$price_field_id] = 'ok';
                continue;
            }

            // Waarden kloppen niet: updaten
            wachthond($extdebug, 1, "Line item onjuist — updaten",
                      "[pfid: $price_field_id | was value_id: {$bestaand['price_field_value_id']}, unit_price: {$bestaand['unit_price']} | wordt: {$spec['value_id']}, €{$spec['amount']}]");

            $params_lineitem_update = [
                'checkPermissions'  => FALSE,
                'debug'             => $apidebug,
                'where'             => [['id', '=', $bestaand['id']]],
                'values'            => [
                    'price_field_value_id'  => $spec['value_id'],
                    'label'                 => $spec['label'],
                    'qty'                   => 1,
                    'unit_price'            => $spec['amount'],
                    'line_total'            => $spec['amount'],
                    'financial_type_id'     => $spec['financial_type_id'],
                ],
            ];
            wachthond($extdebug, 7, "params_lineitem_update [pfid: $price_field_id]",  $params_lineitem_update);
            if ($extwrite == 1) {
                $result_lineitem_update = civicrm_api4('LineItem', 'update', $params_lineitem_update);
            }
            wachthond($extdebug, 9, "result_lineitem_update [pfid: $price_field_id]",  $result_lineitem_update ?? 'SKIPPED');
            $resultaten[$price_field_id] = 'updated';
            continue;
        }

        // Item ontbreekt: aanmaken
        wachthond($extdebug, 1, "Line item ONTBREEKT — aanmaken",
                  "[pfid: $price_field_id | label: {$spec['label']} | €{$spec['amount']}]");

        $params_lineitem_create = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'values'            => [
                'contribution_id'       => $contrib_id,
                'entity_table'          => 'civicrm_participant',
                'entity_id'             => $part_id,
                'price_field_id'        => $price_field_id,
                'price_field_value_id'  => $spec['value_id'],
                'label'                 => $spec['label'],
                'qty'                   => 1,
                'unit_price'            => $spec['amount'],
                'line_total'            => $spec['amount'],
                'financial_type_id'     => $spec['financial_type_id'],
            ],
        ];
        wachthond($extdebug, 7, "params_lineitem_create [pfid: $price_field_id]",   $params_lineitem_create);
        if ($extwrite == 1) {
            $result_lineitem_create = civicrm_api4('LineItem', 'create', $params_lineitem_create);
        }
        wachthond($extdebug, 9, "result_lineitem_create [pfid: $price_field_id]",   $result_lineitem_create ?? 'SKIPPED');
        $resultaten[$price_field_id] = 'created';
    }

    wachthond($extdebug, 3, 'stgave lineitems resultaten',   $resultaten);
    return $resultaten;
}

/**
 * =======================================================================================
 * COLOFON: stgave_maak_contribution
 * =======================================================================================
 * @description     Maakt een nieuwe €0 contribution aan voor een ST.GAVE deelnemer die
 *                  nog geen contribution heeft. Koppelt de contribution aan de participant
 *                  via ParticipantPayment en legt de price set (9) vast via PriceSetEntity.
 *
 * @param int       $contact_id     Het contact ID van de deelnemer.
 * @param array     $part_array     De volledige array vanuit base_pid2part().
 * @param string    $extdebug       Debug-kanaal voor wachthond.
 * @param bool      $apidebug       Debug-vlag voor API-calls.
 * @param int       $extwrite       1 = schrijven actief, 0 = dry-run.
 * @return int|null                 Het nieuwe contribution ID, of NULL bij fout.
 * =======================================================================================
 */
function stgave_maak_contribution(int $contact_id, array $part_array, string $extdebug, bool $apidebug, int $extwrite): ?int {

    $part_id = $part_array['id'] ?? NULL;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE 4.0a AANMAKEN CONTRIBUTION VOOR ST.GAVE DEELNEMER", "[PID: $part_id]");
    wachthond($extdebug, 2, "########################################################################");

    $params_contrib_create = [
        'checkPermissions'          => FALSE,
        'debug'                     => $apidebug,
        'values'                    => [
            'contact_id'            => $contact_id,
            'financial_type_id'     => 4,                           // Kampgeld
            'total_amount'          => 0,                           // Netto €0 (stgave betaalt)
            'receive_date'          => date('Y-m-d H:i:s'),
            'contribution_status_id:name' => 'Completed',
            'payment_instrument_id:name'  => 'Check',              // geen echte betaling
            'source'                => 'St.Gave — automatisch aangemaakt door nl.onvergetelijk.stgave',
        ],
    ];
    wachthond($extdebug, 7, 'params_contrib_create',                $params_contrib_create);

    if ($extwrite != 1) {
        wachthond($extdebug, 1, "DRY-RUN: contribution NIET aangemaakt",                "[PID: $part_id]");
        return NULL;
    }

    try {
        $result_contrib_create = civicrm_api4('Contribution', 'create', $params_contrib_create);
        wachthond($extdebug, 9, 'result_contrib_create',            $result_contrib_create);
    } catch (\Exception $e) {
        wachthond($extdebug, 1, "FOUT bij aanmaken contribution: " . $e->getMessage(),  "[PID: $part_id]");
        return NULL;
    }

    $contrib_id = $result_contrib_create->first()['id'] ?? NULL;
    if (empty($contrib_id)) {
        wachthond($extdebug, 1, "FOUT: Geen contribution ID in API-resultaat",          "[PID: $part_id]");
        return NULL;
    }

    wachthond($extdebug, 1, "Contribution aangemaakt",                                  "[BID: $contrib_id]");

    // Koppel participant aan contribution via ParticipantPayment
    $params_partpay_create = [
        'checkPermissions'          => FALSE,
        'debug'                     => $apidebug,
        'values'                    => [
            'participant_id'        => $part_id,
            'contribution_id'       => $contrib_id,
        ],
    ];
    wachthond($extdebug, 7, 'params_partpay_create',                $params_partpay_create);
    try {
        $result_partpay_create = civicrm_api4('ParticipantPayment', 'create', $params_partpay_create);
        wachthond($extdebug, 9, 'result_partpay_create',            $result_partpay_create);
    } catch (\Exception $e) {
        wachthond($extdebug, 1, "FOUT bij aanmaken ParticipantPayment: " . $e->getMessage(), "[BID: $contrib_id]");
        // Contribution is aangemaakt, geef toch het ID terug zodat line items nog aangemaakt kunnen worden
    }

    // Registreer price set 9 ("Kampgeld") bij de contribution
    $params_pse_create = [
        'checkPermissions'          => FALSE,
        'debug'                     => $apidebug,
        'values'                    => [
            'price_set_id'          => 9,
            'entity_table'          => 'civicrm_contribution',
            'entity_id'             => $contrib_id,
        ],
    ];
    wachthond($extdebug, 7, 'params_pse_create',                    $params_pse_create);
    try {
        $result_pse_create = civicrm_api4('PriceSetEntity', 'create', $params_pse_create);
        wachthond($extdebug, 9, 'result_pse_create',                $result_pse_create);
    } catch (\Exception $e) {
        wachthond($extdebug, 1, "FOUT bij aanmaken PriceSetEntity: " . $e->getMessage(), "[BID: $contrib_id]");
    }

    return $contrib_id;
}

/**
 * Fallback als pecunia niet beschikbaar is: zoek contribution via participant LineItem.
 */
function stgave_get_contribid_fallback(int $part_id): ?int {
    $li = civicrm_api4('LineItem', 'get', [
        'checkPermissions'  => FALSE,
        'select'            => ['contribution_id'],
        'where'             => [
            ['entity_id',    '=', $part_id],
            ['entity_table', '=', 'civicrm_participant'],
        ],
        'limit'             => 1,
    ])->first();
    return isset($li['contribution_id']) ? (int)$li['contribution_id'] : NULL;
}
