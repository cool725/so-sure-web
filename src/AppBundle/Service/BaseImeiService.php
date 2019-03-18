<?php
namespace AppBundle\Service;

use AppBundle\Document\Policy;
use AppBundle\Repository\PhonePolicyRepository;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use AppBundle\Document\Phone;
use AppBundle\Document\LostPhone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use Doctrine\ODM\MongoDB\DocumentManager;
use League\Flysystem\MountManager;
use thiagoalessio\TesseractOCR\TesseractOCR;

class BaseImeiService
{
    use \AppBundle\Document\ImeiTrait;

    // see tesseract --help
    const OEM_TESSERACT_ONLY = 0;
    const OEM_CUBE_ONLY = 1;
    const OEM_TESSERACT_CUBE_COMBINED = 2;
    const OEM_DEFAULT = 3;

    const S3_FAILED_OCR_FOLDER = 'imei-failure';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    protected $redis;

    /** @var MountManager */
    protected $filesystem;

    /**
     * @param MountManager $oneUpFlySystemMountManager
     */
    public function setMountManager(MountManager $oneUpFlySystemMountManager)
    {
        $this->filesystem = $oneUpFlySystemMountManager;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param DocumentManager $dm
     */
    public function setDm($dm)
    {
        $this->dm = $dm;
    }

    public function setRedis($redis)
    {
        $this->redis = $redis;
    }

    /**
     * Check if imei has be registered as lost
     *
     * @param string $imei
     *
     * @return boolean
     */
    public function isLostImei($imei)
    {
        $repo = $this->dm->getRepository(LostPhone::class);
        $phones = $repo->findBy(['imei' => (string) $imei]);

        return count($phones) > 0;
    }

    /**
     * Check if imei is already assigned to another policy
     *
     * @param string $imei
     * @param Policy $original is a policy to consider as the "original", but it can be null and ignored.
     *
     * @return boolean
     */
    public function isDuplicatePolicyImei($imei, Policy $original = null)
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $policies = $repo->findDuplicateImei($imei);

        foreach ($policies as $policy) {
            /** @var Policy $policy */

            // Partial policies can be ignored
            if (!$policy->isPolicy()) {
                continue;
            }

            // Expired policies can be paid for again
            if ($policy->isExpired()) {
                continue;
            }

            // Unrenewed policies can be paid for again
            if ($policy->isUnrenewed()) {
                continue;
            }

            // Cancelled policies that are not policy declined can be paid for again
            if ($policy->isCancelled() && !$policy->isCancelledWithPolicyDeclined()) {
                continue;
            }

            // Extra checks if we are checking with a specific policy in mind.
            if ($original) {
                $ancestor = $original->getPreviousPolicy();
                if ($original->getId() === $policy->getId() || ($ancestor && $ancestor->getId() === $policy->getId())) {
                    continue;
                }
            }

            // TODO: may want to allow a new policy if within 1 month of expiration and same user
            // TODO: consider if we want to allow an unpaid policy on a different user?
            return true;
        }

        return false;
    }

    public function ocr($filename, $make, $extension = null)
    {
        $resultsCubeCombined = $this->parseOcr(
            $this->ocrRaw($filename, $extension, self::OEM_TESSERACT_CUBE_COMBINED),
            $make
        );
        //print_r($resultsCubeCombined);
        if ($resultsCubeCombined['full_success']) {
            return $resultsCubeCombined;
        }

        // fallbacks below do not appear to be any different from cube combined on web
        // little point in having fallback mechanism unless there is a difference, but keeping
        // logic for further investigation in the future
        return $resultsCubeCombined;
        /*
        $resultsCube = $this->parseOcr(
            $this->ocrRaw($filename, $extension, self::OEM_CUBE_ONLY),
            $make
        );
        //print_r($resultsCube);
        $resultsTesseract = $this->parseOcr(
            $this->ocrRaw($filename, $extension, self::OEM_TESSERACT_ONLY),
            $make
        );
        //print_r($resultsTesseract);
        $results = [
            'success' => false,
            'full_success' => false,
            'raw' => $resultsCubeCombined['raw'],
            'imei' => null,
            'serialNumber' => null,
        ];
        if ($this->isImei($resultsCubeCombined['imei'])) {
            $results['imei'] = $resultsCubeCombined['imei'];
            $results['success'] = true;
        } elseif ($this->isImei($resultsCube['imei'])) {
            $results['imei'] = $resultsCube['imei'];
            $results['success'] = true;
        } elseif ($this->isImei($resultsTesseract['imei'])) {
            $results['imei'] = $resultsTesseract['imei'];
            $results['success'] = true;
        }
        if ($make == 'Apple') {
            if ($this->isAppleSerialNumber($resultsCubeCombined['serialNumber'])) {
                $results['serialNumber'] = $resultsCubeCombined['serialNumber'];
                $results['full_success'] = $results['success'];
            } elseif ($this->isAppleSerialNumber($resultsCube['serialNumber'])) {
                $results['serialNumber'] = $resultsCube['serialNumber'];
                $results['full_success'] = $results['success'];
            } elseif ($this->isAppleSerialNumber($resultsTesseract['serialNumber'])) {
                $results['serialNumber'] = $resultsTesseract['serialNumber'];
                $results['full_success'] = $results['success'];
            }
        } else {
            $results['full_success'] = $results['success'];
        }

        return $results;
        */
    }

    private function findSerialNumberByLinePosition($results, $imei)
    {
        // for non-english language settings
        // find imei line (will always be IMEI regardless of language)
        // and go up 3 lines
        $imeiLine = 0;
        $lines = preg_split("/\\r\\n|\\r|\\n/", $results);
        foreach ($lines as $line) {
            $line = str_replace(' ', '', $line);
            if (mb_stripos($line, $imei) !== false) {
                break;
            }
            $imeiLine++;
        }
        if ($imeiLine < 3) {
            return null;
        }
        $serialNumber = null;
        $serialLine = $lines[$imeiLine - 3];
        $serialLineData = explode(' ', $serialLine);
        foreach ($serialLineData as $serialNumber) {
            $serialNumber = str_replace('@', '0', $serialNumber);
            if (preg_match('/[A-Z0-9]{12}/', $serialNumber)) {
                break;
            } else {
                $serialNumber = null;
            }
        }

        return $serialNumber;
    }

    public function parseOcr($results, $make)
    {
        $noSpace = str_replace(array(' ', "\n"), '', $results);
        // print_r($noSpace);
        if ($make == "Apple") {
            if (preg_match('/SerialNumber([A-Z0-9]{12}).*([Il]ME[Il])(\d{15})/s', $noSpace, $matches)) {
                // Expected case
                return [
                    'success' => true,
                    'full_success' => $this->isAppleSerialNumber($matches[1]) && $this->isImei($matches[1]),
                    'raw' => $results,
                    'imei' => $matches[3],
                    'serialNumber' => $matches[1],
                ];
            } elseif (preg_match('/([Il]ME[Il])(\d{15})/', $noSpace, $matches)) {
                // Expected case if non-english language (serial number copy is different)
                $serialNumber = $this->findSerialNumberByLinePosition($results, $matches[2]);
                return [
                    'success' => true,
                    'full_success' => $this->isAppleSerialNumber($serialNumber) && $this->isImei($matches[2]),
                    'raw' => $results,
                    'imei' => $matches[2],
                    'serialNumber' => $serialNumber,
                ];
            } elseif (preg_match('/SerialNumber([A-Z0-9]{12}).*([Il]ME[Il])(\d{14})[A@]/s', $noSpace, $matches)) {
                // 14 digit IMEI followed by A
                return [
                    'success' => true,
                    'full_success' => $this->isAppleSerialNumber($matches[1]), // forcing a valid imei
                    'raw' => $results,
                    'imei' => $this->luhnGenerate($matches[3]),
                    'serialNumber' => $matches[1],
                ];
            } elseif (preg_match('/([Il]ME[Il])(\d{14})[A@]/', $noSpace, $matches)) {
                // 14 digit IMEI followed by A with non-english language (serial number copy is different)
                $serialNumber = $this->findSerialNumberByLinePosition($results, $matches[2]);
                return [
                    'success' => true,
                    'full_success' => $this->isAppleSerialNumber($serialNumber), // forcing a valid imei
                    'raw' => $results,
                    'imei' => $this->luhnGenerate($matches[2]),
                    'serialNumber' => $serialNumber,
                ];
            } elseif (preg_match('/(\d{15})/', $noSpace, $matches)) {
                // might be a screenshot of *#06# rather than settings
                if ($this->isImei($matches[1])) {
                    return [
                        'success' => true,
                        'full_success' => false,
                        'raw' => $results,
                        'imei' => $matches[1],
                        'serialNumber' => null,
                    ];
                }
            }
        } else {
            if (preg_match('/(\d{15})/', $noSpace, $matches)) {
                if ($this->isImei($matches[1])) {
                    return [
                        'success' => true,
                        'full_success' => true,
                        'raw' => $results,
                        'imei' => $matches[1],
                        'serialNumber' => null,
                    ];
                }
            }
        }

        return [
            'raw' => $results,
            'success' => false,
            'full_success' => false,
            'imei' => null,
            'serialNumber' => null
        ];
    }

    public function ocrRaw($filename, $extension = null, $engine = self::OEM_TESSERACT_CUBE_COMBINED)
    {
        $imagine = new \Imagine\Gd\Imagine();
        $image = $imagine->open($filename);
        $image->effects()
            ->grayscale();
        $path = pathinfo($filename);
        $file = sprintf('%s/gray-%s-%s', sys_get_temp_dir(), time(), $path['basename']);
        if ($extension) {
            $file = sprintf('%s.%s', $file, $extension);
        } elseif (pathinfo($file, PATHINFO_EXTENSION) == "") {
            // if no extension is present, default to png
            $file = sprintf('%s.png', $file);
        }

        $image->save($file);

        $ocr = (new TesseractOCR($file));
        $results = $ocr
            ->__call('psm', [6])
            ->__call('lang', ['eng'])
            ->__call('tessedit_ocr_engine_mode', [$engine])
            ->run();

        unlink($file);

        return $results;
    }

    public function saveFailedOcr($filename, $userId, $extension = 'png')
    {
        /** @var Filesystem $fs */
        $fs = $this->filesystem->getFilesystem('s3policy_fs');
        /** @var AwsS3Adapter $s3Adapater */
        $s3Adapater = $fs->getAdapter();
        $bucket = $s3Adapater->getBucket();
        $pathPrefix = $s3Adapater->getPathPrefix();
        $path = pathinfo($filename);
        $key = sprintf(
            '%s/%s/%s.%s',
            BaseImeiService::S3_FAILED_OCR_FOLDER,
            $userId,
            $path['basename'],
            $extension
        );
        $stream = fopen($filename, 'r+');
        $fs->writeStream($key, $stream);
        fclose($stream);

        return sprintf('s3://%s/%s/%s', $bucket, $pathPrefix, $key);
    }
}
