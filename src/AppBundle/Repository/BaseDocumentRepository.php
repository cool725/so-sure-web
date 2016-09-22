<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\DateTrait;

class BaseDocumentRepository extends DocumentRepository
{
    protected $excludedPolicyIds;
    
    public function setExcludedPolicyIds($excludedPolicyIds)
    {
        $this->excludedPolicyIds = $excludedPolicyIds;
    }
    
    protected function addExcludedPolicyQuery($qb, $field)
    {
        $qb->field($field)->notIn($this->excludedPolicyIds);
    }

    public function transformMongoIds($array, $method)
    {
        $ids = [];
        foreach ($array as $item) {
            $ids[] = new \MongoId(call_user_func([$item, $method]));
        }

        return $ids;
    }
}
