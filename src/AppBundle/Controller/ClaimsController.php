<?php

namespace AppBundle\Controller;

use AppBundle\Document\File\S3File;
use AppBundle\Document\PhonePolicy;
use AppBundle\Form\Type\ClaimInfoType;
use AppBundle\Form\Type\ClaimNoteType;
use AppBundle\Form\Type\ClaimSearchType;
use AppBundle\Repository\File\S3FileRepository;
use AppBundle\Service\ClaimsService;
use Gedmo\Loggable\Document\LogEntry;
use Gedmo\Loggable\Document\Repository\LogEntryRepository;
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
use AppBundle\Form\Type\NoteType;
use AppBundle\Form\Type\ClaimType;
use AppBundle\Form\Type\ClaimCrimeRefType;
use AppBundle\Form\Type\ClaimsCheckType;
use AppBundle\Form\Type\ClaimFlagsType;
use AppBundle\Form\Type\UserSearchType;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;
use MongoRegex;
use CensusBundle\Document\Postcode;
use AppBundle\Exception\RedirectException;

/**
 * @Route("/claims")
 */
class ClaimsController extends BaseController
{
    /**
     * @Route("", name="claims_home")
     * @Template
     */
    public function indexAction()
    {
        return [];
    }

    /**
     * @Route("/policies", name="claims_policies")
     * @Template
     */
    public function claimsPoliciesAction(Request $request)
    {
        if ($this->get('security.authorization_checker')->isGranted(User::ROLE_EMPLOYEE)) {
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
     * @Route("/users", name="claims_users")
     * @Template()
     */
    public function usersAction(Request $request)
    {
        try {
            $data = $this->searchUsers($request);
        } catch (RedirectException $e) {
            return new RedirectResponse($e->getMessage());
        }
        return array_merge($data, [
            'policy_route' => 'user_claim',
        ]);
    }

    /**
     * @Route("/user/{id}", name="claims_user")
     * @Template()
     */
    public function userAction($id)
    {
        if ($this->get('security.authorization_checker')->isGranted(User::ROLE_EMPLOYEE)) {
            $this->addFlash('warning', 'Redirected to Admin Site');

            return $this->redirectToRoute('admin_user', ['id' => $id]);
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $user = $repo->find($id);
        if (!$user) {
            throw $this->createNotFoundException('User not found');
        }

        return [
            'user' => $user,
            'policy_route' => 'claims_policy',
        ];
    }

    /**
     * @Route("/policy/{id}", name="claims_policy", requirements={"id":"[0-9a-f]{24,24}"})
     * @Template
     */
    public function claimsPolicyAction(Request $request, $id)
    {
        if ($this->get('security.authorization_checker')->isGranted(User::ROLE_EMPLOYEE)) {
            $this->addFlash('warning', 'Redirected to Admin Site');

            return $this->redirectToRoute('admin_policy', ['id' => $id]);
        }

        $imeiService = $this->get('app.imei');
        $fraudService = $this->get('app.fraud');
        $dm = $this->getManager();
        $repo = $dm->getRepository(Policy::class);
        /** @var Policy $policy */
        $policy = $repo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }

        $claim = $policy->getLatestFnolSubmittedClaim();
        if ($claim === null) {
            $claim = new Claim();
            $claim->setPolicy($policy);
        }
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
                    $claim->setHandlingTeam($this->getUser()->getHandlingTeam());
                    /** @var ClaimsService $claimsService */
                    $claimsService = $this->get('app.claims');
                    if (in_array($claim->getStatus(), [Claim::STATUS_FNOL, Claim::STATUS_SUBMITTED])) {
                        $claim->setStatus(Claim::STATUS_INREVIEW);
                        if ($claimsService->updateClaim($policy, $claim)) {
                            $this->addFlash('success', sprintf(
                                'Claim %s is updated. Excess is £%d',
                                $claim->getNumber(),
                                $claim->getExpectedExcessValue()
                            ));

                            return $this->redirectToRoute('claims_policy', ['id' => $id]);
                        } else {
                            $this->addFlash('error', sprintf('Duplicate claim number %s', $claim->getNumber()));
                        }
                    } elseif ($claimsService->addClaim($policy, $claim, Claim::STATUS_INREVIEW)) {
                        $this->addFlash('success', sprintf(
                            'Claim %s is added. Excess is £%d',
                            $claim->getNumber(),
                            $claim->getExpectedExcessValue()
                        ));

                        return $this->redirectToRoute('claims_policy', ['id' => $id]);
                    } else {
                        $this->addFlash('error', sprintf('Duplicate claim number %s', $claim->getNumber()));
                    }
                } else {
                    $errors = (string) $formClaim->getErrors(true, false);
                    if (mb_stripos($errors, "The CSRF token is invalid") !== false) {
                        $this->get('logger')->info(sprintf(
                            'Error adding claim for policy %s. %s',
                            $policy->getId(),
                            $errors
                        ));
                    } else {
                        $this->get('logger')->error(sprintf(
                            'Error adding claim for policy %s. %s',
                            $policy->getId(),
                            $errors
                        ));
                    }
                    $this->addFlash('error', sprintf('Failed to add claim. Please try again'));
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
                        $this->addFlash('success-raw', sprintf(
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
                    if ($crimeRef->getForce() && $crimeRef->getCrimeRef()) {
                        $validCrimeRef = $imeiService->validateCrimeRef(
                            $crimeRef->getForce(),
                            $crimeRef->getCrimeRef()
                        );
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
                    } else {
                        $this->addFlash('error', sprintf(
                            'Select a force for crimeref %s',
                            $claim->getCrimeRef()
                        ));
                    }

                    return $this->redirectToRoute('claims_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('note_form')) {
                $noteForm->handleRequest($request);
                if ($noteForm->isValid()) {
                    $policy->addNoteDetails($noteForm->getData()['notes'], $this->getUser());
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

        $censusDM = $this->getCensusManager();
        $postcodeRepo = $censusDM->getRepository(PostCode::class);
        $oa = null;
        if ($policy->getUser()->getBillingAddress()) {
            $search = $this->get('census.search');
            $oa = $search->findOutputArea($policy->getUser()->getBillingAddress()->getPostcode());
        }

        /** @var LogEntryRepository $logRepo */
        $logRepo = $this->getManager()->getRepository(LogEntry::class);
        $previousPicSureStatuses = $logRepo->findBy([
            'objectId' => $policy->getId(),
            'data.picSureStatus' => ['$nin' => [null, PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED]],
        ], ['loggedAt' => 'desc'], 1);
        $previousPicSureStatus = null;
        if (count($previousPicSureStatuses) > 0) {
            $previousPicSureStatus = $previousPicSureStatuses[0];
        }

        return [
            'policy' => $policy,
            'formClaim' => $formClaim->createView(),
            'formClaimsCheck' => $formClaimsCheck->createView(),
            'formCrimeRef' => $formCrimeRef->createView(),
            'formClaimFlags' => $claimFlags->createView(),
            'note_form' => $noteForm->createView(),
            'fraud' => $checks,
            'policy_route' => 'claims_policy',
            'user_route' => 'claims_user',
            'policy_history' => $this->getSalvaPhonePolicyHistory($policy->getId()),
            'user_history' => $this->getUserHistory($policy->getUser()->getId()),
            'oa' => $oa,
            'claim_types' => Claim::$claimTypes,
            'phones' => $dm->getRepository(Phone::class)->findActive()->getQuery()->execute(),
            'now' => \DateTime::createFromFormat('U', time()),
            'claim' => $claim,
            'previousPicSureStatus' => $previousPicSureStatus,
        ];
    }

    /**
     * @Route("/claims-form/{id}/policy", name="claims_claims_form_policy")
     * @Route("/claims-form/{id}/claims", name="claims_claims_form_claims")
     */
    public function claimsFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        /** @var Claim $claim */
        $claim = $repo->find($id);

        $claimsNoteForm = $this->get('form.factory')
            ->createNamedBuilder('claims_note_form', ClaimNoteType::class, $claim)
            ->setAction($this->generateUrl(
                $request->get('_route'),
                ['id' => $id]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('claims_note_form')) {
                $claimsNoteForm->handleRequest($request);
                if ($claimsNoteForm->isValid()) {
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        sprintf('Claim %s updated', $claim->getNumber())
                    );
                }
            }

            if ($request->get('_route') == 'claims_claims_form_policy') {
                return $this->redirectToRoute('claims_policy', ['id' => $claim->getPolicy()->getId()]);
            } else {
                return $this->redirectToRoute('claims_claims');
            }
        }

        return $this->render('AppBundle:Claims:claimsModalBody.html.twig', [
            'claim' => $claim,
            'claim_note_form' => $claimsNoteForm->createView(),
        ]);
    }

    /**
     * @Route("/download/{id}", name="claims_download_file")
     * @Route("/download/{id}/attachment", name="claims_download_file_attachment")
     */
    public function claimsDownloadFileAction(Request $request, $id)
    {
        return $this->policyDownloadFile(
            $id,
            $request->get('_route') == 'claims_download_file_attachment'
        );
    }

    /**
     * @Route("/phone/{id}/alternatives", name="claims_phone_alternatives")
     * @Method({"GET"})
     */
    public function phoneAlternativesAction($id)
    {
        return $this->phoneAlternatives($id);
    }

    /**
     * @Route("/claim/{id}", name="claims_notes", requirements={"id":"[0-9a-f]{24,24}"})
     * @Method({"POST"})
     */
    public function claimsNotesAction(Request $request, $id)
    {
        return $this->claimsNotes($request, $id);
    }

    /**
     * @Route("/claim/{number}", name="claims_claim_number")
     */
    public function claimsClaimNumberAction($number)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        /** @var Claim $claim */
        $claim = $repo->findOneBy(['number' => $number]);
        if (!$claim) {
            throw $this->createNotFoundException('Policy not found');
        }

        return $this->redirectToRoute('claims_policy', ['id' => $claim->getPolicy()->getId()]);
    }

    /**
     * @Route("/claims", name="claims_claims")
     * @Template()
     */
    public function claimsAction(Request $request)
    {
        return $this->searchClaims($request);
    }
}
