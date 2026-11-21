<?php

namespace App\Support;

/**
 * Сынып атауы: параллель нөмірі мен бас әріп, арасында бос орынсыз (`7В`, `10Ә`).
 * Мектеп файлдарында дәл осылай жазылады; литерасыз атау қабылданбайды.
 */
final readonly class ClassName
{
    public const MIN_GRADE = 1;

    public const MAX_GRADE = 11;

    private function __construct(public int $grade, public string $letter) {}

    public static function tryParse(string $value): ?self
    {
        if (preg_match('/\A(\d{1,2})(\p{Lu})\z/u', trim($value), $m) !== 1) {
            return null;
        }
        $grade = (int) $m[1];
        if ($grade < self::MIN_GRADE || $grade > self::MAX_GRADE) {
            return null;
        }

        return new self($grade, $m[2]);
    }

    public function __toString(): string
    {
        return $this->grade.$this->letter;
    }
}
