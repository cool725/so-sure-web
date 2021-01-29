<?php
namespace AppBundle\Classes;

class Competitors
{
    public static $competitorComparisonData = [
        'SS' => [
            'oldPhones' => 'All used or refurbished',
            'phoneage' => '<strong>36 months</strong> <div>from purchase</div>',
            'lossAsStandard' => '<i class="far fa-check fa-2x"></i>',
            'timeToRepair' => '<strong>1-2</strong> <div>working days</div>',
            'timeToReplace' => '<strong>24</strong> <div>hours</div>',
            'repairVia' => 'Free courier',
            'cashback' => '<i class="far fa-check fa-2x"></i>',
        ],
        'PYB' => [
            'name' => 'Protect Your Bubble',
            'oldPhones' => '<small>Only refurbished by the manufacturer or network provider</small>',
            'phoneage' => '<strong>6 months</strong> <div>from purchase</div>',
            'lossAsStandard' => '<i class="far fa-times fa-2x text-danger"></i> <br> <small>(extra charge)</small>',
            'excessFrom' => '<strong>£50</strong>',
            'timeToRepair' => 'Within <strong>6</strong> <div>working days</div>',
            'timeToReplace' => 'Within <strong>48</strong> <div>hours</div>',
            'repairVia' => 'Free courier',
            'cashback' => '<i class="far fa-times fa-2x text-danger"></i>',
        ],
        'LICI' => [
            'name' => 'loveit<br>coverit',
            'oldPhones' => '<small>Only refurbished by the manufacturer or network provider</small>',
            'phoneage' => '<strong>36 months</strong> <div>from purchase</div>',
            'lossAsStandard' => '<i class="far fa-times fa-2x text-danger"></i> <br> <small>(extra charge)</small>',
            'excessFrom' => '<strong>£30</strong>',
            'timeToRepair' => 'Within <strong>3</strong> <div>working days</div>',
            'timeToReplace' => 'Within <strong>24</strong> <div>hours</div>',
            'repairVia' => 'Paid post / In Person',
            'cashback' => '<i class="far fa-times fa-2x text-danger"></i>',
        ],
        'LCANI' => [
            'name' => 'Lycainsure',
            'oldPhones' => '<small>Only refurbished by the manufacturer or network provider</small>',
            'phoneage' => '<strong>18 months</strong> <div>from purchase</div>',
            'lossAsStandard' => '<i class="far fa-times fa-2x text-danger"></i> <br> <small>(extra charge)</small>',
            'excessFrom' => '<strong>£50</strong>',
            'timeToRepair' => 'Within <strong>7</strong> <div>working days</div>',
            'timeToReplace' => 'Within <strong>48</strong> <div>hours</div>',
            'repairVia' => 'Paid post',
            'cashback' => '<i class="far fa-times fa-2x text-danger"></i>',
        ],
    ];

    // public static $exampleDataSet = [
    //     'NAME' => [
    //         'name' => '',
    //         'oldPhones' => '',
    //         'phoneage' => '',
    //         'lossAsStandard' => '',
    //         'excessFrom' => '',
    //         'timeToRepair' => '',
    //         'timeToReplace' => '',
    //         'repairVia' => '',
    //         'cashback' => '',
    //     ],
    // ];
}
