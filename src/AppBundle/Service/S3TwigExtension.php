<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use AppBundle\Document\CurrencyTrait;

class S3TwigExtension extends \Twig_Extension
{
    protected $s3;

    /**
     */
    public function __construct($s3)
    {
        $this->s3 = $s3;
    }

    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('s3DownloadLink', [$this, 's3DownloadLink']),
        );
    }

    public function s3DownloadLink($bucket, $key, $filename = null)
    {
        if (!$key || mb_strlen(trim($key)) == 0) {
            return null;
        }

        $keyItems = explode('/', $key);
        if (!$filename) {
            $filename = $keyItems[count($keyItems) - 1];
        }
        $command = $this->s3->getCommand('GetObject', array(
            'Bucket' => $bucket,
            'Key'    => $key,
            'ResponseContentDisposition' => sprintf('attachment; filename="%s"', $filename),
        ));
        $signedUrl = $this->s3->createPresignedRequest($command, '+15 minutes');

        return $signedUrl->getUri();
    }

    public function getName()
    {
        return 'app_twig_s3';
    }
}
