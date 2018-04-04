<?php
namespace AppBundle\Service;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\File\DirectDebitNotificationFile;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Repository\ScheduledPaymentRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use FOS\UserBundle\Mailer\Mailer;
use Psr\Log\LoggerInterface;

class PaymentService
{
    const S3_BUCKET = 'policy.so-sure.com';

    /** @var JudopayService $judopay */
    protected $judopay;

    /** @var LoggerInterface $logger */
    protected $logger;

    /** @var DocumentManager $dm */
    protected $dm;

    /** @var MailerService $mailer */
    protected $mailer;

    /** @var SequenceService $sequenceService */
    protected $sequenceService;

    /** @var string */
    protected $environment;

    /** @var FraudService */
    protected $fraudService;

    protected $templating;
    protected $snappyPdf;
    protected $s3;

    /**
     * PaymentService constructor.
     * @param JudopayService  $judopay
     * @param LoggerInterface $logger
     * @param DocumentManager $dm
     * @param SequenceService $sequenceService
     * @param MailerService   $mailer
     * @param string          $environment
     * @param FraudService    $fraudService
     * @param                 $templating
     * @param                 $snappyPdf
     * @param                 $s3
     */
    public function __construct(
        JudopayService $judopay,
        LoggerInterface $logger,
        DocumentManager $dm,
        SequenceService $sequenceService,
        MailerService $mailer,
        $environment,
        FraudService $fraudService,
        $templating,
        $snappyPdf,
        $s3
    ) {
        $this->judopay = $judopay;
        $this->logger = $logger;
        $this->dm = $dm;
        $this->mailer = $mailer;
        $this->sequenceService = $sequenceService;
        $this->environment = $environment;
        $this->fraudService = $fraudService;
        $this->templating = $templating;
        $this->snappyPdf = $snappyPdf;
        $this->s3 = $s3;
    }

    public function getAllValidScheduledPaymentsForType($prefix, $type, \DateTime $scheduledDate = null)
    {
        $results = [];

        /** @var ScheduledPaymentRepository $repo */
        $repo = $this->dm->getRepository(ScheduledPayment::class);
        $scheduledPayments = $repo->findScheduled($scheduledDate);
        foreach ($scheduledPayments as $scheduledPayment) {
            /** @var ScheduledPayment $scheduledPayment */
            if (!$scheduledPayment->isBillable()) {
                continue;
            }
            if (!$scheduledPayment->getPolicy()->isValidPolicy($prefix)) {
                continue;
            }
            if (!$scheduledPayment->getPolicy()->getPayerOrUser()) {
                $this->logger->warning(sprintf(
                    'Policy %s/%s does not have a user (payer)',
                    $scheduledPayment->getPolicy()->getPolicyNumber(),
                    $scheduledPayment->getPolicy()->getId()
                ));
                continue;
            }
            if (!$scheduledPayment->getPolicy()->getPayerOrUser()->getPaymentMethod() instanceof $type) {
                continue;
            }
            if (!$scheduledPayment->getPolicy()->getPayerOrUser()->hasValidPaymentMethod()) {
                $this->logger->warning(sprintf(
                    'User %s does not have a valid payment method',
                    $scheduledPayment->getPolicy()->getPayerOrUser()->getId()
                ));
            }

            $results[] = $scheduledPayment;
        }

        return $results;
    }

    public function scheduledPayment(
        ScheduledPayment $scheduledPayment,
        $prefix = null,
        \DateTime $date = null,
        $abortOnMultipleSameDayPayment = true
    ) {
        $scheduledPayment->validateRunable($prefix, $date);

        $policy = $scheduledPayment->getPolicy();
        $paymentMethod = $policy->getPayerOrUser()->getPaymentMethod();
        if ($paymentMethod && $paymentMethod instanceof JudoPaymentMethod) {
            return $this->judopay->scheduledPayment(
                $scheduledPayment,
                $prefix,
                $date,
                $abortOnMultipleSameDayPayment
            );
        } else {
            throw new \Exception(sprintf(
                'Payment method not valid for scheduled payment %s',
                $scheduledPayment->getId()
            ));
        }
    }

    /**
     * @param Bacs $bacs
     * @param User $user
     * @return string
     * @throws \Exception
     */
    public function generateBacsReference(Bacs $bacs, User $user)
    {
        if ($this->environment == 'prod') {
            $seq = $this->sequenceService->getSequenceId(SequenceService::SEQUENCE_BACS_REFERENCE);
        } else {
            $seq = $this->sequenceService->getSequenceId(SequenceService::SEQUENCE_BACS_REFERENCE_INVALID);
        }
        $ref = $bacs->generateReference($user, $seq);

        return $ref;
    }

    /**
     * @param Policy            $policy
     * @param BacsPaymentMethod $bacsPaymentMethod
     */
    public function confirmBacs(Policy $policy, BacsPaymentMethod $bacsPaymentMethod)
    {
        $policy->getUser()->setPaymentMethod($bacsPaymentMethod);
        $bacsPaymentMethod->getBankAccount()->setInitialNotificationDate(
            $bacsPaymentMethod->getBankAccount()->getPaymentDate()
        );
        $bacsPaymentMethod->getBankAccount()->setStandardNotificationDate($policy->getBilling());
        $this->dm->flush();

        $this->mailer->sendTemplate(
            sprintf('Your Direct Debit Confirmation'),
            $policy->getUser()->getEmail(),
            'AppBundle:Email:bacs/notification.html.twig',
            ['user' => $policy->getUser(), 'policy' => $policy],
            'AppBundle:Email:bacs/notification.txt.twig',
            ['user' => $policy->getUser(), 'policy' => $policy],
            null,
            'bcc-ddnotifications@so-sure.com'
        );

        $this->generateBacsPdf($policy);

        if ($this->fraudService->getDuplicateBankAccounts($policy) > 0) {
            $this->mailer->send(
                'Duplicate bank account',
                'tech@so-sure.com',
                sprintf('Check %s / %s', $policy->getPolicyNumber(), $policy->getId())
            );
        }
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
        $ddNotificationFile->setBucket(self::S3_BUCKET);
        $ddNotificationFile->setKeyFormat(
            $this->environment . '/dd-notification/' . $date->format('Y') . '/%s'
        );
        $ddNotificationFile->setFileName($filename);
        $policy->addPolicyFile($ddNotificationFile);
        $this->dm->flush();

        $this->uploadS3($tmpFile, $ddNotificationFile->getKey());

        return $tmpFile;
    }

    public function uploadS3($file, $key)
    {
        if ($this->environment == "test") {
            return;
        }

        $result = $this->s3->putObject(array(
            'Bucket' => self::S3_BUCKET,
            'Key'    => $key,
            'SourceFile' => $file,
        ));
    }
}
