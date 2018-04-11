<?php
namespace AppBundle\Service;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\BacsReportAddacsFile;
use AppBundle\Document\File\BacsReportAuddisFile;
use AppBundle\Document\File\BacsReportInputFile;
use AppBundle\Document\File\DirectDebitNotificationFile;
use AppBundle\Document\File\S3File;
use AppBundle\Document\File\UploadFile;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\User;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\UserRepository;
use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use DOMDocument;
use DOMXPath;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class BacsService
{
    use DateTrait;

    const S3_POLICY_BUCKET = 'policy.so-sure.com';
    const S3_ADMIN_BUCKET = 'admin.so-sure.com';
    const SUN = '176198';
    const KEY_BACS_CANCEL = 'bacs:cancel';
    const KEY_BACS_QUEUE = 'bacs:queue';

    const QUEUE_EVENT_CREATED = 'created';

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

    /** @var MailerService */
    protected $mailerService;

    protected $redis;

    /** @var PaymentService */
    protected $paymentService;

    protected $snappyPdf;

    protected $templating;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param S3Client        $s3
     * @param string          $fileEncryptionPassword
     * @param string          $environment
     * @param MailerService   $mailerService
     * @param                 $redis
     * @param PaymentService  $paymentService
     * @param                 $snappyPdf
     * @param                 $templating
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        S3Client $s3,
        $fileEncryptionPassword,
        $environment,
        MailerService $mailerService,
        $redis,
        PaymentService $paymentService,
        $snappyPdf,
        $templating
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->s3 = $s3;
        $this->fileEncryptionPassword = $fileEncryptionPassword;
        $this->environment = $environment;
        $this->mailerService = $mailerService;
        $this->redis = $redis;
        $this->paymentService = $paymentService;
        $this->snappyPdf = $snappyPdf;
        $this->templating = $templating;
    }

    public function processUpload(UploadedFile $file)
    {
        $tmpFile = $file->move(sys_get_temp_dir());
        $uploadFile = null;
        $metadata = null;
        if (mb_stripos($file->getClientOriginalName(), "ADDACS") !== false) {
            $metadata = $this->addacs($tmpFile);
            $uploadFile = new BacsReportAddacsFile();
        } elseif (mb_stripos($file->getClientOriginalName(), "AUDDIS") !== false) {
            $metadata = $this->auddis($tmpFile);
            $uploadFile = new BacsReportAuddisFile();
        } elseif (mb_stripos($file->getClientOriginalName(), "INPUT") !== false) {
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

        $repo = $this->dm->getRepository(UploadFile::class);
        $existingFile = $repo->findOneBy(['bucket' => self::S3_ADMIN_BUCKET, 'key' => $s3Key]);
        if ($existingFile) {
            throw new \Exception(sprintf('File s3://%s/%s already exists', self::S3_ADMIN_BUCKET, $s3Key));
        }

        $this->s3->putObject(array(
            'Bucket' => self::S3_ADMIN_BUCKET,
            'Key' => $s3Key,
            'SourceFile' => $encTempFile,
        ));

        $uploadFile->setBucket(self::S3_ADMIN_BUCKET);
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

    public function downloadS3(S3File $s3File)
    {
        $filename = $s3File->getFilename();
        if (!$filename || mb_strlen($filename) == 0) {
            $key = explode('/', $s3File->getKey());
            $filename = $key[count($key) - 1];
        }
        $encTempFile = sprintf('%s/enc-%s', sys_get_temp_dir(), $filename);
        $tempFile = sprintf('%s/%s', sys_get_temp_dir(), $filename);
        //file_put_contents($tempFile, null);

        $this->s3->getObject(array(
            'Bucket' => self::S3_ADMIN_BUCKET,
            'Key' => $s3File->getKey(),
            'SaveAs' => $encTempFile
        ));
        \Defuse\Crypto\File::decryptFileWithPassword($encTempFile, $tempFile, $this->fileEncryptionPassword);
        unlink($encTempFile);

        return $tempFile;
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
            $this->notifyMandateCancelled($user);
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

    private function notifyMandateCancelled(User $user)
    {
        $baseTemplate = 'AppBundle:Email:bacs/mandateCancelled';
        $claimed = $user->getAvgPolicyClaims() > 0;
        $templateHtml = sprintf('%s.html.twig', $baseTemplate);
        $templateText = sprintf('%s.txt.twig', $baseTemplate);

        $this->mailerService->sendTemplate(
            'Your Direct Debit Cancellation',
            $user->getEmail(),
            $templateHtml,
            ['user' => $user, 'claimed' => $claimed],
            $templateText,
            ['user' => $user, 'claimed' => $claimed],
            null,
            'bcc@so-sure.com'
        );
    }

    public function notifyMandateCancelledByNameChange(User $user)
    {
        $baseTemplate = 'AppBundle:Email:bacs/mandateCancelledNameChange';
        $templateHtml = sprintf('%s.html.twig', $baseTemplate);
        $templateText = sprintf('%s.txt.twig', $baseTemplate);

        $this->mailerService->sendTemplate(
            'Your recent name change',
            $user->getEmail(),
            $templateHtml,
            ['user' => $user],
            $templateText,
            ['user' => $user],
            null,
            'bcc@so-sure.com'
        );
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
            $results['serial-number'] = $element->attributes->getNamedItem('vol-serial-number')->nodeValue;
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

        $elementList = $xpath->query('//BACSDocument/Data/InputReport/Submission/SubmissionInformation');
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $results['serial-number'] = $element->attributes->getNamedItem('volumeSerialNumber')->nodeValue;
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

    public function approveMandates($serialNumber, $actualSerialNumber = null)
    {
        if (!$serialNumber) {
            return false;
        }

        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);
        $users = $repo->findBy(['paymentMethod.bankAccount.mandateSerialNumber' => $serialNumber]);
        foreach ($users as $user) {
            /** @var User $user */
            /** @var BacsPaymentMethod $paymentMethod */
            $paymentMethod = $user->getPaymentMethod();
            if (!$paymentMethod || !$paymentMethod instanceof BacsPaymentMethod) {
                continue;
            }
            $bankAccount = $paymentMethod->getBankAccount();
            // TODO: How can we determine which mandates are successful vs failure
            $bankAccount->setMandateStatus(BankAccount::MANDATE_SUCCESS);
            if ($actualSerialNumber) {
                $bankAccount->setMandateSerialNumber($actualSerialNumber);
            }
        }

        $this->dm->flush();

        return true;
    }

    public function queueCancelBankAccount(BankAccount $bankAccount, $id)
    {
        $this->redis->hset(
            self::KEY_BACS_CANCEL,
            $bankAccount->getReference(),
            json_encode([
                'sortCode' => $bankAccount->getSortCode(),
                'accountNumber' => $bankAccount->getAccountNumber(),
                'accountName' => $bankAccount->getAccountName(),
                'reference' => $bankAccount->getReference(),
                'id' => $id,
            ])
        );
    }

    public function getBacsCancellations()
    {
        $cancellations = [];
        foreach ($this->redis->hgetall(self::KEY_BACS_CANCEL) as $key => $data) {
            $cancellations[] = json_decode($data, true);
        }
        $this->redis->del(self::KEY_BACS_CANCEL);

        return $cancellations;
    }

    public function bacsPayment(Policy $policy, $notes, $amount = null, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        if (!$amount) {
            $amount = $policy->getPremium()->getMonthlyPremiumPrice();
        }
        $user = $policy->getPayerOrUser();

        $payment = new BacsPayment();
        $payment->setAmount($amount);
        $payment->setNotes($notes);
        $payment->setUser($policy->getUser());
        $payment->setStatus(BacsPayment::STATUS_PENDING);
        $payment->setSource(Payment::SOURCE_TOKEN);
        $policy->addPayment($payment);

        if (!$user->hasValidPaymentMethod()) {
            throw new \Exception(sprintf(
                'User %s does not have a valid payment method (Policy %s)',
                $user->getId(),
                $policy->getId()
            ));
        }
        $this->dm->persist($payment);

        return $payment;
    }

    public function getHeader()
    {
        return implode(',', [
            '"Processing Date"',
            '"Action"',
            '"BACS Transaction Code"',
            '"Name"',
            '"Sort Code"',
            '"Account"',
            '"Amount"',
            '"DDI Reference"',
            '"UserId"',
            '"PolicyId"',
            '"PaymentId"',
        ]);
    }

    public function hasMandateOrPaymentDebit($prefix, \DateTime $date = null)
    {
        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);
        $users = $repo->findBy(['paymentMethod.bankAccount.mandateStatus' => BankAccount::MANDATE_PENDING_INIT]);
        if (count($users) > 0) {
            return true;
        }
        if ($this->redis->hlen(self::KEY_BACS_CANCEL) > 0) {
            return true;
        }

        $advanceDate = clone $date;
        $advanceDate = $this->addBusinessDays($advanceDate, 3);

        $scheduledPayments = $this->paymentService->getAllValidScheduledPaymentsForType(
            $prefix,
            BacsPaymentMethod::class,
            $advanceDate
        );
        foreach ($scheduledPayments as $scheduledPayment) {
            /** @var ScheduledPayment $scheduledPayment */
            /** @var BacsPaymentMethod $bacs */
            $bacs = $scheduledPayment->getPolicy()->getUser()->getPaymentMethod();
            if (!$bacs || !$bacs->getBankAccount()) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function hasPaymentCredit()
    {
        /** @var PaymentRepository $repo */
        $repo = $this->dm->getRepository(BacsPayment::class);

        $credits = $repo->findBy(['status' => BacsPayment::STATUS_PENDING, 'amount' < 0]);

        return count($credits) > 0;
    }

    public function exportMandates(\DateTime $date, $serialNumber, $includeHeader = false)
    {
        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);
        $users = $repo->findBy(['paymentMethod.bankAccount.mandateStatus' => BankAccount::MANDATE_PENDING_INIT]);
        $lines = [];
        if ($includeHeader) {
            $lines[] = $this->getHeader();
        }
        foreach ($users as $user) {
            /** @var User $user */
            /** @var BacsPaymentMethod $paymentMethod */
            $paymentMethod = $user->getPaymentMethod();
            $lines[] = implode(',', [
                sprintf('"%s"', $date->format('d/m/y')),
                '"Initial Mandate"',
                '"0N"', // new Auddis
                sprintf('"%s"', $paymentMethod->getBankAccount()->getAccountName()),
                sprintf('"%s"', $paymentMethod->getBankAccount()->getSortCode()),
                sprintf('"%s"', $paymentMethod->getBankAccount()->getAccountNumber()),
                '"0"', // £0 for Addis setup
                sprintf('"%s"', $paymentMethod->getBankAccount()->getReference()),
                sprintf('"%s"', $user->getId()),
                '""',
                '""',
            ]);
            $paymentMethod->getBankAccount()->setMandateStatus(BankAccount::MANDATE_PENDING_APPROVAL);
            $paymentMethod->getBankAccount()->setMandateSerialNumber($serialNumber);

            // do not attempt to take payment until 2 business days after to allow for mandate
            $initialPaymentSubmissionDate = new \DateTime();
            $initialPaymentSubmissionDate = $this->addBusinessDays($initialPaymentSubmissionDate, 2);
            $paymentMethod->getBankAccount()->setInitialPaymentSubmissionDate($initialPaymentSubmissionDate);
        }

        return $lines;
    }

    public function exportMandateCancellations(\DateTime $date, $includeHeader = false)
    {
        $cancellations = $this->getBacsCancellations();
        $lines = [];
        if ($includeHeader) {
            $lines[] = $this->getHeader();
        }
        foreach ($cancellations as $cancellation) {
            $lines[] = implode(',', [
                sprintf('"%s"', $date->format('d/m/y')),
                '"Cancel Mandate"',
                '"0C"', // new Auddis
                sprintf('"%s"', $cancellation['accountName']),
                sprintf('"%s"', $cancellation['sortCode']),
                sprintf('"%s"', $cancellation['accountNumber']),
                '"0"', // £0 for Addis setup
                sprintf('"%s"', $cancellation['reference']),
                sprintf('"%s"', $cancellation['id']),
                '""',
                '""',
            ]);
        }

        return $lines;
    }

    public function exportPaymentsDebits($prefix, \DateTime $date, $serialNumber, &$metadata, $includeHeader = false)
    {
        $lines = [];
        if ($includeHeader) {
            $lines[] = $this->getHeader();
        }

        // get all scheduled payments for bacs that should occur within the next 3 business days in order to allow
        // time for the bacs cycle
        $advanceDate = clone $date;
        $advanceDate = $this->addBusinessDays($advanceDate, 3);

        $scheduledPayments = $this->paymentService->getAllValidScheduledPaymentsForType(
            $prefix,
            BacsPaymentMethod::class,
            $advanceDate
        );
        $metadata['debit-amount'] = 0;
        foreach ($scheduledPayments as $scheduledPayment) {
            /** @var ScheduledPayment $scheduledPayment */
            /** @var BacsPaymentMethod $bacs */
            $bacs = $scheduledPayment->getPolicy()->getUser()->getPaymentMethod();
            if (!$bacs || !$bacs->getBankAccount()) {
                $msg = sprintf(
                    'Skipping scheduled payment %s as unable to determine payment method or missing bank account',
                    $scheduledPayment->getId()
                );
                $this->logger->warning($msg);
                continue;
            }

            $bankAccount = $bacs->getBankAccount();
            if ($bankAccount->getMandateStatus() != BankAccount::MANDATE_SUCCESS) {
                $msg = sprintf(
                    'Skipping scheduled payment %s as mandate is not enabled (%s)',
                    $scheduledPayment->getId(),
                    $bankAccount->getMandateStatus()
                );
                // for first payment, would expected that mandate may not yet be setup
                if ($bankAccount->isFirstPayment()) {
                    $this->logger->info($msg);
                } else {
                    $this->logger->warning($msg);
                }
                continue;
            }
            if (!$bankAccount->allowedSubmission()) {
                $msg = sprintf(
                    'Skipping payment %s as submission is not yet allowed (must be at least %s)',
                    $scheduledPayment->getId(),
                    $bankAccount->getInitialPaymentSubmissionDate()->format('d/m/y')
                );
                $this->logger->error($msg);
                continue;
            }
            if (!$bankAccount->allowedProcessing($scheduledPayment->getScheduled())) {
                $msg = sprintf(
                    'Skipping scheduled payment %s as processing date is not allowed (%s / initial: %s)',
                    $scheduledPayment->getId(),
                    $scheduledPayment->getScheduled()->format('d/m/y'),
                    $bankAccount->isFirstPayment() ? 'yes' : 'no'
                );
                $this->logger->error($msg);
                continue;
            }

            $payment = $this->bacsPayment(
                $scheduledPayment->getPolicy(),
                'Scheduled Payment',
                $scheduledPayment->getAmount()
            );
            $scheduledPayment->setPayment($payment);

            $metadata['debit-amount'] += $scheduledPayment->getAmount();
            $scheduledDate = $this->getCurrentOrNextBusinessDay($scheduledPayment->getScheduled());
            $lines[] = implode(',', [
                sprintf('"%s"', $scheduledDate->format('d/m/y')),
                '"Scheduled Payment"',
                $bankAccount->isFirstPayment() ? '"01"' : '"17"',
                sprintf('"%s"', $bankAccount->getAccountName()),
                sprintf('"%s"', $bankAccount->getSortCode()),
                sprintf('"%s"', $bankAccount->getAccountNumber()),
                sprintf('"%0.2f"', $scheduledPayment->getAmount()),
                sprintf('"%s"', $bankAccount->getReference()),
                sprintf('"%s"', $scheduledPayment->getPolicy()->getUser()->getId()),
                sprintf('"%s"', $scheduledPayment->getPolicy()->getId()),
                sprintf('"SP-%s"', $scheduledPayment->getId()),
            ]);
            $payment->setStatus(BacsPayment::STATUS_GENERATED);
            $payment->setSerialNumber($serialNumber);
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_PENDING);
            if ($bankAccount->isFirstPayment()) {
                $bankAccount->setFirstPayment(false);
            }
        }

        return $lines;
    }

    public function exportPaymentsCredits(\DateTime $date, $serialNumber, &$metadata, $includeHeader = false)
    {
        $lines = [];
        if ($includeHeader) {
            $lines[] = $this->getHeader();
        }
        /** @var PaymentRepository $repo */
        $repo = $this->dm->getRepository(BacsPayment::class);

        $credits = $repo->findBy(['status' => BacsPayment::STATUS_PENDING, 'amount' < 0]);

        $metadata['credit-amount'] = 0;
        foreach ($credits as $payment) {
            /* @var BacsPayment $payment */
            /** @var BacsPaymentMethod $bacs */
            $bacs = $payment->getPolicy()->getUser()->getPaymentMethod();
            if (!$bacs || !$bacs->getBankAccount()) {
                $msg = sprintf(
                    'Skipping payment %s as unable to determine payment method or missing bank account',
                    $payment->getId()
                );
                $this->logger->warning($msg);
                continue;
            }

            $bankAccount = $bacs->getBankAccount();

            $metadata['credit-amount'] += $payment->getAmount();
            $lines[] = implode(',', [
                sprintf('"%s"', $date->format('d/m/y')),
                '"Credit"',
                '"99"',
                sprintf('"%s"', $bankAccount->getAccountName()),
                sprintf('"%s"', $bankAccount->getSortCode()),
                sprintf('"%s"', $bankAccount->getAccountNumber()),
                sprintf('"%0.2f"', 0 - $payment->getAmount()), // amount will be -, but bacs credit needs +
                sprintf('"%s"', $bankAccount->getReference()),
                sprintf('"%s"', $payment->getPolicy()->getUser()->getId()),
                sprintf('"%s"', $payment->getPolicy()->getId()),
                sprintf('"P-%s"', $payment->getId()),
            ]);
            $payment->setStatus(BacsPayment::STATUS_GENERATED);
            $payment->setSerialNumber($serialNumber);
        }

        return $lines;
    }

    public function queueBacsCreated(Policy $policy, $retryAttempts = 0)
    {
        $data = [
            'action' => self::QUEUE_EVENT_CREATED,
            'policyId' => $policy->getId(),
            'retryAttempts' => $retryAttempts,
        ];
        $this->redis->rpush(self::KEY_BACS_QUEUE, serialize($data));
    }

    public function clearQueue()
    {
        $this->redis->del(self::KEY_BACS_QUEUE);
    }

    public function getQueueData($max)
    {
        return $this->redis->lrange(self::KEY_BACS_QUEUE, 0, $max);
    }

    public function process($max)
    {
        $requeued = 0;
        $processed = 0;
        while ($processed + $requeued < $max) {
            $user = null;
            $data = null;
            try {
                $queueItem = $this->redis->lpop(self::KEY_BACS_QUEUE);
                if (!$queueItem) {
                    return $processed;
                }
                $data = unserialize($queueItem);

                $action = null;
                if (isset($data['action'])) {
                    $action = $data['action'];
                }

                if ($action == self::QUEUE_EVENT_CREATED) {
                    if (!isset($data['policyId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->generateBacsPdf($this->getPolicy($data['policyId']));
                } else {
                    throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                }
                $processed++;
            } catch (\InvalidArgumentException $e) {
                $this->logger->error(sprintf(
                    'Error processing bacs queue message %s. Ex: %s',
                    json_encode($data),
                    $e->getMessage()
                ));
            } catch (\Exception $e) {
                if (isset($data['retryAttempts']) && $data['retryAttempts'] < 2) {
                    $data['retryAttempts'] += 1;
                    $this->redis->rpush(self::KEY_BACS_QUEUE, serialize($data));
                } else {
                    $this->logger->error(sprintf(
                        'Error (retry exceeded) in bacs processing %s. Ex: %s',
                        json_encode($data),
                        $e->getMessage()
                    ));
                }
            }
        }

        return $processed;
    }

    public function getPolicy($id)
    {
        if (!$id) {
            throw new \InvalidArgumentException('Missing policyId');
        }
        $repo = $this->dm->getRepository(Policy::class);
        $policy = $repo->find($id);
        if (!$policy) {
            throw new \InvalidArgumentException(sprintf('Unable to find policyId: %s', $id));
        }

        return $policy;
    }

    public function generateBacsPdf(Policy $policy)
    {
        $now = new \DateTime();
        $bankAccount = $policy->getUser()->getPaymentMethod()->getBankAccount();
        $filename = sprintf(
            "%s-%s-%s.pdf",
            $policy->getId(),
            $bankAccount->getReference(),
            $now->format('U')
        );
        $tmpFile = sprintf(
            "%s/%s",
            sys_get_temp_dir(),
            $filename
        );
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        $this->snappyPdf->setOption('orientation', 'Portrait');
        $this->snappyPdf->setOption('lowquality', false);
        $this->snappyPdf->setOption('page-size', 'A4');
        $this->snappyPdf->setOption('margin-top', '1');
        $this->snappyPdf->setOption('margin-bottom', '1');
        $this->snappyPdf->generateFromHtml(
            $this->templating->render('AppBundle:Email:bacs/notification.html.twig', [
                'user' => $policy->getUser(),
                'policy' => $policy
            ]),
            $tmpFile
        );

        $date = new \DateTime();
        $ddNotificationFile = new DirectDebitNotificationFile();
        $ddNotificationFile->setBucket(self::S3_POLICY_BUCKET);
        $ddNotificationFile->setKeyFormat(
            $this->environment . '/dd-notification/' . $date->format('Y') . '/%s'
        );
        $ddNotificationFile->setFileName($filename);
        $policy->addPolicyFile($ddNotificationFile);
        $this->dm->flush();

        if ($this->environment != "test") {
            $result = $this->s3->putObject(array(
                'Bucket' => self::S3_POLICY_BUCKET,
                'Key' => $ddNotificationFile->getKey(),
                'SourceFile' => $tmpFile,
            ));
        }

        return $tmpFile;
    }
}
