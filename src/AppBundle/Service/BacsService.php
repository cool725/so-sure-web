<?php
namespace AppBundle\Service;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\File\BacsReportAddacsFile;
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

    const ADDACS_REASON_BANK = 0;
    const ADDACS_REASON_USER = 1;
    const ADDACS_REASON_DECEASED = 2;
    const ADDACS_REASON_TRANSFER = 3;

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
        if (stripos($file->getClientOriginalName(), "ADDACS Reports") !== false) {
            $metadata = $this->addacs($tmpFile);
            $uploadFile = new BacsReportAddacsFile();
        } else {
            $this->logger->error(sprintf('Unknown bacs report file %s', $file->getClientOriginalName()));
        }

        if ($uploadFile) {
            $this->uploadS3($tmpFile, $file->getClientOriginalName(), $uploadFile, null, $metadata);
        }
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

        $elementList = $xpath->query('//BACSDocument/Data/MessagingAdvices/MessagingAdvice');
        foreach ($elementList as $element) {
            $results['instructions']++;

            /** @var \DOMElement $element */
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
            }
        }

        return $results;
    }
}
