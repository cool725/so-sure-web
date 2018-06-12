<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
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
            ->add('when', HiddenType::class, ['required' => true])
            ->add('time', HiddenType::class, ['required' => true])
            ->add('where', HiddenType::class, ['required' => true])
            ->add('timeToReach', HiddenType::class, ['required' => true])
            ->add('signature', HiddenType::class, ['required' => true])
            ->add('type', HiddenType::class, ['required' => true])
            ->add('network', HiddenType::class, ['required' => true])
            ->add('message', HiddenType::class, ['required' => true])
            ->add('submit', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\ClaimFnol',
        ));
    }
}
