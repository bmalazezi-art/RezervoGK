<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AlbanianLocalizationTest extends TestCase
{
    public function test_recovery_email_is_rendered_in_albanian_without_sending_mail(): void
    {
        app()->setLocale('sq');
        $mail = (new ResetPassword('test-only-placeholder'))->toMail(User::factory()->make(['email' => 'qa@example.test']));
        $this->assertSame('Njoftim për rivendosjen e fjalëkalimit', $mail->subject);
        $html = (string) $mail->render();
        foreach (['Përshëndetje!', 'Rivendos fjalëkalimin', 'skadon pas 60 minutash', 'Me respekt,', 'Nëse nuk mund të hapësh butonin', 'Të gjitha të drejtat e rezervuara.'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        foreach (['Hello!', 'Regards,', 'Reset Password', "If you're having trouble", 'All rights reserved.'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
    }

    public function test_validation_uses_albanian_messages_and_field_names(): void
    {
        app()->setLocale('sq');
        $validator = Validator::make(['status' => 'invalid', 'received_amount' => '6.99', 'decision' => 'rejected', 'bank_verified' => false], [
            'status' => ['in:pending,approved'], 'received_amount' => ['numeric', 'size:7'],
            'review_note' => ['required_if:decision,rejected'], 'bank_verified' => ['accepted'],
        ]);
        $this->assertSame('Zgjedhja për statusi nuk është e vlefshme.', $validator->errors()->first('status'));
        $this->assertSame('Fusha shuma e pranuar duhet të jetë 7.', $validator->errors()->first('received_amount'));
        $this->assertSame('Fusha shënimi i kontrollit është e detyrueshme për këtë zgjedhje.', $validator->errors()->first('review_note'));
        $this->assertSame('Duhet të konfirmosh fushën verifikimi bankar.', $validator->errors()->first('bank_verified'));
    }

    public function test_missing_page_has_albanian_text_and_document_language(): void
    {
        app()->setLocale('sq');
        $this->get('/test-only-missing-page')->assertNotFound()
            ->assertSee('Faqja nuk u gjet')->assertSee('<html lang="sq">', false)->assertDontSee('Not Found');
    }
}
