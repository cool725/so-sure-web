<?php
namespace AppBundle\Service;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\File\BacsReportAddacsFile;
use AppBundle\Document\File\BacsReportAuddisFile;
use AppBundle\Document\File\BacsReportInputFile;
use AppBundle\Document\File\UploadFile;
use AppBundle\Document\User;
use AppBundle\Repository\UserRepository;
use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use DOMDocument;
use DOMXPath;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class BacsService
{
    const S3_BUCKET = 'admin.so-sure.com';
    const SUN = '176198';

    const ADDACS_REASON_BANK = 0;
    const ADDACS_REASON_USER = 1;
    const ADDACS_REASON_DECEASED = 2;
    const ADDACS_REASON_TRANSFER = 3;

    const AUDDIS_REASON_USER = 1;
    const AUDDIS_REASON_DECEASED = 2;
    const AUDDIS_REASON_TRANSFER = 3;
    const AUDDIS_REASON_NO_ACCOUNT = 5;
    const AUDDIS_REASON_NO_INSTRUCTION = 6;
    const AUDDIS_REASON_NON_ZERO = 7;

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var string */
    protected $fileEncryptionPassword;

    /** @var S3Client */
    protected $s3;

    /** @var string */
    protected $environment;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param S3Client        $s3
     * @param string          $fileEncryptionPassword
     * @param string          $environment
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        S3Client $s3,
        $fileEncryptionPassword,
        $environment
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->s3 = $s3;
        $this->fileEncryptionPassword = $fileEncryptionPassword;
        $this->environment = $environment;
    }

    public function processUpload(UploadedFile $file)
    {
        $tmpFile = $file->move(sys_get_temp_dir());
        $uploadFile = null;
        $metadata = null;
        if (stripos($file->getClientOriginalName(), "ADDACS") !== false) {
            $metadata = $this->addacs($tmpFile);
            $uploadFile = new BacsReportAddacsFile();
        } elseif (stripos($file->getClientOriginalName(), "AUDDIS") !== false) {
                $metadata = $this->auddis($tmpFile);
                $uploadFile = new BacsReportAuddisFile();
        } elseif (stripos($file->getClientOriginalName(), "INPUT") !== false) {
            $metadata = $this->input($tmpFile);
            $uploadFile = new BacsReportInputFile();
        } else {
            $this->logger->error(sprintf('Unknown bacs report file %s', $file->getClientOriginalName()));

            return false;
        }

        if ($uploadFile) {
            $this->uploadS3($tmpFile, $file->getClientOriginalName(), $uploadFile, null, $metadata);
        }

        return true;
    }

    public function uploadS3($tmpFile, $filename, UploadFile $uploadFile, \DateTime $date = null, $metadata = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $encTempFile = sprintf('%s/enc-%s', sys_get_temp_dir(), $filename);
        \Defuse\Crypto\File::encryptFileWithPassword($tmpFile, $encTempFile, $this->fileEncryptionPassword);
        unlink($tmpFile);
        $s3Key = sprintf('%s/bacs-report/%s', $this->environment, $filename);

        $this->s3->putObject(array(
            'Bucket' => self::S3_BUCKET,
            'Key'    => $s3Key,
            'SourceFile' => $encTempFile,
        ));

        $uploadFile->setBucket(self::S3_BUCKET);
        $uploadFile->setKey($s3Key);
        $uploadFile->setDate($date);

        if ($metadata) {
            foreach ($metadata as $key => $value) {
                $uploadFile->addMetadata($key, $value);
            }
        }

        $this->dm->persist($uploadFile);
        $this->dm->flush();

        unlink($encTempFile);

        return $s3Key;
    }

    public function addacs($file)
    {
        $results = [
            'success' => true,
            'instructions' => 0,
            'user' => 0,
            'bank' => 0,
            'deceased' => 0,
            'transfer' => 0,
        ];

        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);

        $xml = file_get_contents($file);
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOBLANKS);
        $xpath = new DOMXPath($dom);

        $this->validateMessageHeader($xpath);

        $elementList = $xpath->query('//BACSDocument/Data/MessagingAdvices/MessagingAdvice');
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $this->validateSun($element);
            $this->validateRecordType($element, "A");

            $results['instructions']++;
            $reason = $element->attributes->getNamedItem('reason-code')->nodeValue;
            $reference = $element->attributes->getNamedItem('reference')->nodeValue;
            $user = $repo->findOneBy(['paymentMethod.bankAccount.reference' => $reference]);
            if (!$user) {
                $results['success'] = false;
                $this->logger->error(sprintf('Unable to locate bacs reference %s', $reference));

                continue;
            }
            /** @var BacsPaymentMethod $bacs */
            $bacs = $user->getPaymentMethod();
            $bacs->getBankAccount()->setMandateStatus(BankAccount::MANDATE_CANCELLED);
            if ($reason == self::ADDACS_REASON_TRANSFER) {
                $results['transer']++;
                // TODO: automate transfer
                $this->logger->error(sprintf('Example xml to determine how to handle bacs transfer %s', $reference));
            } elseif ($reason == self::ADDACS_REASON_USER) {
                $results['user']++;
                // TODO: Email user that bacs was cancelled
                $this->logger->error(sprintf('Contact user regarding bacs cancellation %s', $reference));
            } elseif ($reason == self::ADDACS_REASON_BANK) {
                $results['bank']++;
                // TODO: Email user that bacs was cancelled by bank
                $this->logger->error(sprintf('Contact user regarding bacs cancellation %s', $reference));
            } elseif ($reason == self::ADDACS_REASON_DECEASED) {
                $results['deceased']++;
                // TODO: cancel policy, lock user account, unsub user from emails
                $this->logger->error(sprintf('Deceased user - cancel policy %s', $reference));
            } else {
                $this->logger->error(sprintf('Unknown reason %s (Ref: %s)', $reason, $reference));
            }
        }

        return $results;
    }

    private function validateMessageHeader($xpath)
    {
        $elementList = $xpath->query('//BACSDocument/Data/MessagingAdvices/MessagingHeader');
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $this->validateSun($element);
        }
    }

    private function validateSun(\DOMElement $element, $userNumberAttribute = 'user-number')
    {
        $sun = $element->attributes->getNamedItem($userNumberAttribute)->nodeValue;
        if ($sun != self::SUN) {
            throw new \Exception(sprintf('Invalid SUN %s', $sun));
        }
    }

    private function getRecordType(\DOMElement $element)
    {
        return $element->attributes->getNamedItem('record-type')->nodeValue;
    }

    private function validateRecordType(\DOMElement $element, $expectedRecordType)
    {
        $recordType = $this->getRecordType($element);
        if ($recordType != $expectedRecordType) {
            throw new \Exception(sprintf('Unexpected record type %s', $recordType));
        }
    }

    private function validateFileType(\DOMElement $element)
    {
        $fileType = $element->attributes->getNamedItem('file-type')->nodeValue;
        if ($fileType != 'LIVE') {
            throw new \Exception(sprintf('Invalid File Type %s', $fileType));
        }
    }

    public function auddis($file)
    {
        $results = [
            'records' => 0,
            'accepted-ddi' => 0,
            'rejected-ddi' => 0,
        ];

        $xml = file_get_contents($file);
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOBLANKS);
        $xpath = new DOMXPath($dom);

        $this->validateMessageHeader($xpath);

        $elementList = $xpath->query('//BACSDocument/Data/MessagingAdvices/MessagingAdvice');
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $this->validateSun($element);
            $this->validateFileType($element);
            $recordType = $this->getRecordType($element);
            if ($recordType == "V") {
                // successful record
                \AppBundle\Classes\NoOp::ignore([]);
            } elseif ($recordType == "R") {
                $errorsList = $xpath->query('//BACSDocument/Data/MessagingAdvices/MessagingError');
                foreach ($errorsList as $error) {
                    $results['errors'][] = $error->attributes->getNamedItem('line1')->nodeValue;
                }
            } else {
                throw new \Exception(sprintf('Unknown record type %s', $recordType));
            }
            $results['file-numbers'][] = $element->attributes->getNamedItem('originator-file-number')->nodeValue;
            $results['records']++;
            $results['accepted-ddi'] += $element->attributes->getNamedItem('accepted-ddi')->nodeValue;
            $results['rejected-ddi'] += $element->attributes->getNamedItem('rejected-ddi')->nodeValue;
        }

        return $results;
    }

    public function input($file)
    {
        $results = [];

        $xml = file_get_contents($file);
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOBLANKS);
        $xpath = new DOMXPath($dom);

        $elementList = $xpath->query('//BACSDocument/Data/InputReport/Header/ProcessingDate');
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $results['processing-date'] = $element->attributes->getNamedItem('date')->nodeValue;
        }

        // @codingStandardsIgnoreStart
        $elementList = $xpath->query('//BACSDocument/Data/InputReport/Submission/UserFile/InputUserFile/UserFileInformation');
        // @codingStandardsIgnoreEnd
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $this->validateSun($element, 'userNumber');
            $results['file-numbers'][] = $element->attributes->getNamedItem('userFileNumber')->nodeValue;
        }

        // @codingStandardsIgnoreStart
        $elementList = $xpath->query('//BACSDocument/Data/InputReport/Submission/UserFile/InputUserFile/InputReportSummary/AccountTotals/AccountTotal/CreditEntry/AcceptedRecords');
        // @codingStandardsIgnoreEnd
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $results['credit-accepted-records'] = $element->attributes->getNamedItem('numberOf')->nodeValue;
            $results['credit-accepted-value'] = $element->attributes->getNamedItem('valueOf')->nodeValue;
        }

        // @codingStandardsIgnoreStart
        $elementList = $xpath->query('//BACSDocument/Data/InputReport/Submission/UserFile/InputUserFile/InputReportSummary/AccountTotals/AccountTotal/CreditEntry/RejectedRecords');
        // @codingStandardsIgnoreEnd
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $results['credit-rejected-records'] = $element->attributes->getNamedItem('numberOf')->nodeValue;
            $results['credit-rejected-value'] = $element->attributes->getNamedItem('valueOf')->nodeValue;
        }

        // @codingStandardsIgnoreStart
        $elementList = $xpath->query('//BACSDocument/Data/InputReport/Submission/UserFile/InputUserFile/InputReportSummary/AccountTotals/AccountTotal/DebitEntry/AcceptedRecords');
        // @codingStandardsIgnoreEnd
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $results['debit-accepted-records'] = $element->attributes->getNamedItem('numberOf')->nodeValue;
            $results['debit-accepted-value'] = $element->attributes->getNamedItem('valueOf')->nodeValue;
        }

        // @codingStandardsIgnoreStart
        $elementList = $xpath->query('//BACSDocument/Data/InputReport/Submission/UserFile/InputUserFile/InputReportSummary/AccountTotals/AccountTotal/DebitEntry/RejectedRecords');
        // @codingStandardsIgnoreEnd
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $results['debit-rejected-records'] = $element->attributes->getNamedItem('numberOf')->nodeValue;
            $results['debit-rejected-value'] = $element->attributes->getNamedItem('valueOf')->nodeValue;
        }

        return $results;
    }
}
