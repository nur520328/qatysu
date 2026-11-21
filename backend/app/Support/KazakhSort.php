<?php

namespace App\Support;

/**
 * Қазақ әліпбиі бойынша реттеу кілті. Дерекқордың реттеуі қазақ әріптерін (Ә, Ғ, Қ, Ң, Ө,
 * Ұ, Ү, Һ, І) әліпбидегі орнына қоймайды, ал сынып тізімі журналдағыдай ретпен шығуы тиіс.
 * Серверде `intl` кеңейтімі болмауы мүмкін, сондықтан кілт осында есептеледі.
 */
final class KazakhSort
{
    private const ALPHABET = 'аәбвгғдеёжзийкқлмнңоөпрстуұүфхһцчшщъыіьэюя';

    /** @var array<string, string>|null */
    private static ?array $map = null;

    public static function key(string $text): string
    {
        self::$map ??= self::buildMap();

        $key = '';
        foreach (mb_str_split(mb_strtolower(trim($text))) as $char) {
            // Әліпбиде жоқ таңбалар (латын, сан, тыныс белгісі) өз кодымен, кирилден бұрын тұрады.
            $key .= self::$map[$char] ?? '0'.bin2hex(mb_convert_encoding($char, 'UTF-32BE', 'UTF-8'));
        }

        return $key;
    }

    /**
     * @template T
     *
     * @param  iterable<T>  $items
     * @param  callable(T): list<string>  $fields  Реттеу өрістері маңыздылық ретімен.
     * @return list<T>
     */
    public static function sort(iterable $items, callable $fields): array
    {
        $decorated = [];
        foreach ($items as $item) {
            $decorated[] = [implode("\x00", array_map(self::key(...), $fields($item))), $item];
        }
        usort($decorated, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return array_column($decorated, 1);
    }

    /** @return array<string, string> */
    private static function buildMap(): array
    {
        $map = [];
        foreach (mb_str_split(self::ALPHABET) as $index => $char) {
            $map[$char] = sprintf('1%02d', $index);
        }

        return $map;
    }
}
