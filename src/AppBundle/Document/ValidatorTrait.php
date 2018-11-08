<?php

namespace AppBundle\Document;

use AppBundle\Validator\Constraints\AlphanumericSpaceDotPipeValidator;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use AppBundle\Validator\Constraints\AlphanumericValidator;

trait ValidatorTrait
{
    protected function conformAlphanumericSpaceDotPipe($value, $length, $minLength = 0)
    {
        $validator = new AlphanumericSpaceDotPipeValidator();
        $string = $validator->conform(mb_substr($value, 0, $length));
        return mb_strlen($string) >= $minLength ? $string : null;
    }

    protected function conformAlphanumericSpaceDot($value, $length, $minLength = 0)
    {
        $validator = new AlphanumericSpaceDotValidator();
        $string = $validator->conform(mb_substr($value, 0, $length));
        return mb_strlen($string) >= $minLength ? $string : null;
    }

    protected function conformAlphanumeric($value, $length, $minLength = 0)
    {
        $validator = new AlphanumericValidator();
        $string = $validator->conform(mb_substr($value, 0, $length));
        return mb_strlen($string) >= $minLength ? $string : null;
    }
}
