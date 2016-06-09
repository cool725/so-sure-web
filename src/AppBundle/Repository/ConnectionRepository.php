<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;

class ConnectionRepository extends DocumentRepository
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
}
