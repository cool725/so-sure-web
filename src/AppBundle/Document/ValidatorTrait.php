<?php

namespace AppBundle\Document;

use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use AppBundle\Validator\Constraints\AlphanumericValidator;

trait ValidatorTrait
{
    protected function conformAlphanumericSpaceDot($value, $length)
    {
        $validator = new AlphanumericSpaceDotValidator();

        return $validator->conform(mb_substr($value, 0, $length));
    }

    protected function conformAlphanumeric($value, $length)
    {
        $validator = new AlphanumericValidator();

        return $validator->conform(mb_substr($value, 0, $length));
    }
}
