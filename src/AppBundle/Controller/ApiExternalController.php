<?php

namespace AppBundle\Controller;

use AppBundle\Classes\NoOp;
use AppBundle\Document\Address;
use AppBundle\Document\Attribution;
use AppBundle\Document\DateTrait;
use AppBundle\Service\IntercomService;
use AppBundle\Service\RequestService;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use AppBundle\Validator\Constraints\AlphanumericValidator;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\IpUtils;

use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Document\Opt\EmailOptOut;

use AppBundle\Classes\ApiErrorCode;
use AppBundle\Classes\GoCompare;
use AppBundle\Service\RateLimitService;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\ValidationData;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use AppBundle\Exception\ValidationException;
use VasilDakov\Postcode\Postcode;

/**
 * @Route("/external")
 */
class ApiExternalController extends BaseController
{
    use DateTrait;

    /**
     * @Route("/zendesk", name="api_external_zendesk")
     * @Method({"POST"})
     */
    public function unauthZendeskAction(Request $request)
    {
        try {
            $zendeskKey = $this->getParameter('zendesk_key');
            if ($request->get('zendesk_key') != $zendeskKey) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'Invalid key', 404);
            }
            $userToken = $this->getRequestString($request, 'user_token');
            if (mb_strlen($userToken) == 0) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $clientIp = $request->getClientIp();
            // https://support.zendesk.com/hc/en-us/articles/203660846-Zendesk-Public-IP-addresses
            $zendeskIps = [
                '174.137.46.0/24',
                '96.46.150.192/27',
                '96.46.156.0/24',
                '104.218.200.0/21',
                '185.12.80.0/22',
                '188.172.128.0/20',
                '192.161.144.0/20',
                '216.198.0.0/18',
                '52.192.205.30/32',
                '52.193.22.204/32',
                '52.37.220.11/32',
                '52.27.183.82/32',
                '52.37.212.231/32',
                '52.203.58.200/32',
                '52.203.0.71/32',
                '52.21.112.236/32',
            ];
            if ($request->get('debug')) {
                $zendeskIps[] = '127.0.0.1';
            }
            if (!$clientIp || !IpUtils::checkIp($clientIp, $zendeskIps)) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Unauthorized', 401);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($userToken);
            if (!$user || $user->isLocked()) {
                // @codingStandardsIgnoreStart
                // https://support.zendesk.com/hc/en-us/articles/218278138-Building-a-dedicated-JWT-endpoint-for-the-Zendesk-SDK
                // @codingStandardsIgnoreEnd
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'Unauthorized', 401);
            }

            $token = (new Builder())->setIssuedAt(time());
            $token->setId(uniqid('', true));
            $token->set('email', $user->getEmailCanonical());
            $token->set('name', $user->getName());

            $secret = $this->getParameter('zendesk_jwt_secret');
            $jwt = (string) $token->sign(new Sha256(), $secret)->getToken();

            return new JsonResponse(['jwt' => $jwt]);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api zenddesk.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/intercom", name="api_external_intercom")
     * @Method({"POST"})
     */
    public function unauthIntercomAction(Request $request)
    {
        try {
            $intercomWebhookKey = $this->getParameter('intercom_webhook_key');
            $signature = $request->headers->get('X-Hub-Signature');
            if (explode('=', $signature)[0] != 'sha1') {
                throw new \Exception(sprintf('Invalid intercom hash %s', $signature));
            }
            $signatureSha1 = explode('=', $signature)[1];
            $data = json_decode($request->getContent(), true);

            if (hash_hmac('sha1', $request->getContent(), $intercomWebhookKey) != $signatureSha1) {
                throw new \Exception(sprintf('Invalid intercom signature %s', $signature));
            }
            if ($data['topic'] == 'ping') {
                return new JsonResponse('pong');
            } elseif ($data['topic'] == 'user.unsubscribed') {
                $item = $data['data']['item'];
                if ($item['type'] != 'user') {
                    throw new \Exception(sprintf('Unknown unsub object %s', json_encode($item)));
                }

                // TODO: Should we resubscribe if unsubscribed_from_emails is false?
                if ($item['unsubscribed_from_emails']) {
                    $invitation = $this->get('app.invitation');
                    $invitation->optout(
                        $item['email'],
                        EmailOptOut::OPTOUT_CAT_MARKETING,
                        EmailOptOut::OPT_LOCATION_INTERCOM
                    );
                    $invitation->rejectAllInvitations($item['email']);
                }
            } else {
                throw new \Exception(sprintf('Unimplemented topic %s', $data['topic']));
            }

            return new JsonResponse([]);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api intercom.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/mixpanel/delete", name="api_external_mixpanel/delete")
     * @Method({"POST"})
     */
    public function unauthMixpanelDeleteAction(Request $request)
    {
        try {
            $mixpanelKey = $this->getParameter('mixpanel_webhook_key');
            if ($request->get('mixpanel_webhook_key') != $mixpanelKey) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'Invalid key', 404);
            }

            $users = $this->getRequestString($request, 'users');
            if (mb_strlen($users) == 0) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $mixpanel = $this->get('app.mixpanel');
            $userData = json_decode($users, true);
            foreach ($userData as $user) {
                $this->get('logger')->info(sprintf(
                    'Mixpanel delete %s',
                    $user['$distinct_id']
                ));
                $mixpanel->queueDelete($user['$distinct_id']);
            }

            return new JsonResponse([]);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api mixpanel delete.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/gocompare/feed", name="api_external_gocompare_feed")
     * @Method({"POST"})
     */
    public function goCompareFeedAction(Request $request)
    {
        try {
            $goCompareKey = $this->getParameter('gocompare_key');
            if ($request->get('gocompare_key') != $goCompareKey) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'Invalid key', 404);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(Phone::class);

            $phones = [];
            $data = json_decode($request->getContent(), true)['request'];
            foreach ($data['gadgets'] as $key => $gadget) {
                $id = $gadget['gadget']['gadget_id'];
                if ($id && $gadget['gadget']['loss_cover'] && isset(GoCompare::$models[$id])) {
                    if ($query = GoCompare::$models[$id]) {
                        if ($phone = $repo->findOneBy(array_merge($query, ['active' => true]))) {
                            $phones[] = $phone;
                        }
                    }
                }
            }

            $quotes = [];
            foreach ($phones as $phone) {
                $monthlyPrice = $phone->getCurrentMonthlyPhonePrice();
                $yearlyPrice = $phone->getCurrentYearlyPhonePrice();
                if (!($monthlyPrice && $yearlyPrice)) {
                    continue;
                }
                // If there is an end date, then quote should be valid until then
                $quoteValidTo = \DateTime::createFromFormat('U', time());
                $quoteValidTo->add(new \DateInterval('P1D'));
                $promoAddition = 0;
                $isPromoLaunch = false;
                $quotes[] = ['rate' => [
                    'reference' => $phone->getId(),
                    'product_name' => $phone->__toString(),
                    'monthly_premium' => $monthlyPrice->getMonthlyPremiumPrice(),
                    'annual_premium' => $yearlyPrice->getYearlyPremiumPrice(),
                    'additional_gadget' => 0,
                ]];
            }

            $response = [
                'response' => $quotes,
            ];

            return new JsonResponse($response);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api goCompareFeedAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/gocompare/deeplink", name="api_external_gocompare_deeplink_redirect")
     * @Method({"GET"})
     */
    public function goCompareDeeplinkRedirectAction()
    {
        $this->addFlash(
            'error',
            'There seems to be an issue with your request. Please contact support@wearesosure.com for further details.'
        );

        return $this->redirectToRoute('homepage');
    }

    /**
     * @Route("/gocompare/deeplink", name="api_external_gocompare_deeplink")
     * @Method({"POST"})
     */
    public function goCompareDeeplinkAction(Request $request)
    {
        $this->get('logger')->debug(sprintf('GoCompare Deeplink Request: %s', json_encode($request->request->all())));

        $email = $this->getRequestString($request, 'email_address');
        $reference = $this->getRequestString($request, 'reference');

        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = $phoneRepo->find($reference);
        if (!$phone) {
            throw $this->createNotFoundException('Phone reference not found');
        }

        $this->setPhoneSession($request, $phone);

        $data = [
            'id' => $phone->getId()
        ];
        if ($request->get('aggregator') && $request->get('aggregator') == 'true') {
            $data['aggregator'] = 'true';
        }

        $userRepo = $dm->getRepository(User::class);
        $user = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        if ($user) {
            return $this->redirectToRoute('quote_phone', $data);
        }

        $alphaValidator = new AlphanumericValidator();
        $alphaSpaceValidator = new AlphanumericSpaceDotValidator();

        $user = new User();
        $user->setEnabled(true);

        /** @var RequestService $requestService */
        $requestService = $this->get('app.request');
        $attribution = $requestService->getAttribution();
        $attribution->setGoCompareQuote(
            $alphaValidator->conform($this->getRequestString($request, 'quote_id'))
        );
        $user->setAttribution($attribution);

        $user->setFirstName($alphaValidator->conform($this->getRequestString($request, 'first_name')));
        $user->setLastName($alphaValidator->conform($this->getRequestString($request, 'surname')));
        $user->setEmail($email);


        $dob = $this->getRequestString($request, 'dob');
        $birthday = null;
        try {
            $birthday = \DateTime::createFromFormat('Y-m-d', $dob);
            if ($birthday) {
                $birthday = $this->startOfDay($birthday);
            }
        } catch (\Exception $e) {
            // skip birthday if unable to process
            NoOp::ignore([]);
        }
        $user->setBirthday($birthday);

        $address = new Address();
        $line1 = sprintf(
            "%s %s",
            $alphaSpaceValidator->conform($this->getRequestString($request, 'house_no')),
            $alphaSpaceValidator->conform($this->getRequestString($request, 'address_1'))
        );
        $line2 = $alphaSpaceValidator->conform($this->getRequestString($request, 'address_2'));
        $line3 = $alphaSpaceValidator->conform($this->getRequestString($request, 'address_3'));
        $line4 = $alphaSpaceValidator->conform($this->getRequestString($request, 'address_4'));
        $postcode = $alphaSpaceValidator->conform($this->getRequestString($request, 'postcode'));

        // Unfortunately, no city field - assume last line is city
        $city = null;
        if (mb_strlen($line4) > 0) {
            $city = $line4;
            $line4 = null;
        } elseif (mb_strlen($line3) > 0) {
            $city = $line3;
            $line3 = null;
        } elseif (mb_strlen($line2) > 0) {
            $city = $line2;
            $line2 = null;
        }

        if (!Postcode::isValid($postcode)) {
            $postcode = null;
        }

        $address->setLine1($line1);
        $address->setLine2($line2);
        $address->setLine3($line3);
        $address->setCity($city);
        if (mb_strlen($postcode) > 0) {
            $address->setPostcode($postcode);
        }
        $user->setBillingAddress($address);

        $userManager = $this->get('fos_user.user_manager');
        $userManager->updateUser($user, true);
        $dm->persist($user);
        $dm->flush();

        $this->get('fos_user.security.login_manager')->loginUser(
            $this->getParameter('fos_user.firewall_name'),
            $user
        );

        return $this->redirectToRoute('quote_phone', $data);
    }

    /**
     * @Route("/intercom/messenger/init", name="api_external_intercom_messenger_init")
     * @Method({"POST"})
     */
    public function intercomMessengerInitAction(Request $request)
    {
        $this->validateIntercomSignature($request);

        /** @var IntercomService $intercom */
        $intercom = $this->get('app.intercom');
        return new JsonResponse($intercom->getDpaCard());
    }

    /**
     * @Route("/intercom/messenger/config", name="api_external_intercom_messenger_config")
     * @Method({"POST"})
     */
    public function intercomMessengerConfigAction(Request $request)
    {
        $this->validateIntercomSignature($request);

        return new JsonResponse([
            'results' => [
                'name' => ''
            ]
        ]);
    }

    /**
     * @Route("/intercom/messenger/submit", name="api_external_intercom_messenger_submit")
     * @Method({"POST"})
     */
    public function intercomMessengerSubmitAction(Request $request)
    {
        $this->validateIntercomSignature($request);

        $data = json_decode($request->getContent(), true);
        $firstName = $data['input_values']['firstName'];
        $lastName = $data['input_values']['lastName'];
        $dob = $data['input_values']['dob'];
        $mobile = $data['input_values']['mobile'];
        $conversationId = $data['context']['conversation_id'];
        $button = $data['component_id'];

        /*
        print_r($data);
        print_r($name);
        print_r($dob);
        print_r($mobile);
        print_r($conversationId);
        */

        /** @var IntercomService $intercom */
        $intercom = $this->get('app.intercom');
        if ($button == 'manual') {
            $intercom->sendSearchUserNote(
                $firstName,
                $lastName,
                $dob,
                $mobile,
                $conversationId,
                'Manual validation requested.'
            );

            $intercom->sendReply($conversationId, 'One of the team will get back to you soon.');

            return new JsonResponse($intercom->canvasText('DPA Completed (unsuccessfully)'));
        }

        return new JsonResponse($intercom->validateDpa($firstName, $lastName, $dob, $mobile, $conversationId));
    }

    private function validateIntercomSignature(Request $request)
    {
        $intercomDpaAppSecret = $this->getParameter('intercom_dpa_app_secret');
        $signature = $request->headers->get('X-Body-Signature');

        if (hash_hmac('sha256', $request->getContent(), $intercomDpaAppSecret) != $signature) {
            throw new \Exception(sprintf('Invalid intercom signature %s', $signature));
        }
    }
}
