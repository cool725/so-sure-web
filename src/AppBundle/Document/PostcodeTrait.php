<?php

namespace AppBundle\Document;

use VasilDakov\Postcode\Postcode;

trait PostcodeTrait
{
    /**
     * Used for the so-sure db address postcodes
     * Note that the Census::Postcode collection uses a different format
     * @param string $code
     * @return string|null
     */
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
            $postcode = new Postcode(trim($code));

            if ($forDisplay) {
                return $postcode->normalise();
            } else {
                return str_replace(' ', '', $postcode->normalise());
            }
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Searches the given string for a given postcode, even if there is a difference of case or spacing.
     * @param string $haystack is the string to search.
     * @param string $needle   is the postcode to find.
     * @return boolean true iff found.
     */
    public static function findPostcode($haystack, $needle)
    {
        $norm = static::normalizePostcode($needle);
        $flatHaystack = mb_ereg_replace('\s+', '', $haystack);
        return mb_stripos($flatHaystack, $norm) !== false;
    }
}
