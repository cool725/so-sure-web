<?php

namespace AppBundle\Controller;

use AppBundle\Classes\Salva;
use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\ValidatorTrait;
use AppBundle\Exception\DirectDebitBankException;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Service\BacsService;
use AppBundle\Service\InvitationService;
use AppBundle\Service\MailerService;
use AppBundle\Service\PaymentService;
use AppBundle\Service\PCAService;
use AppBundle\Service\PolicyService;
use AppBundle\Service\RouterService;
use AppBundle\Validator\Constraints\BankAccountNameValidator;
use Egulias\EmailValidator\Validation\RFCValidation;
use Psr\Log\LoggerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Form\Type\LaunchType;

use AppBundle\Document\Address;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Cashback;
use AppBundle\Document\Charge;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Sns;
use AppBundle\Document\SCode;
use AppBundle\Document\User;
use AppBundle\Document\MultiPay;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\File\ImeiFile;
use AppBundle\Document\File\PicSureFile;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Coordinates;

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
use AppBundle\Exception\InvalidEmailException;

use AppBundle\Event\PicsureEvent;

use AppBundle\Service\RateLimitService;
use AppBundle\Service\PushService;
use AppBundle\Service\JudopayService;

use AppBundle\Security\PolicyVoter;
use AppBundle\Security\UserVoter;
use AppBundle\Security\InvitationVoter;
use AppBundle\Security\MultiPayVoter;

use AppBundle\Classes\ApiErrorCode;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Egulias\EmailValidator\EmailValidator;
use Symfony\Component\Serializer\Tests\Fixtures\PropertySiblingHolder;

/**
 * @Route("/api/v1/auth")
 */
class ApiAuthController extends BaseController
{
    use PhoneTrait;
    use ValidatorTrait;

    /**
     * @Route("/address", name="api_auth_address")
     * @Route("/lookup/address", name="api_auth_lookup_address")
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
     * @Route("/lookup/bacs", name="api_auth_lookup_bacs")
     * @Method({"GET"})
     */
    public function bacsAction(Request $request)
    {
        $bacs = null;
        try {
            if (!$this->validateQueryFields($request, ['sort_code', 'account_number'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $rateLimit = $this->get('app.ratelimit');
            if (!$rateLimit->allowedByDevice(
                RateLimitService::DEVICE_TYPE_BACS,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            $sortCode = $this->conformAlphanumericSpaceDot($this->getRequestString($request, 'sort_code'), 10);
            $accountNumber = $this->conformAlphanumericSpaceDot(
                $this->getRequestString($request, 'account_number'),
                10
            );
            $includes = [];
            $include = trim($this->conformAlphanumericSpaceDot($this->getRequestString($request, 'include'), 200));
            if (mb_strlen($include) > 0) {
                $includes = explode(',', $include);
            }

            /** @var PCAService $lookup */
            $lookup = $this->get('app.address');
            if (!$bacs = $lookup->getBankAccount($sortCode, $accountNumber, $this->getUser())) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            if (in_array('mandate', $includes)) {
                /** @var PaymentService $paymentService */
                $paymentService = $this->get('app.payment');
                $paymentService->generateBacsReference($bacs, $this->getUser());
            }

            $policy = new PhonePolicy();
            $bacs->setInitialNotificationDate($bacs->getFirstPaymentDateForPolicy($policy));
            $bacs->setAccountName($this->getUser()->getName());

            return new JsonResponse($bacs->toApiArray());
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (DirectDebitBankException $e) {
            $this->get('logger')->info(sprintf(
                'Direct Debit Error lookup details %s.',
                $bacs ? $bacs->__toString() : 'unknnown'
            ), ['exception' => $e]);

            if ($e->getCode() == DirectDebitBankException::ERROR_SORT_CODE) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_BANK_INVALID_SORTCODE,
                    'Invalid Sort Code',
                    422
                );
            } elseif ($e->getCode() == DirectDebitBankException::ERROR_ACCOUNT_NUMBER) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_BANK_INVALID_NUMBER,
                    'Invalid Account Number',
                    422
                );
            } elseif ($e->getCode() == DirectDebitBankException::ERROR_NON_DIRECT_DEBIT) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_BANK_DIRECT_DEBIT_UNAVAILABLE,
                    'Direct debit not available on account',
                    422
                );
            }

            // DirectDebitBankException::ERROR_UNKNOWN
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Unknown issue', 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api addressAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/detected-imei", name="api_auth_detected_imei")
     * @Method({"POST"})
     */
    public function detectedImeiAction(Request $request)
    {
        try {
            /** @var RouterService $router */
            $router = $this->get('app.router');

            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['detected_imei', 'bucket', 'key'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }
            $detectedImei = $this->getDataString($data, 'detected_imei');
            $suggestedImei = $this->getDataString($data, 'suggested_imei');
            $bucket = $this->getDataString($data, 'bucket');
            $key = $this->getDataString($data, 'key');

            $dm = $this->getManager();
            $policyRepo = $dm->getRepository(Policy::class);
            if ($suggestedImei && mb_strlen($suggestedImei) > 0) {
                $policies = $policyRepo->findBy(['imei' => $suggestedImei]);
            } else {
                $policies = [];
            }
            $user = $this->getUser();

            // prefer an active policy
            $policy = null;
            foreach ($policies as $checkPolicy) {
                if ($checkPolicy->isActive()) {
                    $policy = $checkPolicy;
                    break;
                }
            }
            if (!$policy && count($policies) > 0) {
                $policy = $policies[0];
            }

            if ($policy) {
                $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
                if (!$policy->getDetectedImei()) {
                    $policy->setDetectedImei($detectedImei);
                    $dm->flush();
                }
                $this->get('logger')->warning(sprintf(
                    'Policy %s/%s has detected imei set',
                    $policy->getId(),
                    $policy->getPolicyNumber()
                ));

                return new JsonResponse($policy->toApiArray());
            } else {
                $redis = $this->get('snc_redis.default');
                $redis->lpush('DETECTED-IMEI', json_encode([
                    'detected_imei' => $detectedImei,
                    'suggested_imei' => $suggestedImei,
                    'bucket' => $bucket,
                    'key' => $key
                ]));

                $body = sprintf(
                    '<a href="%s">Detected IMEI page</a>',
                    $router->generateUrl('admin_detected_imei', [])
                );

                $this->get('app.mailer')->send(
                    'Unknown App IMEI - Process on admin site',
                    'tech+ops@so-sure.com',
                    $body
                );

                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_DETECTED_IMEI_MANUAL_PROCESSING,
                    'Wait on manual processing',
                    422
                );
            }
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api detectedImeiAction.', ['exception' => $e]);

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
            $rateLimit = $this->get('app.ratelimit');
            if (!$rateLimit->replay($this->getCognitoIdentityId($request), $request)) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Duplicate request', 500);
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
                $this->denyAccessUnlessGranted(InvitationVoter::ACCEPT, $invitation);
                $policy = $policyRepo->find($this->getDataString($data, 'policy_id'));
                // TODO: Validation user hasn't exceeded pot amout
                $invitationService->accept($invitation, $policy);
            } elseif ($action == 'reject') {
                $this->denyAccessUnlessGranted(InvitationVoter::REJECT, $invitation);
                $invitationService->reject($invitation);
            } elseif ($action == 'cancel') {
                $this->denyAccessUnlessGranted(InvitationVoter::CANCEL, $invitation);
                $invitationService->cancel($invitation);
            } elseif ($action == 'reinvite') {
                $this->denyAccessUnlessGranted(InvitationVoter::REINVITE, $invitation);
                //\Doctrine\Common\Util\Debug::dump($invitation);
                if (!$invitationService->reinvite($invitation)) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_INVITATION_LIMIT,
                        'Unable to re-invite',
                        422
                    );
                }
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
    public function putMultiPayAction($id)
    {
        \AppBundle\Classes\NoOp::ignore([$id]);

        return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UPGRADE_APP, 'Deprecated', 422);

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
            if ($redis->exists('ERROR_NOT_YET_REGULATED') == 1) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_YET_REGULATED,
                    "Coming soon",
                    422
                );
            }

            $imeiValidator = $this->get('app.imei');
            $jwtValidator = $this->get('app.jwt');
            $user = $this->getUser();

            if (!$user->canPurchasePolicy()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_POLICY_LIMIT,
                    'User is not able to purchase additional policies',
                    422
                );
            }
            $this->denyAccessUnlessGranted(UserVoter::ADD_POLICY, $user);

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
            if (!$phone->getActive()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to provide policy for this phone',
                    404
                );
            }

            $imei = $this->normalizeImei($this->getDataString($phonePolicyData, 'imei'));
            $serialNumber = $this->getDataString($phonePolicyData, 'serial_number');
            // For phones without a serial number, run check on imei
            if (!$serialNumber) {
                $serialNumber = $imei;
            }

            $modelNumber = $this->getDataString($phonePolicyData, 'model_number');

            try {
                // Device/memory/serial/rooted were not present in initial version of app.
                // TODO: Add app version to request and if app version > number, then require instead of optional
                $claims = $jwtValidator->validate(
                    $this->getCognitoIdentityId($request),
                    $this->getDataString($phonePolicyData, 'validation_data'),
                    ['imei' => $this->getDataString($phonePolicyData, 'imei')],
                    [
                        'device' => $this->getDataString($phonePolicyData, 'device'),
                        'memory' => $this->getDataString($phonePolicyData, 'memory'),
                        'serial_number' => $this->getDataString($phonePolicyData, 'serial_number'),
                        'rooted' => $this->getDataString($phonePolicyData, 'rooted'),
                    ]
                );
            } catch (\InvalidArgumentException $argE) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_INVALID_VALIDATION,
                    'Validation data is not valid',
                    422
                );
            }

            $policyService = $this->get('app.policy');
            $policyService->setWarnMakeModelMismatch(false);
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
                ]),
                $modelNumber
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
            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api getPolicy.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}", name="api_auth_post_policy")
     * @Method({"POST"})
     */
    public function policyAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (empty($data)) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
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
            $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);

            $needUpdate = false;
            if (isset($data['phone_policy'])) {
                if (isset($data['phone_policy']['make']) ||
                    isset($data['phone_policy']['device']) ||
                    isset($data['phone_policy']['memory'])) {
                    if (!$this->validateFields($data['phone_policy'], ['make', 'device', 'memory'])) {
                        return $this->getErrorJsonResponse(
                            ApiErrorCode::ERROR_MISSING_PARAM,
                            'Missing parameters',
                            400
                        );
                    }

                    $phone = $this->getPhone(
                        $this->getDataString($data['phone_policy'], 'make'),
                        $this->getDataString($data['phone_policy'], 'device'),
                        $this->getDataString($data['phone_policy'], 'memory')
                    );
                    if (!$phone) {
                        return $this->getErrorJsonResponse(
                            ApiErrorCode::ERROR_NOT_FOUND,
                            'Unable to provide policy for this phone',
                            404
                        );
                    }
                    if (!$phone->getActive()) {
                        return $this->getErrorJsonResponse(
                            ApiErrorCode::ERROR_NOT_FOUND,
                            'Unable to provide policy for this phone',
                            404
                        );
                    }

                    if ($policy->getStatus() != null && $policy->getStatus() != Policy::STATUS_PENDING) {
                        return $this->getErrorJsonResponse(
                            ApiErrorCode::ERROR_POLICY_UNABLE_TO_UDPATE,
                            'Unable to change phone for this policy',
                            422
                        );
                    }

                    $policy->setPhone($phone);
                    $policy->setPhoneData(json_encode([
                        'make' => $this->getDataString($data['phone_policy'], 'make'),
                        'device' => $this->getDataString($data['phone_policy'], 'device'),
                        'memory' => $this->getDataString($data['phone_policy'], 'memory'),
                    ]));
                    $additionalPremium = null;
                    if ($policy->getUser()) {
                        $additionalPremium = $policy->getUser()->getAdditionalPremium();
                    }
                    /** @var PhonePrice $price */
                    $price = $phone->getCurrentPhonePrice(null);
                    $policy->setPremium($price->createPremium($additionalPremium, null));

                    $needUpdate = true;
                }
                if (isset($data['phone_policy']['model_number'])) {
                    $modelNumber = $this->getDataString($data['phone_policy'], 'model_number');
                    $policy->setModelNumber($modelNumber);
                    $needUpdate = true;
                }
            }

            if ($needUpdate) {
                $dm->flush();
            } else {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_MISSING_PARAM,
                    'No parameters to update',
                    400
                );
            }

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api post policy action.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}/billing-day", name="api_auth_billing_day")
     * @Method({"POST"})
     */
    public function billingDayAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['billing_day'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($id);
            if (!$policy) {
                throw new NotFoundHttpException();
            }
            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);

            $day = $data['billing_day'];
            if ($day < 1 || $day > 28) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                    'Billing day must be 1-28',
                    422
                );
            }

            $policyService = $this->get('app.policy');
            $policyService->billingDay($policy, $day);

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api billingDayAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}/cashback", name="api_auth_cashback")
     * @Method({"POST"})
     */
    public function cashbackAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['account_name', 'sort_code', 'account_number'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($id);
            if (!$policy) {
                throw new NotFoundHttpException();
            }
            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);

            $policyService = $this->get('app.policy');
            $cashback = $policy->getCashback();
            if (!$cashback) {
                $cashback = new Cashback();
                // due to validate object call later
                $cashback->setPolicy($policy);
                $cashback->setStatus(Cashback::STATUS_PENDING_CLAIMABLE);
                $cashback->setDate(\DateTime::createFromFormat('U', time()));
            }
            $cashback->setAccountName($this->getDataString($data, 'account_name'));
            $cashback->setSortcode($this->getDataString($data, 'sort_code'));
            $cashback->setAccountNumber($this->getDataString($data, 'account_number'));

            //\Doctrine\Common\Util\Debug::dump($phone->getCurrentPhonePrice());
            $this->validateObject($cashback);
            $policyService->cashback($policy, $cashback);

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $e) {
            $this->get('logger')->info(sprintf('Failed cashback'), ['exception' => $e]);
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                'Invalid bank details',
                422
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api cashbackAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}/connect", name="api_auth_new_connection")
     * @Method({"POST"})
     */
    public function newConnectionAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!isset($data['policy_id'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($id);
            if (!$policy) {
                throw new NotFoundHttpException();
            }
            $this->denyAccessUnlessGranted(PolicyVoter::CONNECT, $policy);

            $connectPolicy = $repo->find($data['policy_id']);
            if (!$connectPolicy) {
                throw new NotFoundHttpException();
            }
            $this->denyAccessUnlessGranted(PolicyVoter::CONNECT, $connectPolicy);

            $invitationService = $this->get('app.invitation');
            try {
                $invitationService->connect($policy, $connectPolicy);
                $policy = $repo->find($id);

                return new JsonResponse($policy->toApiArray());
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
                $this->get('logger')->error('Error in api newConnection.', ['exception' => $e]);

                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
            }
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api newConnection.', ['exception' => $e]);

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
            $this->denyAccessUnlessGranted(PolicyVoter::SEND_INVITATION, $policy);

            /** @var InvitationService $invitationService */
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
            $facebookId = $this->getDataString($data, 'facebook_id');
            try {
                $invitation  = null;
                if ($email && $validator->isValid($email, new RFCValidation())) {
                    $invitation = $invitationService->inviteByEmail($policy, $email, $name, $skipSend);
                } elseif ($mobile && $this->isValidUkMobile($mobile)) {
                    $invitation = $invitationService->inviteBySms($policy, $mobile, $name, $skipSend);
                } elseif ($scode && SCode::isValidSCode($scode)) {
                    $sdk = $this->getCognitoIdentitySdk($request);
                    $invitation = $invitationService->inviteBySCode($policy, $scode, null, $sdk);
                } elseif ($facebookId && mb_strlen($facebookId) > 5 && mb_strlen($facebookId) < 150) {
                    $invitation = $invitationService->inviteByFacebookId($policy, $facebookId);
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
        $bankAccount = null;
        $dm = $this->getManager();
        $judoData = null;
        $bacsData = null;
        $existingData = null;
        try {
            $this->get('statsd')->startTiming("api.payPolicy");
            $data = json_decode($request->getContent(), true)['body'];
            if (isset($data['bank_account'])) {
                if (!$this->validateFields(
                    $data['bank_account'],
                    ['sort_code', 'account_number', 'account_name', 'mandate', 'initial_amount', 'recurring_amount']
                )) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
                $bacsData = $data['bank_account'];
            } elseif (isset($data['braintree'])) {
                // Not allow braintree
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
            } elseif (isset($data['judo'])) {
                if (!$this->validateFields($data['judo'], ['consumer_token', 'receipt_id'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
                $judoData = $data['judo'];
            } elseif (isset($data['existing'])) {
                if (!$this->validateFields($data['existing'], ['amount'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }

                $existingData = $data['existing'];
            } else {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $redis = $this->get('snc_redis.default');
            if ($redis->exists('ERROR_NOT_YET_REGULATED') == 1) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_YET_REGULATED,
                    "Coming soon",
                    422
                );
            }

            /** @var PolicyRepository $repo */
            $repo = $dm->getRepository(Policy::class);
            /** @var PhonePolicy $policy */
            $policy = $repo->find($id);
            if (!$policy) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find policy',
                    404
                );
            }
            $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);

            if ($bacsData) {
                /** @var BankAccountNameValidator $validator */
                $validator = $this->get('app.validator.bankaccountname');
                $accountName = $this->getDataString($bacsData, 'account_name');
                if ($validator->isAccountName($accountName, $policy->getUser()) === false) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_BANK_NAME_MISMATCH,
                        'Name on bank account does not match policy name',
                        422
                    );
                }

                $mandate = $this->getDataString($bacsData, 'mandate');
                if (count($repo->findDuplicateMandates($mandate)) > 0) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_BANK_INVALID_MANDATE,
                        'Duplicate mandate',
                        422
                    );
                }

                if ($policy->isActive() && !$policy->canBacsPaymentBeMadeInTime()) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_BANK_NOT_ENOUGH_TIME,
                        'Not enough time to make a payment',
                        422
                    );
                }

                /** @var PCAService $pcaService */
                $pcaService = $this->get('app.address');
                $sortCode = $this->getDataString($bacsData, 'sort_code');
                $accountNumber = $this->getDataString($bacsData, 'account_number');
                $bankAccount = $pcaService->getBankAccount($sortCode, $accountNumber);
                if (!$bankAccount) {
                    throw new DirectDebitBankException('Unknown error');
                }

                $bankAccount->setAccountName($accountName);
                $bankAccount->setReference($mandate);
                $bankAccount->setIdentityLog($this->getIdentityLog($request));

                // todo: record/bill initial amount
                $initialAmount = $this->getDataString($bacsData, 'initial_amount');
                $recurringAmount = $this->getDataString($bacsData, 'recurring_amount');
                $installments = $policy->getPremium()->getNumberOfMonthlyPayments($recurringAmount);
                if (!in_array($installments, [1, 12])) {
                    throw new InvalidPremiumException(sprintf(
                        '%0.2f is not a monthly/annual price for policy %s',
                        $recurringAmount,
                        $policy->getId()
                    ));
                }
                $policy->setPremiumInstallments($installments);
                $bankAccount->setAnnual($installments == 1);

                $bacs = new BacsPaymentMethod();
                $bacs->setBankAccount($bankAccount);

                // only create policy if not already created
                if (!$policy->getStatus() || $policy->getStatus() == PhonePolicy::STATUS_PENDING) {
                    /** @var PolicyService $policyService */
                    $policyService = $this->get('app.policy');
                    $policyService->create(
                        $policy,
                        $this->now(),
                        true,
                        $installments,
                        $this->getIdentityLog($request)
                    );
                }

                /** @var PaymentService $paymentService */
                $paymentService = $this->get('app.payment');
                $paymentService->confirmBacs(
                    $policy,
                    $bacs
                );
            } elseif (isset($data['braintree'])) {
                throw new \Exception('Braintree is no longer supported');
            } elseif ($judoData) {
                /** @var JudopayService $judo */
                $judo = $this->get('app.judopay');
                $judo->add(
                    $policy,
                    $this->getDataString($judoData, 'receipt_id'),
                    $this->getDataString($judoData, 'consumer_token'),
                    $this->getDataString($judoData, 'card_token'),
                    Payment::SOURCE_MOBILE,
                    $this->getDataString($judoData, 'device_dna'),
                    null,
                    $this->getIdentityLog($request)
                );
            } elseif ($existingData) {
                if (!$policy->hasPolicyOrUserValidPaymentMethod()) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_POLICY_PAYMENT_REQUIRED,
                        'Invalid payment method for user',
                        422
                    );
                }
                $paymentMethod = $policy->getPolicyOrPayerOrUserPaymentMethod();
                if ($paymentMethod instanceof JudoPaymentMethod) {
                    /** @var JudopayService $judo */
                    $judo = $this->get('app.judopay');
                    if (!$judo->existing($policy, $existingData['amount'])) {
                        throw new PaymentDeclinedException('Token payment failed');
                    }
                } elseif ($paymentMethod instanceof  BacsPaymentMethod) {
                    // for unpaid, we can allow a bacs payment
                    $amount = $existingData['amount'];
                    $now = \DateTime::createFromFormat('U', time());
                    $notes = sprintf(
                        'User manually confirmed payment for £%0.2f on %s',
                        $amount,
                        $now->format(\DateTime::ATOM)
                    );

                    /** @var BacsService $bacsService */
                    $bacsService = $this->get('app.bacs');
                    $payment = $bacsService->bacsPayment($policy, $notes, $amount, null, true, Payment::SOURCE_MOBILE);
                    $payment->setIdentityLog($this->getIdentityLog($request));
                    $dm->flush();
                } else {
                    throw new ValidationException('Unsupport payment method');
                }
            } else {
                throw new \Exception('Unknown payment method');
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
        } catch (ProcessedException $e) {
            $this->get('logger')->error(sprintf(
                'Duplicate receipt id %s.',
                $this->getDataString($judoData, 'receipt_id')
            ), ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_POLICY_PAYMENT_REQUIRED, 'Payment not valid', 422);
        } catch (DirectDebitBankException $e) {
            $this->get('logger')->info(sprintf(
                'Direct Debit Error policy %s details %s.',
                $id,
                $bankAccount ? $bankAccount->__toString() : 'unknown'
            ), ['exception' => $e]);

            // Status should be set to null for pending policies to avoid trigger monitoring alerts
            if ($policy->getStatus() == Policy::STATUS_PENDING) {
                $policy->setStatus(null);
                $dm->flush();
            }

            if ($e->getCode() == DirectDebitBankException::ERROR_SORT_CODE) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_BANK_INVALID_SORTCODE,
                    'Invalid Sort Code',
                    422
                );
            } elseif ($e->getCode() == DirectDebitBankException::ERROR_ACCOUNT_NUMBER) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_BANK_INVALID_NUMBER,
                        'Invalid Account Number',
                        422
                    );
            } elseif ($e->getCode() == DirectDebitBankException::ERROR_NON_DIRECT_DEBIT) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_BANK_DIRECT_DEBIT_UNAVAILABLE,
                    'Direct debit not available on account',
                    422
                );
            }

            // DirectDebitBankException::ERROR_UNKNOWN
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Unknown issue', 422);
        } catch (PaymentDeclinedException $e) {
            $this->get('logger')->info(sprintf(
                'Payment declined policy %s receipt %s.',
                $id,
                $this->getDataString($judoData, 'receipt_id')
            ), ['exception' => $e]);

            // Status should be set to null for pending policies to avoid trigger monitoring alerts
            if ($policy->getStatus() == Policy::STATUS_PENDING) {
                $policy->setStatus(null);
                $dm->flush();
            }

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_POLICY_PAYMENT_DECLINED, 'Payment Declined', 422);
        } catch (AccessDeniedException $e) {
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
     * @Route("/policy/{id}/payment", name="api_auth_policy_payment")
     * @Method({"POST"})
     */
    public function paymentPolicyAction(Request $request, $id)
    {
        $bankAccount = null;
        $judoData = null;
        $bacsData = null;
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (isset($data['bank_account'])) {
                if (!$this->validateFields(
                    $data['bank_account'],
                    ['sort_code', 'account_number', 'account_name', 'mandate', 'initial_amount', 'recurring_amount']
                )) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
                $bacsData = $data['bank_account'];
            } elseif (isset($data['judo'])) {
                if (!$this->validateFields($data['judo'], ['consumer_token', 'receipt_id'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
                $judoData = $data['judo'];
            } else {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            /** @var Policy $policy */
            $policy = $repo->find($id);
            if (!$policy) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find policy',
                    404
                );
            }
            $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);

            if ($bacsData) {
                /** @var BankAccountNameValidator $validator */
                $validator = $this->get('app.validator.bankaccountname');
                $accountName = $this->getDataString($bacsData, 'account_name');
                if ($validator->isAccountName($accountName, $policy->getUser()) === false) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_BANK_NAME_MISMATCH,
                        'Name on bank account does not match policy name',
                        422
                    );
                }

                $mandate = $this->getDataString($bacsData, 'mandate');
                if (count($repo->findDuplicateMandates($mandate)) > 0) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_BANK_INVALID_MANDATE,
                        'Duplicate mandate',
                        422
                    );
                }

                /** @var PCAService $pcaService */
                $pcaService = $this->get('app.address');
                $sortCode = $this->getDataString($bacsData, 'sort_code');
                $accountNumber = $this->getDataString($bacsData, 'account_number');
                $bankAccount = $pcaService->getBankAccount($sortCode, $accountNumber);
                if (!$bankAccount) {
                    throw new DirectDebitBankException('Unknown error');
                }

                $bankAccount->setAccountName($accountName);
                $bankAccount->setReference($mandate);
                $bankAccount->setIdentityLog($this->getIdentityLog($request));

                // todo: record/bill initial amount
                $initialAmount = $this->getDataString($bacsData, 'initial_amount');
                $recurringAmount = $this->getDataString($bacsData, 'recurring_amount');
                $installments = $policy->getPremium()->getNumberOfMonthlyPayments($recurringAmount);
                if (!in_array($installments, [1, 12])) {
                    throw new InvalidPremiumException(sprintf(
                        '%0.2f is not a monthly/annual price for policy %s',
                        $recurringAmount,
                        $policy->getId()
                    ));
                }
                $bankAccount->setAnnual($installments == 1);

                $bacs = new BacsPaymentMethod();
                $bacs->setBankAccount($bankAccount);

                /** @var PaymentService $paymentService */
                $paymentService = $this->get('app.payment');
                $paymentService->confirmBacs(
                    $policy,
                    $bacs
                );
            } elseif ($judoData) {
                /** @var JudopayService $judo */
                $judo = $this->get('app.judopay');
                $judo->updatePaymentMethod(
                    $policy->getUser(),
                    $this->getDataString($judoData, 'receipt_id'),
                    $this->getDataString($judoData, 'consumer_token'),
                    $this->getDataString($judoData, 'card_token'),
                    $this->getDataString($judoData, 'device_dna'),
                    $policy
                );
            }

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
        } catch (PaymentDeclinedException $e) {
            $this->get('logger')->info(sprintf(
                'Payment declined policy %s receipt %s.',
                $id,
                $this->getDataString($judoData, 'receipt_id')
            ), ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_POLICY_PAYMENT_DECLINED, 'Payment Declined', 422);
        } catch (InvalidPremiumException $e) {
            $this->get('logger')->error(sprintf(
                'Invalid premium policy payment %s receipt %s.',
                $id,
                $this->getDataString($judoData, 'receipt_id')
            ), ['exception' => $e]);

            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_PAYMENT_INVALID_AMOUNT,
                'Invalid premium payment',
                422
            );
        } catch (DirectDebitBankException $e) {
            $this->get('logger')->info(sprintf(
                'Direct Debit Error policy payment %s details %s.',
                $id,
                $bankAccount ? $bankAccount->__toString() : 'unknown'
            ), ['exception' => $e]);

            if ($e->getCode() == DirectDebitBankException::ERROR_SORT_CODE) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_BANK_INVALID_SORTCODE,
                    'Invalid Sort Code',
                    422
                );
            } elseif ($e->getCode() == DirectDebitBankException::ERROR_ACCOUNT_NUMBER) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_BANK_INVALID_NUMBER,
                    'Invalid Account Number',
                    422
                );
            } elseif ($e->getCode() == DirectDebitBankException::ERROR_NON_DIRECT_DEBIT) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_BANK_DIRECT_DEBIT_UNAVAILABLE,
                    'Direct debit not available on account',
                    422
                );
            }

            // DirectDebitBankException::ERROR_UNKNOWN
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Unknown issue', 422);
        } catch (AccessDeniedException $e) {
            $this->get('logger')->warning(sprintf(
                'Access denied policy %s receipt %s.',
                $id,
                $this->getDataString($judoData, 'receipt_id')
            ), ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api payPolicy.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}/imei", name="api_auth_imei")
     * @Method({"POST"})
     */
    public function imeiAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $data = null;
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['bucket', 'key'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $repo = $dm->getRepository(Policy::class);
            /** @var PhonePolicy $policy */
            $policy = $repo->find($id);
            if (!$policy) {
                throw new NotFoundHttpException();
            }
            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
            $s3 = $this->get('aws.s3');
            $result = $s3->getObject(array(
                'Bucket' => $this->getDataString($data, 'bucket'),
                'Key'    => $this->getDataString($data, 'key'),
            ));

            if (!in_array($result['ContentType'], array('image/jpeg', 'image/png', 'binary/octet-stream'))) {
                $msg = sprintf(
                    'Invalid file s3://%s/%s of type %s',
                    $this->getDataString($data, 'bucket'),
                    $this->getDataString($data, 'key'),
                    $result['ContentType']
                );
                $this->get('logger')->error($msg);

                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_IMEI_FILE_INVALID,
                    $msg,
                    422
                );
            }

            $imei = new ImeiFile();
            $imei->setBucket($this->getDataString($data, 'bucket'));
            $imei->setKey($this->getDataString($data, 'key'));
            $policy->addPolicyFile($imei);
            if (isset($result['Metadata']['match-header-colour'])) {
                $imei->addMetadata('imei-match-header-colour', $result['Metadata']['match-header-colour']);
            }
            if (isset($result['Metadata']['age-seconds'])) {
                $imei->addMetadata('imei-age-seconds', $result['Metadata']['age-seconds']);
            }
            if (isset($result['Metadata']['match-screen-size'])) {
                $imei->addMetadata('imei-match-screen-size', $result['Metadata']['match-screen-size']);
            }
            if (isset($result['Metadata']['suspected-fraud'])) {
                $imei->addMetadata('imei-suspected-fraud', $result['Metadata']['suspected-fraud']);
                if ($result['Metadata']['suspected-fraud'] === "1") {
                    $policy->setImeiCircumvention(true);
                    /** @var MailerService $mailer */
                    $mailer = $this->get('app.mailer');
                    /** @var RouterService $router */
                    $router = $this->get('app.router');
                    $body = sprintf(
                        '<a href="%s">%s</a>',
                        $router->generateUrl('admin_policy', ['id' => $policy->getId()]),
                        $policy->getPolicyNumber()
                    );
                    $mailer->send(
                        'Detected imei circumvention attempt',
                        'tech+ops@so-sure.com',
                        $body
                    );
                } else {
                    $policy->setImeiCircumvention(false);
                }
            }

            $dm->flush();

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Aws\S3\Exception\S3Exception $e) {
            $msg = sprintf(
                'Missing file s3://%s/%s',
                $this->getDataString($data, 'bucket'),
                $this->getDataString($data, 'key')
            );
            $this->get('logger')->error($msg, ['exception' => $e]);

            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_IMEI_FILE_NOT_FOUND,
                $msg,
                422
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api imeiAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}/picsure", name="api_auth_picsure")
     * @Method({"POST"})
     */
    public function picsureAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $data = null;
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['bucket', 'key'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $repo = $dm->getRepository(PhonePolicy::class);
            /** @var PhonePolicy $policy */
            $policy = $repo->find($id);
            if (!$policy) {
                throw new NotFoundHttpException();
            }
            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);

            if ($policy->getPicSureStatusWithClaims() == PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED) {
                throw new ClaimException('Unable to do pic-sure as claim is in progress');
            }

            $s3 = $this->get('aws.s3');
            $result = $s3->getObject(array(
                'Bucket' => $this->getDataString($data, 'bucket'),
                'Key'    => $this->getDataString($data, 'key'),
            ));

            if (!in_array($result['ContentType'], array('image/jpeg', 'image/png', 'binary/octet-stream'))) {
                $msg = sprintf(
                    'Invalid file s3://%s/%s of type %s',
                    $this->getDataString($data, 'bucket'),
                    $this->getDataString($data, 'key'),
                    $result['ContentType']
                );
                $this->get('logger')->error($msg);

                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_PICSURE_FILE_INVALID,
                    $msg,
                    422
                );
            }

            $picsure = new PicSureFile();
            $picsure->setBucket($this->getDataString($data, 'bucket'));
            $picsure->setKey($this->getDataString($data, 'key'));
            $policy->addPolicyFile($picsure);
            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_MANUAL);
            // for typo in the app: to be removed eventually
            if (isset($result['Metadata']['attemps'])) {
                $picsure->addMetadata('picsure-attempts', $result['Metadata']['attemps']);
            }
            if (isset($result['Metadata']['attempts'])) {
                $picsure->addMetadata('picsure-attempts', $result['Metadata']['attempts']);
            }
            if (isset($result['Metadata']['suspected-fraud'])) {
                $picsure->addMetadata('picsure-suspected-fraud', $result['Metadata']['suspected-fraud']);
                if ($result['Metadata']['suspected-fraud'] === "1") {
                    $policy->setPicSureCircumvention(true);
                    /** @var LoggerInterface $logger */
                    $logger = $this->get('logger');
                    $logger->error(sprintf(
                        'Detected pic-sure circumvention attempt for policy %s',
                        $policy->getId()
                    ));
                } else {
                    $policy->setPicSureCircumvention(false);
                }
            }

            $dm->flush();

            $this->get('event_dispatcher')->dispatch(
                PicsureEvent::EVENT_RECEIVED,
                new PicsureEvent($policy, $picsure)
            );

            $environment = $this->getParameter('kernel.environment');
            $body = '<a href="https://wearesosure.com/admin/picsure">Admin site</a>';

            /** @var MailerService $mailer */
            $mailer = $this->get('app.mailer');
            $mailer->send(
                sprintf('New pic-sure image to process [%s]', $environment),
                'pic-sure@so-sure.com',
                $body,
                null,
                null,
                null,
                'tech@so-sure.com'
            );

            return new JsonResponse($policy->toApiArray());
        } catch (ClaimException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_PICSURE_DISALLOWED,
                'pic-sure is not allowed',
                422
            );
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Aws\S3\Exception\S3Exception $e) {
            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_INVALID);
            $dm->flush();

            $msg = sprintf(
                'Missing file s3://%s/%s',
                $this->getDataString($data, 'bucket'),
                $this->getDataString($data, 'key')
            );
            $this->get('logger')->error($msg, ['exception' => $e]);

            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_PICSURE_FILE_NOT_FOUND,
                $msg,
                422
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api picsureAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}/reconnect", name="api_auth_reconnect")
     * @Method({"POST"})
     */
    public function reconnectAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['renew', 'connection_id'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }
            $renew = $this->getDataBool($data, 'renew');
            $connectionId = $this->getDataString($data, 'connection_id');
            if ($renew === null) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, 'Unknown renew value', 422);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($id);
            if (!$policy) {
                throw new NotFoundHttpException();
            }
            $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);

            $connectionRepo = $dm->getRepository(Connection::class);
            $connection = $connectionRepo->find($connectionId);
            if (!$connection) {
                throw new NotFoundHttpException();
            }
            $connection->setRenew($renew);

            $this->validateObject($policy);

            $dm->flush();

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (NotFoundHttpException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_NOT_FOUND,
                'Unable to find policy/connection',
                404
            );
        } catch (ValidationException $e) {
            $this->get('logger')->info(sprintf('Failed reconnect'), ['exception' => $e]);
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                'Invalid reconnection',
                422
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api reconnectAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}/renew", name="api_auth_renew")
     * @Method({"POST"})
     */
    public function renewAction(Request $request, $id)
    {
        try {
            $decline = false;
            $numberPayments = null;
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['number_payments'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }
            $numberPayments = $this->getDataString($data, 'number_payments');

            if (isset($data['cashback'])) {
                if (!$this->validateFields($data['cashback'], ['account_number', 'sort_code', 'account_name'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
            }
            if (isset($data['decline'])) {
                if (!$this->validateFields($data, ['decline'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
                $decline = $this->getDataBool($data, 'decline');
                if ($decline && $numberPayments > 0) {
                    throw new ValidationException('If declined, number of payment should be 0');
                }
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($id);
            if (!$policy) {
                throw new NotFoundHttpException();
            }
            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);

            /** @var PolicyService $policyService */
            $policyService = $this->get('app.policy');
            $cashback = null;
            if (isset($data['cashback'])) {
                $cashback = new Cashback();
                $cashback->setDate(\DateTime::createFromFormat('U', time()));
                $cashback->setAccountName($this->getDataString($data['cashback'], 'account_name'));
                $cashback->setSortCode($this->getDataString($data['cashback'], 'sort_code'));
                $cashback->setAccountNumber($this->getDataString($data['cashback'], 'account_number'));
                $cashback->setStatus(Cashback::STATUS_PENDING_CLAIMABLE);
                $policyService->cashback($policy, $cashback);
            }

            if ($decline) {
                $policyService->declineRenew($policy);
            } else {
                $policyService->renew($policy, $numberPayments, $cashback);
            }

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $e) {
            $this->get('logger')->info(sprintf('Failed cashback'), ['exception' => $e]);
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                'Invalid bank details',
                422
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api renewAction.', ['exception' => $e]);

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
            $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);
            if ($this->getDataString($data, 'type') == SCode::TYPE_STANDARD && $policy->getStandardSCode()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                    'Only 1 standard active code is allowed',
                    422
                );
            }

            $scodeService = $this->get('app.scode');
            $scode = $scodeService->generateSCode($this->getUser(), $this->getDataString($data, 'type'));
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
            $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);

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
    public function putPolicySCodeAction($code)
    {
        \AppBundle\Classes\NoOp::ignore([$code]);

        return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UPGRADE_APP, 'Deprecated', 422);
    }

    /**
     * @Route("/policy/{id}/terms", name="api_auth_get_policy_terms")
     * @Route("/policy/v2/{id}/terms", name="api_auth_get_policy_terms2")
     * @Method({"GET"})
     */
    public function getPolicyTermsAction(Request $request, $id)
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
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_TERMS_NOT_AVAILABLE,
                    'Terms are not available',
                    422
                );
            }
            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
            if ($request->get('_route') == 'api_auth_get_policy_terms') {
                $termsRoute = 'policy_terms';
            } else {
                $termsRoute = 'policy_terms2';
            }
            $policyTermsRoute = $this->get('router')->generate(
                $termsRoute,
                [
                    'id' => $policy->getId(),
                    'policy_key' => $this->getParameter('policy_key'),
                    'maxPotValue' => $policy->getMaxPot(),
                    'yearlyPremium' => $policy->getPremium()->getYearlyPremiumPrice(),
                ],
                false
            );
            $policyTermsUrl = sprintf("%s%s", $this->getParameter('web_base_url'), $policyTermsRoute);

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
     * @Route("/policy/{id}/track/location", name="api_auth_policy_track_location")
     * @Method({"POST"})
     */
    public function trackPolicyLocationAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['type', 'latitude', 'longitude'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
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
            $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);

            if ($this->getDataString($data, 'type') === "picsure") {
                $coordinates = new Coordinates();
                $coordinates->setCoordinates(
                    $this->getDataString($data, 'longitude'),
                    $this->getDataString($data, 'latitude')
                );
                $policy->addPicsureLocation($coordinates);
                $this->validateObject($coordinates);
                $dm->persist($coordinates);
                $dm->flush();
            } else {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                    "Unrecognised location type",
                    422
                );
            }

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api trackPolicyLocationAction.', ['exception' => $e]);

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

            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $user);

            $this->get('statsd')->endTiming("api.getCurrentUser");
            $intercomHash = $this->get('app.intercom')->getApiUserHash($user);

            foreach ($user->getValidPolicies(true) as $policy) {
                $now = \DateTime::createFromFormat('U', time());
                if ($policy->getStart() > new \DateTime('2017-02-01') &&
                    $policy->getStart() < new \DateTime('2017-04-01') &&
                    $now < new \DateTime('2017-04-01') &&
                    !$policy->getPromoCode()) {
                    if ($reward = $this->findRewardUser('bonus@so-sure.net')) {
                        $invitationService = $this->get('app.invitation');
                        $invitationService->addReward($policy, $reward, 5);
                        $policy->setPromoCode(Policy::PROMO_APP_MARCH_2017);
                        $this->getManager()->flush();
                        $user = $this->getUser();
                    }
                }
            }

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

            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $user);
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

            $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);
            $data = json_decode($request->getContent(), true)['body'];
            // $this->get('logger')->info(sprintf('Update user %s', json_encode($data)));

            $email = $this->getDataString($data, 'email');
            $facebookId = $this->getDataString($data, 'facebook_id');
            $mobileNumber = $this->getDataString($data, 'mobile_number');

            // only need to check for dups for these fields if they have changed
            $emailCheck = null;
            if (mb_strlen($email) > 0 && $user->getEmailCanonical() != mb_strtolower($email)) {
                $emailCheck = $email;
            }
            $mobileCheck = null;
            if (mb_strlen($mobileNumber) > 0 && $user->getMobileNumber() != $mobileNumber) {
                $mobileCheck = $mobileNumber;
            }
            $facebookCheck = null;
            if ($this->isDataStringPresent($data, 'facebook_id') &&
                $user->getFacebookId() != $facebookId) {
                $facebookCheck = $facebookId;
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
            if (mb_strlen($mobileNumber) > 0 && $user->getMobileNumber() != $mobileNumber) {
                $user->setMobileNumber($mobileNumber);
                $userChanged = true;
            }
            if (mb_strlen($email) > 0 && $user->getEmailCanonical() != mb_strtolower($email)) {
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
                if ($user->hasPolicy()) {
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
                if ($user->hasPolicy()) {
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

            try {
                $this->validateObject($user);
            } catch (InvalidEmailException $ex) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, 'Invalid email format', 422);
            }

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

            $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

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

    /**
     * @Route("/user/{id}/payment", name="api_auth_user_payment")
     * @Method({"POST"})
     */
    public function paymentUserAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            $judoData = null;
            if (isset($data['bank_account'])) {
                // Not doing anymore
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
            } elseif (isset($data['judo'])) {
                if (!$this->validateFields($data['judo'], ['consumer_token', 'receipt_id'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
                $judoData = $data['judo'];
            } else {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($id);
            if (!$user) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find user',
                    404
                );
            }
            $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

            if ($judoData) {
                $judo = $this->get('app.judopay');
                $judo->updatePaymentMethod(
                    $user,
                    $this->getDataString($judoData, 'receipt_id'),
                    $this->getDataString($judoData, 'consumer_token'),
                    $this->getDataString($judoData, 'card_token'),
                    $this->getDataString($judoData, 'device_dna')
                );
            }

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
        } catch (PaymentDeclinedException $e) {
            $this->get('logger')->info(sprintf(
                'Payment declined policy %s receipt %s.',
                $id,
                $this->getDataString($judoData, 'receipt_id')
            ), ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_POLICY_PAYMENT_DECLINED, 'Payment Declined', 422);
        } catch (AccessDeniedException $e) {
            $this->get('logger')->warning(sprintf(
                'Access denied policy %s receipt %s.',
                $id,
                $this->getDataString($judoData, 'receipt_id')
            ), ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api payPolicy.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/user/{id}/quote", name="api_auth_user_quote")
     * @Method({"GET"})
     */
    public function userQuoteAction(Request $request, $id)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($id);
            if (!$user) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find user',
                    404
                );
            }
            /*
            // The way the client currently works, checking billing details is problematic
            // Removing for now - could look at having the client choose which quote request to use
            if (!$user->hasValidBillingDetails()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_INVALID_ADDRESS,
                    'Invalid billing address',
                    422
                );
            }
            */
            $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

            $make = $this->getRequestString($request, 'make');
            $device = $this->getRequestString($request, 'device');
            $memory = (float) $this->getRequestString($request, 'memory');
            $rooted = $this->getRequestBool($request, 'rooted');
            $quoteService = $this->get('app.quote');
            $quoteData = $quoteService->getQuotes($make, $device, $memory, $rooted);
            $phones = $quoteData['phones'];
            $deviceFound = $quoteData['deviceFound'];
            if (!$phones || !$deviceFound) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_QUOTE_PHONE_UNKNOWN, 'Unknown phone', 422);
            }

            $quotes = [];
            $hasPolicyWithSamePhone = false;
            foreach ($phones as $phone) {
                $hasPolicyWithSamePhone = $hasPolicyWithSamePhone || $user->hasPolicyWithSamePhone($phone);
                if ($quote = $phone->asQuoteApiArray($user)) {
                    $quotes[] = $quote;
                }
            }

            if ($rooted) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_QUOTE_UNABLE_TO_INSURE, 'Unable to insure', 422);
            }
            if (!$quoteData['anyActive']) {
                if ($quoteData['anyRetired'] && !$hasPolicyWithSamePhone) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_QUOTE_EXPIRED, 'Phone(s) are retired', 422);
                } elseif (!$quoteData['anyPricing']) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_QUOTE_COMING_SOON, 'Coming soon', 422);
                }
            }

            $response = [
                'quotes' => $quotes,
                'device_found' => $deviceFound,
            ];

            if ($this->getRequestBool($request, 'debug')) {
                $response['memory_found'] = $quoteData['memoryFound'];
                $response['rooted'] = $rooted;
                $response['different_make'] = $quoteData['differentMake'];
            }

            return new JsonResponse($response);
        } catch (AccessDeniedException $e) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api quoteAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/user/{id}/verify/mobilenumber", name="api_auth_user_request_verification_mobilenumber")
     * @Method({"GET"})
     */
    public function userRequestVerificationMobileNumberAction($id)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($id);
            if (!$user) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find user',
                    404
                );
            }
            $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

            $mobileNumber = $user->getMobileNumber();
            if (!$this->isValidUkMobile($mobileNumber)) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                    'Invalid UK mobile number',
                    422
                );
            }

            $sms = $this->get('app.sms');
            $code = $sms->setValidationCodeForUser($user);
            $status = $sms->sendTemplate(
                $mobileNumber,
                'AppBundle:Sms:validation-code.txt.twig',
                ['code' => $code],
                $user->getLatestPolicy(),
                Charge::TYPE_SMS_VERIFICATION
            );

            if ($status) {
                return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
            } else {
                $this->get('logger')->error('Error sending SMS.', ['mobile' => $mobileNumber]);
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_SEND_SMS, 'Error sending SMS', 422);
            }
        } catch (AccessDeniedException $e) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api requestVerificationMobileNumberAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/user/{id}/verify/mobilenumber", name="api_auth_user_verify_mobilenumber")
     * @Method({"POST"})
     */
    public function userVerifyMobileNumberAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (empty($data) || !isset($data['code'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($id);
            if (!$user) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find user',
                    404
                );
            }
            $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

            $code = $this->getDataString($data, 'code');
            $sms = $this->get('app.sms');
            if ($sms->checkValidationCodeForUser($user, $code)) {
                $user->setMobileNumberVerified(true);
                $dm->flush();
                return new JsonResponse($user->toApiArray());
            } else {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_VERIFY_CODE, 'No matching code', 422);
            }
        } catch (AccessDeniedException $e) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api verifyMobileNumberAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }
}
