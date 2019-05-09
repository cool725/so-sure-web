<?php
namespace AppBundle\Annotation;

/**
 * @Annotation
 */
class DataChange
{
    const CATEGORY_SALVA_CLAIM = 'salva-claim';
    const CATEGORY_HUBSPOT = 'hubspot';
    const CATEGORY_INTERCOM = 'intercom';
    const CATEGORY_INVITATION_LINK = 'invitation-link';

    const COMPARE_EQUAL = 'equal';
    const COMPARE_CASE_INSENSITIVE = 'case-insensitive';
    const COMPARE_INCREASE = 'increase';
    const COMPARE_DECREASE = 'decrease';
    const COMPARE_OBJECT_EQUALS = 'object-equals';
    const COMPARE_OBJECT_SERIALIZE = 'object-serialize';
    const COMPARE_TO_NULL = 'to-null';
    const COMPARE_BACS = 'bacs';
    const COMPARE_JUDO = 'judo';
    const COMPARE_PAYMENT_METHOD_CHANGED = 'payment-method';

    /**
     * @Required
     *
     * @var string
     */
    public $categories;

    /**
     * @var string
     */
    public $comparison;

    public function getCategories()
    {
        return array_map('trim', explode(',', $this->categories));
    }

    public function getComparison()
    {
        return $this->comparison;
    }
}
