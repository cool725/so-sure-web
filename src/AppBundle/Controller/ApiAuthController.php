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
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Sns;
use AppBundle\Document\SCode;
use AppBundle\Document\User;
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

use AppBundle\Service\RateLimitService;
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

            $postcode = $this->getRequestString($request, 'postcode');
            $number = $this->getRequestString($request, 'number');

            $lookup = $this->get('app.address');
            if (!$address = $lookup->getAddress($postcode, $number, $this->getUser())) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            return new JsonResponse($address->toApiArray());
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $e]);

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
            if (!isset($data['action'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }
            if ($data['action'] == 'accept' && !isset($data['policy_id'])) {
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

            if ($data['action'] == 'accept') {
                $this->denyAccessUnlessGranted('accept', $invitation);
                $policy = $policyRepo->find($data['policy_id']);
                // TODO: Validation user hasn't exceeded pot amout
                $invitationService->accept($invitation, $policy);
            } elseif ($data['action'] == 'reject') {
                $this->denyAccessUnlessGranted('reject', $invitation);
                $invitationService->reject($invitation);
            } elseif ($data['action'] == 'cancel') {
                $this->denyAccessUnlessGranted('cancel', $invitation);
                $invitationService->cancel($invitation);
            } elseif ($data['action'] == 'reinvite') {
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
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api processInvitation.', ['exception' => $e]);

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

            if (!$this->validateFields(
                $data['phone_policy'],
                ['imei', 'make', 'device', 'memory', 'rooted', 'validation_data']
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            if ($data['phone_policy']['rooted']) {
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
            $addressValidator = $this->get('app.address');
            $user = $this->getUser();
            $this->denyAccessUnlessGranted(UserVoter::ADD_POLICY, $user);

            if (!$user->hasValidDetails()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_INVALID_USER_DETAILS,
                    'User needs first/last name, email, birthday & mobile number before policy can be created',
                    422
                );
            }

            // We'll probably want to change this in the future, but for now, a user can only create 1 policy
            if ($user->hasPolicy()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_POLICY_LIMIT,
                    'User can only have 1 policy',
                    422
                );
            }

            if (!$user->hasValidBillingDetails()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_INVALID_USER_DETAILS,
                    'User must have billing address set before policy can be created',
                    422
                );
            }

            if (!$addressValidator->validatePostcode($user->getBillingAddress()->getPostCode())) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_GEO_RESTRICTED,
                    "User's billing address must be valid and in GB",
                    422
                );
            }

            $rateLimit = $this->get('app.ratelimit');
            if (!$rateLimit->allowedByDevice(
                RateLimitService::DEVICE_TYPE_IMEI,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }
            if (!$rateLimit->allowedByDevice(
                RateLimitService::DEVICE_TYPE_POLICY,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            $imei = str_replace(' ', '', $data['phone_policy']['imei']);
            if (!$imeiValidator->isImei($imei)) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_IMEI_INVALID,
                    'Imei fails validation checks',
                    422
                );
            }
            if ($imeiValidator->isLostImei($imei)) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_IMEI_LOSTSTOLEN,
                    'Imei was reported as lost/stolen',
                    422
                );
            }

            $phone = $this->getPhone(
                $data['phone_policy']['make'],
                $data['phone_policy']['device'],
                $data['phone_policy']['memory']
            );
            if (!$phone) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to provide policy for this phone',
                    404
                );
            }

            try {
                $jwtValidator->validate(
                    $this->getCognitoIdentityId($request),
                    (string) $data['phone_policy']['validation_data'],
                    ['imei' => $data['phone_policy']['imei']]
                );
            } catch (\InvalidArgumentException $argE) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_INVALID_VALIDATION,
                    'Validation data is not valid',
                    422
                );
            }

            $dm = $this->getManager();
            $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);

            // TODO: Once a lost/stolen imei store is setup, will want to check there as well.
            if (!$phonePolicyRepo->isMissingOrExpiredOnlyPolicy($imei)) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_DUPLICATE_IMEI,
                    'Imei is already registered for a policy',
                    422
                );
            }

            $serialNumber = isset($data['phone_policy']['serial_number']) ?
                $data['phone_policy']['serial_number'] :
                null;

            // Checking against blacklist should be last check to possible avoid costs
            if (!$imeiValidator->checkImei($phone, $imei, $this->getUser())) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_IMEI_BLACKLISTED,
                    'Imei is blacklisted',
                    422
                );
            }

            // For phones without a serial number, run check on imei
            if (!$serialNumber) {
                $serialNumber = $imei;
            }

            if (!$imeiValidator->checkSerial($phone, $serialNumber, $this->getUser())) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_IMEI_PHONE_MISMATCH,
                    'Imei/Phone mismatch',
                    422
                );
            }

            $policy = new PhonePolicy();
            $policy->setImei($imei);
            $policy->setSerialNumber($serialNumber);
            $policy->setPhone($phone);
            $policy->setIdentityLog($this->getIdentityLog($request));
            $policy->addCheckmendCertData($imeiValidator->getCertId(), $imeiValidator->getResponseData());

            $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
            $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

            $policy->init($user, $latestTerms);
            $policy->setPhoneData(json_encode([
                'make' => $data['phone_policy']['make'],
                'device' => $data['phone_policy']['device'],
                'memory' => $data['phone_policy']['memory'],
            ]));
            
            $this->validateObject($policy);

            $dm->persist($policy);
            $dm->flush();

            $this->get('statsd')->endTiming("api.newPolicy");

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
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
            $email = isset($data['email']) ? $data['email'] : null;
            $mobile = isset($data['mobile']) ? $data['mobile'] : null;
            $name = isset($data['name']) ? $data['name'] : null;
            $scode = isset($data['scode']) ? $data['scode'] : null;
            try {
                $invitation  = null;
                if ($email && $validator->isValid($email)) {
                    $invitation = $invitationService->inviteByEmail($policy, $email, $name);
                } elseif ($mobile && $this->isValidUkMobile($mobile)) {
                    $invitation = $invitationService->inviteBySms($policy, $mobile, $name);
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
                if (!$this->validateFields($data['braintree'], ['nonce'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
            } elseif (isset($data['judo'])) {
                if (!$this->validateFields($data['judo'], ['consumer_token', 'receipt_id'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
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
            } elseif (isset($data['judo'])) {
                $judo = $this->get('app.judopay');
                $judo->add(
                    $policy,
                    $data['judo']['receipt_id'],
                    $data['judo']['consumer_token'],
                    isset($data['judo']['card_token']) ? $data['judo']['card_token'] : null,
                    isset($data['judo']['device_dna']) ? $data['judo']['device_dna'] : null
                );
            }
            $this->get('statsd')->endTiming("api.payPolicy");

            return new JsonResponse($policy->toApiArray());
        } catch (InvalidPremiumException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_PAYMENT_INVALID_AMOUNT,
                'Invalid premium paid',
                422
            );
        } catch (PaymentDeclinedException $e) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_POLICY_PAYMENT_DECLINED, 'Payment Declined', 422);
        } catch (AccessDeniedException $ade) {
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
            $policy = $repo->find($data['policy_id']);
            if (!$policy) {
                throw new NotFoundHttpException();
            }
            $this->denyAccessUnlessGranted('edit', $policy);
            if ($data['type'] == SCode::TYPE_STANDARD && $policy->getStandardSCode()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                    'Only 1 standard active code is allowed',
                    422
                );
            }

            $scode = new SCode();
            $scode->setType($data['type']);
            $scodeRepo = $dm->getRepository(SCode::class);
            if ($scodeRepo->findOneBy(['code' => $scode->getCode()])) {
                // TODO: Change to while loop
                throw new \Exception('duplicate code');
            }
            $policy->addSCode($scode);
            $this->validateObject($scode);

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
     * @Route("/scode/{code}", name="api_auth_get_scode")
     * @Method({"GET"})
     */
    public function getSCodeAction($code)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);

            $scodeRepo = $dm->getRepository(SCode::class);
            $scode = $scodeRepo->findOneBy(['code' => $code]);
            if (!$scode || !$scode->isActive()) {
                throw new NotFoundHttpException();
            }

            return new JsonResponse($scode->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (NotFoundHttpException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_NOT_FOUND,
                'Unable to find policy/code',
                404
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api getPolicySCodeAction.', ['exception' => $e]);

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

            return new JsonResponse($user->toApiArray());
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

            return new JsonResponse($user->toApiArray(null, null, $debug));
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

            $email = isset($data['email']) ? trim($data['email']) : null;
            $facebookId = isset($data['facebook_id']) ? trim($data['facebook_id']) : null;
            $mobileNumber = isset($data['mobile_number']) ? trim($data['mobile_number']) : null;
            $userExists = $repo->existsAnotherUser($user, $email, $facebookId, $mobileNumber);
            if ($userExists) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_EXISTS,
                    'Another user exists with those details',
                    422
                );
            }

            $userChanged = false;
            if (strlen($mobileNumber) > 0) {
                $user->setMobileNumber($mobileNumber);
                $userChanged = true;
            }

            if (strlen($email) > 0) {
                // TODO: Send email to both old & new email addresses
                $user->setEmail($email);
                $userChanged = true;
            }
            if (isset($data['first_name']) &&
                strlen($data['first_name']) > 0 &&
                $data['first_name'] != $user->getFirstName()) {
                if ($user->hasValidPolicy()) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                        'Unable to change name after policy is created',
                        422
                    );
                }
                $user->setFirstName($data['first_name']);
                $userChanged = true;
            }
            if (isset($data['last_name']) &&
                strlen($data['last_name']) > 0 &&
                $data['last_name'] != $user->getLastName()) {
                if ($user->hasValidPolicy()) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                        'Unable to change name after policy is created',
                        422
                    );
                }
                $user->setLastName($data['last_name']);
                $userChanged = true;
            }

            if (isset($data['facebook_id']) && strlen($data['facebook_id']) > 0 &&
                isset($data['facebook_access_token']) && strlen($data['facebook_access_token']) > 0 ) {
                $user->setFacebookId($data['facebook_id']);
                $user->setFacebookAccessToken($data['facebook_access_token']);
                $userChanged = true;
            }

            if (isset($data['birthday']) && strlen($data['birthday']) > 0) {
                $birthday = $this->validateBirthday($data);
                if ($birthday instanceof Response) {
                    return $birthday;
                }
                $user->setBirthday($birthday);
                $userChanged = true;
            }

            if (isset($data['scode']) && strlen($data['scode']) > 0) {
                $scodeRepo = $dm->getRepository(SCode::class);
                $scode = $scodeRepo->findOneBy(['code' => $data['scode']]);
                if (!$scode || !$scode->isActive()) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'SCode missing', 404);
                }
                $scode->addAcceptor($user);
                $userChanged = true;
            }

            if (isset($data['sns_endpoint']) && strlen($data['sns_endpoint']) > 0) {
                $oldEndpointUsers = $repo->findBy(['snsEndpoint' => $data['sns_endpoint']]);
                foreach ($oldEndpointUsers as $oldEndpointUser) {
                    $oldEndpointUser->setSnsEndpoint(null);
                }
                $user->setSnsEndpoint($data['sns_endpoint']);
                $userChanged = true;
            }

            $this->validateObject($user);

            if ($userChanged) {
                $dm->flush();
            }

            return new JsonResponse($user->toApiArray());
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
            if (!$addressValidator->validatePostcode($data['postcode'])) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_INVALID_ADDRESS,
                    "Invalid postcode",
                    422
                );
            }

            $address = new Address();
            $address->setType($data['type']);
            $address->setLine1($data['line1']);
            $address->setLine2(isset($data['line2']) ? $data['line2'] : null);
            $address->setLine3(isset($data['line3']) ? $data['line3'] : null);
            $address->setCity($data['city']);
            $address->setPostcode($data['postcode']);
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
