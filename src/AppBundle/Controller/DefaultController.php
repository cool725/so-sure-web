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
        $repo = $dm->getRepository(User::class);
        $logger = $this->get('logger');

        $user = new User();
        $referral = $request->get('referral');
        if ($referral) {
            $user->setReferralId($referral);
            $logger->debug(sprintf('Referral %s', $referral));
        }
        $form = $this->createForm(LaunchType::class, $user);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $addMailchimp = true;
            try {
                if ($user->getReferralId() && !$user->getReferred()) {
                    $referred = $repo->find($user->getReferralId());
                    $referred->addReferral($user);
                }
                $user->setUsername($user->getEmailCanonical());
                $dm->persist($user);
                $dm->flush();
            } catch (\Exception $e) {
                // Ignore - most likely existing user
                $logger->error($e->getMessage());
                $addMailchimp = false;
            }

            $existingUser = $repo->findOneBy(['emailCanonical' => $user->getEmailCanonical()]);
            if (!$existingUser) {
                throw new \Exception('Failed to add');
            }

            if ($addMailchimp) {
                $mailchimp = $this->get('app.mailchimp.prelaunch');
                $mailchimp->subscribe($user->getEmail());
            }

            return $this->redirectToRoute('launch_share', ['id' => $existingUser->getId()]);
        }

        return array(
            'form' => $form->createView(),
        );
    }

    /**
     * @Route("/launch/{id}", name="launch_share")
     * @Template
     */
    public function launchAction($id)
    {
        return array('id' => $id);
    }
    
    /**
     * @Route("/alpha", name="alpha")
     * @Template
     */
    public function alphaAction()
    {
        return array();
    }

    /**
     * @Route("/terms", name="terms")
     * @Template
     */
    public function termsAction()
    {
        return array();
    }
}
