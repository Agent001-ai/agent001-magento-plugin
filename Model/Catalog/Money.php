<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Catalog;

use NumberFormatter;
use UnexpectedValueException;

class Money
{
    /**
     * @var array<string, int>
     */
    private array $digits = [];

    /**
     * Format an amount with the precision of its currency.
     *
     * @param float $amount
     * @param string $currency
     */
    public function format(float $amount, string $currency): string
    {
        return number_format(
            num: $amount,
            decimals: $this->digits[$currency] ??= $this->fractionDigits(currency: $currency),
            decimal_separator: '.',
            thousands_separator: '',
        );
    }

    /**
     * Return the number of fraction digits of a currency.
     *
     * @param string $currency
     */
    private function fractionDigits(string $currency): int
    {
        $formatter = new NumberFormatter(locale: 'en_US', style: NumberFormatter::CURRENCY);
        if (!$formatter->setTextAttribute(attribute: NumberFormatter::CURRENCY_CODE, value: $currency)) {
            throw new UnexpectedValueException(message: 'Invalid currency.');
        }
        return (int) $formatter->getAttribute(attribute: NumberFormatter::FRACTION_DIGITS);
    }
}
