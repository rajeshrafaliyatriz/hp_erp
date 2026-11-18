<?php

/*
 * Rules of the sales pipeline that an administrator may want to change without a migration (slice 11 of the agentic CRM).
 * Read through App\Domain\Crm\Sales\OpportunityRules; the stage names are the values of the Sales Stage picklist (Picklist Admin), compared without regard to case.
 */
return [

    /*
     * Why an opportunity was lost: code => the plain-English label people see. The API accepts only these codes in `loss_reason_code`, and a report counts by
     * them. The code is what is stored, so a label can be reworded freely; a code that is removed from this list still shows (as its own code) on the deals that
     * already carry it. The free-text `close_reason` stays beside it for the detail.
     */
    'loss_reasons' => [
        'price' => 'Price too high',
        'no_budget' => 'No budget',
        'no_decision' => 'No decision made',
        'competitor' => 'Chose a competitor',
        'timing' => 'Timing was wrong',
        'no_response' => 'Stopped responding',
        'other' => 'Other',
    ],

    /*
     * Whether a move into Closed Lost must carry one of the codes above. OFF: today a free-text reason is enough, and the coded reason is optional. (A coded
     * reason on its own also counts as "saying why", and fills the free-text reason with its label when that is empty, so the older screens and the GTM deal
     * show a reason too.)
     */
    'loss_reason_required' => false,

    /*
     * Fields an opportunity must already have before it can ENTER a stage: stage name => list of fields. EMPTY, so today's behaviour is unchanged until the owner
     * decides. Fields that can be asked for: amount, closing_date, probability, product_master_id. The check fires on a move INTO the stage (not on editing a
     * deal that is already there), and answers 422 with the missing fields by name. Example, deliberately not set:
     *
     *     'required_to_enter' => ['Proposal/Quotation' => ['amount', 'closing_date']],
     *
     * A change a person makes through the API is checked; a stage that the GTM deal sync or a workflow sets is not (nobody is at the keyboard to fill a field).
     */
    'required_to_enter' => [],

];
