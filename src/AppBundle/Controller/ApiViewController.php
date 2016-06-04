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
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Document\PolicyTerms;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * @Route("/view")
 */
class ApiViewController extends BaseController
{
    /**
     * @Route("/policy/keyfacts", name="latest_policy_keyfacts")
     * @Template
     */
    public function policyLatestKeyFactsAction(Request $request)
    {
        $policyKey = $this->getParameter('policy_key');
        if ($request->get('policy_key') != $policyKey) {
            throw $this->createNotFoundException('Policy Keyfacts not found');
        }
        if (!$request->get('maxPotValue')) {
            throw $this->createNotFoundException('Missing max pot value');
        }

        $maxPotVaue = $request->get('maxPotValue');
        $maxConnections = ceil($maxPotVaue / 10);
        $yearlyPremium = $request->get('yearlyPremium') ? $request->get('yearlyPremium') : ( $maxPotVaue / 0.8);
        return array(
            'maxPotValue' => $maxPotVaue,
            'maxConnections' => $maxConnections,
            'yearlyPremium' => $yearlyPremium,
        );
    }

    /**
     * @Route("/policy/terms", name="latest_policy_terms")
     * @Template
     */
    public function policyLatestTermsAction(Request $request)
    {
        $policyKey = $this->getParameter('policy_key');
        if ($request->get('policy_key') != $policyKey) {
            throw $this->createNotFoundException('Policy Terms not found');
        }
        if (!$request->get('maxPotValue')) {
            throw $this->createNotFoundException('Missing max pot value');
        }

        $maxPotVaue = $request->get('maxPotValue');
        $maxConnections = ceil($maxPotVaue / 10);
        $yearlyPremium = $request->get('yearlyPremium') ? $request->get('yearlyPremium') : ( $maxPotVaue / 0.8);
        return array(
            'maxPotValue' => $maxPotVaue,
            'maxConnections' => $maxConnections,
            'yearlyPremium' => $yearlyPremium,
        );
    }

    /**
     * @Route("/policy/{id}/keyfacts", name="policy_keyfacts")
     * @Template("AppBundle::ApiView/policyLatestKeyFacts.html.twig")
     */
    public function policyKeyFactsAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }
        $policyKey = $this->getParameter('policy_key');
        if ($request->get('policy_key') != $policyKey) {
            throw $this->createNotFoundException('Policy not found');
        }

        // TODO: Later would determine which keyfacts to display
        $maxPotVaue = $request->get('maxPotValue');
        $maxConnections = ceil($maxPotVaue / 10);
        $yearlyPremium = $request->get('yearlyPremium') ? $request->get('yearlyPremium') : ( $maxPotVaue / 0.8);
        return array(
            'maxPotValue' => $maxPotVaue,
            'maxConnections' => $maxConnections,
            'yearlyPremium' => $yearlyPremium,
        );
    }

    /**
     * @Route("/policy/{id}/terms", name="policy_terms")
     * @Template("AppBundle::ApiView/policyLatestTerms.html.twig")
     */
    public function policyTermsAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }
        $policyKey = $this->getParameter('policy_key');
        if ($request->get('policy_key') != $policyKey) {
            throw $this->createNotFoundException('Policy not found');
        }

        // TODO: Later would determine which terms to display
        $maxPotVaue = $request->get('maxPotValue');
        $maxConnections = ceil($maxPotVaue / 10);
        $yearlyPremium = $request->get('yearlyPremium') ? $request->get('yearlyPremium') : ( $maxPotVaue / 0.8);
        return array(
            'maxPotValue' => $maxPotVaue,
            'maxConnections' => $maxConnections,
            'yearlyPremium' => $yearlyPremium,
        );
    }
}
