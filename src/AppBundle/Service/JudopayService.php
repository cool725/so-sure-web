<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GoCardlessPro\Client;
use \GoCardlessPro\Environment;
use JudoPay;
use AppBundle\Document\Payment;

class JudopayService
{
   /** @var LoggerInterface */
   protected $logger;

   /** @var JudoPay */
   protected $client;

   /** @var string */
   protected $judoId;
   
   protected $dm;

    /**
     * @param LoggerInterface $logger
     * @param string $apiToken
     * @param string $apiSecret
     * @param string $judoId
     */
    public function __construct($doctrine, LoggerInterface $logger, $apiToken, $apiSecret, $judoId)
    {
       $this->dm = $doctrine->getManager();
       $this->logger = $logger;
       $this->judoId = $judoId;
       $this->client = new Judopay(array(
           'apiToken' => $apiToken,
           'apiSecret' => $apiSecret,
           'judoId' => $judoId,
           'endpointUrl' => 'https://partnerapi.judopay-sandbox.com/',
       ));
    }

    /**
     *
     */
    public function webpay($amount, $ipAddress, $userAgent)
    {
        $payment = new Payment();
        $payment->setAmount($amount);
        $this->dm->persist($payment);
        $this->dm->flush();

        // add payment
        $webPayment = $this->client->getModel('WebPayments\Payment');
        
        // populate the required data fields.
        $webPayment->setAttributeValues(
            array(
                'judoId' => $this->judoId,
                'yourConsumerReference' => '12345',
                'yourPaymentReference' => $payment->getId(),
                'amount' => $amount,
                'currency' => 'GBP',
                'clientIpAddress' => $ipAddress,
                'clientUserAgent' => $userAgent,
            )
        );

        $webpaymentDetails = $webPayment->create();
        $payment->setReference($webpaymentDetails["reference"]);
        // TODO: set write concern
        $this->dm->flush();

        return array('post_url' => $webpaymentDetails["postUrl"], 'payment' => $payment);
    }
}