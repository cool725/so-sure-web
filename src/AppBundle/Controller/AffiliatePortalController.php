<?php

namespace AppBundle\Controller;

use AppBundle\Service\IntercomService;
use AppBundle\Service\MailerService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

use AppBundle\Document\Lead;

class AffiliatePortalController extends BaseController
{
    /**
     * @Route("/helloz", name="helloz")
     */
    public function hellozAction()
    {
        return $this->render('AppBundle:AffiliatePortal:helloz.html.twig');
    }
}
