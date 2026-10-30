<?php

namespace App\Console\Commands;

use App\Models\PaymentEmailDelivery;
use App\Support\LaunchModel;
use App\Support\PaymentEmailSender;
use App\Support\TransactionalMail;
use Illuminate\Console\Command;
use Throwable;

class DeliverTransferEmails extends Command
{
    protected $signature = 'mail:deliver-transfers {--limit=50 : Maximum messages per run (1–500)} {--retry-failed : Requeue exhausted deliveries before sending}';

    protected $description = 'Deliver committed bank-transfer decisions without changing payments or subscriptions';

    public function handle(TransactionalMail $mail, PaymentEmailSender $sender): int
    {
        if (LaunchModel::isFree()) {
            $this->info('Transfer email delivery is paused for the free launch. No records changed.');

            return self::SUCCESS;
        }
        if ($issue = $mail->configurationIssue()) {
            $this->error('Transactional mail unavailable: '.$issue.'. No delivery attempted.');

            return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if (! $limit) {
            $this->error('Limit must be between 1 and 500.');

            return self::FAILURE;
        }
        try {
            if ($this->option('retry-failed')) {
                PaymentEmailDelivery::where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0,
                    'next_attempt_at' => null, 'reserved_until' => null, 'reservation' => null]);
            }
            $ids = PaymentEmailDelivery::whereIn('status', ['pending', 'processing'])
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('reserved_until')->orWhere('reserved_until', '<=', now()))
                ->orderBy('id')->limit($limit)->pluck('id');
            $counts = [];
            foreach ($ids as $id) {
                $result = $sender->deliver($id);
                $counts[$result] = ($counts[$result] ?? 0) + 1;
            }
            $this->info('Transfer email results: '.json_encode($counts ?: (object) []));

            return isset($counts['pending']) || isset($counts['failed']) || isset($counts['disabled']) ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            TransactionalMail::recordFailure('bank_decision_worker');
            $this->error('Email worker unavailable. Check configuration, migrations and sanitized application events.');

            return self::FAILURE;
        }
    }
}
