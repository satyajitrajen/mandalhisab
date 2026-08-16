<?php

namespace Tests\Unit;

use App\Helpers\CsvSanitizer;
use PHPUnit\Framework\TestCase;

class CsvSanitizerTest extends TestCase
{
    public function test_neutralizes_formula_injection_characters(): void
    {
        $this->assertSame('"\'=SUM(A1:A9)"', CsvSanitizer::cell('=SUM(A1:A9)'));
        $this->assertSame('"\'+123"', CsvSanitizer::cell('+123'));
        $this->assertSame('"\'@import evil"', CsvSanitizer::cell('@import evil'));
        $this->assertSame('"\'-100"', CsvSanitizer::cell('-100'));
    }

    public function test_plain_values_are_untouched(): void
    {
        $this->assertSame('"Ramesh Patil"', CsvSanitizer::cell('Ramesh Patil'));
        $this->assertSame('"9876543210"', CsvSanitizer::cell('9876543210'));
    }

    public function test_double_quotes_are_escaped(): void
    {
        $this->assertSame('"He said ""hello"""', CsvSanitizer::cell('He said "hello"'));
    }

    public function test_null_and_empty_values(): void
    {
        $this->assertSame('""', CsvSanitizer::cell(null));
        $this->assertSame('""', CsvSanitizer::cell(''));
    }

    public function test_formula_prefix_only_applied_to_leading_character(): void
    {
        $this->assertSame('"a=b"', CsvSanitizer::cell('a=b'));
    }
}