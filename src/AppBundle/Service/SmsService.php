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
     * Creates and persists an SMS charge record in the database in relation to a given policy
     * @param Policy $policy is the policy that this charge is in relation to. The user and their details can be
     *               inferred from this
     * @param string $type is the type of sms charge being made as there are three different types.
     */
    private function addCharge($policy, $type)
    {
        $charge = new Charge();
        $charge->setType($type);
        $charge->setUser($policy->getUser());
        $charge->setPolicy($policy);
        $charge->setDetails($policy->getUser()->getMobileNumber());
        $this->dm->persist($charge);
        $this->dm->flush();
    }

    /**
     * Sends an SMS to any mobile phone number.
     * @param string $number is the mobile phone number to send the SMS to.
     * @param string $message is the message to be sent.
     * @param string $chargePolicy optional, and describes the policy regarding which the text message has been sent.
     *                             If it is not null then an sms charge is committed to the database for this SMS.
     * @param string $type is the type of sms charge to be made if one is to be made.
     * @return boolean true iff the sms was successfully sent.
     */
    public function send($number, $message, $chargePolicy = null, $type = Charge::TYPE_SMS_INVITATION)
    {
        if ($this->environment == "test") {
            if ($chargePolicy) {
                $this->addCharge($chargePolicy, $type);
            }
            return true;
        }
        try {
            $params = array(
                'src' => $this->sending_number, // Sender's phone number with country code
                'dst' => $number, // Receiver's phone number with country code
                'text' => $message, // Your SMS text message
                'log' => false, // False: message not logged on infrastructure and the dst value will be masked
                //'url' => 'http://example.com/report/', // The URL to which with the status of the message is sent
                //'method' => 'POST' // The method used to call the url
            );
            // Send mes
            $resp = $this->client->send_message($params);
        } catch (\Exception $e) {
            $this->logger->error(sprintf("Unable to send to %s Ex: %s", $number, $e->getMessage()));

            return false;
        }

        if ($chargePolicy) {
            $this->addCharge($chargePolicy, $type);
        }
        return true;
    }

    /**
     * Sends an sms message conforming to a given template.
     * @param string $number is the mobile number to be sent to.
     * @param string $template is the filename of the template to be used.
     * @param array  $data is an array containing the parameters used to render the template.
     * @param Policy $chargePolicy is an optional policy for which an SMS charge object will be committed.
     * @param string $type is the type of sms charge to be made if one is to be made.
     * @return boolean true iff the sms was sent successfully.
     */
    public function sendTemplate($number, $template, $data, $chargePolicy = null, $type = Charge::TYPE_SMS_INVITATION)
    {
        $message = $this->templating->render($template, $data);
        return $this->send($number, $message, $chargePolicy);
    }

    /**
     * Sends an SMS message to a given user with a given template, and commits an SMS charge attributed to them.
     * The SMS charge is not optional for this method.
     * @param Policy $policy is the user's policy which the message regards.
     * @param string $template is the filename of the template that will be used to render the message.
     * @param array $data is the set of parameters that will be used to render the template.
     * @param string $type is the type of sms charge to be made.
     * @return true iff the sms is sent successfuly.
     */
    public function sendUser(Policy $policy, $template, $data, $type = Charge::TYPE_SMS_INVITATION)
    {
        return $this->sendTemplate($policy->getUser()->getMobileNumber(), $template, $data, $policy, $type);
    }

    public function setValidationCodeForUser($user)
    {
        $characters = '0123456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $characters[rand(0, 9)];
        }

        $key = sprintf(self::VALIDATION_KEY, $user->getId(), $code);
        $this->redis->setex($key, self::VALIDATION_TIMEOUT, $code);

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
