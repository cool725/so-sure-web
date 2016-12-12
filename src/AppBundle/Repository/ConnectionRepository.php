<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;

class ConnectionRepository extends BaseDocumentRepository
{
    use PhoneTrait;

    public function isConnectedByEmail(Policy $policy, $email)
    {
        $connectionSourceLinks = $this->createQueryBuilder()
            ->field('sourcePolicy')->references($policy)
            ->getQuery()
            ->execute();
        foreach ($connectionSourceLinks as $connectionSourceLink) {
            if ($connectionSourceLink->getLinkedUser()->getEmailCanonical() == strtolower($email)) {
                return true;
            }
        }

        $connectionLinkSources = $this->createQueryBuilder()
            ->field('linkedPolicy')->references($policy)
            ->getQuery()
            ->execute();
        foreach ($connectionLinkSources as $connectionLinkSource) {
            if ($connectionLinkSource->getSourceUser()->getEmailCanonical() == strtolower($email)) {
                return true;
            }
        }

        return false;
    }

    public function isConnectedBySms(Policy $policy, $mobile)
    {
        $connectionSourceLinks = $this->createQueryBuilder()
            ->field('sourcePolicy')->references($policy)
            ->getQuery()
            ->execute();
        foreach ($connectionSourceLinks as $connectionSourceLink) {
            if ($connectionSourceLink->getLinkedUser()->getMobileNumber() == $this->normalizeUkMobile($mobile)) {
                return true;
            }
        }

        $connectionLinkSources = $this->createQueryBuilder()
            ->field('linkedPolicy')->references($policy)
            ->getQuery()
            ->execute();
        foreach ($connectionLinkSources as $connectionLinkSource) {
            if ($connectionLinkSource->getSourceUser()->getMobileNumber() == $this->normalizeUkMobile($mobile)) {
                return true;
            }
        }

        return false;
    }

    public function count(\DateTime $start = null, \DateTime $end = null)
    {
        $qb = $this->createQueryBuilder();
        $qb->field('excludeReporting')->notEqual(true);

        if ($start) {
            $qb->field('date')->gte($start);
        }
        if ($end) {
            $qb->field('date')->lte($end);
        }
        if ($this->excludedPolicyIds) {
            $this->addExcludedPolicyQuery($qb, 'sourcePolicy.$id');
            $this->addExcludedPolicyQuery($qb, 'linkedPolicy.$id');
        }

        return $qb->getQuery()
            ->execute()
            ->count();
    }

    public function countByConnection($connections)
    {
        $collection = $this->dm->getDocumentCollection($this->documentName)->getMongoCollection();
        $match = ['excludeReporting' => [ '$ne' => true]];
        if ($this->excludedPolicyIds) {
            $match['sourcePolicy.$id'] = [ '$nin' => $this->excludedPolicyIds];
            $match['linkedPolicy.$id'] = [ '$nin' => $this->excludedPolicyIds];
        }
        $ops = [
            [
                '$match' => $match
            ],
            [
                '$group' => [
                   '_id' => ['policy' => '$sourcePolicy'],
                   'count' => ['$sum' => 1]
                ]
            ],
            [
                '$match' => [
                   'count' => [ '$eq' => $connections]
                ]
            ],
        ];

        $data = $collection->aggregate($ops);
        return count($data['result']);
    }

    public function avgHoursToConnect()
    {
        $collection = $this->dm->getDocumentCollection($this->documentName)->getMongoCollection();
        $match = ['excludeReporting' => [ '$ne' => true]];
        if ($this->excludedPolicyIds) {
            $match['sourcePolicy.$id'] = [ '$nin' => $this->excludedPolicyIds];
            $match['linkedPolicy.$id'] = [ '$nin' => $this->excludedPolicyIds];
        }
        $ops = [
            [
                '$match' => $match
            ],
            [
                '$project' => [
                    '_id' => '$_id',
                    'connectHours' => [
                        '$divide' => [
                            ['$subtract' => ['$date', '$initialInvitationDate']],
                            3600000
                        ]
                    ]
                ]
            ],
            [
                '$group' => [
                   '_id' => '',
                   'avgConnectHours' => ['$avg' => '$connectHours']
                ]
            ]
        ];

        $data = $collection->aggregate($ops);

        return $data['result'][0]['avgConnectHours'];
    }
}
