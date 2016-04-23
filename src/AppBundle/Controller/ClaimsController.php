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
            $this->formToMongoSearch($form, $users, 'mobile', 'mobileNumber');
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
            return $this->createNotFoundException('Policy not found');
        }

        $claim = new Claim();
        $form = $this->createForm(ClaimType::class, $claim);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $claim->setHandler($this->getUser());
            $policy->addClaim($claim);
            $dm->flush();
            $this->addFlash('success', sprintf('Claim %s is added', $claim->getNumber()));

            return $this->redirectToRoute('claims_policy', ['id' => $id]);
        }
        $checks = $fraudService->runChecks($policy);

        return [
            'policy' => $policy,
            'form' => $form->createView(),
            'fraud' => $checks,
        ];
    }
}
