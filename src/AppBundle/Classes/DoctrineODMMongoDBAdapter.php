<?php

/*
 * This file is part of the Pagerfanta package.
 *
 * (c) Pablo Díez <pablodip@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AppBundle\Classes;

use Doctrine\ODM\MongoDB\Query\Builder;

/**
 * DoctrineODMMongoDBAdapter.
 *
 * @author Pablo Díez <pablodip@gmail.com>
 */
class DoctrineODMMongoDBAdapter implements AdapterInterface
{
    private $queryBuilder;

    /**
     * Constructor.
     *
     * @param Builder $queryBuilder A DoctrineMongo query builder.
     */
    public function __construct(Builder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }

    /**
     * Returns the query builder.
     *
     * @return Builder The query builder.
     */
    public function getQueryBuilder()
    {
        return $this->queryBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function getNbResults()
    {
        $qb = clone $this->queryBuilder;
        return $qb->getQuery()->count();
    }

    /**
     * {@inheritdoc}
     */
    public function getSlice($offset, $length)
    {
        $qb = clone $this->queryBuilder;
        return $qb
            ->limit($length)
            ->skip($offset)
            ->getQuery()
            ->execute();
    }
}
