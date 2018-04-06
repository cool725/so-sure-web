<?php
namespace AppBundle\Classes;

use AppBundle\Document\Claim;
use AppBundle\Document\DateTrait;

class DaviesExcel
{
    use DateTrait;

    protected function nullIfBlank($field, $fieldName = null, $ref = null)
    {
        if (!$field || $this->isNullableValue($field)) {
            return null;
        } elseif ($this->isUnobtainableValue($field)) {
            if ($fieldName && $ref) {
                $ref->unobtainableFields[] = $fieldName;
            }

            return null;
        }

        return str_replace('£', '', trim($field));
    }

    protected function isSuspicious($field)
    {
        if (!$field || $this->isNullableValue($field)) {
            return null;
        }

        if (in_array(mb_strtolower($field), ['ok'])) {
            return false;
        } elseif (in_array(mb_strtolower($field), ['suspicious'])) {
            return true;
        }

        return null;
    }

    protected function isNullableValue($value)
    {
        // possible values that Davies might use as placeholders
        // when a field is required by their system, but not yet known
        return in_array(trim($value), ['', 'Unknown', 'TBC', 'Tbc', 'tbc', '-', '0',
            'N/A', 'n/a', 'NA', 'na', '#N/A', 'Not Applicable']);
    }

    protected function isUnobtainableValue($value)
    {
        // possible values that Davies might use as placeholders
        // when a field is required by their system, but data will never be provided
        return in_array(trim(mb_strtolower($value)), ['unable to obtain']);
    }

    /**
     * @param mixed   $days           int (excel number of days from 1/1/1900) or string date 31/1/2016
     * @param boolean $skipEndCheck   future dates are normally disallowed except for policy end date
     * @param boolean $nullIfTooEarly davies using 1/1/2001 (or probably other) dates to indicate null
     */
    protected function excelDate($days, $skipEndCheck = false, $nullIfTooEarly = false)
    {
        try {
            if (!$days || $this->isNullableValue($days) || $this->isUnobtainableValue($days)) {
                return null;
            }

            if (!is_numeric($days)) {
                $days = str_replace("\\", "", $days);
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

            if ($nullIfTooEarly && $origin < $minDate) {
                return null;
            }

            if ($origin < $minDate || ($origin > $now && !$skipEndCheck)) {
                throw new \Exception(sprintf('Out of range for date %s', $origin->format(\DateTime::ATOM)));
            }

            return $origin;
        } catch (\Exception $e) {
            throw new \Exception(sprintf('Error creating date (days: %s), %s', $days, $e->getMessage()));
        }
    }
}
