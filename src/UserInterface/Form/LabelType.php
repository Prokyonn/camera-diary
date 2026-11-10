<?php

declare(strict_types=1);

namespace App\UserInterface\Form;

use App\Domain\Model\Verdict;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<LabelData>
 */
final class LabelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'required' => false,
                'help' => 'Who or what this is, e.g. "Luna" or "the postman". Search ranks labels first.',
                'attr' => ['maxlength' => 120, 'autocomplete' => 'off'],
            ])
            ->add('verdictOverride', EnumType::class, [
                'class' => Verdict::class,
                'required' => false,
                'label' => 'Verdict',
                'placeholder' => 'Keep the AI\'s verdict',
                'choice_label' => static fn (Verdict $verdict): string => $verdict->label(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LabelData::class]);
    }
}
