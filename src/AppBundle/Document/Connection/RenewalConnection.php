<?php

namespace AppBundle\Document\Connection;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\Invitation\Invitation;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ConnectionRepository")
 */
class RenewalConnection extends Connection
{
    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $renew;

    public function setRenew($renew)
    {
        $this->renew = $renew;
    }

    public function getRenew()
    {
        return $this->renew;
    }
}
