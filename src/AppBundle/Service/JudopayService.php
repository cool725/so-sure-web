<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GoCardlessPro\Client;
use \GoCardlessPro\Environment;
use JudoPay;
use AppBundle\Document\Payment;
use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Document\Policy;

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
     * @param mixed           $doctrine
     * @param LoggerInterface $logger
     * @param string          $apiToken
     * @param string          $apiSecret
     * @param string          $judoId
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
    public function webpay(User $user, Phone $phone, $amount, $ipAddress, $userAgent)
    {
        $payment = new Payment();
        $payment->setAmount($amount);
        $this->dm->persist($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // add payment
        $webPayment = $this->client->getModel('WebPayments\Payment');

        // populate the required data fields.
        $webPayment->setAttributeValues(
            array(
                'judoId' => $this->judoId,
                'yourConsumerReference' => $user->getId(),
                'yourPaymentReference' => $payment->getId(),
                'amount' => $amount,
                'currency' => 'GBP',
                'clientIpAddress' => $ipAddress,
                'clientUserAgent' => $userAgent,
            )
        );

        $webpaymentDetails = $webPayment->create();
        $payment->setReference($webpaymentDetails["reference"]);

        $policy = new Policy();
        $policy->setUser($user);
        $policy->setPhone($phone);
        $policy->addPayment($payment);
        $this->dm->persist($policy);

        $payment->setPolicy($policy);

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        return array('post_url' => $webpaymentDetails["postUrl"], 'payment' => $payment);
    }
    
    /**
     * Record a successful payment
     *
     * @param string $reference
     * @param string $receipt
     * @param string $token
     *
     * @return Policy
     */
    public function paymentSuccess($reference, $receipt, $token)
    {
        $repo = $this->dm->getRepository(Payment::class);
        $payment = $repo->findOneBy(['reference' => $reference]);
        if (!$payment) {
            throw new \Exception('Unable to locate payment');
        }

        // TODO: Encrypt
        $payment->setToken($token);
        $payment->setReceipt($receipt);
        $payment->getPolicy()->create();

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // TODO: Email receipt?

        return $payment->getPolicy();
    }
}
