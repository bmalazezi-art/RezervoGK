<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class BankTransferDecisionMail extends Mailable
{
    public function __construct(public array $details) {}

    public function build(): static
    {
        $title = $this->details['decision'] === 'approved' ? __('Pagesa për qasjen publike u miratua') : __('Njoftimi i pagesës u refuzua');

        return $this->subject($title.' — Gjej portier')->view('mail.bank-decision')
            ->text('mail.bank-decision-text')->with([
                ...$this->details, 'title' => $title, 'preheader' => $title,
                'actionText' => __('Hap panelin'), 'actionUrl' => $this->details['dashboardUrl'],
            ]);
    }
}
