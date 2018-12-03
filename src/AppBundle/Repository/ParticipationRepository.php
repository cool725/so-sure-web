<?php

namespace AppBundle\Repository;

use AppBundle\Document\Participation;
use Doctrine\ODM\MongoDB\DocumentRepository;

/**
 * Holds some participation related queries.
 */
class ParticipationRepository extends DocumentRepository
{
    /**
     * Finds all participations with a given status and optionally within a given set of promotions.
     * @param String     $status     is the status that the participations must have.
     * @param array|null $promotions is a list of all the promotions to find participations for, and if this is null
     *                               then it just looks in all participations.
     * @return array of participations matching the given conditions.
     */
    public function findByStatus($status = Participation::STATUS_ACTIVE, $promotions = null)
    {
        $qb = $this->createQueryBuilder();
        $qb->field("status")->equals($status);
        if ($promotions !== null) {
            $ids = [];
            foreach ($promotions as $promotion) {
                $ids[] = new \MongoId($promotion->getId());
            }
            $qb->field("promotion.\$id")->in($ids);
        }
        return $qb->getQuery()->execute();
    }
}
