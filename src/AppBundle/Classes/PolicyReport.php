<?php

namespace AppBundle\Classes;

use AppBundle\Document\Policy;
use AppBundle\Helpers\CsvHelper;
use CensusBundle\Service\SearchService;
use Doctrine\ODM\MongoDB\DocumentManager;
use DateTimeZone;
use Exception;
use RuntimeException;

/**
 * Compiles a report on policies.
 */
abstract class PolicyReport
{
    /**
     * @var SearchService $searchService
     */
    protected $searchService;

    /**
     * @var DocumentManager $dm
     */
    protected $dm;

    /**
     * @var DateTimeZone $tz
     */
    protected $tz;

    /**
     * @var array $lines;
     */
    private $lines;

    /**
     * @var number $columns
     */
    private $columns;

    /**
     * Injects some dependencies.
     * @param SearchService   $searchService is used to get the locations of users and stuff.
     * @param DocumentManager $dm            is used to get repositories.
     * @param DateTimeZone    $tz            the timezone to put dates in.
     */
    public function __construct(SearchService $searchService, DocumentManager $dm, DateTimeZone $tz)
    {
        $header = $this->getHeaders();
        $this->lines = [CsvHelper::line(...$header)];
        $this->columns = count($header);
        $this->searchService = $searchService;
        $this->dm = $dm;
        $this->tz = $tz;
    }

    /**
     * Gives you all the lines currently in the report in order starting with the header.
     * @return array of all the lines, with each line being a string.
     */
    public function getLines()
    {
        return $this->lines;
    }

    /**
     * Takes a policy and adds lines to the report with it.
     * @param Policy $policy is the policy to process.
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
     * Creates a report based on string name.
     * @param string          $name          is the name of the report to create.
     * @param SearchService   $searchService is the search service used.
     * @param DocumentManager $dm            is the document manager used.
     * @param DateTimeZone    $tz            is the timezone to do the report in.
     * @return PolicyReport|null the created report unless you gave a junk value in which case it's null.
     */
    public static function createReport(
        $name,
        SearchService $searchService,
        DocumentManager $dm,
        DateTimeZone $tz
    ) {
        switch ($name) {
            case 'policy':
                return new PolicyBiReport($searchService, $dm, $tz);
            case 'picsure':
                return new PolicyPicSureReport($searchService, $dm, $tz);
            case 'scode':
                return new PolicyScodeReport($searchService, $dm, $tz);
            default:
                return null;
        }
    }

    /**
     * Adds a policy to the report. There is not a complete guarantee that this policy will appear in the final report
     * as the report can skip it if it does not think that it is relevant.
     * @param mixed ...$column is each of the columns to add.
     */
    protected function add(...$column)
    {
        // Column is the varargs name so the standards are too dumb to understand it serves a purpose.
        NoOp::ignore($column);
        $args = func_get_args();
        if (count($args) != $this->columns) {
            throw new RuntimeException(sprintf(
                'Invalid line \'%s\' given for report \'%s\'',
                CsvHelper::line(...$args),
                $this->getFile()
            ));
        }
        $this->lines[] = CsvHelper::line(...$args);
    }
}
