<?php
namespace AppBundle\Service;

use DrewM\MailChimp\MailChimp;
use Psr\Log\LoggerInterface;

class MailchimpService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var MailChimp */
    protected $mailchimp;

    /** @var string */
    protected $list;

    /**
     * @param LoggerInterface $logger
     * @param string          $apikey
     * @param string          $list
     */
    public function __construct(LoggerInterface $logger, $apikey, $list)
    {
        $this->logger = $logger;
        $this->mailchimp = new MailChimp($apikey);
        $this->list = $list;
    }

    /**
     * @param string $email
     *
     * @return boolean
     */
    public function subscribe($email)
    {
        $url = sprintf("lists/%s/members", $this->list);
        $result = $this->mailchimp->post($url, [
                  'email_address' => $email,
                  'status'        => 'subscribed',
        ]);
        $this->logger->debug(sprintf('Mailchimp Adding %s Resp: %s', $email, print_r($result, true)));

        return $result['status'] === 'subscribed';
    }
}
