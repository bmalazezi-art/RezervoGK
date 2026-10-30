<?php

namespace Tests\Feature;

use App\Models\GoalkeeperProfile;
use App\Models\User;
use App\Support\ProfilePhoto;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfilePhotoEditorTest extends TestCase
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
        $this->profile = GoalkeeperProfile::firstOrFail();
        $this->profile->user()->associate($this->owner)->save();
    }

    private function url(): string
    {
        return '/keepers/'.$this->profile->id.'/photo';
    }

    public function test_photo_only_save_persists_and_all_public_surfaces_use_it_without_changing_other_data(): void
    {
        $this->profile->weeklyHours()->create(['weekday' => 6, 'start_time' => '13:00', 'end_time' => '02:00']);
        $before = $this->profile->getRawOriginal();
        $dates = DB::table('goalkeeper_availabilities')->get()->toJson();
        $weekly = DB::table('goalkeeper_weekly_hours')->get()->toJson();
        $user = $this->owner->getRawOriginal();
        $this->actingAs($this->owner)->post($this->url(), ['photo' => UploadedFile::fake()->image('selected.png', 640, 480)])
            ->assertRedirect('/dashboard')->assertSessionHas('photo_status', 'Fotografia u ruajt.');
        $photo = $this->profile->fresh();
        Storage::disk('public')->assertExists($photo->photo_path);
        $this->assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->buffer(Storage::disk('public')->get($photo->photo_path)));
        $this->assertSame($before, array_replace($photo->getRawOriginal(), array_intersect_key($before, array_flip(['photo_path', 'photo_url', 'updated_at']))));
        $url = Storage::disk('public')->url($photo->photo_path);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('keeper.photo', $url));
        foreach (['/', '/browse?max_price=10000'] as $page) {
            $this->get($page)->assertInertia(fn (Assert $data) => $data->where('keepers.0.photo', $url));
        }
        $this->get('/keepers/'.$photo->id)->assertInertia(fn (Assert $page) => $page->where('keeper.photo', $url));
        $this->assertSame($dates, DB::table('goalkeeper_availabilities')->get()->toJson());
        $this->assertSame($weekly, DB::table('goalkeeper_weekly_hours')->get()->toJson());
        $this->assertSame($user, $this->owner->fresh()->getRawOriginal());
    }

    public function test_a_managed_photo_uses_the_current_storage_origin_without_rewriting_existing_data(): void
    {
        $path = app(ProfilePhoto::class)->store(UploadedFile::fake()->image('photo.jpg'));
        $this->profile->photo_path = $path;
        $this->profile->photo_url = 'http://old-preview.example.test:8000/storage/'.$path;
        $this->profile->save();
        $before = $this->profile->getRawOriginal();
        config(['filesystems.disks.public.url' => 'https://current.example.test/storage']);
        Storage::forgetDisk('public');
        $this->get('/keepers/'.$this->profile->id)->assertInertia(fn (Assert $page) => $page
            ->where('keeper.photo', 'https://current.example.test/storage/'.$path));
        $this->assertSame($before, $this->profile->fresh()->getRawOriginal());
    }

    public function test_replacement_and_removal_keep_only_the_current_file(): void
    {
        $this->actingAs($this->owner)->post($this->url(), ['photo' => UploadedFile::fake()->image('one.jpg')])->assertRedirect('/dashboard');
        $old = $this->profile->fresh()->photo_path;
        $this->post($this->url(), ['photo' => UploadedFile::fake()->image('two.png')])->assertRedirect('/dashboard');
        $current = $this->profile->fresh()->photo_path;
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($current);
        $this->post($this->url(), ['remove_photo' => true])->assertRedirect('/dashboard')->assertSessionHas('photo_status', 'Fotografia u hoq.');
        Storage::disk('public')->assertMissing($current);
        $this->assertNull($this->profile->fresh()->photo_path);
        $this->assertSame('/keeper-placeholder.svg', $this->profile->fresh()->photo_url);
    }

    public static function invalidPhoto(): array
    {
        return [['empty'], ['text'], ['svg'], ['large'], ['wide'], ['both'], ['path']];
    }

    #[DataProvider('invalidPhoto')]
    public function test_invalid_selection_does_not_change_the_saved_photo(string $kind): void
    {
        $this->actingAs($this->owner)->post($this->url(), ['photo' => UploadedFile::fake()->image('original.jpg')]);
        $before = $this->profile->fresh()->getRawOriginal();
        $data = match ($kind) {
            'text' => ['photo' => UploadedFile::fake()->createWithContent('image.jpg', 'not an image')],
            'svg' => ['photo' => UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
            'large' => ['photo' => UploadedFile::fake()->image('large.jpg')->size(5121)],
            'wide' => ['photo' => UploadedFile::fake()->image('wide.png', 3001, 2)],
            'both' => ['photo' => UploadedFile::fake()->image('new.jpg'), 'remove_photo' => true],
            'path' => ['photo_path' => 'keeper-photos/someone-else.png'],
            default => [],
        };
        $this->postJson($this->url(), $data)->assertUnprocessable();
        $this->assertSame($before, $this->profile->fresh()->getRawOriginal());
        $this->assertCount(1, Storage::disk('public')->allFiles('keeper-photos'));
    }

    public function test_guests_other_owners_and_admins_cannot_save_or_remove_a_photo(): void
    {
        $this->postJson($this->url(), ['remove_photo' => true])->assertUnauthorized();
        foreach ([User::factory()->create(), User::factory()->create(['is_admin' => true])] as $other) {
            $this->actingAs($other)->postJson($this->url(), ['photo' => UploadedFile::fake()->image('other.jpg')])->assertForbidden();
            $this->postJson($this->url(), ['remove_photo' => true])->assertForbidden();
        }
        $this->actingAs($this->owner)->postJson('/keepers/2/photo', ['remove_photo' => true])->assertForbidden();
        $this->assertSame([], Storage::disk('public')->allFiles('keeper-photos'));
    }

    public function test_database_failure_returns_a_safe_photo_error_and_retains_the_old_file(): void
    {
        $this->actingAs($this->owner)->post($this->url(), ['photo' => UploadedFile::fake()->image('original.jpg')]);
        $before = $this->profile->fresh()->getRawOriginal();
        GoalkeeperProfile::saving(fn () => throw new \RuntimeException('private connection details'));
        try {
            $this->postJson($this->url(), ['photo' => UploadedFile::fake()->image('new.jpg')])->assertUnprocessable()
                ->assertJsonValidationErrors('photo')->assertDontSee('private connection details');
        } finally {
            GoalkeeperProfile::flushEventListeners();
        }
        $this->assertSame($before, $this->profile->fresh()->getRawOriginal());
        Storage::disk('public')->assertExists($before['photo_path']);
        $this->assertCount(1, Storage::disk('public')->allFiles('keeper-photos'));
    }

    public function test_storage_failure_returns_validation_error_and_does_not_replace_the_photo(): void
    {
        $this->actingAs($this->owner)->post($this->url(), ['photo' => UploadedFile::fake()->image('original.jpg')]);
        $before = $this->profile->fresh()->getRawOriginal();
        $this->mock(ProfilePhoto::class, function ($mock) {
            $mock->shouldReceive('store')->once()->andThrow(new \RuntimeException('private filesystem details'));
            $mock->shouldReceive('delete')->with(null)->once();
        });
        $this->postJson($this->url(), ['photo' => UploadedFile::fake()->image('new.jpg')])->assertUnprocessable()
            ->assertJsonValidationErrors('photo')->assertDontSee('private filesystem details');
        $this->assertSame($before, $this->profile->fresh()->getRawOriginal());
    }
}
