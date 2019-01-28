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
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Policy;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class UserSearchType extends BaseType
{
    /**
     * @var RequestStack
     */
    private $requestStack;

    private $environment;

    /**
     * @param RequestStack $requestStack
     */
    public function __construct(RequestStack $requestStack, $environment)
    {
        $this->requestStack = $requestStack;
        $this->environment = $environment;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('email', TextType::class, ['required' => false])
            ->add('mobile', TextType::class, ['required' => false])
            ->add('postcode', TextType::class, ['required' => false])
            ->add('firstname', TextType::class, ['required' => false])
            ->add('lastname', TextType::class, ['required' => false])
            ->add('dob', TextType::class, ['required' => false])
            ->add('facebookId', TextType::class, ['required' => false])
            ->add('sosure', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'Facebook' => [
                        'Patrick (Global?)' => '10153878106240169',
                        'Patrick (App Scoped?)' => '10153899912245169',
                    ]
                ]
            ])
            ->add('waitingSanctions', CheckboxType::class, ['required' => false])
            ->add('allSanctions', CheckboxType::class, ['required' => false])
            ->add('id', TextType::class, ['required' => false, 'label'=>'ID (User object id)'])
            ->add('search', SubmitType::class)
        ;

        $currentRequest = $this->requestStack->getCurrentRequest();
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($currentRequest) {
            $form = $event->getForm();
            $this->formQuerystring($form, $currentRequest, 'email');
            $this->formQuerystring($form, $currentRequest, 'mobile');
            $this->formQuerystring($form, $currentRequest, 'postcode');
            $this->formQuerystring($form, $currentRequest, 'firstname');
            $this->formQuerystring($form, $currentRequest, 'lastname');
            $this->formQuerystring($form, $currentRequest, 'dob');
            $this->formQuerystring($form, $currentRequest, 'facebookId');
            $this->formQuerystring($form, $currentRequest, 'waitingSanctions');
            $this->formQuerystring($form, $currentRequest, 'allSanctions');
            $this->formQuerystring($form, $currentRequest, 'id');
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'csrf_protection'   => false,
        ));
    }
}
