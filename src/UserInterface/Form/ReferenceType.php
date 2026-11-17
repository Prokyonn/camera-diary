<?php

declare(strict_types=1);

namespace App\UserInterface\Form;

use App\Application\Settings\Individual;
use App\Application\Settings\SettingsProviderInterface;
use InvalidArgumentException;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ReferenceData>
 */
final class ReferenceType extends AbstractType
{
    public function __construct(
        private readonly SettingsProviderInterface $settings,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $individuals = $this->settings->settings()->individuals;
        $frameCount = $options['frame_count'] ?? null;
        if (!\is_int($frameCount) || $frameCount < 1) {
            throw new InvalidArgumentException('The reference form needs the number of frames.');
        }

        $builder
            ->add('frame', ChoiceType::class, [
                'expanded' => true,
                'choices' => array_combine(
                    array_map(static fn (int $index): string => \sprintf('Frame %d', $index + 1), range(0, $frameCount - 1)),
                    range(0, $frameCount - 1),
                ),
            ])
            ->add('individual', ChoiceType::class, [
                'label' => 'Who it is',
                'placeholder' => 'Choose',
                'choices' => array_combine(
                    array_map(static fn (Individual $individual): string => $individual->label, $individuals),
                    array_map(static fn (Individual $individual): string => $individual->name, $individuals),
                ),
            ])
            ->add('crop', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReferenceData::class]);
        $resolver->setRequired('frame_count');
        $resolver->setAllowedTypes('frame_count', 'int');
    }
}
