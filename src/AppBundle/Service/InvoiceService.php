<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use AppBundle\Document\Invoice;
use AppBundle\Document\File\InvoiceFile;
use AppBundle\Document\CurrencyTrait;

class InvoiceService
{
    use CurrencyTrait;

    const S3_BUCKET = 'admin.so-sure.com';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var SequenceService */
    protected $sequence;

    /** @var MailerService */
    protected $mailer;
    protected $smtp;
    protected $templating;
    protected $snappyPdf;
    protected $s3;

    /** @var string */
    protected $environment;

    /** @var boolean */
    protected $skipS3;

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
    }

    /**
     * Environment is injected into constructed and should only
     * be overwriten for a few test cases.
     *
     * @param string $environment
     */
    public function setEnvironment($environment)
    {
        $this->environment = $environment;
        $this->skipS3 = true;
    }

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param SequenceService $sequence
     * @param MailerService   $mailer
     * @param                 $smtp
     * @param                 $templating
     * @param                 $environment
     * @param                 $snappyPdf
     * @param                 $s3
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        SequenceService $sequence,
        MailerService $mailer,
        $smtp,
        $templating,
        $environment,
        $snappyPdf,
        $s3
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->sequence = $sequence;
        $this->mailer = $mailer;
        $this->smtp = $smtp;
        $this->templating = $templating;
        $this->environment = $environment;
        $this->snappyPdf = $snappyPdf;
        $this->s3 = $s3;
    }

    public function generateInvoice(Invoice $invoice, $email = null, $regenerate = false)
    {
        if (!$invoice->hasInvoiceItems()) {
            return ['genrated' => null, 'file' => null];
        }

        $generated = false;
        if (!$invoice->hasInvoiceFile()) {
            if (!$invoice->getInvoiceNumber()) {
                if ($this->environment == 'prod') {
                    $seq = SequenceService::SEQUENCE_INVOICE;
                } else {
                    $seq = SequenceService::SEQUENCE_INVOICE_INVALID;
                }
                $invoice->setInvoiceNumber(sprintf('ssa-%06d', $this->sequence->getSequenceId($seq)));
            }
            $invoicePdf = $this->generateInvoicePdf($invoice, $regenerate);
            $generated = true;
            $this->dm->flush();
        } else {
            $invoicePdf = $this->downloadS3($invoice);
        }

        if ($email) {
            $this->invoiceEmail($email, $invoice, $invoicePdf);
        }

        return ['genrated' => $generated, 'file' => $invoicePdf];
    }

    public function generateInvoicePdf(Invoice $invoice, $regenerate = false)
    {
        $filename = sprintf(
            "%s-%s.pdf",
            "invoice",
            $invoice->getInvoiceNumber()
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
            $this->templating->render('AppBundle:Pdf:invoice.html.twig', [
                'invoice' => $invoice,
                'regenerate' => $regenerate,
                'isProd' => $this->environment == 'prod',
            ]),
            $tmpFile
        );

        $this->uploadS3($tmpFile, $filename);

        $invoiceFile = new InvoiceFile();
        $invoiceFile->setBucket(self::S3_BUCKET);
        $invoiceFile->setKey($this->getS3Key($filename));
        $invoice->addInvoiceFile($invoiceFile);
        $this->dm->flush();

        return $tmpFile;
    }

    public function getS3Key($filename)
    {
        $date = new \DateTime();
        return sprintf('%s/invoice/%s/%s', $this->environment, $date->format('Y'), $filename);
    }

    public function uploadS3($file, $filename)
    {
        if ($this->environment == "test" || $this->skipS3) {
            return;
        }

        $s3Key = $this->getS3Key($filename);

        $result = $this->s3->putObject(array(
            'Bucket' => self::S3_BUCKET,
            'Key'    => $s3Key,
            'SourceFile' => $file,
        ));
    }

    public function downloadS3(Invoice $invoice)
    {
        if ($this->environment == "test" || $this->skipS3) {
            return;
        }

        $invoiceFile = $invoice->getInvoiceFiles()[0];

        $file = sprintf('%s/%s', sys_get_temp_dir(), $invoiceFile->getFilename());
        if (file_exists($file)) {
            unlink($file);
        }

        $result = $this->s3->getObject(array(
            'Bucket' => self::S3_BUCKET,
            'Key'    => $invoiceFile->getKey(),
            'SaveAs' => $file,
        ));

        return $file;
    }

    public function invoiceEmail($email, Invoice $invoice, $invoicePdf)
    {
        $this->mailer->sendTemplate(
            sprintf('so-sure Invoice %s', $invoice->getInvoiceNumber()),
            $email,
            'AppBundle:Email:invoice/invoice.html.twig',
            ['invoice' => $invoice],
            'AppBundle:Email:invoice/invoice.txt.twig',
            ['invoice' => $invoice],
            [$invoicePdf]
        );
    }
}
