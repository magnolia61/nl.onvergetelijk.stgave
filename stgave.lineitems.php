<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: stgave.lineitems.php
 * =======================================================================================
 *   stgave_sync_lineitems()         Zorgt dat de 3 St.Gave line items op de contribution staan.
 *                                   Maakt contribution aan als die ontbreekt.
 *   stgave_maak_contribution()      Maakt contribution + 3 line items + participant_payment via
 *                                   Order.create (APIv4) in één call.
 *   stgave_get_contribid_fallback() Fallback contrib-lookup via LineItem (APIv4).
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
 * @description     Zorgt dat de factuur in balans komt voor St.Gave.
 *                  1. Controleert of saldo €0 is en er 3 items zijn.
 *                  2. Bij onbalans: Clean Sweep van alle bestaande lineitems.
 *                  3. Forceert ook alle gekoppelde betalingen (Payments) naar €0.
 *
 * @param int       $contact_id     Het contact ID van de deelnemer.
 * @param array     $part_array     De volledige array vanuit base_pid2part().
 * @return array                    Statusarray per actie.
 * =======================================================================================
 */
function stgave_sync_lineitems(int $contact_id, array $part_array): array {

    $extdebug = 'stgave.lineitems';
    $apidebug = FALSE;
    $extwrite = 1;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE LINEITEMS 4.0 START SYNC",                   "[CID: $contact_id]");
    wachthond($extdebug, 2, "########################################################################");

    $part_id = $part_array['id'] ?? NULL;
    if (empty($part_id)) {
        return ['actie' => 'skip', 'reden' => 'geen part_id'];
    }

    // Ophalen contrib_id via pecunia of fallback[cite: 4]
    $contrib_id = NULL;
    if (function_exists('pecunia_get_contribid')) {
        $contrib_id = pecunia_get_contribid($part_array);
    }
    if (empty($contrib_id)) {
        $contrib_id = stgave_get_contribid_fallback($part_id);
    }

    if (empty($contrib_id)) {
        $contrib_id = stgave_maak_contribution($contact_id, $part_array, $extdebug, $apidebug, $extwrite);
        if (empty($contrib_id)) return ['actie' => 'skip', 'reden' => 'maak_contrib_faal'];
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE LINEITEMS 4.1 CONTROLE HUIDIGE STATUS",       "[BID: $contrib_id]");
    wachthond($extdebug, 2, "########################################################################");

    // 1. Haal de bijbehorende contribution op om het betaalde bedrag te checken
    $cont_status = civicrm_api4('Contribution', 'get', [
        'checkPermissions' => FALSE,
        'select'           => ['total_amount', 'actual_total_amount'], // actual_total_amount is incl. betalingen
        'where'            => [['id', '=', $contrib_id]],
    ])->first();

    // 2. Haal ALLE line-items op voor deze contribution
    $params_all_lineitems = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['id', 'unit_price', 'entity_table', 'entity_id'],
        'where'             => [['contribution_id', '=', $contrib_id]],
    ];
    wachthond($extdebug, 7, 'params_all_lineitems',              $params_all_lineitems);
    $result_all_lineitems = civicrm_api4('LineItem', 'get',      $params_all_lineitems);
    wachthond($extdebug, 9, 'result_all_lineitems',              $result_all_lineitems);

    // 3. Filter op participant-specifieke items
    $params_lineitem_get = [
        'checkPermissions'  => FALSE,
        'debug'             => $apidebug,
        'select'            => ['id', 'unit_price'],
        'where'             => [
            ['contribution_id', '=', $contrib_id],
            ['entity_table',    '=', 'civicrm_participant'],
            ['entity_id',       '=', $part_id],
        ],
    ];
    wachthond($extdebug, 7, 'params_lineitem_get',               $params_lineitem_get);
    $result_lineitem_get = civicrm_api4('LineItem', 'get',       $params_lineitem_get);
    wachthond($extdebug, 9, 'result_lineitem_get',               $result_lineitem_get);

    $huidig_aantal      = $result_lineitem_get->count();
    $li_totaal          = array_sum($result_lineitem_get->column('unit_price'));
    $all_items_totaal   = array_sum($result_all_lineitems->column('unit_price'));  // ALLE items
    $has_non_participant_items = FALSE;

    // Controleer of er non-participant items zijn
    foreach ($result_all_lineitems as $item) {
        $is_participant_item = ($item['entity_table'] === 'civicrm_participant' && $item['entity_id'] == $part_id);
        if (!$is_participant_item) {
            $has_non_participant_items = TRUE;
            wachthond($extdebug, 3, 'Vreemde item in contribution gevonden',
                     "entity_table={$item['entity_table']}, entity_id={$item['entity_id']}, price={$item['unit_price']}");
        }
    }

    // Haal de som van de werkelijke betalingen op (Payment records)
    $payment_sum = civicrm_api4('Payment', 'get', [
        'checkPermissions' => FALSE,
        'select' => ['SUM(total_amount) AS totaal'],
        'where' => [['contribution_id', '=', $contrib_id]],
    ])->first()['totaal'] ?? 0;

    // REPARATIE NODIG als:
    // 1. Niet exact 3 participant-items, OF
    // 2. Participant items totaal != €0, OF
    // 3. Payment sum != €0, OF
    // 4. Er zijn non-participant items in de contribution
    $reparatie_nodig = ($huidig_aantal !== 3 || (float)$li_totaal !== 0.0 || (float)$payment_sum !== 0.0 || $has_non_participant_items);

    if ($reparatie_nodig && $has_non_participant_items) {
        wachthond($extdebug, 1, "WAARSCHUWING: Contribution bevat non-participant items (totaal €" . number_format($all_items_totaal, 2) . ")", "[BID: $contrib_id]");
    }

    if (!$reparatie_nodig) {
        wachthond($extdebug, 1, "OK: Factuur volledig in balans (€0.00 / 3 items / €0 betaald).", "[SKIP REPAIR]");
        return ['actie' => 'ok', 'items' => $huidig_aantal];
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE LINEITEMS 4.2 CLEAN SWEEP (REPARATIE)",       "[BID: $contrib_id]");
    wachthond($extdebug, 2, "########################################################################");

    // Verwijder ALLE line items uit de contribution om ze opnieuw op te bouwen
    // Dit omvat zowel participant-specifieke items als evt. leftover items van eerdere wijzigingen
    $all_delete_ids = $result_all_lineitems->column('id');
    if (count($all_delete_ids) > 0) {
        $params_lineitem_delete = [
            'checkPermissions' => FALSE,
            'where'            => [['id', 'IN', $all_delete_ids]],
        ];

        wachthond($extdebug, 7, 'params_lineitem_delete (ALLE items)',   $params_lineitem_delete);
        if ($extwrite == 1) {
            $result_lineitem_delete = civicrm_api4('LineItem', 'delete', $params_lineitem_delete);
            wachthond($extdebug, 9, 'result_lineitem_delete',            $result_lineitem_delete);
        }
        wachthond($extdebug, 1, count($all_delete_ids) . " line-items verwijderd (incl. orphans)", "[BID: $contrib_id]");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE LINEITEMS 4.3 VERSE OPBOUW GAVE-ITEMS",      "[BID: $contrib_id]");
    wachthond($extdebug, 2, "########################################################################");

    $verwachte_items = [
        51  => ['val' => 497, 'lab' => 'Kampgeld St.Gave',       'eur' =>  175, 'fin' =>  4],
        28  => ['val' => 443, 'lab' => 'Korting 55 euro',         'eur' =>  -55, 'fin' => 19],
        101 => ['val' => 523, 'lab' => '120 euro vanuit St.Gave', 'eur' => -120, 'fin' => 23],
    ];

    foreach ($verwachte_items as $pfid => $spec) {
        $params_create = [
            'checkPermissions'  => FALSE,
            'values'            => [
                'contribution_id'       => $contrib_id,
                'entity_table'          => 'civicrm_participant',
                'entity_id'             => $part_id,
                'price_field_id'        => $pfid,
                'price_field_value_id'  => $spec['val'],
                'label'                 => $spec['lab'],
                'qty'                   => 1,
                'unit_price'            => $spec['eur'],
                'line_total'            => $spec['eur'],
                'financial_type_id'     => $spec['fin'],
            ],
        ];
        
        wachthond($extdebug, 7, "params_lineitem_create [$pfid]",    $params_create);
        if ($extwrite == 1) {
            $res_create = civicrm_api4('LineItem', 'create',        $params_create);
            wachthond($extdebug, 9, "result_lineitem_create [$pfid]", $res_create);
        }
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE LINEITEMS 4.4 SYNC PAYMENTS NAAR €0",         "[BID: $contrib_id]");
    wachthond($extdebug, 2, "########################################################################");

    // Los de onbalans in 'Totaal betaald' op door alle bestaande betalingen naar nul te zetten[cite: 3, 4]
    $params_payment_get = [
        'checkPermissions' => FALSE,
        'select' => ['id', 'total_amount'],
        'where'  => [['contribution_id', '=', $contrib_id]],
    ];
    wachthond($extdebug, 7, 'params_payment_get',               $params_payment_get);
    $result_payment_get = civicrm_api4('Payment', 'get',        $params_payment_get);
    wachthond($extdebug, 9, 'result_payment_get',               $result_payment_get);

    foreach ($result_payment_get as $payment) {
        // APIv4 Result iteration geeft objects, niet arrays → gebruik object property access
        $pay_id = $payment->id ?? NULL;
        $pay_amt = $payment->total_amount ?? NULL;

        if (empty($pay_id)) {
            wachthond($extdebug, 1, "SKIP payment: geen id", "[BID: $contrib_id]");
            continue;
        }

        if ((float)$pay_amt !== 0.0) {
            wachthond($extdebug, 7, "Aanpassen payment #{$pay_id} naar €0 via APIv3", "[BID: $contrib_id]");

            if ($extwrite == 1) {
                // APIv4 ondersteunt geen 'update' op Payment, dus gebruik APIv3
                // Cast naar int omdat APIv4 strings teruggeeft maar APIv3 integers verwacht
                $result_pay_v3 = civicrm_api3('Payment', 'create', [
                    'id'           => (int)$pay_id,
                    'total_amount' => 0,
                ]);
                wachthond($extdebug, 9, 'result_payment_update_v3', $result_pay_v3);
            }

            wachthond($extdebug, 1, "Payment #{$pay_id} gereset naar €0", "[BID: $contrib_id]");
        }
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STGAVE LINEITEMS 4.5 UPDATE TOTAL AMOUNT → €0",      "[BID: $contrib_id]");
    wachthond($extdebug, 2, "########################################################################");

    $params_contrib_update = [
        'checkPermissions'  => FALSE,
        'where'             => [['id', '=', $contrib_id]],
        'values'            => ['total_amount' => 0, 'net_amount' => 0],
    ];
    
    wachthond($extdebug, 7, 'params_contrib_update',                 $params_contrib_update);
    if ($extwrite == 1) {
        $result_contrib_update = civicrm_api4('Contribution', 'update', $params_contrib_update);
        wachthond($extdebug, 9, 'result_contrib_update',             $result_contrib_update);
        wachthond($extdebug, 1, "Contribution total_amount gezet op €0",        "[BID: $contrib_id]");
    }

    return ['status' => 'repaired', 'bid' => $contrib_id];
}

/**
 * =======================================================================================
 * COLOFON: stgave_maak_contribution
 * =======================================================================================
 * @description     Maakt een nieuwe €0 contribution aan voor een ST.GAVE deelnemer die
 *                  nog geen contribution heeft. Gebruikt Order.create (APIv4) om in één
 *                  call de contribution, alle 3 line items én de participant_payment
 *                  koppeling aan te maken. Order.create forceert altijd Pending; daarna
 *                  zetten we de status via Payment.create (APIv4) naar Completed.
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

    if ($extwrite != 1) {
        wachthond($extdebug, 1, "DRY-RUN: contribution NIET aangemaakt",                "[PID: $part_id]");
        return NULL;
    }

    // Order.create (APIv4) maakt contribution + 3 line items + participant_payment in één call.
    // entity_id op elk line item verwijst naar de bestaande participant — Order BAO doet dan
    // een no-op save en geeft het bestaande ID terug als entity_id voor het line item.
    // total_amount wordt door Order BAO berekend uit de line items (175 - 55 - 120 = 0).
    // contribution_status_id wordt door Order BAO altijd geforceerd naar Pending.
    $params_order_create = [
        'checkPermissions'      => FALSE,
        'debug'                 => $apidebug,
        'contributionValues'    => [
            'contact_id'                    => $contact_id,
            'financial_type_id'             => 4,               // Kampgeld
            'source'                        => 'Aanmelden St.Gave',
            'receive_date'                  => date('Y-m-d H:i:s'),
            'payment_instrument_id:name'    => 'Check',         // geen echte betaling
        ],
        'lineItems'             => [
            [
                'price_field_id'        => 51,
                'price_field_value_id'  => 497,
                'label'                 => 'Kampgeld St.Gave',
                'qty'                   => 1,
                'unit_price'            =>  175,
                'line_total'            =>  175,
                'financial_type_id'     =>  4,
                'entity_table'          => 'civicrm_participant',
                'entity_id'             => $part_id,
            ],
            [
                'price_field_id'        => 28,
                'price_field_value_id'  => 443,
                'label'                 => 'Korting 55 euro',
                'qty'                   => 1,
                'unit_price'            =>  -55,
                'line_total'            =>  -55,
                'financial_type_id'     => 19,
                'entity_table'          => 'civicrm_participant',
                'entity_id'             => $part_id,
            ],
            [
                'price_field_id'        => 101,
                'price_field_value_id'  => 523,
                'label'                 => '120 euro vanuit St.Gave',
                'qty'                   => 1,
                'unit_price'            => -120,
                'line_total'            => -120,
                'financial_type_id'     => 23,
                'entity_table'          => 'civicrm_participant',
                'entity_id'             => $part_id,
            ],
        ],
    ];
    wachthond($extdebug, 7, 'params_order_create',                  $params_order_create);

    try {
        $result_order_create = civicrm_api4('Order', 'create',      $params_order_create);
        wachthond($extdebug, 9, 'result_order_create',              $result_order_create);
    } catch (\Exception $e) {
        wachthond($extdebug, 1, "FOUT bij Order.create: " . $e->getMessage(),           "[PID: $part_id]");
        return NULL;
    }

    $contrib_id = $result_order_create->first()['id'] ?? NULL;
    if (empty($contrib_id)) {
        wachthond($extdebug, 1, "FOUT: Geen contribution ID in Order.create resultaat", "[PID: $part_id]");
        return NULL;
    }

    wachthond($extdebug, 1, "Order aangemaakt (Pending)",                                "[BID: $contrib_id]");

    // Order.create forceert Pending. Gebruik Payment.create (APIv4) om naar Completed te
    // gaan — dit is de CiviCRM-native manier en verzorgt ook de financiële boekhouding.
    // Voor een €0 bijdrage is total_amount=0: geen echte transactie, maar de status wordt
    // correct gezet en alle financial entities kloppen.
    $params_payment_create = [
        'checkPermissions'              => FALSE,
        'debug'                         => $apidebug,
        'values'                        => [
            'contribution_id'           => $contrib_id,
            'total_amount'              => 0,
            'trxn_date'                 => date('Y-m-d H:i:s'),
            'payment_instrument_id:name'=> 'Check',
        ],
    ];
    wachthond($extdebug, 7, 'params_payment_create',               $params_payment_create);
    try {
        $result_payment_create = civicrm_api4('Payment', 'create', $params_payment_create);
        wachthond($extdebug, 9, 'result_payment_create',           $result_payment_create);
        wachthond($extdebug, 1, "Payment aangemaakt → Contribution Completed",          "[BID: $contrib_id]");
    } catch (\Exception $e) {
        wachthond($extdebug, 1, "FOUT bij Payment.create: " . $e->getMessage(),         "[BID: $contrib_id]");
    }

    return $contrib_id;
}

/**
 * Fallback contrib-lookup via LineItem (APIv4).
 * Werkt omdat Order.create altijd entity_table=civicrm_participant zet op de line items.
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
    return !empty($li['contribution_id']) ? (int)$li['contribution_id'] : NULL;
}
