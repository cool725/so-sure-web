<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;

class LogEntryRepository extends DocumentRepository
{
    public function findStatus()
    {

    }

}
