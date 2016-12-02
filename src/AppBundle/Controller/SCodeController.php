<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SCode;
use AppBundle\Form\Type\PhoneType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class SCodeController extends BaseController
{
    /**
     * @Route("/scode/{code}", name="scode")
     * @Template
     */
    public function scodeAction($code)
    {
        $scode = null;
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(SCode::class);
            $scode = $repo->findOneBy(['code' => $code]);
            $phoneRepo = $dm->getRepository(Phone::class);

            // make sure to get policy user in code first rather than in twig in case policy/user was deleted
            if (!$scode || !$scode->getPolicy()->getUser()) {
                throw new \Exception('Unknown scode');
            }
        } catch (\Exception $e) {
            $scode = null;
        }

        $session = $this->get('session');
        $session->set('scode', $code);

        return array(
            'scode' => $scode,
        );
    }
}
