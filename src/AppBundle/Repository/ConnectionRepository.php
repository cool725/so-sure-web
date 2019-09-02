<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;

class ConnectionRepository extends BaseDocumentRepository
{
    use PhoneTrait;

    public function getConnectedByUserCount(Policy $policy, $user)
    {
        $count = 0;
        $count += $this->createQueryBuilder()
            ->field('sourcePolicy')->references($policy)
            ->field('linkedUser')->references($user)
            ->getQuery()
            ->execute()
            ->count();

        /*
        $count += $this->createQueryBuilder()
            ->field('linkedPolicy')->references($policy)
            ->field('sourceUser')->references($user)
            ->getQuery()
            ->execute()
            ->count();
        */

        return $count;
    }

    public function isConnectedByEmail(Policy $policy, $email)
    {
        $connectionSourceLinks = $this->createQueryBuilder()
            ->field('sourcePolicy')->references($policy)
            ->getQuery()
            ->execute();
        foreach ($connectionSourceLinks as $connectionSourceLink) {
            if ($connectionSourceLink->getLinkedUser()->getEmailCanonical() == mb_strtolower($email)) {
                return true;
            }
        }

        $connectionLinkSources = $this->createQueryBuilder()
            ->field('linkedPolicy')->references($policy)
            ->getQuery()
            ->execute();
        foreach ($connectionLinkSources as $connectionLinkSource) {
            if ($connectionLinkSource->getSourceUser()->getEmailCanonical() == mb_strtolower($email)) {
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

    public function isConnectedByPolicy(Policy $sourcePolicy, Policy $linkedPolicy)
    {
        $connectionLinks = $this->createQueryBuilder()
            ->field('sourcePolicy')->references($sourcePolicy)
            ->field('linkedPolicy')->references($linkedPolicy)
            ->getQuery()
            ->execute()
            ->count();

        return $connectionLinks > 0;
    }

    public function connectedByPolicy(Policy $sourcePolicy, Policy $linkedPolicy)
    {
        return $this->createQueryBuilder()
            ->field("sourcePolicy")->references($sourcePolicy)
            ->field("linkedPolicy")->references($linkedPolicy)
            ->getQuery()
            ->getSingleResult();
    }

    public function findByUser($user, $policy)
    {
        return $this->createQueryBuilder()
            ->field("linkedUser")->references($user)
            ->field("sourcePolicy")->references($policy)
            ->getQuery()
            ->getSingleResult();
    }

    public function count(\DateTime $start = null, \DateTime $end = null, $cancelled = false)
    {
        return $this->connectedByDate($start, $end, $cancelled)->count();
    }

    public function connectedByDate(\DateTime $start = null, \DateTime $end = null, $cancelled = false)
    {
        $qb = $this->createQueryBuilder();
        $qb->field('excludeReporting')->notEqual(true);

        // TODO: Adjust this - should compare initial with final values
        if ($cancelled === false) {
            // connection = 0 are connections attached to a cancelled policy
            $qb->field('value')->gt(0);
        } elseif ($cancelled === true) {
            // connection = 0 are connections attached to a cancelled policy
            $qb->field('value')->equals(0);
        }

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
            ->execute();
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

        $data = $collection->aggregate($ops, ['cursor' => true]);
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

        $data = $collection->aggregate($ops, ['cursor' => true]);

        return $data['result'][0]['avgConnectHours'];
    }
}
