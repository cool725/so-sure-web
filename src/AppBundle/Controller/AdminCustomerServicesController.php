<?php

namespace AppBundle\Controller;

use AppBundle\Document\ArrayToApiArrayTrait;
use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\File\AccessPayFile;
use AppBundle\Document\File\ReconciliationFile;
use AppBundle\Document\Sequence;
use AppBundle\Form\Type\BacsMandatesType;
use AppBundle\Form\Type\UploadFileType;
use AppBundle\Form\Type\ReconciliationFileType;
use AppBundle\Form\Type\SequenceType;
use AppBundle\Repository\BacsPaymentRepository;
use AppBundle\Repository\File\BarclaysFileRepository;
use AppBundle\Repository\File\BarclaysStatementFileRepository;
use AppBundle\Repository\File\JudoFileRepository;
use AppBundle\Repository\File\LloydsFileRepository;
use AppBundle\Repository\File\ReconcilationFileRepository;
use AppBundle\Repository\File\S3FileRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\BacsService;
use AppBundle\Service\LloydsService;
use AppBundle\Service\MailerService;
use AppBundle\Service\ReportingService;
use AppBundle\Service\SequenceService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Classes\SoSure;
use AppBundle\Document\DateTrait;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Claim;
use AppBundle\Document\Charge;
use AppBundle\Document\Cashback;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\ChargebackPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\SoSurePayment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Invoice;
use AppBundle\Document\Feature;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Stats;
use AppBundle\Document\Opt\OptOut;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\SmsOptOut;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\File\S3File;
use AppBundle\Document\File\JudoFile;
use AppBundle\Document\File\BarclaysFile;
use AppBundle\Document\File\BarclaysStatementFile;
use AppBundle\Document\File\LloydsFile;
use AppBundle\Document\Form\Cancel;
use AppBundle\Form\Type\CancelPolicyType;
use AppBundle\Form\Type\ClaimType;
use AppBundle\Form\Type\CashbackSearchType;
use AppBundle\Form\Type\ClaimFlagsType;
use AppBundle\Form\Type\ChargebackType;
use AppBundle\Form\Type\ImeiType;
use AppBundle\Form\Type\NoteType;
use AppBundle\Form\Type\EmailOptOutType;
use AppBundle\Form\Type\AdminSmsOptOutType;
use AppBundle\Form\Type\PartialPolicyType;
use AppBundle\Form\Type\UserSearchType;
use AppBundle\Form\Type\PhoneSearchType;
use AppBundle\Form\Type\JudoFileType;
use AppBundle\Form\Type\FacebookType;
use AppBundle\Form\Type\BarclaysFileType;
use AppBundle\Form\Type\BarclaysStatementFileType;
use AppBundle\Form\Type\LloydsFileType;
use AppBundle\Form\Type\PendingPolicyCancellationType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;
use MongoRegex;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

/**
 * @Route("/admin")
 * @Security("has_role('ROLE_CUSTOMER_SERVICES')")
 */
class AdminCustomerServicesController extends BaseController
{
    use DateTrait;
    use CurrencyTrait;
    use ArrayToApiArrayTrait;

    /**
     * @Route("/claims/replacement-phone", name="admin_claims_replacement_phone")
     * @Method({"POST"})
     */
    public function adminClaimsReplacementPhoneAction(Request $request)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        $phoneRepo = $dm->getRepository(Phone::class);
        $claim = $repo->find($request->get('id'));
        if (!$claim) {
            throw $this->createNotFoundException('Claim not found');
        }

        $phone = $phoneRepo->find($request->get('phone'));
        if ($claim && $phone) {
            $claim->setReplacementPhone($phone);
            $dm->flush();
        }
        return $this->redirectToRoute('admin_claims');
    }

    /**
     * @Route("/claims/update-claim/{route}", name="admin_claims_update_claim")
     * @Route("/claims/update-claim/{route}/{policyId}", name="admin_claims_update_claim_policy")
     * @Method({"POST"})
     */
    public function adminClaimsUpdateClaimAction(Request $request, $route = null, $policyId = null)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }
        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        /** @var Claim $claim */
        $claim = $repo->find($request->get('id'));
        if (!$claim) {
            throw $this->createNotFoundException('Claim not found');
        }

        // inputs that change specific fields on the /admin/claims modal
        if ($request->get('change-claim-number')) {
            $updatedClaimNumber = $request->get('claims-detail-number');
            $updatedClaimNumber = trim($updatedClaimNumber);
            if (empty($updatedClaimNumber)) {
                throw new \LengthException('New claim number cannot be empty');
            }
            $claim->setNumber($updatedClaimNumber, true);
        }

        if ($request->get('change-claim-type') && $request->get('claim-type')) {
            $claim->setType($request->get('claim-type'), true);
        }
        if ($request->get('change-approved-date') && $request->get('approved-date')) {
            $date = new \DateTime($request->get('approved-date'));
            $claim->setApprovedDate($date);
        }
        if ($request->get('update-replacement-phone') && $request->get('replacement-phone')) {
            $phoneRepo = $dm->getRepository(Phone::class);
            $phone = $phoneRepo->find($request->get('replacement-phone'));
            if ($phone) {
                $claim->setReplacementPhone($phone);
            }
        }
        $dm->flush();

        if ($policyId) {
            return $this->redirectToRoute($route, ['id' => $policyId]);
        } elseif ($route) {
            return $this->redirectToRoute($route);
        } else {
            return $this->redirectToRoute('admin_claims');
        }
    }

    /**
     * @Route("/claim/flag/{id}", name="admin_claim_flags")
     * @Method({"POST"})
     */
    public function adminClaimFlagsAction(Request $request, $id)
    {
        $formData = $request->get('claimflags');
        if (!isset($formData['_token'])) {
            throw new \InvalidArgumentException('Missing parameters');
        }
        // TODO: Find default intent for forms. hack to add a second token with known intent
        if (!$this->isCsrfTokenValid('flags', $request->get('_csrf_token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        $claim = $repo->find($id);
        if (!$claim) {
            throw $this->createNotFoundException('Claim not found');
        }

        $formData = $request->get('claimflags');
        $claim->clearIgnoreWarningFlags();
        // may be empty if all unchecked
        if (isset($formData['ignoreWarningFlags'])) {
            foreach ($formData['ignoreWarningFlags'] as $flag) {
                $claim->setIgnoreWarningFlags($flag);
            }
        }
        $dm->flush();

        $this->addFlash(
            'success',
            'Claim flags updated'
        );

        return new RedirectResponse($this->generateUrl('admin_policy', ['id' => $claim->getPolicy()->getId()]));
    }

    /**
     * @Route("/claim/withdraw/{id}", name="admin_claim_withdraw")
     * @Method({"POST"})
     */
    public function adminClaimWithdrawAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('_token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        $claim = $repo->find($id);
        if (!$claim) {
            throw $this->createNotFoundException('Claim not found');
        }
        if (!in_array($claim->getStatus(), [
            Claim::STATUS_FNOL,
            Claim::STATUS_SUBMITTED,
            Claim::STATUS_INREVIEW,
            Claim::STATUS_PENDING_CLOSED,
        ])) {
            throw new \Exception(
                'Claim can only be withdrawn if claim is fnol, submitted, in-review or pending-closed state'
            );
        }

        $claim->setStatus(Claim::STATUS_WITHDRAWN);
        $dm->flush();

        $this->addFlash(
            'success',
            sprintf('Claim %s withdrawn', $claim->getNumber())
        );

        return new RedirectResponse($this->generateUrl('admin_policy', ['id' => $claim->getPolicy()->getId()]));
    }
}
