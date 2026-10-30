<?php

namespace App\Console\Commands;

use App\Models\PaymentEmailDelivery;
use App\Support\LaunchModel;
use App\Support\TransactionalMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TransactionalMailStatus extends Command
{
    protected $signature = 'mail:status';

    protected $description = 'Show transactional email readiness and delivery counts without revealing configuration values';

    public function handle(TransactionalMail $mail): int
    {
        $issue = $mail->configurationIssue();
        $this->line('Configuration: '.($issue ?? 'ready (delivery not yet verified)'));
        if (LaunchModel::isFree()) {
            $this->line('Transfer outbox: paused for the free launch; not required for password reset.');

            return $issue ? self::FAILURE : self::SUCCESS;
        }
        try {
            if (! Schema::hasTable('payment_email_deliveries')) {
                $this->line('Outbox: migration pending');

                return self::FAILURE;
            }
            foreach (['pending', 'processing', 'sent', 'failed'] as $status) {
                $this->line($status.': '.PaymentEmailDelivery::where('status', $status)->count());
            }
        } catch (Throwable) {
            $this->error('Outbox: database unavailable');

            return self::FAILURE;
        }

        return $issue ? self::FAILURE : self::SUCCESS;
    }
}
