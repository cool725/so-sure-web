<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Form\Type\LaunchType;
use AppBundle\Form\Type\PhoneType;

use AppBundle\Document\Address;
use AppBundle\Document\Payment;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Sns;
use AppBundle\Document\SCode;
use AppBundle\Document\User;
use AppBundle\Document\MultiPay;
use AppBundle\Document\Invitation\Invitation;

use AppBundle\Document\PhoneTrait;

use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\ProcessedException;
use AppBundle\Exception\SelfInviteException;
use AppBundle\Exception\FullPotException;
use AppBundle\Exception\DuplicateInvitationException;
use AppBundle\Exception\InvalidPolicyException;
use AppBundle\Exception\OptOutException;
use AppBundle\Exception\ConnectedInvitationException;
use AppBundle\Exception\ClaimException;
use AppBundle\Exception\PaymentDeclinedException;
use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Exception\ValidationException;
use AppBundle\Exception\InvalidUserDetailsException;
use AppBundle\Exception\GeoRestrictedException;
use AppBundle\Exception\DuplicateImeiException;
use AppBundle\Exception\LostStolenImeiException;
use AppBundle\Exception\InvalidImeiException;
use AppBundle\Exception\ImeiBlacklistedException;
use AppBundle\Exception\ImeiPhoneMismatchException;

use AppBundle\Service\RateLimitService;
use AppBundle\Service\PushService;
use AppBundle\Service\JudopayService;

use AppBundle\Security\UserVoter;
use AppBundle\Classes\ApiErrorCode;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Egulias\EmailValidator\EmailValidator;

/**
 * @Route("/api/v1/auth")
 */
class ApiAuthController extends BaseController
{
    use PhoneTrait;

    /**
     * @Route("/address", name="api_auth_address")
     * @Method({"GET"})
     */
    public function addressAction(Request $request)
    {
        try {
            if (!$this->validateQueryFields($request, ['postcode'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $rateLimit = $this->get('app.ratelimit');
            if (!$rateLimit->allowedByDevice(
                RateLimitService::DEVICE_TYPE_ADDRESS,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            $postcode = $this->conformAlphanumericSpaceDot($this->getRequestString($request, 'postcode'), 10);
            // although it says number, some people will try to put in their address
            $number = $this->conformAlphanumericSpaceDot($this->getRequestString($request, 'number'), 50);

            $lookup = $this->get('app.address');
            if (!$address = $lookup->getAddress($postcode, $number, $this->getUser())) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            return new JsonResponse($address->toApiArray());
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api addressAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/invitation/{id}", name="api_auth_process_invitation")
     * @Method({"POST"})
     */
    public function processInvitationAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['action'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }
            $action = $this->getDataString($data, 'action');
            if ($action == 'accept' && !isset($data['policy_id'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(Invitation::class);
            $policyRepo = $dm->getRepository(Policy::class);
            $invitation = $repo->find($id);
            if (!$invitation) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find invitation',
                    404
                );
            }

            if ($invitation->isProcessed()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVITATION_PREVIOUSLY_PROCESSED,
                    'Invitation has already been accepted/reject',
                    422
                );
            }

            $invitationService = $this->get('app.invitation');

            if ($action == 'accept') {
                $this->denyAccessUnlessGranted('accept', $invitation);
                $policy = $policyRepo->find($this->getDataString($data, 'policy_id'));
                // TODO: Validation user hasn't exceeded pot amout
                $invitationService->accept($invitation, $policy);
            } elseif ($action == 'reject') {
                $this->denyAccessUnlessGranted('reject', $invitation);
                $invitationService->reject($invitation);
            } elseif ($action == 'cancel') {
                $this->denyAccessUnlessGranted('cancel', $invitation);
                $invitationService->cancel($invitation);
            } elseif ($action == 'reinvite') {
                $this->denyAccessUnlessGranted('reinvite', $invitation);
                //\Doctrine\Common\Util\Debug::dump($invitation);
                $invitationService->reinvite($invitation);
            } else {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                    'Unknown action',
                    422
                );
            }

            return new JsonResponse($this->getUser()->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (RateLimitException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVITATION_LIMIT,
                'Too many reinvitations to that email/mobile',
                422
            );
        } catch (ProcessedException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVITATION_PREVIOUSLY_PROCESSED,
                'Invitation already processed',
                422
            );
        } catch (ConnectedInvitationException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVITATION_CONNECTED,
                'Already connected invitation',
                422
            );
        } catch (FullPotException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVITATION_MAXPOT,
                'User has a full pot',
                422
            );
        } catch (ClaimException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVITATION_POLICY_HAS_CLAIM,
                'User has previously claimed',
                422
            );
        } catch (OptOutException $e) {
            // OptOut is a failsafe on so-sure invitations
            // Should never occur, but if it does, the maxpot response should be appropiate to display
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVITATION_MAXPOT,
                'User has a full pot',
                422
            );
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api processInvitation.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/multipay/{id}", name="api_auth_put_multipay")
     * @Method({"PUT"})
     */
    public function putMultiPayAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['action'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }
            $action = $this->getDataString($data, 'action');
            $amount = $this->getDataString($data, 'amount');
            if (!in_array($action, ['accept', 'reject'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, 'Unknown action', 422);
            } elseif ($action == 'accept' && !$amount) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $multiPayRepo = $dm->getRepository(MultiPay::class);

            $multiPay = $multiPayRepo->find($id);
            if (!$multiPay) {
                throw new NotFoundHttpException();
            } elseif ($multiPay->getPolicy()->getStatus() != Policy::STATUS_MULTIPAY_REQUESTED) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                    'Invalid policy status',
                    422
                );
            }
            // TODO: Validate payment amount for accept

            $this->denyAccessUnlessGranted('pay', $multiPay);

            if ($action == 'accept') {
                $multiPay->setStatus(MultiPay::STATUS_ACCEPTED);

                /** @var $judopay JudopayService */
                $judopay = $this->get('app.judopay');
                $judopay->multiPay($multiPay, $amount);
            } else {
                $multiPay->setStatus(MultiPay::STATUS_REJECTED);
                $multiPay->getPolicy()->setStatus(Policy::STATUS_MULTIPAY_REJECTED);
            }
            $dm->flush();

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, $action, 200);
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (NotFoundHttpException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_NOT_FOUND,
                'Unable to find multipay',
                404
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api putMultiPayAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/ping", name="api_auth_ping")
     * @Method({"GET"})
     */
    public function pingAuthAction()
    {
        return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
    }

    /**
     * @Route("/policy", name="api_auth_new_policy")
     * @Method({"POST"})
     */
    public function newPolicyAction(Request $request)
    {
        try {
            $this->get('statsd')->startTiming("api.newPolicy");
            $data = json_decode($request->getContent(), true)['body'];
            if (!isset($data['phone_policy'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }
            $phonePolicyData = $data['phone_policy'];

            if (!$this->validateFields(
                $phonePolicyData,
                ['imei', 'make', 'device', 'memory', 'rooted', 'validation_data']
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            if ($this->getDataBool($phonePolicyData, 'rooted')) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_IMEI_BLACKLISTED,
                    'Not able to insure rooted devices at the moment',
                    422
                );
            }

            $redis = $this->get('snc_redis.default');
            if ($redis->exists('ERROR_NOT_YET_REGULATED')) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_YET_REGULATED,
                    "Coming soon",
                    422
                );
            }

            $imeiValidator = $this->get('app.imei');
            $jwtValidator = $this->get('app.jwt');
            $user = $this->getUser();
            $this->denyAccessUnlessGranted(UserVoter::ADD_POLICY, $user);

            // We'll probably want to change this in the future, but for now, a user can only create 1 policy
            if ($user->hasPolicy()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_POLICY_LIMIT,
                    'User can only have 1 policy',
                    422
                );
            }

            $phone = $this->getPhone(
                $this->getDataString($phonePolicyData, 'make'),
                $this->getDataString($phonePolicyData, 'device'),
                $this->getDataString($phonePolicyData, 'memory')
            );
            if (!$phone) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to provide policy for this phone',
                    404
                );
            }

            $imei = str_replace(' ', '', $this->getDataString($phonePolicyData, 'imei'));
            $serialNumber = $this->getDataString($phonePolicyData, 'serial_number');
            // For phones without a serial number, run check on imei
            if (!$serialNumber) {
                $serialNumber = $imei;
            }

            try {
                $jwtValidator->validate(
                    $this->getCognitoIdentityId($request),
                    $this->getDataString($phonePolicyData, 'validation_data'),
                    ['imei' => $this->getDataString($phonePolicyData, 'imei')]
                );
            } catch (\InvalidArgumentException $argE) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_INVALID_VALIDATION,
                    'Validation data is not valid',
                    422
                );
            }

            $policyService = $this->get('app.policy');
            $policy = $policyService->init(
                $user,
                $phone,
                $imei,
                $serialNumber,
                $this->getIdentityLog($request),
                json_encode([
                    'make' => $this->getDataString($phonePolicyData, 'make'),
                    'device' => $this->getDataString($phonePolicyData, 'device'),
                    'memory' => $this->getDataString($phonePolicyData, 'memory'),
                ])
            );
            $policy->setName($this->conformAlphanumericSpaceDot($this->getDataString($phonePolicyData, 'name'), 100));

            $this->validateObject($policy);

            $dm = $this->getManager();
            $dm->persist($policy);
            $dm->flush();

            $this->get('statsd')->endTiming("api.newPolicy");

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (InvalidUserDetailsException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_INVALID_USER_DETAILS,
                'User needs first/last name, email, birthday & mobile number before policy can be created',
                422
            );
        } catch (RateLimitException $e) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
        } catch (GeoRestrictedException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_GEO_RESTRICTED,
                "User's billing address must be valid and in GB",
                422
            );
        } catch (InvalidImeiException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_IMEI_INVALID,
                'Imei fails validation checks',
                422
            );
        } catch (LostStolenImeiException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_IMEI_LOSTSTOLEN,
                'Imei was reported as lost/stolen',
                422
            );
        } catch (DuplicateImeiException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_DUPLICATE_IMEI,
                'Imei is already registered for a policy',
                422
            );
        } catch (ImeiBlacklistedException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_IMEI_BLACKLISTED,
                'Imei is blacklisted',
                422
            );
        } catch (ImeiPhoneMismatchException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_IMEI_PHONE_MISMATCH,
                'Imei/Phone mismatch',
                422
            );
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api newPolicy.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}", name="api_auth_get_policy")
     * @Method({"GET"})
     */
    public function getPolicyAction($id)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($id);
            if (!$policy) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find policy',
                    404
                );
            }
            $this->denyAccessUnlessGranted('view', $policy);

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api getPolicy.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}/invitation", name="api_auth_new_invitation")
     * @Method({"POST"})
     */
    public function newInvitationAction(Request $request, $id)
    {
        try {
            $this->get('statsd')->startTiming("api.newInvitation");
            $validator = new EmailValidator();
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($id);
            if (!$policy) {
                throw new NotFoundHttpException();
            }
            $this->denyAccessUnlessGranted('send-invitation', $policy);

            $invitationService = $this->get('app.invitation');
            // avoid sending email/sms invitations if testing
            if ($this->getRequestBool($request, 'debug')) {
                $invitationService->setDebug(true);
            }

            $data = json_decode($request->getContent(), true)['body'];
            $email = $this->getDataString($data, 'email');
            $mobile = $this->getDataString($data, 'mobile');
            $name = $this->conformAlphanumericSpaceDot($this->getDataString($data, 'name'), 250);
            $scode = $this->getDataString($data, 'scode');
            $skipSend = $this->getDataBool($data, 'skip_send');
            try {
                $invitation  = null;
                if ($email && $validator->isValid($email)) {
                    $invitation = $invitationService->inviteByEmail($policy, $email, $name, $skipSend);
                } elseif ($mobile && $this->isValidUkMobile($mobile)) {
                    $invitation = $invitationService->inviteBySms($policy, $mobile, $name, $skipSend);
                } elseif ($scode && SCode::isValidSCode($scode)) {
                    $invitation = $invitationService->inviteBySCode($policy, $scode);
                } else {
                    // TODO: General
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_NOT_FOUND,
                        'Missing data found',
                        422
                    );
                }
                $this->get('statsd')->endTiming("api.newInvitation");

                return new JsonResponse($invitation->toApiArray());
            } catch (DuplicateInvitationException $e) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVITATION_DUPLICATE,
                    'Duplicate invitation',
                    422
                );
            } catch (ConnectedInvitationException $e) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVITATION_CONNECTED,
                    'Already connected invitation',
                    422
                );
            } catch (OptOutException $e) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVITATION_OPTOUT,
                    'Person has opted out of invitations',
                    422
                );
            } catch (InvalidPolicyException $e) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_PAYMENT_REQUIRED,
                    'Policy not yet been paid',
                    422
                );
            } catch (SelfInviteException $e) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVITATION_SELF_INVITATION,
                    'User can not invite themself',
                    422
                );
            } catch (FullPotException $e) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVITATION_MAXPOT,
                    'User has a full pot',
                    422
                );
            } catch (ClaimException $e) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVITATION_POLICY_HAS_CLAIM,
                    'User has previously claimed',
                    422
                );
            } catch (NotFoundHttpException $e) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find policy/code',
                    404
                );
            } catch (\Exception $e) {
                $this->get('logger')->error('Error in api newInvitation.', ['exception' => $e]);

                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
            }
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api newInvitation.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}/pay", name="api_auth_policy_pay")
     * @Method({"POST"})
     */
    public function payPolicyAction(Request $request, $id)
    {
        try {
            $this->get('statsd')->startTiming("api.payPolicy");
            $data = json_decode($request->getContent(), true)['body'];
            $judoData = null;
            if (isset($data['bank_account'])) {
                // Not doing anymore, but too many tests currently expect gocardless, so allow for non-prod
                if ($this->getParameter('kernel.environment') == 'prod') {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
                }
                if (!$this->validateFields(
                    $data['bank_account'],
                    ['sort_code', 'account_number', 'first_name', 'last_name']
                )) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
            } elseif (isset($data['braintree'])) {
                // Not allow braintree
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
            } elseif (isset($data['judo'])) {
                if (!$this->validateFields($data['judo'], ['consumer_token', 'receipt_id'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
                $judoData = $data['judo'];
            } else {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $redis = $this->get('snc_redis.default');
            if ($redis->exists('ERROR_NOT_YET_REGULATED')) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_YET_REGULATED,
                    "Coming soon",
                    422
                );
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($id);
            if (!$policy) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find policy',
                    404
                );
            }
            $this->denyAccessUnlessGranted('edit', $policy);

            if (isset($data['bank_account'])) {
                $gocardless = $this->get('app.gocardless');
                $gocardless->add(
                    $policy,
                    $data['bank_account']['first_name'],
                    $data['bank_account']['last_name'],
                    $data['bank_account']['sort_code'],
                    $data['bank_account']['account_number']
                );
            } elseif (isset($data['braintree'])) {
                throw new \Exception('Braintree is no longer supported');
            } elseif ($judoData) {
                $judo = $this->get('app.judopay');
                $judo->add(
                    $policy,
                    $this->getDataString($judoData, 'receipt_id'),
                    $this->getDataString($judoData, 'consumer_token'),
                    $this->getDataString($judoData, 'card_token'),
                    Payment::SOURCE_MOBILE,
                    $this->getDataString($judoData, 'device_dna')
                );
            }
            $this->get('statsd')->endTiming("api.payPolicy");

            return new JsonResponse($policy->toApiArray());
        } catch (InvalidPremiumException $e) {
            $this->get('logger')->error(sprintf(
                'Invalid premium policy %s receipt %s.',
                $id,
                $this->getDataString($judoData, 'receipt_id')
            ), ['exception' => $e]);

            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_PAYMENT_INVALID_AMOUNT,
                'Invalid premium paid',
                422
            );
        } catch (\DomainException $e) {
            $this->get('logger')->error(sprintf(
                'Duplicate receipt id %s.',
                $this->getDataString($judoData, 'receipt_id')
            ), ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_POLICY_PAYMENT_REQUIRED, 'Payment not valid', 422);
        } catch (PaymentDeclinedException $e) {
            $this->get('logger')->info(sprintf(
                'Payment declined policy %s receipt %s.',
                $id,
                $this->getDataString($judoData, 'receipt_id')
            ), ['exception' => $e]);

            // Status should be set to null to avoid trigger monitoring alerts
            $policy->setStatus(null);
            $dm->flush();

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_POLICY_PAYMENT_DECLINED, 'Payment Declined', 422);
        } catch (AccessDeniedException $ade) {
            $this->get('logger')->warning(sprintf(
                'Access denied policy %s receipt %s.',
                $id,
                $this->getDataString($judoData, 'receipt_id')
            ), ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\InvalidArgumentException $e) {
            $this->get('logger')->error('User is invalid', ['exception' => $e]);

            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_INVALID_USER_DETAILS,
                'User is missing required details',
                422
            );
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api payPolicy.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/scode", name="api_auth_create_scode")
     * @Method({"POST"})
     */
    public function postSCodeAction(Request $request)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['type', 'policy_id'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($this->getDataString($data, 'policy_id'));
            if (!$policy) {
                throw new NotFoundHttpException();
            }
            $this->denyAccessUnlessGranted('edit', $policy);
            if ($this->getDataString($data, 'type') == SCode::TYPE_STANDARD && $policy->getStandardSCode()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                    'Only 1 standard active code is allowed',
                    422
                );
            }

            $scode = new SCode();
            $scode->setType($this->getDataString($data, 'type'));
            $scodeRepo = $dm->getRepository(SCode::class);
            if ($scodeRepo->findOneBy(['code' => $scode->getCode()])) {
                // TODO: Change to while loop
                throw new \Exception('duplicate code');
            }
            $shortLink = $this->get('app.branch')->generateSCode($scode->getCode());
            // branch is preferred, but can fallback to old website version if branch is down
            if (!$shortLink) {
                $link = $this->generateUrl('scode', ['code' => $scode->getCode()], true);
                $shortLink = $this->get('app.shortlink')->addShortLink($link);
            }
            $scode->setShareLink($shortLink);
            $policy->addSCode($scode);
            $this->validateObject($scode);

            $dm->flush();

            return new JsonResponse($scode->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (NotFoundHttpException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_NOT_FOUND,
                'Unable to find policy/code',
                404
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api postSCodeAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/scode/{code}", name="api_auth_delete_scode")
     * @Method({"DELETE"})
     */
    public function deletePolicySCodeAction($code)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);

            $scodeRepo = $dm->getRepository(SCode::class);
            $scode = $scodeRepo->findOneBy(['code' => $code]);
            if (!$scode || !$scode->isActive()) {
                throw new NotFoundHttpException();
            }

            $policy = $scode->getPolicy();
            if (!$policy) {
                throw new NotFoundHttpException();
            }
            $this->denyAccessUnlessGranted('edit', $policy);

            $scode->setActive(false);
            $dm->flush();

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, sprintf('%s inactivated', $code), 200);
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        /*
         * Nothing client can do for Validation Exception, so 500 response should be returned
        } catch (ValidationException $ex) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        */
        } catch (NotFoundHttpException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_NOT_FOUND,
                'Unable to find policy/code',
                404
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api deletePolicySCodeAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/scode/{code}", name="api_auth_put_scode")
     * @Method({"PUT"})
     */
    public function putPolicySCodeAction(Request $request, $code)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['action', 'policy_id'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }
            $action = $this->getDataString($data, 'action');
            if (!in_array($action, ['request', 'cancel'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, 'Unknown action', 422);
            }

            $dm = $this->getManager();
            $policyRepo = $dm->getRepository(Policy::class);
            $scodeRepo = $dm->getRepository(SCode::class);

            $scode = $scodeRepo->findOneBy(['code' => $code]);
            if (!$scode || !$scode->isActive() || !$scode->getPolicy()) {
                throw new NotFoundHttpException();
            }

            $scodePolicy = $scode->getPolicy();
            $policyId = $this->getDataString($data, 'policy_id');
            $multiPayPolicy = $policyRepo->find($policyId);
            if (!$multiPayPolicy) {
                throw new NotFoundHttpException();
            } elseif ($action == 'request' && $multiPayPolicy->getStatus() !== null) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                    'Policy status not consistent with request',
                    422
                );
            } elseif ($action == 'cancel' && $multiPayPolicy->getStatus() !== Policy::STATUS_MULTIPAY_REQUESTED) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                    'Policy status not consistent with cancellation',
                    422
                );
            }

            $this->denyAccessUnlessGranted('edit', $multiPayPolicy);

            if ($action == 'request') {
                $multiPay = new MultiPay();
                $multiPay->setSCode($scode);
                $multiPay->setPayee($this->getUser());
                $multiPay->setPolicy($multiPayPolicy);
                $scodePolicy->getUser()->addMultiPay($multiPay);
                $multiPayPolicy->setStatus(Policy::STATUS_MULTIPAY_REQUESTED);

                try {
                    $push = $this->get('app.push');
                    // @codingStandardsIgnoreStart
                    $push->sendToUser(PushService::MESSAGE_MULTIPAY, $multiPay->getPayer(), sprintf(
                        '%s has requested you to pay for their policy. You can pay £%0.2f/month or £%0.2f for the year.',
                        $multiPay->getPayee()->getName(),
                        $multiPayPolicy->getPremium()->getMonthlyPremiumPrice(),
                        $multiPayPolicy->getPremium()->getYearlyPremiumPrice()
                    ));
                    // @codingStandardsIgnoreEnd
                } catch (\Exception $e) {
                    $this->get('logger')->error(sprintf("Error in multipay push."), ['exception' => $e]);
                }
                try {
                    $mailer = $this->get('app.mailer');
                    $mailer->sendTemplate(
                        sprintf('%s has requested you pay for their policy', $multiPay->getPayee()->getName()),
                        $multiPay->getPayer()->getEmail(),
                        'AppBundle:Email:policy/multiPayRequest.html.twig',
                        ['multiPay' => $multiPay],
                        'AppBundle:Email:policy/multiPayRequest.txt.twig',
                        ['multiPay' => $multiPay]
                    );
                } catch (\Exception $e) {
                    $this->get('logger')->error(sprintf("Error in multipay email."), ['exception' => $e]);
                }
            } else {
                $multiPay = $dm->getRepository(MultiPay::class)->findOneBy([
                    'scode' => $scode,
                    'policy' => $multiPayPolicy,
                    'payee' => $this->getUser()
                ]);
                if ($multiPay) {
                    $multiPay->setStatus(MultiPay::STATUS_CANCELLED);
                    $multiPay->getPolicy()->setStatus(null);
                } else {
                    throw new NotFoundHttpException();
                }
            }
            $dm->flush();

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, sprintf('success multipay %s', $action), 200);
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (NotFoundHttpException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_NOT_FOUND,
                'Unable to find policy/code',
                404
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api putPolicySCodeAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}/terms", name="api_auth_get_policy_terms")
     * @Method({"GET"})
     */
    public function getPolicyTermsAction($id)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($id);
            if (!$policy) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find policy',
                    404
                );
            }
            if (!$policy->getPolicyTerms()) {
                throw new \Exception('Policy is missing terms');
            }
            $this->denyAccessUnlessGranted('view', $policy);
            $policyTermsRoute = $this->get('router')->generate(
                'policy_terms',
                [
                    'id' => $policy->getId(),
                    'policy_key' => $this->getParameter('policy_key'),
                    'maxPotValue' => $policy->getMaxPot(),
                    'yearlyPremium' => $policy->getPremium()->getYearlyPremiumPrice(),
                ],
                false
            );
            $policyTermsUrl = sprintf("%s%s", $this->getParameter('api_base_url'), $policyTermsRoute);

            return new JsonResponse($policy->getPolicyTerms()->toApiArray($policyTermsUrl));
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api getPolicyTerms.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/secret", name="api_auth_secret")
     * @Method({"GET"})
     */
    public function secretAuthAction()
    {
        try {
            // TODO: This should be stored in redis against the sourceIp and time limited
            return new JsonResponse(['secret' => $this->getParameter('api_secret')]);
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api getSecret.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/user", name="api_auth_get_current_user")
     * @Method({"GET"})
     */
    public function getCurrentUserAction()
    {
        try {
            $this->get('statsd')->startTiming("api.getCurrentUser");
            $user = $this->getUser();
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'User not found', 404);
            }

            $this->denyAccessUnlessGranted('view', $user);

            $this->get('statsd')->endTiming("api.getCurrentUser");
            $intercomHash = $this->get('app.intercom')->getApiUserHash($user);

            $response = $user->toApiArray($intercomHash);
            $this->get('logger')->info(sprintf('getCurrentUserAction Resp %s', json_encode($response)));

            return new JsonResponse($response);
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api getCurrentUser.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/user/{id}", name="api_auth_get_user")
     * @Method({"GET"})
     */
    public function getUserAction(Request $request, $id)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($id);
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'User not found', 404);
            }

            $this->denyAccessUnlessGranted('view', $user);
            $debug = false;
            if ($this->getRequestBool($request, 'debug')) {
                $debug = true;
            }
            $intercomHash = $this->get('app.intercom')->getApiUserHash($user);

            $response = $user->toApiArray($intercomHash, null, null, $debug);
            $this->get('logger')->info(sprintf('getUserAction Resp %s', json_encode($response)));

            return new JsonResponse($response);
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api getUser.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/user/{id}", name="api_auth_update_user")
     * @Method({"PUT"})
     */
    public function updateUserAction(Request $request, $id)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($id);
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'User not found', 404);
            }

            $this->denyAccessUnlessGranted('edit', $user);
            $data = json_decode($request->getContent(), true)['body'];
            // $this->get('logger')->info(sprintf('Update user %s', json_encode($data)));

            $email = $this->getDataString($data, 'email');
            $facebookId = $this->getDataString($data, 'facebook_id');
            $mobileNumber = $this->getDataString($data, 'mobile_number');

            // only need to check for dups for these fields if they have changed
            $emailCheck = null;
            if (strlen($email) > 0 && $user->getEmailCanonical() != strtolower($email)) {
                $emailCheck = $email;
            }
            $mobileCheck = null;
            if (strlen($mobileNumber) > 0 && $user->getMobileNumber() != $mobileNumber) {
                $mobileCheck = $mobileNumber;
            }
            $facebookCheck = null;
            if ($this->isDataStringPresent($data, 'facebook_id') &&
                $user->getFacebookId() != $facebookId) {
                $facebookCheck = $user->getFacebookId();
            }

            $userExists = $repo->existsAnotherUser($user, $emailCheck, $facebookCheck, $mobileCheck);
            if ($userExists) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_EXISTS,
                    'Another user exists with those details',
                    422
                );
            }

            $userChanged = false;
            if (strlen($mobileNumber) > 0 && $user->getMobileNumber() != $mobileNumber) {
                $user->setMobileNumber($mobileNumber);
                $userChanged = true;
            }
            if (strlen($email) > 0 && $user->getEmailCanonical() != strtolower($email)) {
                $user->setEmail($email);
                $userChanged = true;
            }
            if ($this->isDataStringPresent($data, 'facebook_id') &&
                $this->isDataStringPresent($data, 'facebook_access_token') &&
                $user->getFacebookId() != $facebookId) {
                $user->setFacebookId($facebookId);
                $user->setFacebookAccessToken($this->getDataString($data, 'facebook_access_token'));
                $userChanged = true;
            }

            if ($this->isDataStringPresent($data, 'first_name') &&
                $this->conformAlphanumeric($this->getDataString($data, 'first_name'), 50) != $user->getFirstName()) {
                if ($user->hasValidPolicy()) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                        'Unable to change name after policy is created',
                        422
                    );
                }
                $user->setFirstName($this->conformAlphanumeric($this->getDataString($data, 'first_name'), 50));
                $userChanged = true;
            }
            if ($this->isDataStringPresent($data, 'last_name') &&
                $this->conformAlphanumeric($this->getDataString($data, 'last_name'), 50) != $user->getLastName()) {
                if ($user->hasValidPolicy()) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                        'Unable to change name after policy is created',
                        422
                    );
                }
                $user->setLastName($this->conformAlphanumeric($this->getDataString($data, 'last_name'), 50));
                $userChanged = true;
            }

            if ($this->isDataStringPresent($data, 'birthday')) {
                $birthday = $this->validateBirthday($data);
                if ($birthday instanceof Response) {
                    return $birthday;
                }
                $user->setBirthday($birthday);
                $userChanged = true;
            }

            if ($this->isDataStringPresent($data, 'scode')) {
                $scodeRepo = $dm->getRepository(SCode::class);
                $scode = $scodeRepo->findOneBy(['code' => $this->getDataString($data, 'scode')]);
                if (!$scode || !$scode->isActive()) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'SCode missing', 404);
                }
                $scode->addAcceptor($user);
                $userChanged = true;
            }

            if ($this->isDataStringPresent($data, 'sns_endpoint')) {
                $oldEndpointUsers = $repo->findBy(['snsEndpoint' => $this->getDataString($data, 'sns_endpoint')]);
                foreach ($oldEndpointUsers as $oldEndpointUser) {
                    $oldEndpointUser->setSnsEndpoint(null);
                }
                $user->setSnsEndpoint($this->getDataString($data, 'sns_endpoint'));
                $userChanged = true;
            }

            $this->validateObject($user);

            if ($userChanged) {
                $dm->flush();
            }
            $intercomHash = $this->get('app.intercom')->getApiUserHash($user);

            return new JsonResponse($user->toApiArray($intercomHash));
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api updateUser.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/user/{id}/address", name="api_auth_add_user_address")
     * @Method({"POST"})
     */
    public function addUserAddressAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['type', 'line1', 'city', 'postcode'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($id);

            $this->denyAccessUnlessGranted('edit', $user);

            $addressValidator = $this->get('app.address');
            if (!$addressValidator->validatePostcode($this->getDataString($data, 'postcode'))) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_INVALID_ADDRESS,
                    "Invalid postcode",
                    422
                );
            }

            $address = new Address();
            $address->setType($this->getDataString($data, 'type'));
            $address->setLine1($this->conformAlphanumericSpaceDot($this->getDataString($data, 'line1'), 250));
            $address->setLine2($this->conformAlphanumericSpaceDot($this->getDataString($data, 'line2'), 250));
            $address->setLine3($this->conformAlphanumericSpaceDot($this->getDataString($data, 'line3'), 250));
            $address->setCity($this->conformAlphanumericSpaceDot($this->getDataString($data, 'city'), 250));
            $address->setPostcode($this->getDataString($data, 'postcode'));
            $user->setBillingAddress($address);

            $this->validateObject($address);

            $dm->persist($address);
            $dm->flush();

            return new JsonResponse($user->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api addUserAddress.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }
}
