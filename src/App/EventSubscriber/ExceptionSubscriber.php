<?php
namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ExceptionSubscriber implements EventSubscriberInterface
{
    /** @var LoggerInterface */
    private $log;

    public function __construct(LoggerInterface $logger)
    {
        $this->log = $logger;
    }

    public static function getSubscribedEvents()
    {
        // return the subscribed events, their methods and priorities
        return array(
            KernelEvents::RESPONSE => [ ['bearerApiResponse', 32], ],
        );
    }

    /**
     * Convert the exception from generic FosOauthServer to something Starling wants
     */
    public function bearerApiResponse(FilterResponseEvent $event)
    {
        $request = $event->getRequest();
        if ($request->get('_route') !== 'fos_oauth_server_token') {
            return;
        }

        if ($this->isStandardOauthError($event->getResponse())) {
            $this->translateJsonErrorForStarling($event);
        }
    }

    /**
     * is this a not-Starling Oauth2 error?
     */
    private function isStandardOauthError(Response $response) : bool
    {
        $content = $response->getContent();
        if ($content[0] !== '{') {
            return false;
        }

        $obj = json_decode($content);

        return property_exists($obj, 'error') && !property_exists($obj, 'error-code');
    }

    private function translateJsonErrorForStarling(FilterResponseEvent $event)
    {
        $response = $event->getResponse();
        $content = $response->getContent();

        $obj = json_decode($content);

        // @codingStandardsIgnoreStart
        // rebuild the error to Starling's spec
        static $starlingErrorDescription = [
            'Invalid grant_type parameter or parameter missing' => 'grant_type must be provided',
            'Client id was not found in the headers or body' => 'client_id must be specified',
            'The client credentials are invalid' => 'Client not authorised to access token or authentication failed',
            'Missing parameter. "code" is required' => 'authorization_code must be specified',
            'The redirect URI parameter is required.' => 'redirect_uri must match the value provided when getting the authorization code',
            "Code doesn't exist or is invalid for the client" => 'authorization code could not be validated. It could be invalid, expired or revoked',
        ];
        // @codingStandardsIgnoreEnd

        if (isset($starlingErrorDescription[$obj->error_description])) {
            if ($response->getStatusCode() === 400) {
                $obj->{'error-code'} = 'invalid_request';
            }
            if ($response->getStatusCode() === 403) {
                $obj->{'error-code'} = 'invalid_grant';
            }
            unset($obj->error);
            $obj->error_description = $starlingErrorDescription[$obj->error_description];

            $response->setContent(json_encode($obj));
            return;
        }

        /*var_dump($obj);
        debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40);
        die;*/
    }
}
