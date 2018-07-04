<?php

namespace AppBundle\Document;

trait PostcodeTrait
{
    public function normalizePostcode($postcode)
    {
        return mb_strtoupper(str_replace(' ', '', trim($postcode)));
    }
}
