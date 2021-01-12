<?php

namespace AppBundle\Classes;

use AppBundle\Document\Policy;
use AppBundle\Helpers\CsvHelper;
use Doctrine\ODM\MongoDB\DocumentManager;
use DateTimeZone;
use Exception;
use RuntimeException;

/**
 * Compiles a report on policies.
 */
abstract class PolicyReport
{
    const TYPE_POLICY = 'policy';
    const TYPE_PICSURE = 'picsure';
    const TYPE_SCODE = 'scode';

    /**
     * @var DocumentManager $dm
     */
    protected $dm;

    /**
     * @var DateTimeZone $tz
     */
    protected $tz;

    /**
     * Injects some dependencies.
     * @param DocumentManager $dm is used to get repositories.
     * @param DateTimeZone    $tz the timezone to put dates in.
     */
    public function __construct(DocumentManager $dm, DateTimeZone $tz)
    {
        $this->dm = $dm;
        $this->tz = $tz;
    }

    /**
     * Takes a policy and adds lines to the report with it.
     * @param Policy $policy is the policy to process.
     * @return string the processed version.
     */
    abstract public function process(Policy $policy);

    /**
     * Gives you a string of the headers the report should have.
     * @return array the column titles as seperate values.
     */
    abstract public function getHeaders();

    /**
     * Says the name of the file that the final report wants to be saved to.
     * @return string the filename.
     */
    abstract public function getFile();

    /**
     * Tells you the type of report that this is.
     * @return string the type.
     */
    abstract public function getType();

    /**
     * Creates a report based on string name.
     * @param string          $name is the name of the report to create.
     * @param DocumentManager $dm   is the document manager used.
     * @param DateTimeZone    $tz   is the timezone to do the report in.
     * @return PolicyReport|null the created report unless you gave a junk value in which case it's null.
     */
    public static function createReport(
        $name,
        DocumentManager $dm,
        DateTimeZone $tz
    ) {
        switch ($name) {
            case static::TYPE_POLICY:
                return new PolicyBiReport($dm, $tz);
            case static::TYPE_PICSURE:
                return new PolicyPicSureReport($dm, $tz);
            case static::TYPE_SCODE:
                return new PolicyScodeReport($dm, $tz);
            default:
                return null;
        }
    }
}
