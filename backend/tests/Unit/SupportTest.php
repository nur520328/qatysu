<?php

namespace Tests\Unit;

use App\Support\ClassName;
use App\Support\KazakhSort;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_kazakh_alphabet_order(): void
    {
        $letters = mb_str_split('яіыһүұөңқғәа');
        $sorted = KazakhSort::sort($letters, static fn (string $l): array => [$l]);

        $this->assertSame('аәғқңөұүһыія', implode('', $sorted));
    }

    public function test_sort_ignores_case_and_orders_prefix_first(): void
    {
        $sorted = KazakhSort::sort(['Әлиев', 'али', 'Алиев', 'Али-Хан'], static fn (string $s): array => [$s]);

        $this->assertSame(['али', 'Али-Хан', 'Алиев', 'Әлиев'], $sorted);
    }

    public function test_class_name_parsing(): void
    {
        $name = ClassName::tryParse(' 10Ә ');
        $this->assertNotNull($name);
        $this->assertSame(10, $name->grade);
        $this->assertSame('Ә', $name->letter);
        $this->assertSame('10Ә', (string) $name);

        foreach (['7 В', '7в', '7', 'В7', '12А', '0А', '', '7ВВ'] as $bad) {
            $this->assertNull(ClassName::tryParse($bad), $bad);
        }
    }
}
