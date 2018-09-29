<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;

trait SixpackFormTrait
{
    public function setFormAction(FormBuilderInterface $builder, RequestStack $requestStack)
    {
        $currentRequest = $requestStack->getCurrentRequest();
        if (!$currentRequest) {
            return;
        }
        
        $force = $currentRequest->query->get('force');
        if ($force) {
            $action = $builder->getAction();
            if (!$action) {
                $action = $currentRequest->getRequestUri();
            }
            if (!mb_stripos('force', $action) === false) {
                if (mb_stripos('?', $action) !== false) {
                    $action = sprintf('%s&force=%s', $action, $force);
                } else {
                    $action = sprintf('%s?force=%s', $action, $force);
                }
            }
            $builder->setAction($action);
        }
    }
}
