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
use AppBundle\Document\PhonePolicy;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class PicSureSearchType extends BaseType
{
    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @param RequestStack $requestStack
     */
    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('status', ChoiceType::class, [
                'required' => true,
                'multiple' => false,
                'expanded' => false,
                'choices' => [
                    PhonePolicy::PICSURE_STATUS_MANUAL => PhonePolicy::PICSURE_STATUS_MANUAL,
                    PhonePolicy::PICSURE_STATUS_INVALID => PhonePolicy::PICSURE_STATUS_INVALID,
                    PhonePolicy::PICSURE_STATUS_APPROVED => PhonePolicy::PICSURE_STATUS_APPROVED,
                    PhonePolicy::PICSURE_STATUS_REJECTED => PhonePolicy::PICSURE_STATUS_REJECTED,
                ]
            ])
            ->add('search', SubmitType::class)
        ;

        $currentRequest = $this->requestStack->getCurrentRequest();
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($currentRequest) {
            $form = $event->getForm();
            if ($currentRequest && $currentRequest->query->get('status')) {
                $this->formQuerystring($form, $currentRequest, 'status');
            } else {
                $form->get('status')->setData(PhonePolicy::PICSURE_STATUS_MANUAL);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'csrf_protection'   => false,
        ));
    }
}
