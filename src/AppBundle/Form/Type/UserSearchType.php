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

class UserSearchType extends AbstractType
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
            ->add('lastname', TextType::class, ['required' => false])
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
            ->add('search', SubmitType::class)
        ;

        $currentRequest = $this->requestStack->getCurrentRequest();
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($currentRequest) {
            $form = $event->getForm();
            $form->get('email')->setData($currentRequest->query->get('email'));
            $form->get('mobile')->setData($currentRequest->query->get('mobile'));
            $form->get('postcode')->setData($currentRequest->query->get('postcode'));
            $form->get('lastname')->setData($currentRequest->query->get('lastname'));
            $form->get('facebookId')->setData($currentRequest->query->get('facebookId'));
            $form->get('waitingSanctions')->setData($currentRequest->query->get('waitingSanctions'));
            $form->get('allSanctions')->setData($currentRequest->query->get('allSanctions'));
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'csrf_protection'   => false,
        ));
    }
}
