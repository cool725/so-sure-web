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
use AppBundle\Document\Policy;
use AppBundle\Form\Type\PhoneType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;

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
        $launchUser = $this->get('app.user.launch');

        $userTop = new User();
        $referral = $request->get('referral');
        if ($referral) {
            $userTop->setReferralId($referral);
            $logger->debug(sprintf('Referral %s', $referral));
        }
        $userBottom = clone $userTop;

        $formTop = $this->get('form.factory')
            ->createNamedBuilder('launch_top', LaunchType::class, $userTop)
            ->getForm();
        $formBottom = $this->get('form.factory')
            ->createNamedBuilder('launch_bottom', LaunchType::class, $userBottom)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            $existingUser = null;
            if ($request->request->has('launch_top')) {
                $formTop->handleRequest($request);
                if ($formTop->isValid()) {
                    $existingUser = $launchUser->addUser($userTop);
                }
            } elseif ($request->request->has('launch_bottom')) {
                $formBottom->handleRequest($request);
                if ($formBottom->isValid()) {
                    $existingUser = $launchUser->addUser($userBottom);
                }
            }

            if ($existingUser) {
                return $this->redirectToRoute('launch_share', ['id' => $existingUser->getId()]);
            }
        }

        return array(
            'form_top' => $formTop->createView(),
            'form_bottom' => $formBottom->createView(),
            'referral' => $referral,
        );
    }

    /**
     * @Route("/launch/{id}", name="launch_share")
     * @Template
     */
    public function launchAction($id)
    {
        $launchUser = $this->get('app.user.launch');
        $url = $launchUser->getLink($id);

        return array('id' => $id, 'referral_url' => $url, 'fb_pixel_event' => 'Lead');
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
     * @Route("/quote", name="quote")
     * @Template
     */
    public function quoteAction(Request $request)
    {
        $policy = new Policy();
        $form = $this->createForm(PhoneType::class, $policy);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $session = new Session();
            $session->set('quote', $policy->getPhone()->getId());

            return $this->redirectToRoute('purchase');
        }

        return array(
            'form' => $form->createView(),
        );
    }

    /**
     * @Route("/price/{id}/", name="price_item")
     */
    public function priceItemAction($id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if (!$phone) {
            return new JsonResponse([], 404);
        }

        return new JsonResponse([
            'price' => $phone->getPolicyPrice(),
        ]);
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
}
