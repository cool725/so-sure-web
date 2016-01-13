<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Form\Type\LaunchType;
use AppBundle\Document\User;

class DefaultController extends BaseController
{
    /**
     * @Route("/", name="homepage")
     * @Template
     */
    public function indexAction(Request $request)
    {
        $dm = $this->getManager();

        $user = new User();
        $user->setReferralId($request->get('referral'));

        $form = $this->createForm(LaunchType::class, $user);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $repo = $dm->getRepository(User::class);
            $existingUser = $repo->findOneBy(['emailCanonical' => $user->getEmailCanonical()]);
            $id = null;
            if (!$existingUser) {
                $dm->persist($user);
                $dm->flush();
                $id = $user->getId();
            } else {
                $id = $existingUser->getId();
            }

            return $this->redirectToRoute('launch_share', ['id' => $id]);
        }

        return array(
            'form' => $form->createView(),
        );
    }

    /**
     * @Route("/launch/{id}", name="launch_share")
     * @Template
     */
    public function launchAction(Request $request, $id)
    {
        return array('id' => $id);
    }
    
    /**
     * @Route("/alpha", name="alpha")
     * @Template
     */
    public function alphaAction(Request $request)
    {
        return array();
    }
}
