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
use AppBundle\Document\Feature;
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
     * @Route("/policy/terms", name="latest_policy_terms")
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

        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);

        $tmpPolicy = new PhonePolicy();
        $prefix = $tmpPolicy->getPolicyNumberPrefix();

        $maxPotVaue = $request->get('maxPotValue');
        $maxConnections = ceil($maxPotVaue / 10);
        $yearlyPremium = $request->get('yearlyPremium') ? $request->get('yearlyPremium') : ( $maxPotVaue / 0.8);
        $data = array(
            'maxPotValue' => $maxPotVaue,
            'maxConnections' => $maxConnections,
            'yearlyPremium' => $yearlyPremium,
            'promo_code' => null,
            'include' => $request->get('include'),
            // don't display promo values
            // 'promo_code' => $repo->isPromoLaunch($prefix) ? 'launch' : null,
        );

        $feature = $this->get('app.feature');
        if ($feature->isEnabled(Feature::FEATURE_PICSURE)) {
            return $this->render('AppBundle:ApiView:policyTermsV2.html.twig', $data);
        } else {
            return $this->render('AppBundle:ApiView:policyTermsV1.html.twig', $data);
        }
    }

    /**
     * @Route("/policy/{id}/terms", name="policy_terms")
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
        $promoCode = $policy->getPromoCode();
        $data = array(
            'maxPotValue' => $maxPotVaue,
            'maxConnections' => $maxConnections,
            'yearlyPremium' => $yearlyPremium,
            'promo_code' => $promoCode,
            'include' => $request->get('include'),
        );

        if ($policy->getPolicyTerms()->isPicSureEnabled()) {
            return $this->render('AppBundle:ApiView:policyTermsV2.html.twig', $data);
        } else {
            return $this->render('AppBundle:ApiView:policyTermsV1.html.twig', $data);
        }
    }
}
