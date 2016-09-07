<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Classes\Salva;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PhonePolicyRepository")
 * @Gedmo\Loggable
 */
class SalvaPhonePolicy extends PhonePolicy
{
    /** @MongoDB\Field(type="hash") */
    protected $salvaPolicyNumbers = array();

    /** @MongoDB\Field(type="hash") */
    protected $salvaPolicyResults = array();

    public function getSalvaPolicyNumbers()
    {
        return $this->salvaPolicyNumbers;
    }

    public function getSalvaPolicyResults()
    {
        return $this->salvaPolicyResults;
    }

    public function addSalvaPolicyResults($responseId, $cancel)
    {
        $key = sprintf('%d-create', $this->getLatestSalvaPolicyNumberVersion());
        if ($cancel) {
            $key = sprintf('%d-cancel', $this->getLatestSalvaPolicyNumberVersion() - 1);
        }
        $this->salvaPolicyResults[$key] = serialize(['responseId' => $responseId, 'time' => new \DateTime()]);
    }

    public function getLatestSalvaPolicyNumberVersion()
    {
        return count($this->salvaPolicyNumbers) + 1;
    }

    public function getSalvaPolicyNumberByDate(\DateTime $date)
    {
        return $this->getSalvaPolicyNumber($this->getSalvaVersion($date));
    }

    public function getSalvaPolicyNumber($version = null)
    {
        if (!$this->getPolicyNumber()) {
            return null;
        }
        if (!$version) {
            $version = $this->getLatestSalvaPolicyNumberVersion();
        }

        return sprintf("%s/%d", $this->getPolicyNumber(), $version);
    }

    public function getLatestSalvaStartDate()
    {
        return $this->getSalvaStartDate($this->getLatestSalvaPolicyNumberVersion());
    }

    public function getSalvaStartDate($version = null)
    {
        if (!$version) {
            $version = count($this->getSalvaPolicyNumbers());
        } else {
            $version = $version - 1;
        }

        if (!isset($this->getSalvaPolicyNumbers()[$version])) {
            return $this->getStart();
        }

        return new \DateTime($this->getSalvaPolicyNumbers()[$version]);
    }

    public function getSalvaTerminationDate($version = null)
    {
        if (!$version) {
            return null;
        }

        return new \DateTime($this->getSalvaPolicyNumbers()[$version]);
    }
    
    public function incrementSalvaPolicyNumber(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $this->salvaPolicyNumbers[$this->getLatestSalvaPolicyNumberVersion()] = $date->format(\DateTime::ATOM);
    }

    public function getSalvaVersion(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $versions = $this->getSalvaPolicyNumbers();
        ksort($versions);
        foreach ($versions as $version => $versionDate) {
            if (new \DateTime($versionDate) > $date) {
                return $version;
            }
        }

        // Current version null
        return null;
    }

    public function getPaymentsForSalvaVersions($multiArray = true)
    {
        $payments = [];
        $flatPayments = [];
        $paymentsUsed = [];
        $salvaPolicyNumbers = $this->getSalvaPolicyNumbers();
        foreach ($salvaPolicyNumbers as $version => $versionDate) {
            $payments[$version] = [];
            foreach ($this->getPayments() as $payment) {
                if ($payment->isSuccess()) {
                    if ($payment->getDate() < new \DateTime($versionDate) &&
                        !in_array($payment->getId(), $paymentsUsed)) {
                        $paymentsUsed[] = $payment->getId();
                        $payments[$version][] = $payment;
                        $flatPayments[] = $payment;
                    }
                }
            }
        }

        if ($multiArray) {
            return $payments;
        } else {
            return $flatPayments;
        }
    }
}
