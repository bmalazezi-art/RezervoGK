<?php

namespace Tests\Feature;

use App\Models\GoalkeeperProfile;
use App\Models\User;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MarketplacePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GoalkeeperDemoSeeder::class);
        foreach (GoalkeeperProfile::all() as $profile) {
            $profile->user()->associate(User::factory()->create());
            $profile->save();
        }
    }

    public static function pages(): array
    {
        return [
            ['/', 'Home'], ['/browse', 'Browse'], ['/register', 'Register'],
            ['/login', 'Login'], ['/forgot-password', 'ForgotPassword'],
            ['/how-it-works', 'Information', 'howItWorks'], ['/faq', 'Information', 'faq'],
            ['/contact', 'Information', 'contact'], ['/privacy', 'Information', 'privacy'], ['/terms', 'Information', 'terms'],
        ];
    }

    public function test_pricing_page_is_temporarily_redirected_away(): void
    {
        $this->get('/pricing')->assertRedirect('/');
    }

    #[DataProvider('pages')]
    public function test_screens_load_directly(string $url, string $component, ?string $pageKey = null): void
    {
        $this->get($url)->assertOk()->assertInertia(function (Assert $page) use ($component, $pageKey) {
            $page->component($component)->where('auth.user', null);
            if ($pageKey !== null) {
                $page->where('pageKey', $pageKey);
            }
        });
    }

    public function test_legacy_demo_claims_are_not_exposed_by_public_payloads_and_records_are_preserved(): void
    {
        $profile = GoalkeeperProfile::firstOrFail();
        $before = $profile->getRawOriginal();
        $this->assertTrue($profile->is_verified);
        $this->assertGreaterThan(0, $profile->review_count);

        foreach (['/', '/browse'] as $url) {
            $keepers = $this->get($url)->assertOk()->viewData('page')['props']['keepers'];
            $this->assertContains($profile->id, array_column($keepers, 'id'));
            foreach ($keepers as $keeper) {
                foreach (['rating', 'reviews', 'matchesPlayed', 'verified'] as $field) {
                    $this->assertArrayNotHasKey($field, $keeper);
                }
            }
        }
        $this->get('/keepers/'.$profile->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('keeper.id', $profile->id)->missing('keeper.rating')->missing('keeper.reviews')
            ->missing('keeper.matchesPlayed')->missing('keeper.verified'));
        $this->assertSame($before, $profile->fresh()->getRawOriginal());
    }

    public function test_browse_shows_eight_goalkeepers_per_page(): void
    {
        $template = GoalkeeperProfile::firstOrFail();
        foreach (range(1, 9) as $n) {
            $copy = $template->replicate(['slug']);
            $copy->slug = GoalkeeperProfile::uniqueSlug($template->name.' '.$n);
            $owner = $template->user->replicate();
            $owner->email = "keeper{$n}@example.test";
            $owner->save();
            $copy->user_id = $owner->id;
            $copy->save();
        }
        $total = GoalkeeperProfile::publiclyVisible()->where('currency', 'EUR')->count();
        $this->assertGreaterThan(8, $total, 'Test needs more than one page of goalkeepers.');

        $this->get('/browse?max_price=1000')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('keepers', min(8, $total))->where('pagination.page', 1)->where('pagination.perPage', 8)
            ->where('pagination.total', $total));
    }

    public function test_home_shows_up_to_four_goalkeepers(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('keepers', fn ($keepers) => count($keepers) > 0 && count($keepers) <= 4));
    }

    public function test_each_demo_profile_has_a_direct_url(): void
    {
        foreach ([1, 2, 3, 4] as $keeper) {
            $this->get("/keepers/{$keeper}")->assertOk()->assertInertia(
                fn (Assert $page) => $page->component('Profile')->where('keeper.id', $keeper)
            );
        }
    }

    public function test_keepers_listing_alias_redirects_to_browse(): void
    {
        $this->get('/keepers')->assertRedirect('/browse');
    }

    public function test_profiles_have_readable_links_and_old_links_keep_working(): void
    {
        $profile = GoalkeeperProfile::firstOrFail();
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $profile->slug);

        $this->get('/p/'.$profile->slug)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Profile')->where('keeper.id', $profile->id)->where('keeper.url', '/p/'.$profile->slug));
        $this->get('/keepers/'.$profile->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('keeper.id', $profile->id));
        $this->get('/p/no-such-goalkeeper')->assertNotFound();
    }

    public function test_readable_slugs_stay_unique(): void
    {
        $this->assertSame('ardit-krasniqi-2', GoalkeeperProfile::uniqueSlug('Ardit Krasniqi'));
        $existing = GoalkeeperProfile::where('slug', 'ardit-krasniqi')->firstOrFail();
        $this->assertSame('ardit-krasniqi', GoalkeeperProfile::uniqueSlug('Ardit Krasniqi', $existing->id));
        $this->assertSame('portier', GoalkeeperProfile::uniqueSlug('!!!'));
    }

    public function test_public_profile_includes_absolute_share_url_and_open_graph_metadata(): void
    {
        config(['app.url' => 'https://portieri.example']);
        $profile = GoalkeeperProfile::firstOrFail();
        $response = $this->get('/keepers/'.$profile->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shareUrl', 'https://portieri.example/p/'.$profile->slug)
                ->where('shareTitle', $profile->name.' | Gjej portier')
                ->has('shareImage'));
        $this->assertMatchesRegularExpression('~^https?://~', $response->viewData('page')['props']['shareImage']);

        $response->assertSee('property="og:url" content="https://portieri.example/p/'.$profile->slug.'"', false)
            ->assertSee('property="og:title"', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('name="description"', false);
    }

    public function test_inertia_navigation_returns_the_requested_page(): void
    {
        $version = $this->get('/')->viewData('page')['version'];

        $this->get('/keepers/2', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Requested-With' => 'XMLHttpRequest',
        ])
            ->assertOk()->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Profile')->assertJsonPath('props.keeper.id', 2);
    }

    public function test_unknown_profiles_return_404(): void
    {
        foreach (['999', 'ardit', '01'] as $keeper) {
            $this->get("/keepers/{$keeper}")->assertNotFound();
        }
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
