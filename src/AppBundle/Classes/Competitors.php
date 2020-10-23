<?php
namespace AppBundle\Classes;

class Competitors
{
    public static $competitorComparisonData = [
        'SS' => [
            'oldPhones' => '<i class="far fa-check fa-2x"></i>',
            'phoneage' => '<strong>3 years</strong> <div>from purchase</div>',
            'lossAsStandard' => '<i class="far fa-check fa-2x"></i>',
            'timeToRepair' => '<strong>24-48</strong> <div>hours</div>',
            'timeToReplace' => '<strong>24</strong> <div>hours</div>',
            'repairVia' => 'Courier sent to you',
            'cashback' => '<i class="far fa-check fa-2x"></i>',
        ],
        'PYB' => [
            'name' => 'Protect Your Bubble',
            'oldPhones' => 'Only from approved retailers',
            'phoneage' => '<strong>6 months</strong> <div>from purchase</div>',
            'lossAsStandard' => '<i class="far fa-times fa-2x text-danger"></i> <br> <small>(extra charge)</small>',
            'excessFrom' => '<strong>£50</strong>',
            'timeToRepair' => '<strong>120</strong> <div>hours</div>',
            'timeToReplace' => '<strong>48</strong> <div>hours</div>',
            'repairVia' => 'Post',
            'cashback' => '<i class="far fa-times fa-2x text-danger"></i>',
        ],
        'LICI' => [
            'name' => 'loveit<br>coverit',
            'oldPhones' => 'Only Grade A phones',
            'phoneage' => '<strong>12 months</strong> <div>from purchase</div>',
            'lossAsStandard' => '<i class="far fa-times fa-2x text-danger"></i> <br> <small>(extra charge)</small>',
            'excessFrom' => '<strong>£30</strong>',
            'timeToRepair' => '<strong>72</strong> <div>hours</div>',
            'timeToReplace' => '<strong>48</strong> <div>hours</div>',
            'repairVia' => 'Post/In Person',
            'cashback' => '<i class="far fa-times fa-2x text-danger"></i>',
        ],
        'LCANI' => [
            'name' => 'Lycainsure',
            'oldPhones' => 'Only phones with 1 year warranty',
            'phoneage' => '<strong>18 months</strong> <div>from purchase</div>',
            'lossAsStandard' => '<i class="far fa-times fa-2x text-danger"></i> <br> <small>(extra charge)</small>',
            'excessFrom' => '<strong>£50</strong>',
            'timeToRepair' => '<strong>48</strong> <div>hours</div>',
            'timeToReplace' => '<strong>48</strong> <div>hours</div>',
            'repairVia' => 'Post',
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
