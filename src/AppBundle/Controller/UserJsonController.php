<?php

namespace AppBundle\Controller;

use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\File\PolicyTermsFile;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Security\UserVoter;
use AppBundle\Security\ClaimVoter;
use AppBundle\Service\BacsService;
use AppBundle\Service\ClaimsService;
use AppBundle\Service\InvitationService;
use AppBundle\Service\PaymentService;
use AppBundle\Service\PCAService;
use AppBundle\Service\PolicyService;
use AppBundle\Service\SequenceService;
use AppBundle\Service\SmsService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Document\Cashback;
use AppBundle\Document\Charge;
use AppBundle\Document\Feature;
use AppBundle\Document\User;
use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Claim;
use AppBundle\Document\Form\Renew;
use AppBundle\Document\Form\RenewCashback;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Form\ClaimFnol;
use AppBundle\Document\Form\ClaimFnolDamage;
use AppBundle\Document\Form\ClaimFnolTheftLoss;
use AppBundle\Document\Form\ClaimFnolUpdate;
use AppBundle\Form\Type\BacsType;
use AppBundle\Form\Type\BacsConfirmType;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\EmailInvitationType;
use AppBundle\Form\Type\UserEmailType;
use AppBundle\Form\Type\SCodeInvitationType;
use AppBundle\Form\Type\InvitationType;
use AppBundle\Form\Type\RenewType;
use AppBundle\Form\Type\RenewCashbackType;
use AppBundle\Form\Type\CashbackType;
use AppBundle\Form\Type\SentInvitationType;
use AppBundle\Form\Type\UnconnectedUserPolicyType;
use AppBundle\Form\Type\RenewConnectionsType;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Form\BillingDay;
use AppBundle\Form\Type\BillingDayType;
use AppBundle\Form\Type\ClaimFnolType;
use AppBundle\Form\Type\ClaimFnolConfirmType;
use AppBundle\Form\Type\ClaimFnolDamageType;
use AppBundle\Form\Type\ClaimFnolTheftLossType;
use AppBundle\Form\Type\ClaimFnolUpdateType;

use AppBundle\Service\FacebookService;
use AppBundle\Security\InvitationVoter;
use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;
use AppBundle\Service\JudopayService;

use AppBundle\Security\PolicyVoter;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Facebook\Facebook;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use AppBundle\Exception\ValidationException;

use AppBundle\Exception\FullPotException;
use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\ProcessedException;
use AppBundle\Exception\SelfInviteException;
use AppBundle\Exception\InvalidPolicyException;
use AppBundle\Exception\OptOutException;
use AppBundle\Exception\ConnectedInvitationException;
use AppBundle\Exception\ClaimException;
use AppBundle\Exception\PaymentDeclinedException;
use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Exception\InvalidUserDetailsException;
use AppBundle\Exception\GeoRestrictedException;
use AppBundle\Exception\DuplicateImeiException;
use AppBundle\Exception\DuplicateInvitationException;
use AppBundle\Exception\LostStolenImeiException;
use AppBundle\Exception\InvalidImeiException;
use AppBundle\Exception\ImeiBlacklistedException;
use AppBundle\Exception\ImeiPhoneMismatchException;
use AppBundle\Exception\InvalidEmailException;
use AppBundle\Exception\DirectDebitBankException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

/**
 * @Route("/user/json")
 * @Security("has_role('ROLE_USER')")
 */
class UserJsonController extends BaseController
{
    use DateTrait;
    use CurrencyTrait;

    /**
     * @Route("/invite/email", name="json_invite_email")
     * @Method({"POST"})
     */
    public function inviteEmailAction(Request $request)
    {
        $user = $this->getUser();
        $this->denyAccessUnlessGranted(UserVoter::VIEW, $user);
        $policy = $user->getLatestPolicy();
        $email = $request->get("email");
        if (!$email) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, "Email parameter missing.");
        } elseif (!$this->isCsrfTokenValid("invite-email", $request->request->get('csrf'))) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, "Invalid CSRF token.");
        } elseif (!$policy) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, "User lacks policy.");
        } elseif ($user->getEmail() == $email) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVITATION_SELF_INVITATION, "Self invitation.");
        }
        try {
            /** @var InvitationService $invitationService */
            $invitationService = $this->get("app.invitation");
            $invitationService->inviteByEmail($policy, $email, null, null, 'Onboarding');
            return $this->getSuccessJsonResponse("Email invitation sent.");
        } catch (InvalidPolicyException $e) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_POLICY_INVALID_VALIDATION, "Invalid policy.");
        } catch (SelfInviteException $e) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVITATION_SELF_INVITATION, "Self invitation.");
        } catch (DuplicateInvitationException $e) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVITATION_DUPLICATE, "Duplicate invitation.");
        } catch (FullPotException $e) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVITATION_MAXPOT, "User's pot full.");
        } catch (\Exception $e) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, $e->getMessage());
        }
    }

    /**
     * @Route("/app/sms", name="json_app_sms")
     * @Method({"POST"})
     */
    public function appSmsAction()
    {
        $user = $this->getUser();
        $this->denyAccessUnlessGranted(UserVoter::VIEW, $user);
        $dm = $this->getManager();
        $chargeRepository = $dm->getRepository(Charge::class);
        /** @var SmsService $smsService */
        $smsService = $this->get('app.sms');
        $mobileNumber = $user->getMobileNumber();
        if (!$mobileNumber) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, "User lacks phone number.");
        } elseif ($user->getFirstLoginInApp()) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, "User already has app.");
        } elseif ($chargeRepository->findLastByUser($user, Charge::TYPE_SMS_DOWNLOAD)) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, "User already sent sms.");
        }
        $sent = $smsService->sendTemplate(
            $mobileNumber,
            'AppBundle:Sms:text-me.txt.twig',
            ['branch_pot_url' => $this->getParameter('branch_pot_url')],
            $user->getLatestPolicy(),
            Charge::TYPE_SMS_DOWNLOAD
        );
        if ($sent) {
            $sixpack = $this->get('app.sixpack');
            $sixpack->convertByClientId($user->getId(), $sixpack::EXPERIMENT_APP_LINK_SMS);

            /** @var MixpanelService $mixpanel */
            $mixpanel = $this->get('app.mixpanel');
            $mixpanel->queueTrackWithUser($user, MixpanelService::EVENT_TEST, ['Test Name' => 'SMS Download Link']);

            return $this->getSuccessJsonResponse("Sms invitation sent.");
        } else {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, "Sms could not be sent.");
        }
    }

    /**
     * @Route("/policyterms", name="json_policyterms")
     * @Method({"GET"})
     */
    public function policyTermsAction()
    {
        $user = $this->getUser();
        $this->denyAccessUnlessGranted(UserVoter::VIEW, $user);
        $s3 = $this->get("app.twig.s3");
        $policy = $user->getLatestPolicy();
        if (!$policy) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, "User lacks policy.");
        }
        $policyService = $this->get("app.policy");
        $policyTermsFile = $policy->getLatestPolicyTermsFile();
        if (!$policyTermsFile) {
            return new JsonResponse(["description" => "File not yet generated.", "code" => ApiErrorCode::SUCCESS], 200);
        }
        $file = $s3->s3DownloadLink($policyTermsFile->getBucket(), $policyTermsFile->getKey());
        return new JsonResponse(
            [
                "file" => "{$file}",
                "description" => "File download available.",
                "code" => ApiErrorCode::SUCCESS
            ],
            200
        );
    }
}
