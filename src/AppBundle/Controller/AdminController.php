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
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Payment;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\User;
use AppBundle\Document\File\S3File;
use AppBundle\Form\Type\CancelPolicyType;
use AppBundle\Form\Type\ClaimType;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\UserSearchType;
use AppBundle\Form\Type\PhoneSearchType;
use AppBundle\Form\Type\YearMonthType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;
use MongoRegex;

/**
 * @Route("/admin")
 */
class AdminController extends BaseController
{
    /**
     * @Route("/", name="admin_home")
     * @Template
     */
    public function indexAction()
    {
        return [];
    }

    /**
     * @Route("/phones", name="admin_phones")
     * @Template
     */
    public function phonesAction(Request $request)
    {
        $csrf = $this->get('form.csrf_provider');
        $expectedClaimFrequency = $this->getParameter('expected_claim_frequency');
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phones = $repo->createQueryBuilder();
        $phones = $phones->field('make')->notEqual('ALL');

        $form = $this->createForm(PhoneSearchType::class, null, ['method' => 'GET']);
        $form->handleRequest($request);
        $data = $form->get('os')->getData();
        $phones = $phones->field('os')->in($data);
        $data = filter_var($form->get('active')->getData(), FILTER_VALIDATE_BOOLEAN);
        $phones = $phones->field('active')->equals($data);
        $rules = $form->get('rules')->getData();
        if ($rules == 'missing') {
            $phones = $phones->field('suggestedReplacement')->exists(false);
            $phones = $phones->field('replacementPrice')->lte(0);
        } elseif ($rules == 'retired') {
            $retired = new \DateTime();
            $retired->sub(new \DateInterval(sprintf('P%dM', Phone::MONTHS_RETIREMENT + 1)));
            $phones = $phones->field('releaseDate')->lte($retired);
        } elseif ($rules == 'loss') {
            $phoneIds = [];
            foreach ($phones->getQuery()->execute() as $phone) {
                if ($phone->policyProfit($expectedClaimFrequency) < 0) {
                    $phoneIds[] = $phone->getId();
                }
            }
            $phones->field('id')->in($phoneIds);
        } elseif ($rules == 'price') {
            $phoneIds = [];
            foreach ($phones->getQuery()->execute() as $phone) {
                if (abs($phone->policyProfit($expectedClaimFrequency)) > 30) {
                    $phoneIds[] = $phone->getId();
                }
            }
            $phones->field('id')->in($phoneIds);
        } elseif ($rules == 'brightstar') {
            $replacementPhones = clone $phones;
            $phones = $phones->field('replacementPrice')->lte(0);
            $phones = $phones->field('initialPrice')->gte(300);
            $year = new \DateTime();
            $year->sub(new \DateInterval('P1Y'));
            $phones = $phones->field('releaseDate')->gte($year);

            $phoneIds = [];
            foreach ($phones->getQuery()->execute() as $phone) {
                $phoneIds[] = $phone->getId();
            }
            foreach ($replacementPhones->getQuery()->execute() as $phone) {
                if ($phone->getSuggestedReplacement() &&
                    $phone->getSuggestedReplacement()->getMemory() < $phone->getMemory()) {
                    $phoneIds[] = $phone->getId();
                }
            }

            $phones = $replacementPhones->field('id')->in($phoneIds);
        } elseif ($rules == 'replacement') {
            $phones = $phones->field('suggestedReplacement')->exists(true);
        }
        $pager = $this->pager($request, $phones);

        return [
            'phones' => $pager->getCurrentPageResults(),
            'token' => $csrf->generateCsrfToken('default'),
            'form' => $form->createView(),
            'pager' => $pager
        ];
    }

    /**
     * @Route("/phone", name="admin_phone_add")
     * @Method({"POST"})
     */
    public function phoneAddAction(Request $request)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $devices = explode("|", $request->get('devices'));
        $devices = array_filter(array_map('trim', $devices));
        $phone = new Phone();
        $phone->setMake($request->get('make'));
        $phone->setModel($request->get('model'));
        $phone->setDevices($devices);
        $phone->setMemory($request->get('memory'));
        $phone->getCurrentPhonePrice()->setGwp($request->get('gwp'));
        $dm->persist($phone);
        $dm->flush();
        $this->addFlash(
            'notice',
            'Your changes were saved!'
        );

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }
    
    /**
     * @Route("/phone/{id}", name="admin_phone_edit")
     * @Method({"POST"})
     */
    public function phoneEditAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if ($phone) {
            $devices = explode("|", $request->get('devices'));
            $devices = array_filter(array_map('trim', $devices));
            $phone->setMake($request->get('make'));
            $phone->setModel($request->get('model'));
            $phone->setDevices($devices);
            $phone->setMemory($request->get('memory'));
            $active = filter_var($request->get('active'), FILTER_VALIDATE_BOOLEAN);
            $phone->setActive($active);
            $phone->getCurrentPhonePrice()->setGwp($request->get('gwp'));
            $dm->flush();
            $this->addFlash(
                'notice',
                'Your changes were saved!'
            );
        }

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }

    /**
     * @Route("/phone/{id}", name="admin_phone_delete")
     * @Method({"DELETE"})
     */
    public function phoneDeleteAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if ($phone) {
            $dm->remove($phone);
            $dm->flush();
            $this->addFlash(
                'notice',
                'Phone deleted!'
            );
        }

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }

    /**
     * @Route("/users", name="admin_users")
     * @Template("AppBundle::Claims/claimsUsers.html.twig")
     */
    public function adminUsersAction(Request $request)
    {
        $csrf = $this->get('form.csrf_provider');
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);

        $users = $repo->createQueryBuilder();
        $form = $this->createForm(UserSearchType::class, null, ['method' => 'GET']);
        $form->handleRequest($request);
        $this->formToMongoSearch($form, $users, 'email', 'email');
        $this->formToMongoSearch($form, $users, 'lastname', 'lastName');
        $this->formToMongoSearch($form, $users, 'mobile', 'mobileNumber');
        $this->formToMongoSearch($form, $users, 'postcode', 'billingAddress.postcode');

        $policyRepo = $dm->getRepository(Policy::class);
        $policiesQb = $policyRepo->createQueryBuilder();
        if ($policies = $this->formToMongoSearch($form, $policiesQb, 'policy', 'policyNumber', true)) {
            $userIds = [];
            foreach ($policies as $policy) {
                $userIds[] = $policy->getUser()->getId();
            }
            $users->field('id')->in($userIds);
        }
        $policiesQb = $policyRepo->createQueryBuilder();
        if ($policies = $this->formToMongoSearch($form, $policiesQb, 'status', 'status', true)) {
            $userIds = [];
            foreach ($policies as $policy) {
                $userIds[] = $policy->getUser()->getId();
            }
            $users->field('id')->in($userIds);
        }
        $pager = $this->pager($request, $users);

        return [
            'users' => $pager->getCurrentPageResults(),
            'token' => $csrf->generateCsrfToken('default'),
            'pager' => $pager,
            'form' => $form->createView(),
            'policy_route' => 'admin_policy',
        ];
    }

    /**
     * @Route("/policy/{id}", name="admin_policy")
     * @Template("AppBundle::Admin/claimsPolicy.html.twig")
     */
    public function claimsPolicyAction(Request $request, $id)
    {
        $policyService = $this->get('app.policy');
        $fraudService = $this->get('app.fraud');
        $dm = $this->getManager();
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }
        $form = $this->createForm(CancelPolicyType::class);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $policyService->cancel($policy, $form->get('cancelledReason')->getData());
            $this->addFlash(
                'success',
                sprintf('Policy %s was cancelled.', $policy->getPolicyNumber())
            );

            return $this->redirectToRoute('admin_users');
        }
        $checks = $fraudService->runChecks($policy);

        return [
            'policy' => $policy,
            'form' => $form->createView(),
            'fraud' => $checks,
            'policy_route' => 'admin_policy',
            'policy_history' => $this->getPhonePolicyHistory($policy->getId()),
            'user_history' => $this->getUserHistory($policy->getUser()->getId()),
        ];
    }

    /**
     * @Route("/claims", name="admin_claims")
     * @Template("AppBundle::Admin/claims.html.twig")
     */
    public function adminClaimsAction(Request $request)
    {
        $csrf = $this->get('form.csrf_provider');
        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        $qb = $repo->createQueryBuilder();
        $pager = $this->pager($request, $qb);

        return [
            'claims' => $pager->getCurrentPageResults(),
            'token' => $csrf->generateCsrfToken('default'),
            'pager' => $pager,
        ];
    }

    /**
     * @Route("/accounts", name="admin_accounts")
     * @Route("/accounts/{year}/{month}", name="admin_accounts_date")
     * @Template
     */
    public function adminAccountsAction(Request $request, $year = null, $month = null)
    {
        $now = new \DateTime();
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = new \DateTime(sprintf('%d-%d-01', $year, $month));

        $dm = $this->getManager();
        $paymentRepo = $dm->getRepository(JudoPayment::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $s3FileRepo = $dm->getRepository(S3File::class);

        $payments = $paymentRepo->getAllPaymentsForExport($date);
        $total = 0;
        $brokerFee = 0;
        $sosureBrokerFee = 0;
        $aflBrokerFee = 0;
        foreach($payments as $payment) {
            $total += $payment->getAmount();
            $brokerFee += $payment->getBrokerFee();
            $sosureBrokerFee += $payment->getSoSureBrokerFee();
            $aflBrokerFee += $payment->getAflBrokerFee();
        }
        $numPayments = count($payments);
        $avgPayment = 'n/a';
        if ($numPayments > 0) {
            $avgPayment = $total / $numPayments;
        }

        $form = $this->createForm(YearMonthType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return $this->redirectToRoute('admin_accounts_date', [
                'year' => $form->get('year')->getData(),
                'month' => $form->get('month')->getData()
            ]);
        }

        return [
            'form' => $form->createView(),
            'year' => $year,
            'month' => $month,
            'total' => $total,
            'brokerFee' => $brokerFee,
            'sosureBrokerFee' => $sosureBrokerFee,
            'aflBrokerFee' => $aflBrokerFee,
            'underwriterAmount' => $total - $brokerFee,
            'numPayments' => $numPayments,
            'avgPayment' => $avgPayment,
            // TODO: query will eve
            'activePolicies' => $phonePolicyRepo->countAllActivePolicies($date),
            'files' => $s3FileRepo->getAllFiles($date),
        ];
    }
}
