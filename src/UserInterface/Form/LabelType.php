<?php

declare(strict_types=1);

namespace App\UserInterface\Form;

use App\Application\Settings\Individual;
use App\Application\Settings\SettingsProviderInterface;
use App\Application\Settings\Term;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Verdict;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<LabelData>
 */
final class LabelType extends AbstractType
{
    public function __construct(
        private readonly SettingsProviderInterface $settings,
    ) {
    }

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
            ])
            ->add('falseAlarmCauseOverride', ChoiceType::class, [
                'required' => false,
                'label' => 'Cause of a false alarm',
                'placeholder' => 'Keep the AI\'s cause',
                'choices' => array_combine(
                    array_map(static fn (Term $cause): string => $cause->label, $this->settings->settings()->falseAlarmCauses->terms),
                    array_map(static fn (Term $cause): string => $cause->name, $this->settings->settings()->falseAlarmCauses->terms),
                ),
            ]);

        $individuals = $this->settings->settings()->individuals;
        if ([] !== $individuals) {
            $builder->add('individualOverride', ChoiceType::class, [
                'required' => false,
                'label' => 'Who',
                'placeholder' => 'Keep the AI\'s answer',
                'choices' => [
                    ...array_combine(
                        array_map(static fn (Individual $individual): string => $individual->label, $individuals),
                        array_map(static fn (Individual $individual): string => $individual->name, $individuals),
                    ),
                    'Nobody we know' => CameraEvent::NO_KNOWN_INDIVIDUAL,
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LabelData::class]);
    }
}
