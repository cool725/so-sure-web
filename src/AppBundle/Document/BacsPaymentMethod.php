<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/** @MongoDB\EmbeddedDocument */
class BacsPaymentMethod extends PaymentMethod
{
    public function getName()
    {
        return 'Direct Debit';
    }

    public function isValid()
    {
        // TODO: Fix me
        return true;
    }
}
