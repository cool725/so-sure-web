<?php

namespace PicsureMLBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use PicsureMLBundle\Document\TrainingData;

class PicsureMLRepository extends DocumentRepository
{

    public function imageExists($path)
    {
        $qb = $this->createQueryBuilder();
        $qb->field('imagePath')->equals($path);

        return $qb
            ->getQuery()
            ->execute()
            ->count() > 0;
    }

    public function getTotalCount()
    {
        return $this->createQueryBuilder('d')
                ->select('count(d.id)')
                ->getQuery()
                ->execute()
                ->count();
    }

    public function getNoneCount()
    {
        return $this->createQueryBuilder('d')
                ->select('count(d.id)')
                ->field('label')->equals(null)
                ->getQuery()
                ->execute()
                ->count();
    }

    public function getUndamagedCount()
    {
        return $this->createQueryBuilder('d')
                ->select('count(d.id)')
                ->field('label')->equals(TrainingData::LABEL_UNDAMAGED)
                ->getQuery()
                ->execute()
                ->count();
    }

    public function getInvalidCount()
    {
        return $this->createQueryBuilder('d')
                ->select('count(d.id)')
                ->field('label')->equals(TrainingData::LABEL_INVALID)
                ->getQuery()
                ->execute()
                ->count();
    }

    public function getDamagedCount()
    {
        return $this->createQueryBuilder('d')
                ->select('count(d.id)')
                ->field('label')->equals(TrainingData::LABEL_DAMAGED)
                ->getQuery()
                ->execute()
                ->count();
    }

    public function getPreviousImage($id)
    {
        $qb = $this->createQueryBuilder();
        $qb->sort('id', 'desc');

        $results = $qb->getQuery()->execute();

        $prevId = null;
        $previousId = '0';
        foreach ($results as $result) {
            if ($result->getId() == $id) {
                $prevId = $previousId;
                break;
            } else {
                $previousId = $result->getId();
            }
        }

        return $prevId;
    }

    public function getNextImage($id)
    {
        $qb = $this->createQueryBuilder();
        $qb->sort('id', 'desc');

        $results = $qb->getQuery()->execute();

        $nextId = null;
        $getNext = false;
        foreach ($results as $result) {
            if (!$getNext) {
                if ($result->getId() == $id) {
                    $getNext = true;
                }
            } else {
                $nextId = $result->getId();
                break;
            }
        }

        return $nextId;
    }
}
