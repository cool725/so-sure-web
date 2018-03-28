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
use AppBundle\Document\ArrayToApiArrayTrait;
use AppBundle\Document\Phone;
use AppBundle\Document\Sns;
use AppBundle\Document\Feature;
use AppBundle\Document\SCode;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PolicyTerms;

use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;
use AppBundle\Service\SixpackService;
use AppBundle\Exception\ValidationException;

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @Route("/api/v1/partial")
 */
class ApiPartialController extends BaseController
{
    use ArrayToApiArrayTrait;

    /**
     * @Route("/ab/{name}", name="api_ab_partipate")
     * @Method({"GET"})
     */
    public function abParticipateAction($name)
    {
        try {
            $sixpack = $this->get('app.sixpack');

            if ($name == SixpackService::EXPIRED_EXPERIMENT_SHARE_MESSAGE) {
                $user = $this->getUser();
                if (!$user || !$user instanceof User || !$user->hasActivePolicy()) {
                    throw new NotFoundHttpException();
                }
                // all policies should have the same scode
                $scode = null;
                foreach ($user->getPolicies() as $policy) {
                    if ($scode = $policy->getStandardSCode()) {
                        break;
                    }
                }
                if (!$scode) {
                    $this->get('logger')->warning(sprintf('Unable to find scode for user %s', $user->getId()));
                    throw new NotFoundHttpException();
                }

                $text = $sixpack->getText(
                    SixpackService::EXPIRED_EXPERIMENT_SHARE_MESSAGE,
                    SixpackService::ALTERNATIVES_SHARE_MESSAGE_SIMPLE,
                    [$scode->getShareLink(), $scode->getCode()]
                );

                return new JsonResponse([
                    'option' => SixpackService::ALTERNATIVES_SHARE_MESSAGE_SIMPLE,
                    'text' => $text
                ]);
            } elseif ($name == SixpackService::EXPERIMENT_APP_SHARE_METHOD) {
                $user = $this->getUser();
                if (!$user || !$user instanceof User || !$user->hasActivePolicy()) {
                    throw new NotFoundHttpException();
                }
                // all policies should have the same scode
                $scode = null;
                foreach ($user->getPolicies() as $policy) {
                    if ($scode = $policy->getStandardSCode()) {
                        break;
                    }
                }
                if (!$scode) {
                    $this->get('logger')->warning(sprintf('Unable to find scode for user %s', $user->getId()));
                    throw new NotFoundHttpException();
                }

                $experiment = $sixpack->participate(
                    SixpackService::EXPERIMENT_APP_SHARE_METHOD,
                    [
                        SixpackService::ALTERNATIVES_APP_SHARE_METHOD_NATIVE,
                        SixpackService::ALTERNATIVES_APP_SHARE_METHOD_API
                    ],
                    SixpackService::LOG_MIXPANEL_NONE,
                    1,
                    $scode->getCode()
                );

                return new JsonResponse([
                    'option' => $experiment,
                    'text' => null,
                ]);
            } else {
                throw new NotFoundHttpException();
            }
        } catch (NotFoundHttpException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_NOT_FOUND,
                'Unable to find experiment',
                404
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api abParticipateAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/ab/{name}", name="api_ab_convert")
     * @Method({"POST"})
     */
    public function abConvertAction(Request $request, $name)
    {
        try {
            $sixpack = $this->get('app.sixpack');

            if ($name == SixpackService::EXPIRED_EXPERIMENT_SHARE_MESSAGE) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::SUCCESS,
                    '',
                    200
                );
            } elseif ($name == SixpackService::EXPERIMENT_APP_SHARE_METHOD) {
                $id = $this->getRequestString($request, 'id');
                if ($id) {
                    $experiment = $sixpack->convertByClientId(
                        $id,
                        SixpackService::EXPERIMENT_APP_SHARE_METHOD
                    );
                } else {
                    $experiment = $sixpack->convert(
                        SixpackService::EXPERIMENT_APP_SHARE_METHOD
                    );
                }

                return $this->getErrorJsonResponse(
                    ApiErrorCode::SUCCESS,
                    '',
                    200
                );
            } else {
                throw new NotFoundHttpException();
            }
        } catch (NotFoundHttpException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_NOT_FOUND,
                'Unable to find experiment',
                404
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api abConvertAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/feature-flags", name="api_feature_flags")
     * @Method({"GET"})
     */
    public function featureFlagsAction()
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Feature::class);
            $features = $repo->findAll();

            return new JsonResponse([
                'flags' => $this->eachApiArray($features),
            ]);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api featureFlagsAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/sns", name="api_sns")
     * @Method({"POST"})
     */
    public function snsAction(Request $request)
    {
        try {
            $dm = $this->getManager();
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['endpoint'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing endpoint', 400);
            }

            $endpoint = $this->getDataString($data, 'endpoint');
            $platform = $this->getDataString($data, 'platform');
            $version = $this->getDataString($data, 'version');
            $oldEndpoint = $this->getDataString($data, 'old_endpoint');
            $this->snsSubscribe('all', $endpoint);
            $this->snsSubscribe('unregistered', $endpoint);
            if ($platform) {
                $this->snsSubscribe($platform, $endpoint);
                $this->snsSubscribe(sprintf('%s-%s', $platform, $version), $endpoint);
            }
            if ($oldEndpoint) {
                $this->snsUnsubscribe('all', $oldEndpoint);
                $this->snsUnsubscribe('unregistered', $oldEndpoint);
                $this->snsUnsubscribe('registered', $oldEndpoint);

                $repo = $dm->getRepository(Sns::class);
                $remove = $repo->findOneBy(['endpoint' => $oldEndpoint]);
                if ($remove) {
                    $dm->remove($remove);
                    $dm->flush();
                }
            }

            $user = $this->getUser();
            if ($user) {
                $repo = $dm->getRepository(User::class);
                $oldEndpointUsers = $repo->findBy(['snsEndpoint' => $endpoint]);
                foreach ($oldEndpointUsers as $oldEndpointUser) {
                    $oldEndpointUser->setSnsEndpoint(null);
                }
                $user->setSnsEndpoint($endpoint);
                $dm->flush();
            }

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'Endpoint added', 200);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api snsAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/ping", name="api_partial_ping")
     * @Method({"GET"})
     */
    public function pingAuthAction()
    {
        return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
    }
}
