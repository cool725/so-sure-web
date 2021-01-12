<?php

namespace AppBundle\Document;

use InvalidArgumentException;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * Represents a policy reduced to a line in a policy report.
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ReportLineRepository")
 */
class ReportLine
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    private $id;

    /**
     * @MongoDB\Field(type="int")
     * @MongoDB\Index(unique=true)
     */
    private $number;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Policy", inversedBy="reportLines")
     * @var Policy
     */
    private $policy;

    /**
     * @Assert\Choice({"picsure","policy","scode"}, strict=true)
     * @MongoDB\Field(type="string")
     */
    private $report;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    private $date;

    /**
     * @MongoDB\Field(type="string")
     */
    private $content;

    /**
     * Gives you the subvariant's mongo id.
     * @return string the monbgo id as a string.
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Gives you the line's number.
     * @return number the number.
     */
    public function getNumber()
    {
        return $this->number;
    }

    /**
     * Sets the line's number.
     * @param number $number is the number to give to it.
     */
    public function setNumber($number)
    {
        $this->number = $number;
    }

    /**
     * Gives you the policy that this line is for.
     * @return Policy the policy.
     */
    public function getPolicy()
    {
        return $this->policy;
    }

    /**
     * Sets the policy that this line is for.
     * @param Policy $policy is the policy.
     */
    public function setPolicy($policy)
    {
        $this->policy = $policy;
    }

    /**
     * Gives you the report that this line is for.
     * @return string the name of the report.
     */
    public function getReport()
    {
        return $this->report;
    }

    /**
     * Sets the report that this line is for.
     * @param string $report is the name of the report type as seen in PolicyReport.
     */
    public function setReport($report)
    {
        $this->report = $report;
    }

    /**
     * Gives you the date that the report line was last set.
     * @return \DateTime the date.
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * Sets the date at which the report line was last set.
     * @param \DateTime $date is the date to set it to.
     */
    public function setDate($date)
    {
        $this->date = $date;
    }

    /**
     * Gives you the line's text.
     * @return string the content.
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * Sets the line's content.
     * @param string $content is the content to set it to.
     */
    public function setContent($content)
    {
        $this->content = $content;
    }
}
