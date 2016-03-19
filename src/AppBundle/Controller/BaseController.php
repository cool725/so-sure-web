<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;

abstract class BaseController extends Controller
{
    protected function getManager()
    {
        return $this->get('doctrine_mongodb')->getManager();
    }

    /**
     * @param string $request
     *
     * @return array|null
     */
    protected function parseIdentity(Request $request)
    {
        return $this->get('app.cognito.identity')->parseIdentity($request->getContent());
    }

    /**
     * Page results
     *
     * @param Request $request
     * @param         $qb
     * @param integer $maxPerPage
     *
     * @return Pagerfanta
     */
    protected function pager(Request $request, $qb, $maxPerPage = 50)
    {
        $adapter = new DoctrineODMMongoDBAdapter($qb);
        $pagerfanta = new Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage($maxPerPage);
        $pagerfanta->setCurrentPage($request->get('page') ? $request->get('page') : 1);

        return $pagerfanta;
    }
}
