<?php

namespace PicsureMLBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use PicsureMLBundle\Document\TrainingData;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class PicsureMLSearchType extends AbstractType
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
            ->add('label', ChoiceType::class, [
                'required' => true,
                'multiple' => false,
                'expanded' => true,
                'choices' => [
                    'All' => null,
                    'None' => 'none',
                    'Undamaged' => TrainingData::LABEL_UNDAMAGED,
                    'Invalid' => TrainingData::LABEL_INVALID,
                    'Damaged' => TrainingData::LABEL_DAMAGED,
                ]
            ])
            ->add('images_per_page', IntegerType::class, [
                'required' => true,
                'attr' => array('min' => 1, 'placeholder' => 30)
            ])
            ->add('search', SubmitType::class)
        ;

        $currentRequest = $this->requestStack->getCurrentRequest();
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($currentRequest) {
            $form = $event->getForm();
            if ($currentRequest->query->get('label')) {
                $form->get('label')->setData($currentRequest->query->get('label'));
            }
            if ($currentRequest->query->get('images_per_page')) {
                $form->get('images_per_page')->setData($currentRequest->query->get('images_per_page'));
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
