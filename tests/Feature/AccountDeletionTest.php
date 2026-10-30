<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\AdminReportAudit;
use App\Models\GoalkeeperProfile;
use App\Models\ManualPayment;
use App\Models\PaymentAudit;
use App\Models\Subscription;
use App\Support\AccountDeletionFileCleanup;
use App\Models\User;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function goalkeeper(): array
    {
        $this->seed(GoalkeeperDemoSeeder::class);
        Storage::fake('public'); Storage::fake('receipts');
        $user = User::factory()->create();
        $profile = $user->goalkeeperProfile()->create(['name'=>'Delete Me','slug'=>'delete-me','city_id'=>City::firstOrFail()->id,'bio'=>'bio','price_cents'=>1000,'photo_url'=>'/keeper-placeholder.svg','languages'=>['Shqip']]);
        return [$user,$profile];
    }

    public function test_deletion_anonymizes_payment_history_and_removes_profile_and_user(): void
    {
        [$user,$profile]=$this->goalkeeper();
        $receiptPath='123e4567-e89b-12d3-a456-426614174003.jpg';
        $payment=ManualPayment::create(['user_id'=>$user->id,'reference'=>'REF-DELETE','amount_cents'=>1200,'currency'=>'EUR','duration_seconds'=>100,'status'=>'approved','bank_snapshot'=>['beneficiary'=>'Personal'],'sender_note'=>'my name','receipt_path'=>$receiptPath,'receipt_mime'=>'image/jpeg','bank_transaction_reference'=>'BANK-REF']);
        $subscription=Subscription::create(['user_id'=>$user->id,'manual_payment_id'=>$payment->id,'starts_at'=>now(),'ends_at'=>now()->addMonth()]);
        $admin=User::factory()->create(['is_admin'=>true]);
        $audit=PaymentAudit::create(['manual_payment_id'=>$payment->id,'actor_id'=>$admin->id,'event'=>'approved','metadata'=>['from'=>'pending','to'=>'approved','email'=>'private@example.test'],'created_at'=>now()]);
        $ownerAudit=PaymentAudit::create(['manual_payment_id'=>$payment->id,'actor_id'=>$user->id,'event'=>'submitted','metadata'=>['from'=>'draft','to'=>'pending','sender'=>'private'],'created_at'=>now()]);
        $photoPath='keeper-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
        \Illuminate\Support\Facades\DB::table('goalkeeper_profiles')->where('id',$profile->id)->update(['photo_path'=>$photoPath]);
        Storage::disk('public')->put($photoPath,'photo');
        Storage::disk('receipts')->put($receiptPath,'receipt');
        \Illuminate\Support\Facades\DB::table('goalkeeper_profile_reports')->insert(['goalkeeper_profile_id'=>$profile->id,'reason'=>'fake_profile','details'=>'private reporter detail','status'=>'open','created_at'=>now(),'updated_at'=>now()]);
        $this->actingAs($user)->delete('/account',['confirmation'=>true])->assertRedirect('/')->assertSessionHas('status');
        $this->assertDatabaseMissing('users',['id'=>$user->id]); $this->assertDatabaseMissing('goalkeeper_profiles',['id'=>$profile->id]);
        $this->assertDatabaseHas('manual_payments',['id'=>$payment->id,'user_id'=>null,'amount_cents'=>1200,'status'=>'approved','sender_note'=>null,'review_note'=>null,'bank_transaction_reference'=>null,'receipt_path'=>null]);
        $this->assertDatabaseHas('payment_audits',['id'=>$audit->id,'actor_id'=>$admin->id]);
        $this->assertSame(['from'=>'pending','to'=>'approved'],$audit->fresh()->metadata);
        $this->assertDatabaseHas('payment_audits',['id'=>$ownerAudit->id,'actor_id'=>null,'event'=>'submitted']);
        $this->assertSame(['from'=>'draft','to'=>'pending'],$ownerAudit->fresh()->metadata);
        $this->assertDatabaseHas('subscriptions',['id'=>$subscription->id,'user_id'=>null,'manual_payment_id'=>$payment->id]);
        $this->assertDatabaseMissing('goalkeeper_profile_reports',['goalkeeper_profile_id'=>$profile->id]);
        $this->assertSame([], \Illuminate\Support\Facades\DB::select('PRAGMA foreign_key_check'));
        $this->assertFalse(Storage::disk('public')->exists($photoPath));
        $this->assertFalse(Storage::disk('receipts')->exists($receiptPath)); $this->assertGuest();
    }

    public function test_account_deletion_preserves_report_moderation_audits_without_retaining_report_foreign_keys(): void
    {
        [$user, $profile] = $this->goalkeeper();
        $report = $profile->reports()->create(['reason' => 'inaccurate', 'details' => 'Account deletion audit test', 'status' => 'reviewed']);
        $admin = User::factory()->create(['is_admin' => true]);
        $audit = AdminReportAudit::create([
            'goalkeeper_profile_report_id' => $report->id,
            'actor_id' => $admin->id,
            'event' => 'status_changed',
            'from_status' => 'open',
            'to_status' => 'reviewed',
            'note' => 'Review completed with no policy concern.',
            'created_at' => now(),
        ]);

        $this->actingAs($user)->delete('/account', ['confirmation' => true])->assertRedirect('/');

        $this->assertDatabaseMissing('goalkeeper_profile_reports', ['id' => $report->id]);
        $this->assertDatabaseHas('admin_report_audits', [
            'id' => $audit->id,
            'goalkeeper_profile_report_id' => null,
            'actor_id' => $admin->id,
            'event' => 'status_changed',
        ]);
    }

    public function test_deletion_requires_confirmation_and_rejects_admins_and_users_without_profiles(): void
    {
        [$user]=$this->goalkeeper();
        $this->actingAs($user)->delete('/account')->assertSessionHasErrors('confirmation');
        $this->assertDatabaseHas('users',['id'=>$user->id]);
        $admin=User::factory()->create(['is_admin'=>true]);
        $this->actingAs($admin)->delete('/account',['confirmation'=>true])->assertForbidden();
        $other=User::factory()->create();
        $this->actingAs($other)->delete('/account',['confirmation'=>true])->assertForbidden();
    }

    public function test_failure_inside_deletion_transaction_rolls_back_account_and_retention_changes(): void
    {
        [$user,$profile]=$this->goalkeeper();
        $payment=ManualPayment::create(['user_id'=>$user->id,'reference'=>'REF-ROLLBACK','amount_cents'=>900,'currency'=>'EUR','duration_seconds'=>90,'status'=>'pending','bank_snapshot'=>['account'=>'secret'],'sender_note'=>'private']);
        GoalkeeperProfile::deleting(function () { throw new \RuntimeException('simulated delete failure'); });
        try {
            $this->actingAs($user)->delete('/account',['confirmation'=>true]);
        } finally {
            GoalkeeperProfile::flushEventListeners();
        }
        $this->assertDatabaseHas('users',['id'=>$user->id]);
        $this->assertDatabaseHas('goalkeeper_profiles',['id'=>$profile->id]);
        $this->assertDatabaseHas('manual_payments',['id'=>$payment->id,'user_id'=>$user->id,'sender_note'=>'private']);
    }

    public function test_storage_failure_does_not_partially_delete_the_account_or_skip_other_file_cleanup(): void
    {
        [$user,$profile]=$this->goalkeeper();
        $photoPath='keeper-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
        \Illuminate\Support\Facades\DB::table('goalkeeper_profiles')->where('id',$profile->id)->update(['photo_path'=>$photoPath]);
        $receiptPath='123e4567-e89b-12d3-a456-426614174002.jpg';
        $payment=ManualPayment::create(['user_id'=>$user->id,'reference'=>'REF-STORAGE','amount_cents'=>900,'currency'=>'EUR','duration_seconds'=>90,'status'=>'pending','bank_snapshot'=>[],'receipt_path'=>$receiptPath]);
        $photoDisk=\Mockery::mock();
        $photoDisk->shouldReceive('delete')->once()->with($photoPath)->andThrow(new \RuntimeException('storage unavailable'));
        $receiptDisk=\Mockery::mock();
        $receiptDisk->shouldReceive('delete')->once()->with($receiptPath)->andReturn(true);
        Storage::shouldReceive('disk')->with('public')->andReturn($photoDisk);
        Storage::shouldReceive('disk')->with('receipts')->andReturn($receiptDisk);

        $this->actingAs($user)->delete('/account',['confirmation'=>true])->assertRedirect('/')->assertSessionHas('status');

        $this->assertDatabaseMissing('users',['id'=>$user->id]);
        $this->assertDatabaseMissing('goalkeeper_profiles',['id'=>$profile->id]);
        $this->assertDatabaseHas('manual_payments',['id'=>$payment->id,'user_id'=>null,'receipt_path'=>null]);
        $this->assertDatabaseHas('account_deletion_file_cleanups',['disk'=>'public','path'=>$photoPath,'attempts'=>1]);
        $this->assertDatabaseMissing('account_deletion_file_cleanups',['disk'=>'receipts','path'=>$receiptPath]);
        $this->assertGuest();
    }

    public function test_failed_file_cleanup_is_retried_by_the_durable_worker(): void
    {
        [$user]=$this->goalkeeper();
        $receiptPath='123e4567-e89b-12d3-a456-426614174001.pdf';
        $payment=ManualPayment::create(['user_id'=>$user->id,'reference'=>'REF-RETRY','amount_cents'=>900,'currency'=>'EUR','duration_seconds'=>90,'status'=>'pending','bank_snapshot'=>[],'receipt_path'=>$receiptPath]);
        $disk=\Mockery::mock();
        $disk->shouldReceive('delete')->once()->with($receiptPath)->andThrow(new \RuntimeException('temporary storage outage'));
        $disk->shouldReceive('delete')->once()->with($receiptPath)->andReturn(true);
        Storage::shouldReceive('disk')->with('receipts')->twice()->andReturn($disk);

        $this->actingAs($user)->delete('/account',['confirmation'=>true])->assertRedirect('/');
        $cleanupId=DB::table('account_deletion_file_cleanups')->where('disk','receipts')->where('path',$receiptPath)->value('id');
        $this->assertNotNull($cleanupId);
        $this->assertDatabaseHas('account_deletion_file_cleanups',['id'=>$cleanupId,'attempts'=>1]);
        DB::table('account_deletion_file_cleanups')->where('id',$cleanupId)->update(['next_attempt_at'=>now()->subSecond()]);

        $this->artisan('account-deletion:cleanup-files')->assertSuccessful();

        $this->assertDatabaseMissing('account_deletion_file_cleanups',['id'=>$cleanupId]);
        $this->assertDatabaseMissing('users',['id'=>$user->id]);
        $this->assertDatabaseHas('manual_payments',['id'=>$payment->id,'user_id'=>null,'receipt_path'=>null]);
    }

    public function test_policy_deletion_text_matches_sq_and_en_behavior(): void
    {
        $source=file_get_contents(resource_path('js/pages/Information.tsx'));
        $privacySq='Mund ta fshish llogarinë nga cilësimet e panelit pasi të konfirmosh veprimin.';
        $termsSq='Ti je përgjegjës për ruajtjen e fjalëkalimit dhe për veprimet në llogarinë tënde. Mund ta fshish llogarinë nga cilësimet e panelit pasi të konfirmosh veprimin.';
        $this->assertStringContainsString($privacySq,$source);
        $this->assertStringContainsString($termsSq,$source);
        $this->assertStringNotContainsString('nuk ka ende buton për fshirje në aplikacion',$source);
        $this->assertStringNotContainsString('up to 30 days before being overwritten',$source);
        preg_match('/\{ title: "Ruajtja dhe fshirja e të dhënave", paragraphs: \["(.*?)"\] \}/s',$source,$policyMatch);
        $policyKey=json_decode('"'.$policyMatch[1].'"',true);
        $englishPrivacy=Lang::get($policyKey,[],'en');
        $this->assertStringContainsString('Backups may temporarily contain deleted information',$englishPrivacy);
        $this->assertStringContainsString('remain queued for retry',$englishPrivacy);
    }

    public function test_privacy_policy_retention_matches_profile_cascade_without_fixed_period_promises(): void
    {
        $source = file_get_contents(resource_path('js/pages/Information.tsx'));
        $analyticsKey = 'Për profilet publike numërojmë shikimet dhe klikimet në WhatsApp ose telefon. Tabela ruan numrin ditor sipas profilit, datës dhe llojit të ngjarjes. Një shenjë në sesion kufizon numërimin në një herë për profil, lloj ngjarjeje dhe ditë. Analitika nuk ruan emrin, adresën IP ose të dhënat e pajisjes së vizitorit. Shikimet e pronarit dhe administratorit përjashtohen. Aktualisht nuk është konfiguruar afat fiks ruajtjeje. Regjistrat mbeten të lidhur me profilin dhe fshihen kur fshihet profili.';
        $reportsKey = 'Çdokush mund të raportojë në mënyrë anonime një profil publik të portierit të regjistruar, duke zgjedhur një arsye dhe, nëse dëshiron, duke dhënë hollësi deri në 1,000 karaktere. Raporti nuk ruan identitetin ose adresën IP të raportuesit. Për të kufizuar abuzimin, kërkesat e raportimit kufizohen sipas një çelësi të prejardhur me hash nga adresa IP për një orë; ky kufizim ruhet përkohësisht. Administratori mund ta shënojë raportin hapur, të shqyrtuar ose të mbyllur; raporti nuk pezullon automatikisht profilin. Aktualisht nuk është konfiguruar afat fiks ruajtjeje për raportet; ato fshihen kur fshihet profili përkatës.';

        $this->assertStringContainsString($analyticsKey, $source);
        $this->assertStringContainsString($reportsKey, $source);
        $this->assertStringContainsString('No fixed retention period is currently configured.', Lang::get($analyticsKey, [], 'en'));
        $this->assertStringContainsString('deleted when the profile is deleted.', Lang::get($analyticsKey, [], 'en'));
        $this->assertStringContainsString('No fixed retention period is currently configured for reports;', Lang::get($reportsKey, [], 'en'));
        $this->assertStringContainsString('deleted when the relevant profile is deleted.', Lang::get($reportsKey, [], 'en'));
        $this->assertStringNotContainsString('24 months', $source);
        $this->assertStringNotContainsString('12 months', $source);
        $this->assertStringNotContainsString('24 muaj', Lang::get($analyticsKey, [], 'sq'));
        $this->assertStringNotContainsString('12 muaj', Lang::get($reportsKey, [], 'sq'));
    }

}
