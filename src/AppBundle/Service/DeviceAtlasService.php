<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Document\PlayDevice;
use AppBundle\Document\Phone;

class DeviceAtlasService
{
    const KEY_MISSING = 'deviceatlas:missing';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    protected $redis;

    /** @var MailerService */
    protected $mailerService;

    /**
     * @param LoggerInterface $logger
     * @param DocumentManager $dm
     * @param                 $redis
     * @param MailerService   $mailerService
     */
    public function __construct(
        LoggerInterface $logger,
        DocumentManager $dm,
        $redis,
        $mailerService
    ) {
        $this->logger = $logger;
        $this->dm = $dm;
        $this->redis = $redis;
        $this->mailerService = $mailerService;
    }

    /**
     * @param Request $request
     *
     * @return Phone|null
     */
    public function getPhone(Request $request)
    {
        $userAgent = $request->headers->get('User-Agent');

        return null;

        try {
            // $result = DeviceAtlasCloudClient::getDeviceData($userAgent);
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
        $marketingName = isset($result['properties']['marketingName']) ? $result['properties']['marketingName'] : null;

        return $this->getPhoneFromDetails($manufacturer, $marketingName, $model, $osVersion);
    }

    /**
     * @param Request $request
     *
     * @return bool|null
     */
    public function isMobile(Request $request)
    {
        $userAgent = $request->headers->get('User-Agent');

        return null;
        try {
            // $result = DeviceAtlasCloudClient::getDeviceData($userAgent);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());

            return null;
        }

        if (!isset($result['properties']) ||
            !isset($result['properties']['isMobilePhone'])) {
            return null;
        }

        if ($result['properties']['isMobilePhone'] == 1) {
            return true;
        }

        return false;
    }

    public function getMissing()
    {
        $items = [];
        while (($item = $this->redis->lpop(self::KEY_MISSING)) != null) {
            $items[] = $item;
        }

        return $items;
    }

    public function sendAll()
    {
        $html = implode('<br>', $this->getMissing());
        if (!$html) {
            $html = 'No missing browsers';
        }
        $this->mailerService->send('Mobile browsers not in db', 'analysis@so-sure.com', $html);
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

        if ($marketingName && strlen($marketingName) > 0) {
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
        }

        // Known issue where iphones are not detectable
        if ($manufacturer != "Apple" && $model != "iPhone") {
            $missing = sprintf("%s %s %s %s", $manufacturer, $marketingName, $model, $osVersion);
            $this->redis->rpush(self::KEY_MISSING, $missing);
            $this->logger->debug(sprintf(
                'Mobile web browser (Phone: %s) is not in our db (or device name mismatch)',
                $missing
            ));
        }

        return $manufacturer;
    }
}
