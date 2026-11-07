<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\GoalkeeperAvailability;
use App\Models\GoalkeeperProfile;
use App\Models\User;
use App\Support\AvailabilityCalendar;
use Carbon\CarbonImmutable;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GoalkeeperManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GoalkeeperProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GoalkeeperDemoSeeder::class);
        Storage::fake('public');
        $this->owner = User::factory()->create();
        $this->profile = $this->owner->goalkeeperProfile()->create([
            'name' => 'Owned Keeper', 'slug' => 'owned-management-keeper', 'city_id' => City::firstOrFail()->id,
            'bio' => 'Original biography', 'price_cents' => 1000, 'photo_url' => '/keeper-placeholder.svg', 'languages' => ['Shqip'],
        ]);
        $this->profile->contact()->create(['phone' => '+38344123456', 'whatsapp' => '38344987654']);
    }

    private function data(array $changes = []): array
    {
        return array_replace([
            'name' => 'Owned Keeper', 'city_id' => $this->profile->city_id, 'bio' => 'Updated biography',
            'price' => '7.25', 'phone' => '+383 44 123 456', 'whatsapp' => '+355 68 123 4567',
            'experience_years' => 8, 'languages' => ['Shqip', 'English'], 'formats' => ['5v5', '7v7'],
        ], $changes);
    }

    public function test_owner_can_save_all_profile_details_and_public_display_uses_them(): void
    {
        $this->actingAs($this->owner)->patch('/keepers/'.$this->profile->id, $this->data())->assertRedirect('/dashboard');
        $profile = $this->profile->fresh();
        $this->assertSame(725, $profile->price_cents);
        $this->assertSame(8, $profile->experience_years);
        $this->assertSame(['Shqip', 'English'], $profile->languages);
        $this->assertSame(['5v5', '7v7'], $profile->matchFormats->pluck('code')->all());
        $this->assertSame('355681234567', $profile->contact->whatsapp);
        $this->get('/keepers/'.$profile->id)->assertInertia(fn (Assert $page) => $page
            ->where('keeper.experienceYears', 8)->where('keeper.languages', ['Shqip', 'English'])
            ->where('keeper.price', 7.25)->where('keeper.bio', 'Updated biography')
            ->where('keeper.whatsapp', '355681234567'));
    }

    public function test_owner_can_select_nine_a_side_format(): void
    {
        $this->actingAs($this->owner)->patch('/keepers/'.$this->profile->id, $this->data(['formats' => ['9v9']]))
            ->assertRedirect('/dashboard');

        $this->assertSame(['9v9'], $this->profile->fresh()->matchFormats->pluck('code')->all());
    }

    public static function invalidFields(): array
    {
        return [
            [['experience_years' => -1], 'experience_years'], [['experience_years' => 101], 'experience_years'],
            [['experience_years' => 1.5], 'experience_years'], [['languages' => []], 'languages'],
            [['languages' => ['English', 'english']], 'languages.0'], [['languages' => [str_repeat('a', 41)]], 'languages.0'],
            [['formats' => []], 'formats'], [['formats' => ['10v10']], 'formats.0'],
            [['whatsapp' => 'bad'], 'whatsapp'], [['price' => '7.251'], 'price'],
            [['photo_path' => 'keeper-photos/another-user.jpg'], 'photo_path'], [['photo_url' => 'https://example.test/image.jpg'], 'photo_url'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_profile_changes_are_validated(array $changes, string $field): void
    {
        $this->actingAs($this->owner)->patchJson('/keepers/'.$this->profile->id, $this->data($changes))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame('Original biography', $this->profile->fresh()->bio);
        $this->assertSame(1000, $this->profile->fresh()->price_cents);
    }

    public function test_uploaded_photo_is_reencoded_and_replaced_only_after_a_successful_save(): void
    {
        $photo = UploadedFile::fake()->image('untrusted-original-name.jpg');
        file_put_contents($photo->getRealPath(), '\nUNTRUSTED_TRAILER', FILE_APPEND);
        $this->actingAs($this->owner)->post('/keepers/'.$this->profile->id, $this->data(['_method' => 'PATCH', 'photo' => $photo]))
            ->assertRedirect('/dashboard');
        $first = $this->profile->fresh()->photo_path;
        $this->assertMatchesRegularExpression('/^keeper-photos\/[a-f0-9-]{36}\.jpg$/', $first);
        Storage::disk('public')->assertExists($first);
        $this->assertStringNotContainsString('UNTRUSTED_TRAILER', Storage::disk('public')->get($first));
        $this->post('/keepers/'.$this->profile->id, $this->data(['_method' => 'PATCH', 'photo' => UploadedFile::fake()->image('replacement.png')]))
            ->assertRedirect('/dashboard');
        $second = $this->profile->fresh()->photo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        $this->patch('/keepers/'.$this->profile->id, $this->data(['remove_photo' => true]))->assertRedirect('/dashboard');
        $this->assertNull($this->profile->fresh()->photo_path);
        $this->assertSame('/keeper-placeholder.svg', $this->profile->fresh()->photo_url);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_invalid_uploads_leave_the_existing_photo_and_data_intact(): void
    {
        $this->actingAs($this->owner)->patch('/keepers/'.$this->profile->id, $this->data(['photo' => UploadedFile::fake()->image('original.jpg')]));
        $original = $this->profile->fresh()->photo_path;
        foreach ([
            UploadedFile::fake()->create('payload.svg', 1, 'image/svg+xml'),
            UploadedFile::fake()->image('large.jpg')->size(5121),
            UploadedFile::fake()->image('wide.png', 3001, 1),
            UploadedFile::fake()->create('disguised.jpg', 1, 'image/jpeg'),
        ] as $file) {
            $this->postJson('/keepers/'.$this->profile->id, $this->data(['_method' => 'PATCH', 'photo' => $file]))
                ->assertUnprocessable()->assertJsonValidationErrors('photo');
            $this->assertSame($original, $this->profile->fresh()->photo_path);
            Storage::disk('public')->assertExists($original);
        }
        $this->assertCount(1, Storage::disk('public')->allFiles('keeper-photos'));
    }

    public function test_failed_database_write_preserves_old_photo_and_removes_new_upload(): void
    {
        $this->actingAs($this->owner)->patch('/keepers/'.$this->profile->id, $this->data(['photo' => UploadedFile::fake()->image('original.jpg')]));
        $original = $this->profile->fresh()->photo_path;
        GoalkeeperProfile::updating(fn () => throw new RuntimeException('Simulated management failure'));
        $this->withoutExceptionHandling();
        try {
            $this->patch('/keepers/'.$this->profile->id, $this->data(['photo' => UploadedFile::fake()->image('new.jpg')]));
            $this->fail('Expected the simulated failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated management failure', $exception->getMessage());
        } finally {
            GoalkeeperProfile::flushEventListeners();
        }
        $this->assertSame($original, $this->profile->fresh()->photo_path);
        Storage::disk('public')->assertExists($original);
        $this->assertCount(1, Storage::disk('public')->allFiles('keeper-photos'));
    }

    public function test_ownership_applies_to_profile_photos_and_availability_writes(): void
    {
        $other = User::factory()->create();
        $date = AvailabilityCalendar::today()->addDays(2)->toDateString();
        $this->actingAs($other)->patchJson('/keepers/'.$this->profile->id, $this->data(['photo' => UploadedFile::fake()->image('bad-owner.jpg')]))->assertForbidden();
        $this->post('/keepers/'.$this->profile->id.'/availability', ['date' => $date])->assertForbidden();
        $this->delete('/keepers/'.$this->profile->id.'/availability/'.$date)->assertForbidden();
        $this->post('/keepers/1/availability', ['date' => $date])->assertForbidden();
        $this->assertDatabaseCount('goalkeeper_availabilities', 0);
        $this->assertCount(0, Storage::disk('public')->allFiles('keeper-photos'));
    }

    public function test_date_save_is_idempotent_public_and_removable(): void
    {
        $date = AvailabilityCalendar::today()->addDays(2)->toDateString();
        $url = '/keepers/'.$this->profile->id;
        $this->actingAs($this->owner)->post($url.'/availability', ['date' => $date])->assertRedirect('/dashboard');
        $this->post($url.'/availability', ['date' => $date])->assertRedirect('/dashboard');
        $this->assertDatabaseCount('goalkeeper_availabilities', 1);
        $this->post('/logout');
        $this->get($url.'?date='.$date)->assertInertia(fn (Assert $page) => $page
            ->where('keeper.availabilityDates', [$date])->where('calendar.0.available', true)->where('calendar.1.available', false));
        $this->get('/browse?date='.$date)->assertInertia(fn (Assert $page) => $page
            ->has('keepers', 1)->where('keepers.0.id', $this->profile->id)
            ->where('keepers.0.availableOnRequestedDate', true)->where('filters.date', $date));
        $this->actingAs($this->owner)->delete($url.'/availability/'.$date)->assertRedirect('/dashboard');
        $this->delete($url.'/availability/'.$date)->assertRedirect('/dashboard');
        $this->assertDatabaseCount('goalkeeper_availabilities', 0);
        $this->get('/browse?date='.$date)->assertInertia(fn (Assert $page) => $page->has('keepers', 1)
            ->where('keepers.0.id', $this->profile->id)
            ->where('keepers.0.availableOnRequestedDate', false));
    }

    public function test_date_filter_combines_with_city_price_and_format(): void
    {
        $date = AvailabilityCalendar::today()->addDay()->toDateString();
        $this->profile->availabilities()->create(['available_on' => $date]);
        $this->actingAs($this->owner)->patch('/keepers/'.$this->profile->id, $this->data())->assertRedirect('/dashboard');
        $this->get('/browse?date='.$date.'&city_id='.$this->profile->city_id.'&max_price=7.25&format=7v7')
            ->assertInertia(fn (Assert $page) => $page->has('keepers', 1));
        $this->get('/browse?date='.$date.'&max_price=7.24')->assertInertia(fn (Assert $page) => $page->has('keepers', 0));
        $this->get('/browse?date='.$date.'&format=6v6')->assertInertia(fn (Assert $page) => $page->has('keepers', 0));
    }

    public function test_invalid_dates_and_past_dates_are_rejected(): void
    {
        $this->actingAs($this->owner);
        foreach (['2026-02-30', 'not-a-date', AvailabilityCalendar::today()->subDay()->toDateString(), AvailabilityCalendar::today()->addYears(2)->toDateString()] as $date) {
            $this->postJson('/keepers/'.$this->profile->id.'/availability', ['date' => $date])->assertUnprocessable();
            $this->getJson('/browse?date='.urlencode($date))->assertUnprocessable();
        }
        $this->deleteJson('/keepers/'.$this->profile->id.'/availability/2026-02-30')->assertUnprocessable();
        $this->assertDatabaseCount('goalkeeper_availabilities', 0);
    }

    public function test_calendar_uses_local_dates_and_excludes_expired_entries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 22:30:00', 'UTC'));
        $this->owner->forceFill(['trial_started_at' => now()->subDay(), 'trial_ends_at' => now()->addDays(29)])->save();
        $this->assertSame('2026-10-21', AvailabilityCalendar::today()->toDateString());
        $this->profile->availabilities()->create(['available_on' => '2026-10-20']);
        $this->profile->availabilities()->create(['available_on' => '2026-10-21']);
        $this->get('/keepers/'.$this->profile->id)->assertInertia(fn (Assert $page) => $page
            ->where('keeper.availabilityDates', ['2026-10-21'])->where('calendar.0.iso', '2026-10-21')->where('calendar.0.available', true));
        $this->travelBack();
    }

    public function test_deleting_profile_cascades_its_availability_and_guest_writes_are_blocked(): void
    {
        $date = AvailabilityCalendar::today()->toDateString();
        $this->postJson('/keepers/'.$this->profile->id.'/availability', ['date' => $date])->assertUnauthorized();
        $this->deleteJson('/keepers/'.$this->profile->id.'/availability/'.$date)->assertUnauthorized();
        $this->profile->availabilities()->create(['available_on' => $date]);
        $this->profile->delete();
        $this->assertSame(0, GoalkeeperAvailability::count());
    }
}
