<?php
namespace AppBundle\Annotation;

/**
 * @Annotation
 */
class DataChange
{
    const CATEGORY_SALVA_CLAIM = 'salva-claim';

    /**
     * @Required
     *
     * @var string
     */
    public $categories;

    public function getCategories()
    {
        return array_map('trim', explode(',', $this->categories));
    }
}
