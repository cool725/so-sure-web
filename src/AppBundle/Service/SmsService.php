<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\Common\Persistence\ManagerRegistry;
use Plivo\RestAPI;
use AppBundle\Document\User;
use AppBundle\Document\Charge;
use AppBundle\Document\Policy;

class SmsService
{

    const VALIDATION_TIMEOUT = 600; // 10 minutes

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var RestAPI */
    protected $client;

    /** @var string */
    protected $sending_number;

    protected $router;

    protected $templating;

    protected $redis;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param                 $router
     * @param string          $auth_id
     * @param string          $auth_token
     * @param string          $sending_number
     * @param                 $templating
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $router,
        $auth_id,
        $auth_token,
        $sending_number,
        $templating,
        $redis
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->router = $router;
        $this->client = new RestAPI($auth_id, $auth_token);
        $this->sending_number = $sending_number;
        $this->templating = $templating;
        $this->redis = $redis;
    }

    /**
     * @param string $number
     * @param string $message
     *
     * @return boolean
     */
    public function send($number, $message)
    {
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

    public function setValidationCodeForUser($userId)
    {
        $characters = '0123456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $characters[rand(0, 9)];
        }

        $key = sprintf('Mobile:Validation:%s:%s', $userId, $code);
        $this->redis->setEx($key, self::VALIDATION_TIMEOUT, $code);

        return $code;
    }

    public function checkValidationCodeForUser($userId, $code)
    {
        if (mb_strlen($code) != 6) {
            return false;
        }

        $key = sprintf('Mobile:Validation:%s:%s', $userId, $code);
        $foundCode = $this->redis->get($key);

        if ($foundCode === $code) {
            $this->redis->del($key);
            return true;
        }

        return false;
    }
}
