<?php

namespace App\Support;

class BankTransfer
{
    public const AMOUNT_CENTS = 700;

    public const DURATION_SECONDS = 30 * 24 * 60 * 60;

    public static function details(): array
    {
        $bank = config('payments.bank');

        return ['enabled' => (bool) $bank['enabled'], 'beneficiary' => trim((string) $bank['beneficiary']),
            'bankName' => trim((string) $bank['bankName']), 'account' => trim((string) $bank['account']), 'bic' => trim((string) $bank['bic'])];
    }

    public static function configured(): bool
    {
        if (LaunchModel::isFree()) {
            return false;
        }
        $bank = self::details();

        return $bank['enabled'] && $bank['beneficiary'] !== '' && $bank['bankName'] !== '' && $bank['account'] !== '';
    }
}
