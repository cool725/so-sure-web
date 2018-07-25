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
        return self::normalizePostcode($code, true);
    }

    public static function normalizePostcode($code, $forDisplay = false)
    {
        try {
            $postcode = new Postcode($code);

            if ($forDisplay) {
                return $postcode->normalise();
            } else {
                return str_replace(' ', '', $postcode->normalise());
            }
        } catch (\Exception $e) {
            return null;
        }
    }
}
