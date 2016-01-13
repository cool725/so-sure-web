<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

/**
 * @Route("/purchase")
 */
class PurchaseController extends BaseController
{
    /**
     * @Route("/", name="purchase")
     * @Template
     */
    public function indexAction(Request $request)
    {
        return array();
    }
}
