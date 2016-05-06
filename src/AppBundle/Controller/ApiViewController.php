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
     * @Route("/policy/terms", name="latest_policy_terms")
     * @Template
     */
    public function policyLatestTermsAction(Request $request)
    {
        $policyKey = $this->getParameter('policy_key');
        if ($request->get('policy_key') != $policyKey) {
            throw $this->createNotFoundException('Policy not found');
        }

        return array();
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

        return array();
    }
}
