<?php

namespace AppBundle\Document;

use VasilDakov\Postcode\Postcode;

trait PostcodeTrait
{
    public function normalizePostcodeForDb($code)
    {
        return self::normalizePostcode($code);
    }

    public function normalizePostcodeForDisplay($code)
    {
        $postcode = new Postcode($code);

        return $postcode->normalise();
    }

    public static function normalizePostcode($code)
    {
        $postcode = new Postcode($code);

        return str_replace(' ', '', $postcode->normalise());
    }
}
