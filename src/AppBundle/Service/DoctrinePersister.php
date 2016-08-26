<?php
namespace AppBundle\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Scheb\TwoFactorBundle\Model\PersisterInterface;

class DoctrinePersister implements PersisterInterface
{
    /** @var DocumentManager */
    protected $dm;

    /**
     * @param DocumentManager $dm
     */
    public function __construct(
        DocumentManager $dm
    ) {
        $this->dm = $dm;
    }

    /**
     * Persist the user entity.
     *
     * @param object $user
     */
    public function persist($user)
    {
        $this->dm->persist($user);
        $this->dm->flush();
    }
}
