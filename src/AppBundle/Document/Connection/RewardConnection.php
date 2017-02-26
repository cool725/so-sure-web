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
class RewardConnection extends BaseConnection
{
}
