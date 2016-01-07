<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

abstract class BaseController extends Controller
{
    protected function getManager()
    {
        return $this->get('doctrine_mongodb')->getManager();
    }
}
