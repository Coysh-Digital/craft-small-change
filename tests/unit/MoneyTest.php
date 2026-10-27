<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\helpers\Money;

final class MoneyTest extends Unit
{
    /**
     * @dataProvider parseCases
     */
    public function testParse(mixed $input, string $currency, ?int $expected): void
    {
        $this->assertSame($expected, Money::parse($input, $currency));
    }

    /**
     * @return array<string, array{mixed, string, ?int}>
     */
    public static function parseCases(): array
    {
        return [
            'whole pounds' => ['10', 'GBP', 1000],
            'pounds and pence' => ['10.50', 'GBP', 1050],
            'one decimal place' => ['10.5', 'GBP', 1050],
            'currency symbol and separator' => ['£1,000.25', 'GBP', 100025],
            'padded with spaces' => [' 25 ', 'GBP', 2500],
            'trailing zeros beyond the exponent' => ['10.500', 'GBP', 1050],
            'too many real decimals' => ['10.505', 'GBP', null],
            'zero' => ['0', 'GBP', null],
            'zero with decimals' => ['0.00', 'GBP', null],
            'negative' => ['-5', 'GBP', null],
            'words' => ['ten', 'GBP', null],
            'empty' => ['', 'GBP', null],
            'null' => [null, 'GBP', null],
            'array' => [['10'], 'GBP', null],
            'scientific notation' => ['1e3', 'GBP', null],
            'comma as decimal mark is rejected' => ['10,50', 'GBP', null],
            'comma thousands' => ['1,000', 'GBP', 100000],
            'misplaced thousands comma' => ['10,00', 'GBP', null],
            'trailing comma' => ['10,', 'GBP', null],
            'above the Stripe maximum' => ['1000000', 'GBP', null],
            'at the Stripe maximum' => ['999999.99', 'GBP', 99999999],
            'integer input' => [5, 'GBP', 500],
            'zero-decimal currency' => ['500', 'JPY', 500],
            'zero-decimal currency rejects decimals' => ['500.5', 'JPY', null],
            'three-decimal currency' => ['1.234', 'KWD', 1234],
            'leading zeros' => ['007.50', 'GBP', 750],
            'absurdly long' => [str_repeat('9', 40), 'GBP', null],
        ];
    }

    /**
     * @dataProvider decimalCases
     */
    public function testToDecimal(int $minor, string $currency, string $expected): void
    {
        $this->assertSame($expected, Money::toDecimal($minor, $currency));
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function decimalCases(): array
    {
        return [
            'pounds' => [1050, 'GBP', '10.50'],
            'pence only' => [5, 'GBP', '0.05'],
            'zero' => [0, 'GBP', '0.00'],
            'zero-decimal' => [500, 'JPY', '500'],
            'three-decimal' => [1234, 'KWD', '1.234'],
            'negative' => [-250, 'GBP', '-2.50'],
        ];
    }

    public function testParseAndToDecimalRoundTrip(): void
    {
        foreach ([1, 99, 100, 101, 12345, 99999999] as $minor) {
            $this->assertSame($minor, Money::parse(Money::toDecimal($minor, 'GBP'), 'GBP'));
        }
    }

    public function testFormat(): void
    {
        $this->assertSame('£10.50', Money::format(1050, 'GBP'));
        $this->assertSame('£10', Money::format(1000, 'GBP', true));
    }
}
