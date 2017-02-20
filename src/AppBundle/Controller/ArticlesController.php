<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

/**
 * @Route("/articles")
 */
class ArticlesController extends BaseController
{

    /**
     * @Route("/", name="articles_home")
     * @Template
     */
    public function articlesAction(Request $request)
    {
    }

    /**
     * @Route("/think-your-iPhone-7-is-insured-by-your-bank", name="think_your_iPhone-7_is_insured_by_your_bank")
     * @Template
     */
    public function thinkYourIPhone7IsInsuredByYourBank(Request $request)
    {
    }
}
