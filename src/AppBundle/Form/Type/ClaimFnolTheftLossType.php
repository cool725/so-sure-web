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
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Claim;
use AppBundle\Service\ReceperioService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;

class ClaimFnolTheftLossType extends AbstractType
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
            ->add('hasContacted', ChoiceType::class, [
                'required' => true,
                'placeholder' => '',
                'choices' => [
                    'contacted' => 1,
                    'did not contact' => 0,
                ],
            ])
            ->add('contactedPlace', TextType::class, ['required' => true])
            ->add('blockedDate', DateType::class, [
                  'required' => $this->required,
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => array(
                      'year' => 'YYYY', 'month' => 'MM', 'day' => 'DD',
                  ),
            ])
            ->add('reportedDate', DateType::class, [
                  'required' => $this->required,
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => array(
                      'year' => 'YYYY', 'month' => 'MM', 'day' => 'DD',
                  ),
            ])
            ->add('reportType', ChoiceType::class, [
                'required' => true,
                'placeholder' => '',
                'choices' => [
                    'police station' => Claim::REPORT_POLICE_STATION,
                    'online' => Claim::REPORT_ONLINE,
                ],
            ])
            ->add('proofOfUsage', FileType::class)
            ->add('proofOfBarring', FileType::class)
            ->add('proofOfPurchase', FileType::class)
            ->add('crimeReferenceNumber', TextType::class, ['required' => true])
            ->add('policeLossReport', TextType::class, ['required' => true])
            ->add('confirm', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $claim = $event->getData()->getClaim();

        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\ClaimFnolTheftLoss',
        ));
    }
}
