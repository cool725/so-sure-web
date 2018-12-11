<?php
namespace AppBundle\Service;

use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Claim;
use Symfony\Component\Routing\Router;

class ClaimsTwigExtension extends \Twig_Extension
{
    /** @var S3Client */
    protected $s3;

    /** @var RouterService */
    protected $router;

    /** @var RequestService */
    protected $requestService;

    /**
     * ClaimsTwigExtension constructor.
     * @param S3Client       $s3
     * @param RouterService  $router
     * @param RequestService $requestService
     */
    public function __construct(S3Client $s3, RouterService $router, RequestService $requestService)
    {
        $this->s3 = $s3;
        $this->router = $router;
        $this->requestService = $requestService;
    }

    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('s3DownloadLinks', [$this, 's3DownloadLinks']),
        );
    }

    public function s3DownloadLinks($claim)
    {
        $viewRoute = 'claims_download_file';
        $downloadRoute = 'claims_download_file_attachment';
        if ($this->requestService->hasEmployeeRole()) {
            $viewRoute = 'admin_download_file';
            $downloadRoute = 'admin_download_file_attachment';
        }
        $proofOfUsages = array();
        foreach ($claim->getProofOfUsageFiles() as $file) {
            $proofOfUsages[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->router->generateUrl($viewRoute, ['id' => $file->getId()]),
                'url_download' => $this->router->generateUrl(
                    $downloadRoute,
                    ['id' => $file->getId()]
                ),
            );
        }

        $proofOfBarrings = array();
        foreach ($claim->getProofOfBarringFiles() as $file) {
            $proofOfBarrings[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->router->generateUrl($viewRoute, ['id' => $file->getId()]),
                'url_download' => $this->router->generateUrl(
                    $downloadRoute,
                    ['id' => $file->getId()]
                ),
            );
        }

        $proofOfPurchases = array();
        foreach ($claim->getProofOfPurchaseFiles() as $file) {
            $proofOfPurchases[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->router->generateUrl($viewRoute, ['id' => $file->getId()]),
                'url_download' => $this->router->generateUrl(
                    $downloadRoute,
                    ['id' => $file->getId()]
                ),
            );
        }

        $damagePictures = array();
        foreach ($claim->getDamagePictureFiles() as $file) {
            $damagePictures[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->router->generateUrl($viewRoute, ['id' => $file->getId()]),
                'url_download' => $this->router->generateUrl(
                    $downloadRoute,
                    ['id' => $file->getId()]
                ),
            );
        }

        $proofOfLosses = array();
        foreach ($claim->getProofOfLossFiles() as $file) {
            $proofOfLosses[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->router->generateUrl($viewRoute, ['id' => $file->getId()]),
                'url_download' => $this->router->generateUrl(
                    $downloadRoute,
                    ['id' => $file->getId()]
                ),
            );
        }

        $others = array();
        foreach ($claim->getOtherFiles() as $file) {
            $others[] = array(
                'filename' => $file->getFilename(),
                'url' => $this->router->generateUrl($viewRoute, ['id' => $file->getId()]),
                'url_download' => $this->router->generateUrl(
                    $downloadRoute,
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
