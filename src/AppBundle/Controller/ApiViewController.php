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
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\Feature;
use AppBundle\Document\PolicyTerms;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * @Route("/view")
 */
class ApiViewController extends BaseController
{
    /**
     * @Route("/policy/terms", name="latest_policy_terms")
     * @Route("/policy/v2/terms", name="latest_policy_terms2")
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

        $latestTerms = $this->getLatestPolicyTerms();

        $tmpPolicy = new HelvetiaPhonePolicy();
        $maxPotVaue = $request->get('maxPotValue');
        $maxConnections = ceil($maxPotVaue / 10);
        $yearlyPremium = $request->get('yearlyPremium') ? $request->get('yearlyPremium') : ( $maxPotVaue / 0.8);
        $data = array(
            'maxPotValue' => $maxPotVaue,
            'maxConnections' => $maxConnections,
            'yearlyPremium' => $yearlyPremium,
            'promo_code' => null,
            'include' => $request->get('include'),
            'claims_default_direct_group' => $this->get('app.feature')->isEnabled(
                Feature::FEATURE_CLAIMS_DEFAULT_DIRECT_GROUP
            ),
        );

        $template = sprintf(
            'AppBundle:ApiView:policyTermsV%d.html.twig',
            $latestTerms->getVersionNumber()
        );

        $html = $this->renderView($template, $data);
        if ($request->get('_route') == 'latest_policy_terms') {
            $noH1 = $request->get('noH1');
            if (!$noH1) {
                $html = $this->upgradeHTags($html);
            }
        }
        return new Response($html);
    }

    /**
     * @Route("/policy/{id}/terms", name="policy_terms")
     * @Route("/policy/v2/{id}/terms", name="policy_terms2")
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
            'claims_default_direct_group' => $this->get('app.feature')->isEnabled(
                Feature::FEATURE_CLAIMS_DEFAULT_DIRECT_GROUP
            ),
        );

        $template = sprintf(
            'AppBundle:ApiView:policyTermsV%d.html.twig',
            $policy->getPolicyTerms()->getVersionNumber()
        );

        if ($request->get('_route') == 'policy_terms') {
            $html = $this->renderView($template, $data);
            return new Response($this->upgradeHTags($html));
        } else {
            return $this->render($template, $data);
        }
    }

    private function upgradeHTags($html)
    {
        $html = str_replace('<h2', '<h1', $html);
        $html = str_replace('</h2', '</h1', $html);
        $html = str_replace('<h3', '<h2', $html);
        $html = str_replace('</h3', '</h2', $html);
        $html = str_replace('<h4', '<h3', $html);
        $html = str_replace('</h4', '</h3', $html);
        return $html;
    }
}
