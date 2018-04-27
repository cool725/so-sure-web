<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Policy;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class BaseType extends AbstractType
{
    /**
     * @param FormInterface $form
     * @param Request|null  $request
     * @param string|null   $field
     * @param boolean       $setValueIfAbsent
     * @param mixed|null    $valueIfAbsent
     */
    protected function formQuerystring(
        FormInterface $form,
        Request $request = null,
        $field = null,
        $setValueIfAbsent = false,
        $valueIfAbsent = null
    ) {
        if (!$field) {
            throw new \Exception('field expected');
        }
        if ($request && $request->query->get($field)) {
            $form->get($field)->setData($request->query->get($field));
        } elseif ($setValueIfAbsent) {
            $form->get($field)->setData($valueIfAbsent);
        }
    }
}
