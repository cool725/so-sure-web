<?php

namespace AppBundle\Listener;

use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Core\Event\AuthenticationFailureEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\Security\Core\User\UserInterface;
use AppBundle\Document\IdentityLog;
use AppBundle\Document\User;
use AppBundle\Service\MixpanelService;
use AppBundle\Event\ActualInteractiveLoginEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

class SecurityListener
{
	const LOGIN_FAILURE_KEY = 'privledged:login:failure:%s';

    /** @var EventDispatcher */
    protected $dispatcher;

    protected $logger;

    /** @var RequestStack */
    protected $requestStack;

    /** @var MixpanelService */
    protected $mixpanel;

    protected $redis;
    protected $dm;

    public function __construct(
        $logger,
        RequestStack $requestStack,
        $dispatcher,
        MixpanelService $mixpanel,
        $redis,
        $dm
    ) {
        $this->logger = $logger;
        $this->requestStack = $requestStack;
        $this->dispatcher = $dispatcher;
        $this->mixpanel = $mixpanel;
        $this->redis = $redis;
        $this->dm = $dm;
    }

    /**
     * @param InteractiveLoginEvent $event
     */
    public function onSecurityInteractiveLogin(InteractiveLoginEvent $event)
    {
        // although INTERACTIVE_LOGIN is supposed to ignore api requests
        //   http://symfony.com/doc/current/components/security/authentication.html
        // and its recommend to use the SimplePreAuthenticatorInterface
        //   http://symfony.com/doc/current/security/api_key_authentication.html
        // but triggering was clearly added to 2.7
        //   https://github.com/symfony/symfony/commit/2d17a0cac6cada236a4dfe8392738f8b176b26e4
        // so as a workaround, just ignore the api path
        //throw new \Exception(stripos($event->getRequest()->getPathInfo(), '/api/'));
        if ($event->getRequest() && stripos($event->getRequest()->getPathInfo(), '/api/') === 0) {
            $this->logger->debug(sprintf(
                'Skipping actual interative login path: %s',
                $event->getRequest()->getPathInfo()
            ));
            return;
        }

        $this->dispatcher->dispatch('security.interactive_login.actual', $event);
    }

    /**
     * @param InteractiveLoginEvent $event
     */
    public function onActualSecurityInteractiveLogin(InteractiveLoginEvent $event)
    {
        if (!$event->getAuthenticationToken() || !$event->getAuthenticationToken()->getUser() instanceof User) {
            return;
        }

        $identityLog = new IdentityLog();
        $identityLog->setIp($this->requestStack->getCurrentRequest()->getClientIp());
        $user = $event->getAuthenticationToken()->getUser();
        $user->setLatestWebIdentityLog($identityLog);

        if ($event->getRequest() && stripos($event->getRequest()->getPathInfo(), '/purchase/') === 0) {
            $this->logger->debug(sprintf(
                'Skipping mixpanel login event for purchase flow login path: %s',
                $event->getRequest()->getPathInfo()
            ));
            return;
        }
        $this->mixpanel->queueTrackWithUser($user, MixpanelService::EVENT_LOGIN);

		$key = sprintf(self::LOGIN_FAILURE_KEY, strtolower($user->getUsername()));
        $this->redis->del($key);
    }

    public function onAuthenticationFailure(AuthenticationFailureEvent $event)
    {
        $token = $event->getToken();
		// Get the attempted username.
		if ($token instanceof UsernamePasswordToken) {
			$username = $token->getUsername();
		} else {
			$username = null;
		}

		if ($username) {
			$repo = $this->dm->getRepository(User::class);
			$user = $repo->findOneBy(['usernameCanonical' => strtolower($username)]);
			if ($user->hasEmployeeRole() || $user->hasClaimsRole()) {
				$key = sprintf(self::LOGIN_FAILURE_KEY, strtolower($username));
				$count = $this->redis->incr($key);
				// PCI doesn't state how long a failed login persists. 1 hour should be enough for brute force
				$this->redis->expire($key, 3600);
				// PCI requirements - 6 failed logins
				if ($count > 6) {
					$user->setLocked(true);
					$this->dm->flush();
				}
			}
		}
    }
}
