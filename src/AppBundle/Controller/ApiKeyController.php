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
use AppBundle\Document\Phone;
use AppBundle\Document\Sns;
use AppBundle\Document\User;
use AppBundle\Document\PolicyTerms;

use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use AppBundle\Exception\MonitorException;
use AppBundle\Exception\ValidationException;

/**
 * @Route("/api/v1/key")
 */
class ApiKeyController extends BaseController
{
    /**
     * @Route("/quote", name="api_key_quote")
     * @Method({"GET"})
     */
    public function quoteAction(Request $request)
    {
        try {
            $make = $this->getRequestString($request, 'make');
            $model = $this->getRequestString($request, 'model');

            $dm = $this->getManager();
            $repo = $dm->getRepository(Phone::class);
            $query = null;
            if ($make && $model) {
                $query = ['make' => $make, 'model' => $model, 'active' => true];
            } elseif ($make) {
                $query = ['make' => $make, 'active' => true];
            } else {
                $query = ['active' => true];
            }

            $phones = $repo->findBy($query);

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
                $quotes[] = [
                    'monthly_premium' => $monthlyPrice->getMonthlyPremiumPrice(),
                    'monthly_loss' => 0,
                    'yearly_premium' => $yearlyPrice->getYearlyPremiumPrice(),
                    'yearly_loss' => 0,
                    'phone' => $phone->toApiArray(),
                    'connection_value' => $monthlyPrice->getInitialConnectionValue($promoAddition),
                    'max_connections' => $monthlyPrice->getMaxConnections($promoAddition, $isPromoLaunch),
                    'max_pot' => $monthlyPrice->getMaxPot($isPromoLaunch),
                    'valid_to' => $quoteValidTo->format(\DateTime::ATOM),
                ];
            }

            $response = [
                'quotes' => $quotes,
                'device_found' => false,
            ];

            return new JsonResponse($response);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api quoteAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/monitor/{name}", name="api_key_monitor")
     * @Route("/monitor/{name}/details", name="api_key_monitor_details")
     * @Method({"GET"})
     */
    public function monitorAction(Request $request, $name)
    {
        // monitor/api-monitor-intercomPolicyPremium : CheckHttp CRITICAL: Request timed out is occurring frequently
        set_time_limit(60);
        try {
            $monitor = $this->get('app.monitor');
            $message = $monitor->run($name, $request->get('_route') == 'api_key_monitor_details');

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, $message, 200);
        } catch (MonitorException $ex) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api monitorAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }
}
