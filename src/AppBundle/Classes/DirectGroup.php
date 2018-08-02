<?php
namespace AppBundle\Classes;

use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\ImeiTrait;

class DirectGroup
{
    public static $breakdownEmailAddresses = [
        'SoSure@directgroup.co.uk',
    ];

    public static $errorEmailAddresses = [
        'SoSure@directgroup.co.uk',
        'patrick@so-sure.com',
        'dylan@so-sure.com',
    ];
}
