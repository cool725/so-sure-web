<?php
namespace AppBundle\Service;

use Predis\Client;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\Common\Persistence\ManagerRegistry;
use Plivo\RestAPI;
use AppBundle\Document\User;
use AppBundle\Document\Charge;
use AppBundle\Document\Policy;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Templating\EngineInterface;
use Twig\Template;

class SmsService
{

    const VALIDATION_KEY = 'Mobile:Validation:%s:%s';
    const VALIDATION_TIMEOUT = 600; // 10 minutes

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var RestAPI */
    protected $client;

    /** @var string */
    protected $sending_number;

    /** @var RouterInterface */
    protected $router;

    /** @var EngineInterface */
    protected $templating;

    /** @var Client */
    protected $redis;

    /** @var string */
    protected $environment;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param RouterInterface $router
     * @param string          $auth_id
     * @param string          $auth_token
     * @param string          $sending_number
     * @param EngineInterface $templating
     * @param Client          $redis
     * @param string          $environment
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        RouterInterface $router,
        $auth_id,
        $auth_token,
        $sending_number,
        EngineInterface $templating,
        $redis,
        $environment
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->router = $router;
        $this->client = new RestAPI($auth_id, $auth_token);
        $this->sending_number = $sending_number;
        $this->templating = $templating;
        $this->redis = $redis;
        $this->environment = $environment;
    }

    /**
     * Environment is injected into constructed and should only
     * be overwriten for a few test cases.
     *
     * @param string $environment
     */
    public function setEnvironment($environment)
    {
        $this->environment = $environment;
    }

    /**
     * @param string $number
     * @param string $message
     *
     * @return boolean
     */
    public function send($number, $message)
    {
        if ($this->environment == "test") {
            return true;
        }
        try {
            $params = array(
                'src' => $this->sending_number, // Sender's phone number with country code
                'dst' => $number, // Receiver's phone number with country code
                'text' => $message, // Your SMS text message
                //'url' => 'http://example.com/report/', // The URL to which with the status of the message is sent
                //'method' => 'POST' // The method used to call the url
            );
            // Send mes
            $resp = $this->client->send_message($params);
        } catch (\Exception $e) {
            $this->logger->error(sprintf("Unable to send to %s Ex: %s", $number, $e->getMessage()));

            return false;
        }

        return true;
    }

    public function sendTemplate($number, $template, $data)
    {
        $message = $this->templating->render($template, $data);

        return $this->send($number, $message);
    }

    public function sendUser(Policy $policy, $template, $data)
    {
        $user = $policy->getUser();
        $this->sendTemplate($user->getMobileNumber(), $template, $data);

        $charge = new Charge();
        $charge->setType(Charge::TYPE_SMS);
        $charge->setUser($user);
        $charge->setPolicy($policy);
        $charge->setDetails($user->getMobileNumber());

        $this->dm->persist($charge);
        $this->dm->flush();
    }

    public function setValidationCodeForUser($user)
    {
        $characters = '0123456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $characters[rand(0, 9)];
        }

        $key = sprintf(self::VALIDATION_KEY, $user->getId(), $code);
        $this->redis->setEx($key, self::VALIDATION_TIMEOUT, $code);

        return $code;
    }

    public function checkValidationCodeForUser($user, $code)
    {
        if (mb_strlen($code) != 6) {
            return false;
        }

        $key = sprintf(self::VALIDATION_KEY, $user->getId(), $code);
        $foundCode = $this->redis->get($key);

        if ($foundCode === $code) {
            $this->redis->del($key);
            return true;
        }

        return false;
    }
}
