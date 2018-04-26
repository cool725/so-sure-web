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
    
    /** @var string */
    protected $environment;

    /**
     * @param LoggerInterface $logger
     * @param string          $apikey
     * @param string          $list
     * @param string          $environment
     */
    public function __construct(LoggerInterface $logger, $apikey, $list, $environment)
    {
        $this->logger = $logger;
        $this->mailchimp = new MailChimp($apikey);
        $this->list = $list;
        $this->environment = $environment;
    }

    /**
     * @param string $email
     *
     * @return boolean|null
     */
    public function subscribe($email)
    {
        if ($this->environment != 'prod') {
            return null;
        }

        // don't send @so-sure.com emails to mailchimp
        if (mb_stripos($email, "@so-sure.com") !== false) {
            return null;
        }

        $url = sprintf("lists/%s/members", $this->list);
        $result = $this->mailchimp->post($url, [
                  'email_address' => $email,
                  'status'        => 'subscribed',
        ]);
        $this->logger->debug(sprintf('Mailchimp Adding %s Resp: %s', $email, json_encode($result)));

        return $result['status'] === 'subscribed';
    }
}
