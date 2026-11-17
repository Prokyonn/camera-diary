<?php

declare(strict_types=1);

namespace App\Application\Settings;

/**
 * A configured word list, in the order of the settings.
 */
final readonly class Vocabulary
{
    /**
     * @param list<Term> $terms
     */
    public function __construct(
        public array $terms,
    ) {
    }

    public function has(string $name): bool
    {
        return \in_array($name, $this->names(), true);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (Term $term): string => $term->name, $this->terms);
    }

    /**
     * The label of a term; names no longer in the settings, e.g. on older events, read as words.
     */
    public function label(string $name): string
    {
        foreach ($this->terms as $term) {
            if ($term->name === $name) {
                return $term->label;
            }
        }

        return self::humanize($name);
    }

    public static function humanize(string $name): string
    {
        return ucfirst(str_replace('_', ' ', $name));
    }
}
