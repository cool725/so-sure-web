<?php
namespace AppBundle\Controller\Api;

use AppBundle\Classes\ApiErrorCode;
use AppBundle\Controller\BaseController;
use AppBundle\DataObjects\PolicySummary;
use AppBundle\Document\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * API that deals with Bearer-token based authentication
 *
 * @Route("/api/v1/bearer")
 */
class Bearer extends BaseController
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @Route("/proof")
     */
    public function proof(Request $request): JsonResponse
    {
        return new JsonResponse($request);
    }

    /**
     * @Route("/user/{id}")
     */
    public function user(Request $request, $id): JsonResponse
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            /** @var User $user */
            $user = $repo->find($id);
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'User not found', 404);
            }

            #$this->denyAccessUnlessGranted(PolicyVoter::VIEW, $user);

            $debug = false;
            if ($this->getRequestBool($request, 'debug')) {
                $debug = true;
            }

            $response = new PolicySummary($user);
            #$this->logger->info(sprintf('getUserAction Resp %s', json_encode($response)));

            return new JsonResponse($response->get());
        } catch (\Throwable $e) {
            $this->logger->notice('exception thrown: ', ['exception'=>$e]);
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        }
    }
}
