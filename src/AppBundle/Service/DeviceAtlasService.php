<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use DeviceAtlasCloudClient;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Document\PlayDevice;
use AppBundle\Document\Phone;

class DeviceAtlasService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /**
     * @param LoggerInterface $logger
     * @param DocumentManager $dm
     */
    public function __construct(LoggerInterface $logger, DocumentManager $dm)
    {
        $this->logger = $logger;
        $this->dm = $dm;
    }

    /**
     * @param Request $request
     *
     * @return Phone|null
     */
    public function getPhone(Request $request)
    {
        $userAgent = $request->headers->get('User-Agent');
        try {
            $result = DeviceAtlasCloudClient::getDeviceData($userAgent);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());

            return null;
        }
        if (!isset($result['properties']) ||
            !isset($result['properties']['isMobilePhone']) ||
            $result['properties']['isMobilePhone'] != 1) {
            return null;
        }

        $manufacturer = $result['properties']['manufacturer'];
        $model = $result['properties']['model'];
        $osVersion = $result['properties']['osVersion'];
        $marketingName = $result['properties']['marketingName'];

        return $this->getPhoneFromDetails($manufacturer, $marketingName, $model, $osVersion);
    }

    /**
     * @param string $manufacturer
     * @param string $marketingName
     * @param string $model
     * @param string $osVersion
     *
     * return Phone|null
     */
    protected function getPhoneFromDetails($manufacturer, $marketingName, $model, $osVersion)
    {
        $playRepo = $this->dm->getRepository(PlayDevice::class);
        $phoneRepo = $this->dm->getRepository(Phone::class);

        $names = [
            sprintf("%s %s", $manufacturer, $marketingName),
            $marketingName,
        ];
        $devices = [];
        $playDevices = $playRepo->createQueryBuilder()
            ->field('marketingName')->in($names)
            ->getQuery()->execute();
        foreach ($playDevices as $playDevice) {
            $devices[] = $playDevice->getDevice();
        }

        $phones = $phoneRepo->createQueryBuilder()
            ->field('devices')->in($devices)
            ->getQuery()->execute();
        foreach ($phones as $phone) {
            return $phone;
        }

        // Known issue where iphones are not detectable
        if ($manufacturer != "Apple" && $model != "iPhone") {
            $missing = sprintf("%s %s %s %s", $manufacturer, $marketingName, $model, $osVersion);
            $this->logger->warning(sprintf('Mobile web browser (Phone: %s) is not in our db (or device name mismatch)', $missing));
        }

        return $manufacturer;
    }
}
