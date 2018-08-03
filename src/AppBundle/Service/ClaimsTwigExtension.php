<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Claim;
use Symfony\Component\Routing\Router;

class ClaimsTwigExtension extends \Twig_Extension
{
    protected $s3;

    /** @var RouterService */
    protected $router;

    /**
     */
    public function __construct($s3, $router)
    {
        $this->s3 = $s3;
        $this->router = $router;
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
                'url' => $this->router->generateUrl('claims_download_file', ['id' => $file->getId()]),
                'url_download' => $this->router->generateUrl(
                    'claims_download_file_attachment',
                    ['id' => $file->getId()]
                ),
            );
        }

        $proofOfBarrings = array();
        foreach ($claim->getProofOfBarringFiles() as $file) {
            $proofOfBarrings[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->router->generateUrl('claims_download_file', ['id' => $file->getId()]),
                'url_download' => $this->router->generateUrl(
                    'claims_download_file_attachment',
                    ['id' => $file->getId()]
                ),
            );
        }

        $proofOfPurchases = array();
        foreach ($claim->getProofOfPurchaseFiles() as $file) {
            $proofOfPurchases[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->router->generateUrl('claims_download_file', ['id' => $file->getId()]),
                'url_download' => $this->router->generateUrl(
                    'claims_download_file_attachment',
                    ['id' => $file->getId()]
                ),
            );
        }

        $damagePictures = array();
        foreach ($claim->getDamagePictureFiles() as $file) {
            $damagePictures[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->router->generateUrl('claims_download_file', ['id' => $file->getId()]),
                'url_download' => $this->router->generateUrl(
                    'claims_download_file_attachment',
                    ['id' => $file->getId()]
                ),
            );
        }

        $proofOfLosses = array();
        foreach ($claim->getProofOfLossFiles() as $file) {
            $proofOfLosses[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->router->generateUrl('claims_download_file', ['id' => $file->getId()]),
                'url_download' => $this->router->generateUrl(
                    'claims_download_file_attachment',
                    ['id' => $file->getId()]
                ),
            );
        }

        $others = array();
        foreach ($claim->getOtherFiles() as $file) {
            $others[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->router->generateUrl('claims_download_file', ['id' => $file->getId()]),
                'url_download' => $this->router->generateUrl(
                    'claims_download_file_attachment',
                    ['id' => $file->getId()]
                ),
            );
        }

        return array(
            'proofOfUsages' => $proofOfUsages,
            'proofOfBarrings' => $proofOfBarrings,
            'proofOfPurchases' => $proofOfPurchases,
            'damagePictures' => $damagePictures,
            'proofOfLosses' => $proofOfLosses,
            'others' => $others,
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
