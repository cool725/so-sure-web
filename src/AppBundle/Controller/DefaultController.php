<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Form\Type\LaunchType;
use AppBundle\Document\User;
use AppBundle\Document\Phone;

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
        $url = $this->generateUrl('homepage', ['referral' => $id], UrlGeneratorInterface::ABSOLUTE_URL);

        return array('id' => $id, 'referral_url' => $this->addShortLink($url));
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

    /**
     * @Route("/jobs", name="jobs")
     * @Template
     */
    public function jobsAction()
    {
        return array();
    }

    /**
     * @Route("/phone/{make}/{model}", name="phone_make_model")
     * @Template
     */
    public function phoneMakeModelAction($make, $model)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->findOneBy(['make' => $make, 'model' => $model]);
        if (!$phone) {
            return new RedirectResponse($this->generateUrl('phone_make', ['make' => $make]));
        }

        return array('phone' => $phone);
    }

    /**
     * @Route("/phone/{make}", name="phone_make")
     * @Template
     */
    public function phoneMakeAction($make)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phones = $repo->findBy(['make' => $make]);
        // TODO: Redirect to other phone

        return array('phones' => $phones);
    }

    private function addShortLink($url)
    {
        try {
            $client = new \Google_Client();
            $client->setApplicationName("SoSure");
            $client->setDeveloperKey($this->getParameter('google_apikey'));
            $service = new \Google_Service_Urlshortener($client);
            $gUrl = new \Google_Service_Urlshortener_Url();
            $gUrl->longUrl = $url;
            $result = $service->url->insert($gUrl);

            return $result['id'];
        } catch (\Exception $e) {
            return $url;
        }
    }
}
