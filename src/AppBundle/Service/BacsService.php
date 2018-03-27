<?php
namespace AppBundle\Service;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\User;
use AppBundle\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use DOMDocument;
use DOMXPath;

class BacsService
{
    const ADDACS_REASON_BANK = 0;
    const ADDACS_REASON_USER = 1;
    const ADDACS_REASON_DECEASED = 2;
    const ADDACS_REASON_TRANSFER = 3;

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
    }

    public function addacs($file)
    {
        $result = true;

        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);

        $xml = file_get_contents($file);
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOBLANKS);
        $xpath = new DOMXPath($dom);

        $elementList = $xpath->query('//BACSDocument/Data/MessagingAdvices/MessagingAdvice');
        foreach ($elementList as $element) {
            /** @var \DOMElement $element */
            $reason = $element->attributes->getNamedItem('reason-code')->nodeValue;
            $reference = $element->attributes->getNamedItem('reference')->nodeValue;
            $user = $repo->findOneBy(['paymentMethod.bankAccount.reference' => $reference]);
            if (!$user) {
                $result = false;
                $this->logger->error(sprintf('Unable to locate bacs reference %s', $reference));

                continue;
            }
            /** @var BacsPaymentMethod $bacs */
            $bacs = $user->getPaymentMethod();
            $bacs->getBankAccount()->setMandateStatus(BankAccount::MANDATE_CANCELLED);
            if ($reason == self::ADDACS_REASON_TRANSFER) {
                // TODO: automate transfer
                $this->logger->error(sprintf('Example xml to determine how to handle bacs transfer %s', $reference));
            } elseif ($reason == self::ADDACS_REASON_USER) {
                // TODO: Email user that bacs was cancelled
                $this->logger->error(sprintf('Contact user regarding bacs cancellation %s', $reference));
            } elseif ($reason == self::ADDACS_REASON_BANK) {
                // TODO: Email user that bacs was cancelled by bank
                $this->logger->error(sprintf('Contact user regarding bacs cancellation %s', $reference));
            } elseif ($reason == self::ADDACS_REASON_DECEASED) {
                // TODO: cancel policy, lock user account, unsub user from emails
                $this->logger->error(sprintf('Deceased user - cancel policy %s', $reference));
            }
        }

        return $result;
    }
}
