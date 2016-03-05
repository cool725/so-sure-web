<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

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
}
