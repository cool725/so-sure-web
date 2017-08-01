<?php
namespace AppBundle\Classes;

use AppBundle\Document\Policy;

class ClientUrl
{
    const POT = 'sosure://open/pot';
    const POLICY = 'sosure://open/policy';
    const SHARE = 'sosure://open/share';
    const CARD = 'sosure://open/card';
    const NEWPHONE = 'sosure://open/newphone';
    const PICSURE = 'sosure://open/picsure';

    public static function getUrlWithQuerystring($url, Policy $policy)
    {
        if ($policy) {
            return sprintf('%s/?policy_id=%s', $url, $policy->getId());
        } else {
            return $url;
        }
    }
}
