<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\ClaimType;
use AppBundle\Form\Type\UserSearchType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;
use MongoRegex;

/**
 * @Route("/claims")
 */
class ClaimsController extends BaseController
{
    /**
     * @Route("/", name="claims_home")
     */
    public function indexAction()
    {
        return $this->redirectToRoute('claims_users');
    }

    /**
     * @Route("/users", name="claims_users")
     * @Template
     */
    public function claimsUsersAction(Request $request)
    {
        $csrf = $this->get('form.csrf_provider');
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $users = $repo->createQueryBuilder();
        $pager = $this->pager($request, $users);

        $users = $repo->createQueryBuilder();
        $form = $this->createForm(UserSearchType::class);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $this->formToMongoSearch($form, $users, 'email', 'email');
            $this->formToMongoSearch($form, $users, 'lastname', 'lastName');
            $this->formToMongoSearch($form, $users, 'mobile', 'mobile_number');
            $this->formToMongoSearch($form, $users, 'postcode', 'addresses.postcode');

            $policyRepo = $dm->getRepository(Policy::class);
            $policiesQb = $policyRepo->createQueryBuilder();
            if ($policies = $this->formToMongoSearch($form, $policiesQb, 'policy', 'policy_number', true)) {
                $userIds = [];
                foreach ($policies as $policy) {
                    $userIds[] = $policy->getUser()->getId();
                }
                $users->field('id')->in($userIds);
            }
        }
        $pager = $this->pager($request, $users);

        return [
            'users' => $pager->getCurrentPageResults(),
            'token' => $csrf->generateCsrfToken('default'),
            'pager' => $pager,
            'form' => $form->createView(),
            'policy_route' => 'claims_policy',
        ];
    }

    /**
     * @Route("/user/{id}", name="claims_user")
     */
    public function claimsUserAction($id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $user = $repo->find($id);
        if (!$user) {
            throw $this->createNotFoundException('User not found');
        }
        foreach ($user->getPolicies() as $policy) {
            if ($policy->isPolicy()) {
                return $this->redirectToRoute('claims_policy', ['id' => $policy->getId()]);
            }
        }

        throw $this->createNotFoundException('User does not have an active policy');
    }

    /**
     * @Route("/policy/{id}", name="claims_policy")
     * @Template
     */
    public function claimsPolicyAction(Request $request, $id)
    {
        $fraudService = $this->get('app.fraud');
        $dm = $this->getManager();
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }

        $claim = new Claim();
        $form = $this->createForm(ClaimType::class, $claim);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $claim->setHandler($this->getUser());
            $claimsService = $this->get('app.claims');
            $claimsService->addClaim($policy, $claim);
            $this->addFlash('success', sprintf('Claim %s is added', $claim->getNumber()));

            return $this->redirectToRoute('claims_policy', ['id' => $id]);
        }
        $checks = $fraudService->runChecks($policy);

        return [
            'policy' => $policy,
            'form' => $form->createView(),
            'fraud' => $checks,
            'policy_route' => 'claims_policy',
            'policy_history' => $this->getPhonePolicyHistory($policy->getId()),
            'user_history' => $this->getUserHistory($policy->getUser()->getId()),
        ];
    }


    /**
     * @Route("/phone/{id}/alternatives", name="claims_phone_alternatives")
     * @Method({"GET"})
     */
    public function phoneAlternativesAction($id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        $alternatives = $repo->alternatives($phone);
        $suggestedReplacement = null;
        if ($phone->getSuggestedReplacement()) {
            $suggestedReplacement = $repo->find($phone->getSuggestedReplacement()->getId());
        }

        $data = [];
        foreach ($alternatives as $alternative) {
            $data[] = $alternative->toAlternativeArray();
        }

        return new JsonResponse([
            'alternatives' => $data,
            'suggestedReplacement' => $suggestedReplacement ? $suggestedReplacement->toAlternativeArray() : null,
        ]);
    }
}
