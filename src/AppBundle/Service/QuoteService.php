<?php

namespace AppBundle\Service;

use AppBundle\Document\PlayDevice;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\Phone;
use AppBundle\Service\MailerService;

class QuoteService
{

    /** @var MailerService */
    protected $mailer;
    protected $dm;

    /**
     * @param MailerService   $mailer
     * @param DocumentManager $dm
     */
    public function __construct(MailerService $mailer, DocumentManager $dm)
    {
        $this->mailer = $mailer;
        $this->dm = $dm;
    }

    public function getQuotes($make, $device, $memory = null, $rooted = null, $ignoreMake = false)
    {
        // TODO: We should probably be checking make as well.  However, we need to analyize the data
        // See Phone::isSameMake()
        \AppBundle\Classes\NoOp::ignore([$make]);
        $repo = $this->dm->getRepository(Phone::class);
        $phones = $repo->findBy(['devices' => $device]);
        $anyActive = false;
        $anyRetired = false;
        $anyPricing = false;
        $memoryFound = null;

        if ($memory !== null) {
            $memoryFound = false;
        }
        foreach ($phones as $phone) {
            if ($phone->getActive()) {
                $anyActive = true;
            }
            if ($phone->shouldBeRetired()) {
                $anyRetired = true;
            }
            if ($phone->getCurrentPhonePrice() && $phone->getCurrentPhonePrice()->getYearlyGwp() > 0) {
                $anyPricing = true;
            }
            if ($memory !== null && $memory <= $phone->getMemory()) {
                $memoryFound = true;
            }
        }

        $deviceFound = count($phones) > 0 && $phones[0]->getMake() != "ALL";

        if (!$deviceFound || $memoryFound === false) {
            $this->unknownDevice($device, $memory);
        }

        if ($rooted) {
            $this->rootedDevice($device, $memory);
        }

        $differentMake = false;
        if ($deviceFound && !$phones[0]->isSameMake($make)) {
            $differentMake = true;
            if (!$ignoreMake) {
                $this->differentMake($phones[0], $make);
            }
        }

        return [
            'phones' => $phones,
            'deviceFound' => $deviceFound,
            'memoryFound' => $memoryFound,
            'differentMake' => $differentMake,
            'anyActive' => $anyActive,
            'anyRetired' => $anyRetired,
            'anyPricing' => $anyPricing,
        ];
    }


    /**
     * @param string $device
     * @param float  $memory
     *
     * @return boolean true if unknown device notification was sent
     */
    private function unknownDevice($device, $memory)
    {
        if (in_array($device, [
            "", "generic_x86", "generic_x86_64", "Simulator",
            "iPad4,4", "iPad5,2", "iPad5,3", "iPad5,4", "iPad6,7", "iPad6,8", "iPad Air", "iPad Air 2"
        ])) {
            return false;
        }

        $playDeviceRepo = $this->dm->getRepository(PlayDevice::class);
        $playDevice = $playDeviceRepo->findOneBy(['device' => $device]);
        $marketingName = ($playDevice) ? $playDevice->getMarketingName() : 'unknown';
        $body = sprintf(
            'Unknown device queried: %s (%s GB). If device exists, memory may be higher than expected.
            PlayDevice: %s',
            $device,
            $memory,
            $marketingName
        );

        $this->mailer->send(
            'Unknown Device/Memory',
            'analysis@so-sure.com',
            $body,
            null,
            null,
            null,
            'tech@so-sure.com',
            null
        );


        return true;
    }

    /**
     * @param Phone  $phone
     * @param string $phoneMake
     */
    private function differentMake(Phone $phone, $phoneMake)
    {
        $body = sprintf(
            'Make in db is different than phone make. Db: %s Phone: %s. DB details are enclosed: ObjectId("%s") %s',
            $phone->getMake(),
            $phoneMake,
            $phone->getId(),
            json_encode($phone->toApiArray())
        );

        $this->mailer->send(
            'Make different in db',
            'tech@so-sure.com',
            $body,
            null,
            null,
            null,
            'tech@so-sure.com',
            null
        );


    }

    /**
     * @param string $device
     * @param float  $memory
     */
    private function rootedDevice($device, $memory)
    {
        $body = sprintf(
            'Rooted device queried: %s (%s GB).',
            $device,
            $memory
        );

        $this->mailer->send(
            'Rooted Device/Memory',
            'tech@so-sure.com',
            $body,
            null,
            null,
            null,
            'tech@so-sure.com',
            null
        );

    }
    public function getMailer()
    {
        return $this->mailer;
    }

    public function setMailerMailer($mailer)
    {
        $this->mailer->setMailer($mailer);
    }
}
