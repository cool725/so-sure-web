<?php

namespace AppBundle\Document;

use VasilDakov\Postcode\Postcode;

trait PostcodeTrait
{
    public function normalizePostcodeForDb($code)
    {
        return str_replace(' ', '', self::normalizePostcodeForDisplay($code));
    }

    public function normalizePostcodeForDisplay($code)
    {
        $postcode = new Postcode($code);

        return $postcode->normalise();
    }
}
