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
use AppBundle\Document\User;
use AppBundle\Document\Invitation\Invitation;

use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\ProcessedException;

use AppBundle\Service\RateLimitService;
use AppBundle\Security\UserVoter;
use AppBundle\Classes\ApiErrorCode;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @Route("/api/v1/auth")
 */
class ApiAuthController extends BaseController
{
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

            $postcode = trim($request->get('postcode'));
            $number = trim($request->get('number'));

            $lookup = $this->get('app.address');
            $address = $lookup->getAddress($postcode, $number);

            return new JsonResponse($address->toApiArray());
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api addressAction. %s', $e->getMessage()));

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
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api processInvitation. %s', $e->getMessage()));

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

            $imeiValidator = $this->get('app.imei');
            $jwtValidator = $this->get('app.jwt');
            $addressValidator = $this->get('app.address');
            $user = $this->getUser();
            $this->denyAccessUnlessGranted(UserVoter::ADD_POLICY, $user);

            if (!$user->hasValidDetails()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_INVALID_USER_DETAILS,
                    'User must have firstname/lastname, email & mobile number present before policy can be created',
                    422
                );
            }

            if (!$user->hasValidGocardlessDetails()) {
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

            if (!$imeiValidator->checkImei($imei)) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_IMEI_BLACKLISTED,
                    'Imei is blacklisted',
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

            // TODO: check we're not already insuring the same imei (only for policy state active,pending)

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

            $policy = new PhonePolicy();
            $policy->setImei($imei);
            $policy->setPhone($phone);

            $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
            $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);
            $policy->setPolicyTerms($latestTerms);

            $policy->setPhoneData(json_encode([
                'make' => $data['phone_policy']['make'],
                'device' => $data['phone_policy']['device'],
                'memory' => $data['phone_policy']['memory'],
            ]));
            $user->addPolicy($policy);

            $dm->persist($policy);
            $dm->flush();

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api newPolicy. %s', $e->getMessage()));

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
            $this->get('logger')->error(sprintf('Error in api getPolicy. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}/dd", name="api_auth_new_policy_dd")
     * @Method({"POST"})
     */
    public function newPolicyDdAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['sort_code', 'account_number', 'first_name', 'last_name'])) {
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
            $this->denyAccessUnlessGranted('edit', $policy);

            $gocardless = $this->get('app.gocardless');
            $gocardless->add(
                $policy,
                $data['first_name'],
                $data['last_name'],
                $data['sort_code'],
                $data['account_number']
            );

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api newPolicyDD. %s', $e->getMessage()));

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
            $this->denyAccessUnlessGranted('send-invitation', $policy);

            $invitationService = $this->get('app.invitation');
            // avoid sending email/sms invitations if testing
            if ($request->get('debug')) {
                $invitationService->setDebug(true);
            }

            $data = json_decode($request->getContent(), true)['body'];
            $email = isset($data['email']) ? $data['email'] : null;
            $mobile = isset($data['mobile']) ? $data['mobile'] : null;
            $name = isset($data['name']) ? $data['name'] : null;
            try {
                $invitation  = null;
                if ($email) {
                    $invitation = $invitationService->email($policy, $email, $name);
                } elseif ($mobile) {
                    $invitation = $invitationService->sms($policy, $mobile, $name);
                } else {
                    // TODO: General
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_NOT_FOUND,
                        'Missing data found',
                        422
                    );
                }

                if (!$invitation) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_INVITATION_OPTOUT,
                        'Person has opted out of invitations',
                        422
                    );
                }

                return new JsonResponse($invitation->toApiArray());
            } catch (\InvalidArgumentException $argEx) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVITATION_DUPLICATE,
                    'Duplicate invitation',
                    422
                );
            } catch (\Exception $e) {
                $this->get('logger')->error(sprintf('Error in api newInvitation. %s', $e->getMessage()));

                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
            }
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api newInvitation. %s', $e->getMessage()));

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
            $this->denyAccessUnlessGranted('view', $policy);
            $policyTermsUrl = $this->get('router')->generate('policy_terms', [
                'id' => $policy->getId(),
                'policy_key' => $this->getParameter('policy_key'),
            ]);

            return new JsonResponse($policy->getPolicyTerms()->toApiArray($policyTermsUrl));
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api getPolicyTerms. %s', $e->getMessage()));

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
            $this->get('logger')->error(sprintf('Error in api getSecret. %s', $e->getMessage()));

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
            $user = $this->getUser();
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'User not found', 404);
            }

            $this->denyAccessUnlessGranted('view', $user);

            return new JsonResponse($user->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api getUser. %s', $e->getMessage()));

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
            if ($request->get('debug')) {
                $debug = true;
            }

            return new JsonResponse($user->toApiArray(null, null, $debug));
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api getUser. %s', $e->getMessage()));

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
            $userChanged = false;

            if (isset($data['mobile_number']) && strlen($data['mobile_number']) > 0) {
                $user->setMobileNumber($data['mobile_number']);
                $userChanged = true;
            }

            if (isset($data['email']) && strlen($data['email']) > 0) {
                // TODO: Send email to both old & new email addresses
                $user->setEmail($data['email']);
                $userChanged = true;
            }
            if (isset($data['first_name']) && strlen($data['first_name']) > 0) {
                $user->setFirstName($data['first_name']);
                $userChanged = true;
            }
            if (isset($data['last_name']) && strlen($data['last_name']) > 0) {
                $user->setLastName($data['last_name']);
                $userChanged = true;
            }

            if (isset($data['facebook_id']) && strlen($data['facebook_id']) > 0 &&
                isset($data['facebook_access_token']) && strlen($data['facebook_access_token']) > 0 ) {
                $user->setFacebookId($data['facebook_id']);
                $user->setFacebookAccessToken($data['facebook_access_token']);
                $userChanged = true;
            }

            $validator = $this->get('validator');
            $errors = $validator->validate($user);
            if (count($errors) > 0) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, (string) $errors);
            }

            if ($userChanged) {
                $dm->flush();
            }

            return new JsonResponse($user->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api updateUser. %s', $e->getMessage()));

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

            if ($existingAddress = $user->getBillingAddress()) {
                $user->removeAddress($existingAddress);
                $dm->remove($existingAddress);
            }

            $address = new Address();
            $address->setType($data['type']);
            $address->setLine1($data['line1']);
            $address->setLine2(isset($data['line2']) ? $data['line2'] : null);
            $address->setLine3(isset($data['line3']) ? $data['line3'] : null);
            $address->setCity($data['city']);
            $address->setPostcode($data['postcode']);
            $user->addAddress($address);

            $dm->persist($address);
            $dm->flush();

            return new JsonResponse($user->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api addUserAddress. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }
}
