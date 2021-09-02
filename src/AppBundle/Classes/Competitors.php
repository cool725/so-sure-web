<?php
namespace AppBundle\Classes;

class Competitors
{
    public static $competitorComparisonData = [
        'sosure' => [
            'cashback' => '<i class="far fa-check text-success fa-2x"></i>',
            'timeToReplace' => '<strong>24</strong> <div>hours</div>',
            'timeToRepair' => '<small><div>free courier</div> <strong>1-2</strong> work days</small>',
            'oldPhones' => '<small>Any new, used or refurbished</small>',
            'phoneAge' => '<strong>36 months</strong>',
        ],
        'protectyourbubble' => [
            'name' => 'Protect Your Bubble',
            'cashback' => '<i class="far fa-times fa-2x text-danger"></i>',
            'excess' => '<strong>£50</strong>',
            'timeToReplace' => 'Within <strong>48</strong> <div>hours</div>',
            'timeToRepair' => '<small><div>free courier</div> Within <strong>6</strong> work days</small>',
            'oldPhones' => '<small>Only refurbished by the manufacturer or network provider</small>',
            'phoneAge' => '<strong>6 months</strong>',
        ],
        'loveitcoverit' => [
            'name' => 'loveit<br>coverit',
            'cashback' => '<i class="far fa-times fa-2x text-danger"></i>',
            'excess' => '<strong>£30</strong>',
            'timeToReplace' => 'Within <strong>24</strong> <div>hours</div>',
            'timeToRepair' => '<small><div>paid post/in person</div> Within <strong>3</strong> work days</small>',
            'oldPhones' => '<small>Only refurbished by the manufacturer or network provider</small>',
            'phoneAge' => '<strong>36 months</strong>',
        ],
        'insurance2go' => [
            'name' => 'insurance2go',
            'cashback' => '<i class="far fa-times fa-2x text-danger"></i>',
            'excess' => '<strong>£25</strong>',
            'timeToReplace' => 'Within <strong>3-5</strong> <div>working days</div>',
            'timeToRepair' => '<small><div>paid post</div> Within <strong>7</strong>  work days</small>',
            'oldPhones' => '<small>Refurbished only with 12 month warranty</small>',
            'phoneAge' => '<strong>36 months</strong>',
        ],
    ];
}
