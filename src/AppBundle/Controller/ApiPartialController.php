<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Form\Type\LaunchType;

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
     * @Route("/ab/v2", name="api_ab_partipate_all")
     * @Method({"GET"})
     */
    public function abParticipateAllAction(Request $request)
    {
        try {
            if (!$this->validateQueryFields($request, ['names'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $names = $this->getRequestString($request, 'names');
            
            $experiments = explode(',', $names);
            $tests = array();
            foreach ($experiments as $experiment) {
                try {
                    $tests[] = $this->abParticipate($experiment);
                } catch (NotFoundHttpException $e) {
                    $this->get('logger')->error(
                        'Error finding experiment in api abParticipateAllAction.',
                        ['exception' => $e]
                    );
                }
            }
            return new JsonResponse([
                'tests' => $tests,
            ]);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api abParticipateAllAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/ab/{name}", name="api_ab_partipate")
     * @Method({"GET"})
     */
    public function abParticipateAction($name)
    {
        try {
            return new JsonResponse($this->abParticipate($name));
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

    protected function abParticipate($name)
    {
        $user = $this->getUser();
        if (!$user || !$user instanceof User || !$user->hasActivePolicy()) {
            throw new NotFoundHttpException();
        }

        $sixpack = $this->get('app.sixpack');

        if (in_array($name, array_keys(SixpackService::$appExperiments))) {
            $clientId = null;
            if (in_array($name, SixpackService::getAppParticipationByClientId())) {
                $clientId = $user->getId();
            }

            $experiment = $sixpack->participate(
                $name,
                SixpackService::$appExperiments[$name],
                SixpackService::LOG_MIXPANEL_ALL,
                1,
                $clientId
            );

            return array(
                'name' => $name,
                'option' => $experiment,
                'text' => null,
            );
        } else {
            throw new NotFoundHttpException();
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
            } elseif (in_array($name, array_keys(SixpackService::$appExperiments))) {
                $id = $this->getRequestString($request, 'id');
                if ($id) {
                    $experiment = $sixpack->convertByClientId($id, $name);
                } else {
                    $experiment = $sixpack->convert($name);
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
