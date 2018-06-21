<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Claim;

class ClaimsTwigExtension extends \Twig_Extension
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
            new \Twig_SimpleFunction('s3DownloadLinks', [$this, 's3DownloadLinks']),
        );
    }

    public function s3DownloadLinks($claim)
    {
        $proofOfUsages = array();
        foreach ($claim->getProofOfUsageFiles() as $file) {
            $proofOfUsages[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->s3DownloadLink($file->getBucket(), $file->getKey())
            );
        }

        $proofOfBarrings = array();
        foreach ($claim->getProofOfBarringFiles() as $file) {
            $proofOfBarrings[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->s3DownloadLink($file->getBucket(), $file->getKey())
            );
        }

        $proofOfPurchases = array();
        foreach ($claim->getProofOfPurchaseFiles() as $file) {
            $proofOfPurchases[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->s3DownloadLink($file->getBucket(), $file->getKey())
            );
        }

        $damagePictures = array();
        foreach ($claim->getDamagePictureFiles() as $file) {
            $damagePictures[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->s3DownloadLink($file->getBucket(), $file->getKey())
            );
        }

        return array(
            'proofOfUsages' => $proofOfUsages,
            'proofOfBarrings' => $proofOfBarrings,
            'proofOfPurchases' => $proofOfPurchases,
            'damagePictures' => $damagePictures,
        );
    }

    public function s3DownloadLink($bucket, $key)
    {
        if (!$key || mb_strlen(trim($key)) == 0) {
            return null;
        }

        $keyItems = explode('/', $key);
        $filename = $keyItems[count($keyItems) - 1];
        $command = $this->s3->getCommand('GetObject', array(
            'Bucket' => $bucket,
            'Key'    => $key,
            'ResponseContentDisposition' => sprintf('attachment; filename="%s"', $filename),
        ));
        $signedUrl = $this->s3->createPresignedRequest($command, '+15 minutes');

        return sprintf(
            "%s://%s%s?%s",
            $signedUrl->getUri()->getScheme(),
            $signedUrl->getUri()->getHost(),
            $signedUrl->getUri()->getPath(),
            $signedUrl->getUri()->getQuery()
        );
    }

    public function getName()
    {
        return 'app_twig_claims';
    }
}
