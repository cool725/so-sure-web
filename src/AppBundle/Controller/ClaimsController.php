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
use AppBundle\Document\Form\ClaimsCheck;
use AppBundle\Document\Form\CrimeRef;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\NoteType;
use AppBundle\Form\Type\ClaimType;
use AppBundle\Form\Type\ClaimCrimeRefType;
use AppBundle\Form\Type\ClaimsCheckType;
use AppBundle\Form\Type\ClaimFlagsType;
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
        return $this->redirectToRoute('claims_policies');
    }

    /**
     * @Route("/policies", name="claims_policies")
     * @Template
     */
    public function claimsPoliciesAction(Request $request)
    {
        if ($this->get('security.authorization_checker')->isGranted('ROLE_EMPLOYEE')) {
            $this->addFlash('warning', 'Redirected to Admin Site');

            return $this->redirectToRoute('admin_policies');
        }

        $includeInvalid = $this->getParameter('kernel.environment') != 'prod';

        $data = $this->searchPolicies($request, $includeInvalid);
        return array_merge($data, [
            'policy_route' => 'claims_policy'
        ]);
    }

    /**
     * @Route("/user/{id}", name="claims_user")
     */
    public function claimsUserAction($id)
    {
        if ($this->get('security.authorization_checker')->isGranted('ROLE_EMPLOYEE')) {
            $this->addFlash('warning', 'Redirected to Admin Site');

            return $this->redirectToRoute('admin_user', ['id' => $id]);
        }

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
        if ($this->get('security.authorization_checker')->isGranted('ROLE_EMPLOYEE')) {
            $this->addFlash('warning', 'Redirected to Admin Site');

            return $this->redirectToRoute('admin_policy', ['id' => $id]);
        }

        $imeiService = $this->get('app.imei');
        $fraudService = $this->get('app.fraud');
        $dm = $this->getManager();
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }

        $claim = new Claim();
        $claim->setPolicy($policy);
        $claimscheck = new ClaimsCheck();
        $claimscheck->setPolicy($policy);
        $crimeRef = new CrimeRef();
        $crimeRef->setPolicy($policy);
        $formClaim = $this->get('form.factory')
            ->createNamedBuilder('claim', ClaimType::class, $claim)
            ->getForm();
        $formCrimeRef = $this->get('form.factory')
            ->createNamedBuilder('crimeref', ClaimCrimeRefType::class, $crimeRef)
            ->getForm();
        $formClaimsCheck = $this->get('form.factory')
            ->createNamedBuilder('claimscheck', ClaimsCheckType::class, $claimscheck)
            ->getForm();
        $noteForm = $this->get('form.factory')
            ->createNamedBuilder('note_form', NoteType::class)
            ->getForm();
        $claimFlags = $this->get('form.factory')
            ->createNamedBuilder('claimflags', ClaimFlagsType::class, $claim)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('claim')) {
                $formClaim->handleRequest($request);
                if ($formClaim->isValid()) {
                    $claim->setHandler($this->getUser());
                    $claimsService = $this->get('app.claims');
                    if ($claimsService->addClaim($policy, $claim)) {
                        $this->addFlash('success', sprintf('Claim %s is added', $claim->getNumber()));

                        return $this->redirectToRoute('claims_policy', ['id' => $id]);
                    } else {
                        $this->addFlash('error', sprintf('Duplicate claim number %s', $claim->getNumber()));
                    }
                } else {
                    $this->get('logger')->error(sprintf(
                        'Error adding claim for policy %s. %s',
                        $policy->getId(),
                        (string) $formClaim->getErrors(true, false)
                    ));
                }
            } elseif ($request->request->has('claimscheck')) {
                $formClaimsCheck->handleRequest($request);
                if ($formClaimsCheck->isValid()) {
                    try {
                        $result = $imeiService->policyClaim(
                            $policy,
                            $claimscheck->getType(),
                            $claimscheck->getClaim(),
                            $this->getUser()
                        );
                        $this->addFlash('success', sprintf(
                            '%s £%0.2f <a href="%s" target="_blank">%s</a> (Phone is %s)',
                            ucfirst($claimscheck->getType()),
                            $claimscheck->getClaim()->getLastChargeAmountWithVat(),
                            $imeiService->getCertUrl(),
                            $imeiService->getCertId(),
                            $result ? 'not blocked' : 'blocked'
                        ));
                    } catch (\Exception $e) {
                        $this->addFlash('error', $e->getMessage());
                    }
                    return $this->redirectToRoute('claims_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('crimeref')) {
                $formCrimeRef->handleRequest($request);
                if ($formCrimeRef->isValid()) {
                    $validCrimeRef = $imeiService->validateCrimeRef($crimeRef->getForce(), $crimeRef->getCrimeRef());
                    $crimeRef->getClaim()->setForce($crimeRef->getForce());
                    $crimeRef->getClaim()->setCrimeRef($crimeRef->getCrimeRef());
                    $crimeRef->getClaim()->setValidCrimeRef($validCrimeRef);
                    $dm->flush();
                    if (!$validCrimeRef) {
                        $this->addFlash('error', sprintf(
                            'CrimeRef %s is not valid. %s',
                            $claim->getCrimeRef(),
                            json_encode($imeiService->getResponseData())
                        ));
                    } else {
                        $this->addFlash('error', sprintf(
                            'CrimeRef %s is valid.',
                            $claim->getCrimeRef()
                        ));
                    }

                    return $this->redirectToRoute('claims_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('note_form')) {
                $noteForm->handleRequest($request);
                if ($noteForm->isValid()) {
                    $policy->addNote(json_encode([
                        'user_id' => $this->getUser()->getId(),
                        'name' => $this->getUser()->getName(),
                        'notes' => $noteForm->getData()['notes']
                    ]));
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        sprintf('Added note to Policy %s.', $policy->getPolicyNumber())
                    );

                    return $this->redirectToRoute('claims_policy', ['id' => $id]);
                }
            }
        }
        $checks = $fraudService->runChecks($policy);

        return [
            'policy' => $policy,
            'formClaim' => $formClaim->createView(),
            'formClaimsCheck' => $formClaimsCheck->createView(),
            'formCrimeRef' => $formCrimeRef->createView(),
            'formClaimFlags' => $claimFlags->createView(),
            'note_form' => $noteForm->createView(),
            'fraud' => $checks,
            'policy_route' => 'claims_policy',
            'policy_history' => $this->getSalvaPhonePolicyHistory($policy->getId()),
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

    /**
     * @Route("/claim/{id}", name="claims_notes")
     * @Method({"POST"})
     */
    public function claimsNotesAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        $claim = $repo->find($id);
        $notes = $this->conformAlphanumericSpaceDot($this->getRequestString($request, 'notes'), 500);
        $claim->setNotes($notes);

        $dm->flush();
        $this->addFlash(
            'success',
            'Claim notes updated'
        );

        return new RedirectResponse($this->generateUrl('claims_policy', ['id' => $claim->getPolicy()->getId()]));
    }
}
