<?php
namespace AppBundle\Classes;

use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;

class DaviesExcel
{
    protected function nullIfBlank($field)
    {
        if (!$field || $this->isNullableValue($field)) {
            return null;
        }

        return str_replace('£', '', trim($field));
    }

    protected function isNullableValue($value)
    {
        // possible values that Davies might use as placeholders
        // when a field is required by their system, but not yet known
        return in_array(trim($value), ['', 'Unknown', 'TBC', 'Tbc', 'tbc', '-', '0', 'N/A', 'n/a', 'NA', 'na']);
    }

    protected function excelDate($days, $skipEndCheck = false)
    {
        try {
            if (!$days || $this->isNullableValue($days)) {
                return null;
            }

            if (!is_numeric($days)) {
                // unfortunately davies is incapable of formatting dates
                // so may be an excel date or may be a d/m/Y formatted string
                $date = \DateTime::createFromFormat('d/m/Y', $days);
                if (!$date instanceof \DateTime) {
                    throw new \Exception('Unable to parse date');
                }
                $origin = $this->startOfDay($date);
            } else {
                $origin = new \DateTime("1900-01-01");
                $origin->add(new \DateInterval(sprintf('P%dD', $days - 2)));
            }

            $minDate = new \DateTime(SoSure::POLICY_START);
            $now = new \DateTime();

            if ($origin < $minDate || ($origin > $now && !$skipEndCheck)) {
                throw new \Exception(sprintf('Out of range for date %s', $origin->format(\DateTime::ATOM)));
            }

            return $origin;
        } catch (\Exception $e) {
            throw new \Exception(sprintf('Error creating date (days: %s), %s', $days, $e->getMessage()));
        }
    }
}
