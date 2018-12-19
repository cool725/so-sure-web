<?php
namespace AppBundle\Service;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\AccessPayFile;
use AppBundle\Document\File\BacsReportAddacsFile;
use AppBundle\Document\File\BacsReportAruddFile;
use AppBundle\Document\File\BacsReportAuddisFile;
use AppBundle\Document\File\BacsReportDdicFile;
use AppBundle\Document\File\BacsReportInputFile;
use AppBundle\Document\File\DirectDebitNotificationFile;
use AppBundle\Document\File\S3File;
use AppBundle\Document\File\UploadFile;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Payment\BacsIndemnityPayment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\User;
use AppBundle\Classes\SoSure;
use AppBundle\Event\PolicyEvent;
use AppBundle\Repository\BacsPaymentRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\UserRepository;
use Aws\S3\S3Client;
use Knp\Bundle\SnappyBundle\Snappy\LoggableGenerator;
use Knp\Snappy\AbstractGenerator;
use Knp\Snappy\GeneratorInterface;
use phpseclib\Crypt\RSA;
use phpseclib\Net\SFTP;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use DOMDocument;
use DOMXPath;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Templating\EngineInterface;

class BacsService
{
    use DateTrait;

    const S3_POLICY_BUCKET = 'policy.so-sure.com';
    const S3_ADMIN_BUCKET = 'admin.so-sure.com';
    const SUN = '176198';
    const KEY_BACS_CANCEL = 'bacs:cancel';
    const KEY_BACS_QUEUE = 'bacs:queue';

    const QUEUE_EVENT_CREATED = 'created';

    const BACS_COMMAND_CREATE_MANDATE = '0N';
    const BACS_COMMAND_CANCEL_MANDATE = '0C';
    const BACS_COMMAND_FIRST_DIRECT_DEBIT = '01';
    const BACS_COMMAND_DIRECT_DEBIT = '17';
    const BACS_COMMAND_DIRECT_CREDIT = '99';

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
    const AUDDIS_REASON_CLOSED = 'B';
    const AUDDIS_REASON_TRANSFER_BRANCH = 'C';
    const AUDDIS_REASON_INVALID_ACCOUNT_TYPE = 'F';
    const AUDDIS_REASON_DD_NOT_ALLOWED = 'G';
    const AUDDIS_REASON_EXPIRED = 'H';
    const AUDDIS_REASON_DUPLICATE_REFERENCE = 'I';
    const AUDDIS_REASON_INCORRECT_DETAILS = 'L';
    const AUDDIS_REASON_CODE_INCOMPATIBLE = 'M';
    const AUDDIS_REASON_NOT_ALLOWED = 'N';
    const AUDDIS_REASON_INVALID_REFERENCE = 'O';
    const AUDDIS_REASON_MISSING_PAYER_NAME = 'P';
    const AUDDIS_REASON_MISSING_SERVICE_NAME = 'Q';

    const ARUDD_RETURN_CODE_PAYER = '0128';

    const VALIDATE_OK = 'ok';
    const VALIDATE_SKIP = 'skip';
    const VALIDATE_CANCEL = 'cancel';
    const VALIDATE_RESCHEDULE = 'reschedule';

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

    /** @var Client */
    protected $redis;

    /** @var PaymentService */
    protected $paymentService;

    /** @var LoggableGenerator */
    protected $snappyPdf;

    /** @var EngineInterface */
    protected $templating;

    /** @var SequenceService */
    protected $sequenceService;

    /** @var string */
    protected $accessPayServer;

    /** @var string */
    protected $accessPayUsername;

    /** @var string */
    protected $accessPayPassword;

    /** @var string */
    protected $accessPayKeyFile;

    /** @var MailerService */
    protected $mailer;

    /** @var EventDispatcherInterface */
    protected $dispatcher;

    public function setDispatcher($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /** @var SftpService */
    protected $sosureSftpService;

    /** @var SftpService */
    protected $accesspaySftpService;

    /**
     * @param DocumentManager          $dm
     * @param LoggerInterface          $logger
     * @param S3Client                 $s3
     * @param string                   $fileEncryptionPassword
     * @param string                   $environment
     * @param MailerService            $mailerService
     * @param Client                   $redis
     * @param PaymentService           $paymentService
     * @param LoggableGenerator        $snappyPdf
     * @param EngineInterface          $templating
     * @param SequenceService          $sequenceService
     * @param array                    $accessPay
     * @param MailerService            $mailer
     * @param EventDispatcherInterface $dispatcher
     * @param SftpService              $sosureSftpService
     * @param SftpService              $accesspaySftpService
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        S3Client $s3,
        $fileEncryptionPassword,
        $environment,
        MailerService $mailerService,
        Client $redis,
        PaymentService $paymentService,
        LoggableGenerator $snappyPdf,
        EngineInterface $templating,
        SequenceService $sequenceService,
        array $accessPay,
        MailerService $mailer,
        EventDispatcherInterface $dispatcher,
        SftpService $sosureSftpService,
        SftpService $accesspaySftpService
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
        $this->sequenceService = $sequenceService;
        $this->accessPayServer = $accessPay[0];
        $this->accessPayUsername = $accessPay[1];
        $this->accessPayPassword = $accessPay[2];
        $this->accessPayKeyFile = $accessPay[3];
        $this->mailer = $mailer;
        $this->dispatcher = $dispatcher;
        $this->sosureSftpService = $sosureSftpService;
        $this->accesspaySftpService = $accesspaySftpService;
    }

    /**
     * @param mixed   $data
     * @param string  $filename
     * @param boolean $debit
     * @return mixed
     * @throws \Exception
     */
    public function uploadSftp($data, $filename, $debit = true)
    {
        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), $filename);
        file_put_contents($tmpFile, $data);

        $sftp = new SFTP($this->accessPayServer);
        $key = new RSA();
        $key->loadKey(file_get_contents($this->accessPayKeyFile));
        if (!$sftp->login($this->accessPayUsername, $key) &&
            !$sftp->login($this->accessPayUsername, $this->accessPayPassword)) {
            throw new \Exception('Login Failed');
        }

        if ($debit) {
            $sftp->chdir('Inbound/DD_Collections');
        } else {
            $sftp->chdir('Inbound/DC_Refunds');
        }
        $sftp->put($filename, $tmpFile, SFTP::SOURCE_LOCAL_FILE);
        $files = $sftp->nlist('.', false);

        return $files;
    }

    private function getAccessPayFileDate($serialNumber)
    {
        $repo = $this->dm->getRepository(AccessPayFile::class);
        /** @var AccessPayFile $file */
        $file = $repo->findOneBy(['serialNumber' => $serialNumber]);
        if ($file && $file->getDate()) {
            return clone $file->getDate();
        }

        return null;
    }

    public function sftp()
    {
        $results = [];
        $files = $this->sosureSftpService->listSftp();
        foreach ($files as $file) {
            $error = false;
            $unzippedFile = null;
            try {
                $zip = $this->sosureSftpService->downloadFile($file);
                $unzippedFiles = $this->unzipFile($zip);
                // print_r($unzippedFiles);
                foreach ($unzippedFiles as $unzippedFile) {
                    $results[$file][$unzippedFile] = $this->processFile($unzippedFile);
                }
            } catch (\Exception $e) {
                $error = true;
                $this->logger->error(
                    sprintf('Failed processing file %s in %s', $unzippedFile, $file),
                    ['exception' => $e]
                );
            }

            $this->sosureSftpService->moveSftp($file, !$error);
        }
        $date = new \DateTime(SoSure::TIMEZONE);
        $hour = $date->format("H");
        if ($hour >= 8 && $hour <= 13) {
            $this->autoApprovePaymentsAndMandates($date);
        }
        return $results;
    }

    /**
     * Automatically approves all pending mandates and payments up to the current date and time.
     */
    public function autoApprovePaymentsAndMandates($date)
    {
        /** @var UserRepository $userRepository */
        $userRepository = $this->dm->getRepository(User::class);
        $this->approvePayments($date);
        $users = $userRepository->findPendingMandates()->getQuery()->execute();
        foreach ($users as $user) {
            $serialNumber = $user->getPaymentMethod()->getBankAccount()->getMandateSerialNumber();
            $this->approveMandates($serialNumber);
        }
    }

    public function unzipFile($file, $extension = '.xml')
    {
        $files = [];

        $zip = new \ZipArchive();
        if ($zip->open($file) === true) {
            if (!$zip->extractTo(sys_get_temp_dir())) {
                throw new \Exception("Extraction failed");
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (mb_stripos($zip->getNameIndex($i), $extension) !== false) {
                    $files[] = sprintf('%s/%s', sys_get_temp_dir(), $zip->getNameIndex($i));
                }
            }

            $zip->close();
        }

        return $files;
    }

    public function processUpload(UploadedFile $file)
    {
        $tmpFile = $file->move(sys_get_temp_dir());

        return $this->processFile($tmpFile, $file->getClientOriginalName());
    }

    public function processFile($file, $originalName = null)
    {
        if (!$originalName) {
            $originalName = basename($file);
        }

        $uploadFile = null;
        $metadata = null;
        $date = null;

        if ($this->isS3FilePresent($originalName)) {
            return false;
        }

        if (mb_stripos($originalName, "ADDACS") !== false) {
            $metadata = $this->addacs($file);
            $uploadFile = new BacsReportAddacsFile();
        } elseif (mb_stripos($originalName, "AUDDIS") !== false) {
            $metadata = $this->auddis($file);
            $uploadFile = new BacsReportAuddisFile();
            if (isset($metadata['serial-number'])) {
                $date = $this->getAccessPayFileDate(AccessPayFile::formatSerialNumber($metadata['serial-number']));
            }
        } elseif (mb_stripos($originalName, "INPUT") !== false) {
            $metadata = $this->input($file);
            $uploadFile = new BacsReportInputFile();
            if (isset($metadata['serial-number'])) {
                $date = $this->getAccessPayFileDate(AccessPayFile::formatSerialNumber($metadata['serial-number']));
            }
        } elseif (mb_stripos($originalName, "ARUDD") !== false) {
            $metadata = $this->arudd($file);
            $uploadFile = new BacsReportAruddFile();
        } elseif (mb_stripos($originalName, "DDIC") !== false) {
            $metadata = $this->ddic($file);
            $uploadFile = new BacsReportDdicFile();
        } else {
            $this->logger->error(sprintf('Unknown bacs report file %s', $originalName));

            return false;
        }

        if ($uploadFile) {
            $this->uploadS3($file, $originalName, $uploadFile, $date, $metadata);
        }

        return true;
    }

    public function checkSubmissionFile($fileDataArray)
    {
        $columnCount = count(str_getcsv($this->getHeader(), ",", '"'));

        foreach ($fileDataArray as $line) {
            if (!$line || mb_strlen($line) == 0) {
                continue;
            }
            $lineData = str_getcsv($line, ",", '"');
            if (count($lineData) != $columnCount) {
                return false;
            } else {
                return true;
            }
        }
    }

    public function processSubmissionUpload(UploadedFile $file, $debit = true)
    {
        $tmpFile = $file->move(sys_get_temp_dir());

        $now = \DateTime::createFromFormat('U', time());
        $sftpFilename = sprintf('%s-%s.csv', $now->format('Ymd'), $now->format('U'));

        $fileData = file_get_contents($tmpFile);
        $fileDataArray = explode(PHP_EOL, $fileData);

        if (!$this->checkSubmissionFile($fileDataArray)) {
            throw new \Exception('Invalid submission file, number of parameter is invalid');
        }

        $this->uploadSftp($fileData, $sftpFilename, $debit);

        $metadata = [
            'lines' => 0,
            'ddi' => 0,
            'ddi-cancellations' => 0,
            'debit-amount' => 0,
            'debits' => 0,
            'credit-amount' => 0,
            'credits' => 0,
        ];
        foreach ($fileDataArray as $line) {
            if (!$line || mb_strlen($line) == 0) {
                continue;
            }
            $lineData = str_getcsv($line, ",", '"');
            if (in_array($lineData[2], [self::BACS_COMMAND_FIRST_DIRECT_DEBIT, self::BACS_COMMAND_DIRECT_DEBIT])) {
                $metadata['debits']++;
                $metadata['debit-amount'] += $lineData[6];
            } elseif ($lineData[2] == self::BACS_COMMAND_CANCEL_MANDATE) {
                $metadata['ddi-cancellations']++;
            } elseif ($lineData[2] == self::BACS_COMMAND_CREATE_MANDATE) {
                $metadata['ddi']++;
            } elseif ($lineData[2] == self::BACS_COMMAND_DIRECT_CREDIT) {
                $metadata['credits']++;
                $metadata['credit-amount'] += $lineData[6];
            }
            $metadata['lines']++;
        }

        $serialNumber = $this->sequenceService->getSequenceId(SequenceService::SEQUENCE_BACS_SERIAL_NUMBER);
        $serialNumber = AccessPayFile::formatSerialNumber($serialNumber);
        $metadata['serial-number'] = $serialNumber;

        $uploadFile = new AccessPayFile();
        $uploadFile->setSerialNumber($serialNumber);

        $s3Key = $this->uploadS3($tmpFile, $file->getClientOriginalName(), $uploadFile, null, $metadata, 'bacs');

        return $s3Key;
    }

    public function getS3Key($filename, $folder = 'bacs-report')
    {
        $s3Key = sprintf('%s/%s/%s', $this->environment, $folder, $filename);

        return $s3Key;
    }

    public function isS3FilePresent($filename, $folder = 'bacs-report')
    {
        $s3Key = $this->getS3Key($filename, $folder);

        $repo = $this->dm->getRepository(UploadFile::class);
        $existingFile = $repo->findOneBy(['bucket' => self::S3_ADMIN_BUCKET, 'key' => $s3Key]);

        return $existingFile != null;
    }

    public function uploadS3(
        $tmpFile,
        $filename,
        UploadFile $uploadFile,
        \DateTime $date = null,
        $metadata = null,
        $folder = 'bacs-report'
    ) {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $encTempFile = sprintf('%s/enc-%s', sys_get_temp_dir(), $filename);
        \Defuse\Crypto\File::encryptFileWithPassword($tmpFile, $encTempFile, $this->fileEncryptionPassword);
        unlink($tmpFile);

        $s3Key = $this->getS3Key($filename, $folder);

        if ($this->isS3FilePresent($filename, $folder)) {
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
            $this->validateSun($element, 'user-number');
            $this->validateRecordType($element, "A");

            $results['instructions']++;
            $reason = $this->getReason($element);
            $reference = $this->getReference($element);
            /** @var User $user */
            $user = $repo->findOneBy(['paymentMethod.bankAccount.reference' => $reference]);
            if (!$user) {
                $results['success'] = false;
                $this->logger->error(sprintf('Unable to locate bacs reference %s', $reference));

                continue;
            }
            /** @var BacsPaymentMethod $bacs */
            $bacs = $user->getPaymentMethod();

            $bacs->getBankAccount()->setMandateStatus(BankAccount::MANDATE_CANCELLED);
            $this->dm->flush();

            // Service users must not send a 0C transaction to the old bank on receipt of an ADDACS reason code 3
            // advice containing both old and new account details.
            if ($reason != self::ADDACS_REASON_TRANSFER) {
                $this->queueCancelBankAccount($bacs->getBankAccount(), $user->getId());
            }

            $this->notifyMandateCancelled($user, $reason);

            if ($reason == self::ADDACS_REASON_TRANSFER) {
                $results['transer']++;
                $bacs->getBankAccount()->setAccountNumber($this->getNodeValue($element, 'payer-new-account-number'));
                $bacs->getBankAccount()->setSortCode($this->getNodeValue($element, 'payer-new-sort-code'));
                $bacs->getBankAccount()->setMandateStatus(BankAccount::MANDATE_PENDING_INIT);
            } elseif ($reason == self::ADDACS_REASON_USER) {
                $results['user']++;
                $this->logger->info(sprintf('Contact user regarding bacs cancellation %s', $reference));
            } elseif ($reason == self::ADDACS_REASON_BANK) {
                $results['bank']++;
                $this->logger->info(sprintf('Contact user regarding bacs cancellation %s', $reference));
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

    public function arudd($file)
    {
        $results = [
            'records' => 0,
            'success' => true,
            'failed-payments' => 0,
            'details' => [],
        ];

        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);

        /** @var BacsPaymentRepository $paymentRepo */
        $paymentRepo = $this->dm->getRepository(BacsPayment::class);

        $xml = file_get_contents($file);
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOBLANKS);
        $xpath = new DOMXPath($dom);

        $this->validateServiceLicenseInformation($xpath);

        $submittedPayments = $paymentRepo->findBy(['status' => BacsPayment::STATUS_SUBMITTED]);

        /** @var \DateTime $currentProcessingDate */
        $currentProcessingDate = null;
        $elementList = $xpath->query(
            '//BACSDocument/Data/ARUDD/Header'
        );
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $currentProcessingDate = $this->getCurrentProcessingDate($element);
        }

        $elementList = $xpath->query(
            '//BACSDocument/Data/ARUDD/Advice/OriginatingAccountRecords/OriginatingAccountRecord/ReturnedDebitItem'
        );
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $results['records']++;
            $returnCode = $this->getReturnCode($element);
            $reference = $this->getReference($element, 'ref');
            $returnDescription = $this->getNodeValue($element, 'returnDescription');
            $originalProcessingDate = $this->getOriginalProcessingDate($element);
            /** @var User $user */
            $user = $repo->findOneBy(['paymentMethod.bankAccount.reference' => $reference]);
            if (!$user) {
                $results['success'] = false;
                $this->logger->error(sprintf('Unable to locate bacs reference %s', $reference));

                continue;
            }

            $activePendingMandate = false;
            $bacs = $user->getBacsPaymentMethod();
            if ($bacs) {
                if ($bankAccount = $bacs->getBankAccount()) {
                    if ($bankAccount->isMandateInProgress() || $bankAccount->isMandateSuccess()) {
                        $activePendingMandate = true;
                    }
                }
            }
            if ($activePendingMandate && mb_stripos($returnDescription, "INSTRUCTION CANCELLED") !== false) {
                $this->logger->warning(sprintf(
                    'User %s has an active or pending mandate, but an arudd indicating cancelled',
                    $user->getId()
                ));
            }

            $foundPayments = 0;
            foreach ($submittedPayments as $submittedPayment) {
                /** @var BacsPayment $submittedPayment */
                $policy = $submittedPayment->getPolicy();
                if ($policy->getUser()->getId() == $user->getId()) {
                    $foundPayments++;

                    $debitPayment = new BacsPayment();
                    $debitPayment->setAmount(0 - $submittedPayment->getAmount());
                    $debitPayment->setStatus(BacsPayment::STATUS_SUCCESS);
                    $debitPayment->setSuccess(true);
                    $debitPayment->setSerialNumber($submittedPayment->getSerialNumber());
                    $debitPayment->setDate($this->getNextBusinessDay($currentProcessingDate));
                    $debitPayment->setSource(Payment::SOURCE_SYSTEM);
                    $debitPayment->setNotes(sprintf(
                        'Arudd payment failure: %s (%s)',
                        $returnDescription,
                        $returnCode
                    ));
                    $policy->addPayment($debitPayment);

                    // refund requires commission to be set, but probably isn't at this point in time
                    if (!$submittedPayment->getTotalCommission()) {
                        $submittedPayment->setCommission();
                    }
                    $debitPayment->setRefundTotalCommission($submittedPayment->getTotalCommission());
                    $debitPayment->calculateSplit();

                    $submittedPayment->approve($currentProcessingDate, true);

                    // Set policy as unpaid if there's a payment failure
                    $policy->setPolicyStatusUnpaidIfActive();

                    $this->dm->flush(null, array('w' => 'majority', 'j' => true));
                    $this->triggerPolicyEvent($policy, PolicyEvent::EVENT_UNPAID);

                    $this->failedPaymentEmail($policy);

                    $results['failed-payments']++;
                    $days = $submittedPayment->getDate()->diff($originalProcessingDate);
                    $results['details'][$reference] = [$submittedPayment->getId() => $days->days];
                    if ($days->days > 5) {
                        $this->logger->warning(sprintf(
                            'Failed Payment %s for user %s was %d days off from processing date',
                            $submittedPayment->getId(),
                            $user->getId(),
                            $days->days
                        ));
                    }
                }
            }

            if ($foundPayments == 0) {
                $this->logger->error(sprintf(
                    'Failed to find any pending(submitted) payments for user %s',
                    $user->getId()
                ));
            } elseif ($foundPayments > 1) {
                $this->logger->error(sprintf('Failed %d payments for user %s', $foundPayments, $user->getId()));
            }
        }
        return $results;
    }

    private function triggerPolicyEvent($policy, $event)
    {
        if (!$policy) {
            return;
        }

        // Primarily used to allow tests to avoid triggering policy events
        if ($this->dispatcher) {
            $this->logger->debug(sprintf('Event %s', $event));
            $this->dispatcher->dispatch($event, new PolicyEvent($policy));
        } else {
            $this->logger->warning('Dispatcher is disabled for Bacs Service');
        }
    }

    public function approvePayments(\DateTime $date)
    {
        /** @var BacsPaymentRepository $repo */
        $repo = $this->dm->getRepository(BacsPayment::class);
        $payments = $repo->findSubmittedPayments($this->endOfDay($date));
        foreach ($payments as $payment) {
            /** @var BacsPayment $payment */
            try {
                $payment->approve();
            } catch (\Exception $e) {
                $this->logger->error(
                    sprintf('Skipping payment %s approval', $payment->getId()),
                    ['exception' => $e]
                );
            }
        }
        $this->dm->flush();
    }

    public function ddic($file)
    {
        $results = [
            'records' => 0,
            'indemnity-amount' => 0,
            'details' => [],
        ];

        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);

        /** @var BacsPaymentRepository $paymentRepo */
        $paymentRepo = $this->dm->getRepository(BacsPayment::class);

        $xml = file_get_contents($file);
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOBLANKS);
        $xpath = new DOMXPath($dom);

        $this->validateServiceUserNumber($xpath);

        $submittedPayments = $paymentRepo->findBy(['status' => BacsPayment::STATUS_SUBMITTED]);

        $elementList = $xpath->query(
            '//VocaDocument/Data/Document/NewAdvices/DDICAdvice'
        );
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $results['records']++;
            $reasonCode = $this->getChildNodeValue($element, 'ReasonCode');
            $reasonCodeMeaning = $this->getChildNodeValue($element, 'ReasonCodeMeaning');
            $reference = $this->getChildNodeValue($element, 'SUReference');
            $ddicReference = $this->getChildNodeValue($element, 'PayingBankReference');
            $amount = $this->getChildNodeValue($element, 'TotalAmount');
            $results['indemnity-amount'] += $amount;
            $results['details'][] = [$reference => [$reasonCode => $reasonCodeMeaning]];
            /** @var User $user */
            $user = $repo->findOneBy(['paymentMethod.bankAccount.reference' => $reference]);
            if (!$user) {
                $this->logger->error(sprintf('Unable to locate bacs reference %s', $reference));

                continue;
            }
            /** @var BacsPaymentMethod $bacsPaymentMethod */
            $bacsPaymentMethod = $user->getPaymentMethod();
            $bacsPaymentMethod->getBankAccount()->setMandateStatus(BankAccount::MANDATE_CANCELLED);
            $this->logger->warning(sprintf(
                'Cancelled bacs mandate for User (%s) due to DDIC. Review as cancellation may not be needed.',
                $user->getId()
            ));
            /** @var PhonePolicy $policy */
            $policy = $user->getLatestPolicy();
            if ($policy) {
                $indemnityPayment = new BacsIndemnityPayment();
                $indemnityPayment->setAmount(0 - $amount);
                $indemnityPayment->setStatus(BacsIndemnityPayment::STATUS_RAISED);
                $indemnityPayment->setSource(BacsIndemnityPayment::SOURCE_SYSTEM);
                $indemnityPayment->setNotes('Direct Debit Indemnity Claim (Chargeback)');
                $indemnityPayment->setReference($ddicReference);
                $policy->addPayment($indemnityPayment);
                $this->dm->persist($indemnityPayment);
            }
        }

        $this->dm->flush();

        return $results;
    }

    /**
     * TODO: Combine with JudopayService::failedPaymentEmail (move to policy service?)
     * @param Policy $policy
     */
    private function failedPaymentEmail(Policy $policy)
    {
        $subject = sprintf('Payment failure for your so-sure policy %s', $policy->getPolicyNumber());
        $baseTemplate = sprintf('AppBundle:Email:bacs/failedPayment');

        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $this->mailer->sendTemplateToUser(
            $subject,
            $policy->getUser(),
            $htmlTemplate,
            ['policy' => $policy],
            $textTemplate,
            ['policy' => $policy]
        );
    }

    private function notifyMandateCancelled(User $user, $reason)
    {
        // If a user doesn't have an active or unpaid policy, there is no need to notify of a mandate cancellation
        // copy would be confusing and there's no value to sending
        if (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy()) {
            return;
        }

        $baseTemplate = 'AppBundle:Email:bacs/mandateCancelled';
        if ($reason == self::ADDACS_REASON_TRANSFER) {
            return;
        }
        $templateHtml = sprintf('%s.html.twig', $baseTemplate);
        $templateText = sprintf('%s.txt.twig', $baseTemplate);

        $claimed = $user->getAvgPolicyClaims() > 0;
        $this->mailerService->sendTemplateToUser(
            'Your Direct Debit Cancellation',
            $user,
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
        // If a user doesn't have an active or unpaid policy, there is no need to notify of a mandate cancellation
        // copy would be confusing and there's no value to sending
        if (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy()) {
            return;
        }

        $baseTemplate = 'AppBundle:Email:bacs/mandateCancelledNameChange';
        $templateHtml = sprintf('%s.html.twig', $baseTemplate);
        $templateText = sprintf('%s.txt.twig', $baseTemplate);

        $this->mailerService->sendTemplateToUser(
            'Your recent name change',
            $user,
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
            $this->validateSun($element, 'user-number');
        }
    }

    private function validateServiceLicenseInformation($xpath)
    {
        $elementList = $xpath->query('//BACSDocument/Data/ARUDD/ServiceLicenseInformation');
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $this->validateSun($element, 'userNumber');
        }
    }

    private function validateServiceUserNumber($xpath)
    {
        $elementList = $xpath->query('//VocaDocument/Data/Document/ServiceUserNumber');
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $this->validateSun($element);
        }
    }

    private function validateSun(\DOMElement $element, $userNumberAttribute = null)
    {
        if ($userNumberAttribute) {
            $sun = $element->attributes->getNamedItem($userNumberAttribute)->nodeValue;
        } else {
            $sun = $element->nodeValue;
        }
        if ($sun != self::SUN) {
            throw new \Exception(sprintf('Invalid SUN %s', $sun));
        }
    }

    private function getRecordType(\DOMElement $element)
    {
        return $this->getNodeValue($element, 'record-type');
    }

    private function getReference(\DOMElement $element, $referenceName = 'reference')
    {
        return trim($this->getNodeValue($element, $referenceName));
    }


    private function getChildNodeValue(\DOMElement $element, $name)
    {
        foreach ($element->childNodes as $childNode) {
            /** @var \DOMElement $childNode */
            if ($childNode->nodeName == $name) {
                return trim($childNode->nodeValue);
            }
        }

        return null;
    }

    private function getReason(\DOMElement $element)
    {
        return $element->attributes->getNamedItem('reason-code')->nodeValue;
    }

    private function getReturnCode(\DOMElement $element)
    {
        return $element->attributes->getNamedItem('returnCode')->nodeValue;
    }

    private function getOriginalProcessingDate(\DOMElement $element)
    {
        $originalProcessingDate = $element->attributes->getNamedItem('originalProcessingDate')->nodeValue;

        $originalProcessingDate = \DateTime::createFromFormat('Y-m-d', $originalProcessingDate);

        return $originalProcessingDate;
    }

    private function getCurrentProcessingDate(\DOMElement $element)
    {
        $currentProcessingDate = $element->attributes->getNamedItem('currentProcessingDate')->nodeValue;

        $currentProcessingDate = \DateTime::createFromFormat('Y-m-d', $currentProcessingDate);

        return $currentProcessingDate;
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
            'cancelled-ddi' => 0,
        ];

        $xml = file_get_contents($file);
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOBLANKS);
        $xpath = new DOMXPath($dom);

        $this->validateMessageHeader($xpath);

        $elementList = $xpath->query('//BACSDocument/Data/MessagingAdvices/MessagingAdvice');
        /** @var \DOMElement $element */
        foreach ($elementList as $element) {
            $this->validateSun($element, 'user-number');
            $recordType = $this->getRecordType($element);
            if ($recordType == "V") {
                $this->validateFileType($element);
            } elseif ($recordType == "R") {
                $this->validateFileType($element);
                $errorsList = $xpath->query('//BACSDocument/Data/MessagingAdvices/MessagingError');
                foreach ($errorsList as $error) {
                    $results['errors'][] = $error->attributes->getNamedItem('line1')->nodeValue;
                }
            } elseif (in_array($recordType, ["N", "D"])) {
                $reference = $this->getReference($element);
                $repo = $this->dm->getRepository(User::class);
                /** @var User $user */
                $user = $repo->findOneBy(['paymentMethod.bankAccount.reference' => $reference]);
                if (!$user) {
                    $error = sprintf('Unable to find user with reference %s. Unable to cancel mandate.', $reference);
                    $results['errors'][] = $error;
                    $this->logger->warning($error);

                    continue;
                }

                $reason = $this->getReason($element);
                if (in_array($reason, [
                    self::AUDDIS_REASON_USER,
                    self::AUDDIS_REASON_DECEASED,
                    self::AUDDIS_REASON_TRANSFER,
                    self::AUDDIS_REASON_NO_ACCOUNT,
                    self::AUDDIS_REASON_NO_INSTRUCTION,
                    self::AUDDIS_REASON_NON_ZERO,
                    self::AUDDIS_REASON_CLOSED,
                    self::AUDDIS_REASON_TRANSFER_BRANCH,
                    self::AUDDIS_REASON_INVALID_ACCOUNT_TYPE,
                    self::AUDDIS_REASON_DD_NOT_ALLOWED,
                    self::AUDDIS_REASON_EXPIRED,
                    self::AUDDIS_REASON_DUPLICATE_REFERENCE,
                    self::AUDDIS_REASON_CODE_INCOMPATIBLE,
                    self::AUDDIS_REASON_NOT_ALLOWED,
                    self::AUDDIS_REASON_INVALID_REFERENCE,
                    self::AUDDIS_REASON_MISSING_PAYER_NAME,
                    self::AUDDIS_REASON_MISSING_SERVICE_NAME,
                    self::AUDDIS_REASON_INCORRECT_DETAILS,
                ])) {
                    /** @var BacsPaymentMethod $bacs */
                    $bacs = $user->getPaymentMethod();
                    $bacs->getBankAccount()->setMandateStatus(BankAccount::MANDATE_CANCELLED);

                    foreach ($user->getValidPolicies(true) as $policy) {
                        /** @var Policy $policy */
                        $policy->setPolicyStatusUnpaidIfActive();
                    }

                    $this->dm->flush(null, array('w' => 'majority', 'j' => true));
                    $results['cancelled-ddi']++;
                } else {
                    throw new \Exception(sprintf('Unknown auddis reason %s', $reason));
                }
            } else {
                throw new \Exception(sprintf('Unknown record type %s', $recordType));
            }

            $results['records']++;
            if ($recordType != "D") {
                $results['serial-number'] = $this->getNodeValue($element, 'vol-serial-number');
                $results['file-numbers'][] = $this->getNodeValue($element, 'originator-file-number');
                $results['accepted-ddi'] += $this->getNodeValue($element, 'accepted-ddi', 0);
                $results['rejected-ddi'] += $this->getNodeValue($element, 'rejected-ddi', 0);
            }
        }

        if ($results['rejected-ddi'] == 0 && $results['cancelled-ddi'] == 0) {
            $this->approveMandates($results['serial-number']);
        } else {
            $this->logger->warning(sprintf(
                'Failed to auto-approve mandates for serial file %s due to %d rejected ddis/%d cancelled ddis',
                $results['serial-number'],
                $results['rejected-ddi'],
                $results['cancelled-ddi']
            ));
        }

        return $results;
    }

    /**
     * Mark file as submitted and update payment data
     * @param AccessPayFile $file
     */
    public function bacsFileSubmitted(AccessPayFile $file)
    {
        $file->setStatus(AccessPayFile::STATUS_SUBMITTED);
        $file->setSubmittedDate(\DateTime::createFromFormat('U', time()));
        $paymentRepo = $this->dm->getRepository(BacsPayment::class);

        $payments = $paymentRepo->findBy([
            'serialNumber' => $file->getSerialNumber(),
            'status' => BacsPayment::STATUS_GENERATED
        ]);
        foreach ($payments as $payment) {
            /** @var BacsPayment $payment */
            $payment->setStatus(BacsPayment::STATUS_SUBMITTED);
            $payment->submit();
        }

        // TODO: update bacs mandate date if necessary

        $this->dm->flush();
    }

    /**
     * Mark file as cancelled
     * @param AccessPayFile $file
     */
    public function bacsFileCancelled(AccessPayFile $file)
    {
        $file->setStatus(AccessPayFile::STATUS_CANCELLED);
        $this->dm->flush();
    }

    /**
     * Update payments with new serial number
     * @param AccessPayFile $file
     * @param string        $serialNumber
     */
    public function bacsFileUpdateSerialNumber(AccessPayFile $file, $serialNumber)
    {
        $paymentRepo = $this->dm->getRepository(BacsPayment::class);
        $payments = $paymentRepo->findBy([
            'serialNumber' => $file->getSerialNumber(),
            'status' => BacsPayment::STATUS_GENERATED
        ]);
        foreach ($payments as $payment) {
            /** @var BacsPayment $payment */
            $payment->setSerialNumber($serialNumber);
        }

        $file->setSerialNumber($serialNumber);
        $metadata = $file->getMetadata();
        $metadata['serial-number'] = $serialNumber;
        $file->setMetadata($metadata);
        $this->dm->flush();
    }

    /**
     * @param \DOMElement $element
     * @param string      $name
     * @param mixed       $missingValue
     * @return string|null
     */
    private function getNodeValue(\DOMElement $element, $name, $missingValue = null)
    {
        if ($element->attributes->getNamedItem($name)) {
            return $element->attributes->getNamedItem($name)->nodeValue;
        }

        return $missingValue;
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
        $this->redis->del([self::KEY_BACS_CANCEL]);

        return $cancellations;
    }

    /**
     * @param Policy         $policy
     * @param string         $notes
     * @param float|null     $amount
     * @param \DateTime|null $date
     * @param boolean        $update
     * @param string         $source
     * @return BacsPayment
     * @throws \Exception
     */
    public function bacsPayment(
        Policy $policy,
        $notes,
        $amount = null,
        \DateTime $date = null,
        $update = true,
        $source = Payment::SOURCE_TOKEN
    ) {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if (!$amount) {
            $amount = $policy->getPremium()->getMonthlyPremiumPrice();
        }
        $user = $policy->getPayerOrUser();

        $payment = new BacsPayment();
        $payment->setDate($date);
        $payment->setAmount($amount);
        $payment->setNotes($notes);
        $payment->setUser($policy->getUser());
        $payment->setStatus(BacsPayment::STATUS_PENDING);
        $payment->setSource($source);

        // Admin or user source is always a one off payment
        if (in_array($source, [Payment::SOURCE_ADMIN, Payment::SOURCE_WEB])) {
            $payment->setIsOneOffPayment(true);
        }

        if (!$user->hasValidPaymentMethod()) {
            $payment->setStatus(BacsPayment::STATUS_SKIPPED);
            $this->logger->warning(sprintf(
                'User %s does not have a valid payment method (Policy %s)',
                $user->getId(),
                $policy->getId()
            ));
        }

        if ($update) {
            $policy->addPayment($payment);
            $this->dm->persist($payment);
        }

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

        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
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

        $credits = $repo->getAllPendingCredits();

        return count($credits) > 0;
    }

    public function exportMandates(\DateTime $date, $serialNumber, $includeHeader = false, $update = true)
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
                sprintf('"%s"', self::BACS_COMMAND_CREATE_MANDATE), // new Auddis
                sprintf('"%s"', $paymentMethod->getBankAccount()->getAccountName()),
                sprintf('"%s"', $paymentMethod->getBankAccount()->getSortCode()),
                sprintf('"%s"', $paymentMethod->getBankAccount()->getAccountNumber()),
                '"0"', // £0 for Addis setup
                sprintf('"%s"', $paymentMethod->getBankAccount()->getReference()),
                sprintf('"%s"', $user->getId()),
                '""',
                '""',
            ]);

            if ($update) {
                $paymentMethod->getBankAccount()->setMandateStatus(BankAccount::MANDATE_PENDING_APPROVAL);
                $paymentMethod->getBankAccount()->setMandateSerialNumber($serialNumber);

                // do not attempt to take payment until 2 business days after to allow for mandate
                $initialPaymentSubmissionDate = \DateTime::createFromFormat('U', time());
                $initialPaymentSubmissionDate = $this->addBusinessDays($initialPaymentSubmissionDate, 2);
                $paymentMethod->getBankAccount()->setInitialPaymentSubmissionDate($initialPaymentSubmissionDate);
            }
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
                sprintf('"%s"', self::BACS_COMMAND_CANCEL_MANDATE), // cancel Auddis
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

    public function generatePaymentsDebits(
        $prefix,
        \DateTime $date,
        &$metadata,
        $update = true
    ) {
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
            $scheduledDate = $this->getNextBusinessDay($scheduledPayment->getScheduled());
            $policy = $scheduledPayment->getPolicy();

            // If admin has rescheduled, then allow payment to go through, but should be manually approved
            $ignoreNotEnoughTime = $scheduledPayment->getType() == ScheduledPayment::TYPE_ADMIN;
            $validate = $this->validateBacs($policy, $scheduledDate, $scheduledPayment->getId(), $ignoreNotEnoughTime);
            if ($validate == self::VALIDATE_SKIP) {
                continue;
            } elseif ($validate == self::VALIDATE_CANCEL) {
                if ($update) {
                    $scheduledPayment->cancel();
                    $this->dm->flush(null, array('w' => 'majority', 'j' => true));
                }

                continue;
            } elseif ($validate == self::VALIDATE_RESCHEDULE) {
                if ($update) {
                    $scheduledPayment->setStatus(ScheduledPayment::STATUS_CANCELLED);
                    $rescheduled = $scheduledPayment->reschedule($scheduledDate, 0);
                    $policy->addScheduledPayment($rescheduled);
                    $this->dm->flush(null, array('w' => 'majority', 'j' => true));
                }

                continue;
            }

            $payment = $this->bacsPayment(
                $scheduledPayment->getPolicy(),
                $scheduledPayment->getNotes() ?: 'Scheduled Payment',
                $scheduledPayment->getAmount(),
                $scheduledDate,
                $update,
                $scheduledPayment->getType() == ScheduledPayment::TYPE_ADMIN ?
                    Payment::SOURCE_ADMIN :
                    Payment::SOURCE_TOKEN
            );
            $scheduledPayment->setPayment($payment);
            if ($payment->getStatus() != BacsPayment::STATUS_SKIPPED) {
                $metadata['debit-amount'] += $scheduledPayment->getAmount();
            }
            if ($update) {
                $this->dm->flush(null, array('w' => 'majority', 'j' => true));
            }
        }
    }

    private function validateBacs(Policy $policy, $scheduledDate, $id, $ignoreNotEnoughTime = false)
    {
        /** @var BacsPaymentMethod $bacs */
        $bacs = $policy->getUser()->getPaymentMethod();

        if ($this->environment == 'prod' && !$policy->isValidPolicy()) {
            $msg = sprintf(
                'Cancelling (scheduled) payment %s policy is not valid',
                $id
            );
            $this->logger->warning($msg);

            return self::VALIDATE_CANCEL;
        }

        if (!$bacs || !$bacs->getBankAccount()) {
            $msg = sprintf(
                'Skipping (scheduled) payment %s as unable to determine payment method or missing bank account',
                $id
            );
            $this->logger->warning($msg);

            return self::VALIDATE_SKIP;
        }

        $bankAccount = $bacs->getBankAccount();
        if (in_array($bankAccount->getMandateStatus(), [
            BankAccount::MANDATE_CANCELLED,
            BankAccount::MANDATE_FAILURE
        ])) {
            $msg = sprintf(
                'Cancelling (scheduled) payment %s as mandate is %s',
                $id,
                $bankAccount->getMandateStatus()
            );
            $this->logger->warning($msg);

            return self::VALIDATE_CANCEL;
        } elseif ($bankAccount->getMandateStatus() != BankAccount::MANDATE_SUCCESS) {
            $msg = sprintf(
                'Skipping (scheduled) payment %s as mandate is not enabled (%s)',
                $id,
                $bankAccount->getMandateStatus()
            );
            // for first payment, would expected that mandate may not yet be setup
            if ($bankAccount->isFirstPayment()) {
                $this->logger->info($msg);
            } else {
                $this->logger->warning($msg);
            }

            return self::VALIDATE_SKIP;
        }

        if (!$bankAccount->allowedSubmission()) {
            $msg = sprintf(
                'Skipping payment %s as submission is not yet allowed (must be at least %s) [Rescheduled]',
                $id,
                $bankAccount->getInitialPaymentSubmissionDate()->format('d/m/y')
            );
            $this->logger->error($msg);

            return self::VALIDATE_RESCHEDULE;
        }

        $bacsPaymentForDateCalcs = new BacsPayment();
        $bacsPaymentForDateCalcs->submit($scheduledDate);
        if ($policy->getPolicyExpirationDate() < $bacsPaymentForDateCalcs->getBacsReversedDate()) {
            if (!$ignoreNotEnoughTime) {
                $msg = sprintf(
                    'Skipping (scheduled) payment %s as payment date is after expiration date',
                    $id
                );
                $this->logger->error($msg);

                return self::VALIDATE_SKIP;
            }

            // @codingStandardsIgnoreStart
            $msg = sprintf(
                'Running admin (scheduled) payment %s for policy %s. Warning! Payment date is after expiration date and should be immediately manually approved',
                $id,
                $policy->getId()
            );
            // @codingStandardsIgnoreEnd
            $this->logger->error($msg);

            // continue with other logic
        }

        // If admin has rescheduled, then notify to user will have been performed and so no need to check
        // processing date
        if (!$ignoreNotEnoughTime) {
            if (!$bankAccount->allowedProcessing($scheduledDate)) {
                $msg = sprintf(
                    'Skipping (scheduled) payment %s as processing date is not allowed (%s / initial: %s)',
                    $id,
                    $scheduledDate->format('d/m/y'),
                    $bankAccount->isFirstPayment() ? 'yes' : 'no'
                );
                $this->logger->error($msg);

                return self::VALIDATE_SKIP;
            }
        }

        return self::VALIDATE_OK;
    }

    public function exportPaymentsDebits(
        $prefix,
        \DateTime $date,
        $serialNumber,
        &$metadata,
        $includeHeader = false,
        $update = true
    ) {
        $lines = [];
        $accounts = [];

        if ($includeHeader) {
            $lines[] = $this->getHeader();
        }

        $this->generatePaymentsDebits($prefix, $date, $metadata, $update);

        /** @var BacsPaymentRepository $repo */
        $repo = $this->dm->getRepository(BacsPayment::class);
        $payments = $repo->getAllPendingDebits();
        foreach ($payments as $payment) {
            /** @var BacsPayment $payment */
            $policy = $payment->getPolicy();
            /** @var BacsPaymentMethod $bacs */
            $bacs = $payment->getPolicy()->getUser()->getPaymentMethod();
            $bankAccount = $bacs->getBankAccount();

            $validate = $this->validateBacs(
                $policy,
                $payment->getDate(),
                $payment->getId(),
                $payment->isOneOffPayment()
            );
            // rescheduling doesn't make sense in context of already generated payments
            if (in_array($validate, [self::VALIDATE_SKIP, self::VALIDATE_RESCHEDULE])) {
                continue;
            } elseif ($validate == self::VALIDATE_CANCEL) {
                if ($update) {
                    $payment->setStatus(BacsPayment::STATUS_SKIPPED);
                    $this->dm->flush(null, array('w' => 'majority', 'j' => true));
                }

                continue;
            }


            $lines[] = implode(',', [
                sprintf('"%s"', $payment->getDate()->format('d/m/y')),
                '"Scheduled Payment"',
                $bankAccount->isFirstPayment() ?
                    sprintf('"%s"', self::BACS_COMMAND_FIRST_DIRECT_DEBIT) :
                    sprintf('"%s"', self::BACS_COMMAND_DIRECT_DEBIT),
                sprintf('"%s"', $bankAccount->getAccountName()),
                sprintf('"%s"', $bankAccount->getSortCode()),
                sprintf('"%s"', $bankAccount->getAccountNumber()),
                sprintf('"%0.2f"', $payment->getAmount()),
                sprintf('"%s"', $bankAccount->getReference()),
                sprintf('"%s"', $policy->getUser()->getId()),
                sprintf('"%s"', $policy->getId()),
                sprintf('"P-%s"', $payment->getId()),
            ]);
            $payment->setSubmittedDate($payment->getDate());
            $payment->setStatus(BacsPayment::STATUS_GENERATED);
            $payment->setSerialNumber($serialNumber);
            if ($payment->getScheduledPayment()) {
                $payment->getScheduledPayment()->setStatus(ScheduledPayment::STATUS_PENDING);
            } else {
                $this->logger->warning(sprintf(
                    'Unable to find scheduled payment for payment %s',
                    $payment->getId()
                ));
            }
            if ($bankAccount->isFirstPayment()) {
                $bankAccount->setFirstPayment(false);
            }
            $accountData = sprintf('%s%s', $bankAccount->getSortCode(), $bankAccount->getAccountNumber());
            if (in_array($accountData, $accounts)) {
                $this->logger->warning(sprintf(
                    'More than 1 payment for Policy %s is present in the bacs file',
                    $policy->getId()
                ));
            }
            $accounts[] = $accountData;
        }

        return $lines;
    }

    public function exportPaymentsCredits(
        \DateTime $date,
        $serialNumber,
        &$metadata,
        $includeHeader = false,
        $update = true
    ) {
        $lines = [];
        if ($includeHeader) {
            $lines[] = $this->getHeader();
        }
        /** @var PaymentRepository $repo */
        $repo = $this->dm->getRepository(BacsPayment::class);

        $credits = $repo->getAllPendingCredits();

        $metadata['credit-amount'] = 0;
        foreach ($credits as $payment) {
            /* @var BacsPayment $payment */
            /** @var BacsPaymentMethod $bacs */
            $bacs = $payment->getPolicy()->getUser()->getPaymentMethod();
            if (!$bacs || !$payment->getPolicy()->getUser()->hasBacsPaymentMethod()) {
                $msg = sprintf(
                    'Skipping payment %s as unable to determine payment method or missing bank account',
                    $payment->getId()
                );
                $this->logger->warning($msg);
                continue;
            }

            $bankAccount = $bacs->getBankAccount();

            // amount will be -, but bacs credit needs +
            $normalisedPaymentAmount = 0 - $payment->getAmount();

            $metadata['credit-amount'] += $normalisedPaymentAmount;
            $lines[] = implode(',', [
                sprintf('"%s"', $date->format('d/m/y')),
                '"Credit"',
                sprintf('"%s"', self::BACS_COMMAND_DIRECT_CREDIT),
                sprintf('"%s"', $bankAccount->getAccountName()),
                sprintf('"%s"', $bankAccount->getSortCode()),
                sprintf('"%s"', $bankAccount->getAccountNumber()),
                sprintf('"%0.2f"', $normalisedPaymentAmount),
                sprintf('"%s"', $bankAccount->getReference()),
                sprintf('"%s"', $payment->getPolicy()->getUser()->getId()),
                sprintf('"%s"', $payment->getPolicy()->getId()),
                sprintf('"P-%s"', $payment->getId()),
            ]);

            if ($update) {
                $payment->setStatus(BacsPayment::STATUS_GENERATED);
                $payment->setSerialNumber($serialNumber);
            }
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
        $this->redis->del([self::KEY_BACS_QUEUE]);
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

    /**
     * @return Policy
     */
    public function getPolicy($id)
    {
        if (!$id) {
            throw new \InvalidArgumentException('Missing policyId');
        }
        $repo = $this->dm->getRepository(Policy::class);
        /** @var Policy $policy */
        $policy = $repo->find($id);
        if (!$policy) {
            throw new \InvalidArgumentException(sprintf('Unable to find policyId: %s', $id));
        }

        return $policy;
    }

    public function generateBacsPdf(Policy $policy)
    {
        if (!$policy->getUser()->hasBacsPaymentMethod()) {
            $this->logger->error(sprintf(
                'Policy %s/%s does not have a bacs payment method for generating bacs pdf',
                $policy->getPolicyNumber(),
                $policy->getId()
            ));

            return;
        }

        $now = \DateTime::createFromFormat('U', time());
        /** @var BacsPaymentMethod $paymentMethod */
        $paymentMethod = $policy->getUser()->getPaymentMethod();
        $bankAccount = $paymentMethod->getBankAccount();
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

        $date = \DateTime::createFromFormat('U', time());
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
