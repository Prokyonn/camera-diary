<?php

declare(strict_types=1);

namespace App\UserInterface\Form;

use App\Application\Individual\CropBox;
use InvalidArgumentException;
use Symfony\Component\Validator\Constraints as Assert;

final class ReferenceData
{
    public const string CROP_PATTERN = '/^(\d(?:\.\d+)?),(\d(?:\.\d+)?),(\d(?:\.\d+)?),(\d(?:\.\d+)?)$/';

    public function __construct(
        #[Assert\NotBlank]
        public ?string $individual = null,
        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $frame = 0,
        #[Assert\Regex(self::CROP_PATTERN, message: 'Drag a box on the frame again.')]
        public ?string $crop = null,
    ) {
    }

    /**
     * The dragged box ("x,y,width,height" in fractions of the frame); null for the whole frame.
     */
    public function cropBox(): ?CropBox
    {
        if (null === $this->crop || 1 !== preg_match(self::CROP_PATTERN, $this->crop, $box)) {
            return null;
        }

        return new CropBox((float) $box[1], (float) $box[2], (float) $box[3], (float) $box[4]);
    }

    #[Assert\IsTrue(message: 'The box must lie inside the frame.')]
    public function hasValidCrop(): bool
    {
        try {
            $this->cropBox();
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
