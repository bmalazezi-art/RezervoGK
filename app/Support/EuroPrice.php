<?php

namespace App\Support;

class EuroPrice
{
    /** Convert an already validated decimal amount without floating-point arithmetic. */
    public static function cents(string $amount): int
    {
        [$euros, $cents] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $euros * 100) + (int) str_pad($cents, 2, '0');
    }
}
