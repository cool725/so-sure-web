<?php

namespace PicsureMLBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use PicsureMLBundle\Document\TrainingVersionsInfo;

class TrainingVersionsInfoRepository extends DocumentRepository
{

    public function create()
    {
        $versionInfo = new TrainingVersionsInfo();
        $versionInfo->addVersion(1);
        $versionInfo->setLatestVersion(1);

        return $versionInfo;
    }
}
