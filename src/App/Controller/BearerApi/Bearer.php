<?php
namespace App\Controller\BearerApi;

use AppBundle\Classes\ApiErrorCode;
use AppBundle\Controller\BaseController;
use AppBundle\DataObjects\PolicySummary;
use AppBundle\Document\User;
use AppBundle\Security\PolicyVoter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * API that deals with Bearer-token based authentication
 *
 * @Route("/bearer-api/v1")
 */
class Bearer extends BaseController
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger, ContainerInterface $container)
    {
        $this->logger = $logger;
        $this->container = $container;
    }

    /**
     * Show the identified username for the access-token (Bearer token)
     * @Route("/ping")
     */
    public function ping(): Response
    {
        $user = $this->getUser();

        $data = [
            'response' => 'pong',
            'data' => $user->getUsername(),
        ];

        return new Response(json_encode($data));
    }

    /**
     * Get user & policy summary for the current user.
     *
     * When accessing via a Bearer token, the user was attached to the token during the original auth.
     *
     * @Route("/user")
     */
    public function user(): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'User not found', 404);
            }

            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $user);

            $response = new PolicySummary($user);

            return new JsonResponse($response->get());
        } catch (AccessDeniedException $exception) {
            $this->logger->notice('Access Denied for user', ['user' => $user->getUsername()]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Throwable $e) {
            $this->logger->error('exception thrown: ', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        }
    }
}
