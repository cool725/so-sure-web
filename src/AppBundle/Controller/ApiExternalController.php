<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\IpUtils;

use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Document\OptOut\EmailOptOut;

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

/**
 * @Route("/external")
 */
class ApiExternalController extends BaseController
{
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
                    $invitation->optout($item['email'], EmailOptOut::OPTOUT_CAT_MARKETING);
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
                $currentPhonePrice = $phone->getCurrentPhonePrice();
                if (!$currentPhonePrice) {
                    continue;
                }

                // If there is an end date, then quote should be valid until then
                $quoteValidTo = $currentPhonePrice->getValidTo();
                if (!$quoteValidTo) {
                    $quoteValidTo = new \DateTime();
                    $quoteValidTo->add(new \DateInterval('P1D'));
                }

                $promoAddition = 0;
                $isPromoLaunch = false;

                $quotes[] = ['rate' => [
                    'reference' => $phone->getId(),
                    'product_name' => $phone->__toString(),
                    'monthly_premium' => $currentPhonePrice->getMonthlyPremiumPrice(),
                    'annual_premium' => $currentPhonePrice->getYearlyPremiumPrice(),
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
     * @Route("/gocompare/deeplink", name="api_external_gocompare_deeplink")
     * @Method({"POST"})
     */
    public function goCompareDeeplinkAction(Request $request)
    {
        $email = $this->getRequestString($request, 'email_address');
        $reference = $this->getRequestString($request, 'reference');

        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = $phoneRepo->find($reference);
        if (!$phone) {
            throw $this->createNotFoundException('Phone reference not found');
        }

        $this->setPhoneSession($request, $phone);

        $userRepo = $dm->getRepository(User::class);
        $user = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        if ($user) {
            return $this->redirectToRoute('quote_phone', ['id' => $phone->getId()]);
        }

        $user = new User();
        $user->setFirstName($this->getRequestString($request, 'first_name'));
        $user->setLastName($this->getRequestString($request, 'surname'));
        $user->setEmail($email);
        $user->setBirthday(\DateTime::createFromFormat('Y-m-d', $this->getRequestString($request, 'dob')));
        $user->setEnabled(true);

        $userManager = $this->get('fos_user.user_manager');
        $userManager->updateUser($user, true);
        $dm->persist($user);
        $dm->flush();

        $this->get('fos_user.security.login_manager')->loginUser(
            $this->getParameter('fos_user.firewall_name'),
            $user
        );

        return $this->redirectToRoute('quote_phone', ['id' => $phone->getId()]);
    }
}
