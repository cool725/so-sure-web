<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Claim;
use AppBundle\Service\ReceperioService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;

class ClaimFnolConfirmType extends AbstractType
{

    /**
     * @var boolean
     */
    private $required;
 
    /**
     * @param boolean $required
     */
    public function __construct($required)
    {
        $this->required = $required;
    }
    
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('email', HiddenType::class, ['required' => true])
            ->add('name', HiddenType::class, ['required' => true])
            ->add('phone', HiddenType::class, ['required' => true])
            ->add('policyNumber', HiddenType::class, ['required' => true])
            ->add('time', HiddenType::class, ['required' => true])
            ->add('where', HiddenType::class, ['required' => true])
            ->add('timeToReach', HiddenType::class, ['required' => true])
            ->add('signature', HiddenType::class, ['required' => true])
            ->add('type', HiddenType::class, ['required' => true])
            ->add('network', HiddenType::class, ['required' => true])
            ->add('message', HiddenType::class, ['required' => true])
            ->add('checkTruthful', CheckboxType::class, ['required' => true])
            ->add('checkPermanent', CheckboxType::class, ['required' => true])
            ->add('submit', SubmitType::class)
        ;


        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $data = $event->getData();

            $form->add('when', HiddenType::class, [
                  'required' => $this->required,
                  'data'   => $data->getWhen(true),
            ]);
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $data = $event->getData();

            $when = $data->getWhen();

            $data->setWhen(new \DateTime($when));
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\ClaimFnol',
        ));
    }
}
