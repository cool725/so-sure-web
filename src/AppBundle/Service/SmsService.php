<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\Common\Persistence\ManagerRegistry;
use Plivo\RestAPI;

class SmsService
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var RestAPI */
    protected $client;

    /** @var string */
    protected $sending_number;

    protected $router;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param                 $router
     * @param string          $auth_id
     * @param string          $auth_token
     * @param string          $sending_number
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $router,
        $auth_id,
        $auth_token,
        $sending_number
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->router = $router;
        $this->client = new RestAPI($auth_id, $auth_token);
        $this->sending_number = $sending_number;
    }

    /**
     * @param string $number
     * @param string $message
     *
     * @return boolean
     */
    public function send($number, $message)
    {
        if ($number == "+447775740466") {
            return;
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
}
