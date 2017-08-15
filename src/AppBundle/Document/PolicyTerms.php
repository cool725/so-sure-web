<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class PolicyTerms extends PolicyDocument
{
    public function isPicSureEnabled()
    {
        // assuming that picsure will always be enabled going forward
        return !in_array($this->getVersion(), [
            'Version 1 June 2016'
        ]);
    }
}
