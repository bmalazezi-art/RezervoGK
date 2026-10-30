<?php

namespace Tests\Feature;

use App\Models\GoalkeeperProfile;
use App\Models\User;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite()->seed(GoalkeeperDemoSeeder::class);
    }

    public function test_analytics_requires_existing_admin_authorization_and_validates_filters(): void
    {
        $this->get('/admin/analytics')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/analytics')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/admin/analytics?period=custom&date_from=2026-10-20&country_id=999999')->assertSessionHasErrors('country_id');
        $this->get('/admin/analytics?period=custom')->assertSessionHasErrors('date_from');
    }

    public function test_analytics_aggregates_existing_users_profiles_reports_and_public_visibility(): void
    {
        $profiles = GoalkeeperProfile::whereIn('slug', ['ardit-krasniqi', 'altin-berisha', 'leon-dervishi', 'enis-hasani'])->get()->keyBy('slug');
        $owners = [];
        foreach ($profiles as $slug => $profile) {
            $owners[$slug] = User::factory()->create(['name' => ucfirst($slug).' Owner']);
            $profile->user()->associate($owners[$slug]);
            $profile->save();
        }
        $profiles['altin-berisha']->forceFill(['admin_hidden' => true])->save();
        $profiles['leon-dervishi']->forceFill(['is_published' => false])->save();
        $owners['enis-hasani']->forceFill(['is_suspended' => true])->save();
        $profiles['ardit-krasniqi']->reports()->create(['reason' => 'spam', 'status' => 'open']);
        $profiles['altin-berisha']->reports()->create(['reason' => 'other', 'status' => 'reviewed']);

        $kosovoId = $profiles['ardit-krasniqi']->city->country_id;
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/admin/analytics?period=30&country_id='.$kosovoId)
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('AdminAnalytics')
                ->where('stats.totalProfiles', 2)
                ->where('stats.publicProfiles', 1)
                ->where('stats.hiddenProfiles', 1)
                ->where('stats.unpublishedProfiles', 0)
                ->where('stats.totalReports', 2)
                ->where('stats.unresolvedReports', 1)
                ->where('charts.profileCountries.0.country', 'Kosovë')
                ->where('charts.profileCountries.0.profiles', 2)
                ->where('charts.profileCountries.0.active', 1)
                ->where('charts.reportStatuses.0.total', 1)
                ->has('charts.labels', 30));
    }

    public function test_custom_date_range_filters_growth_charts_and_period_counts_without_exposing_user_details(): void
    {
        $profile = GoalkeeperProfile::where('slug', 'ardit-krasniqi')->firstOrFail();
        $owner = User::factory()->create(['name' => 'Current Owner', 'created_at' => now()->subDays(2)]);
        $profile->user()->associate($owner)->save();
        $profile->reports()->create(['reason' => 'inaccurate', 'status' => 'dismissed']);
        $oldProfile = GoalkeeperProfile::where('slug', 'altin-berisha')->firstOrFail();
        $oldUser = User::factory()->create(['created_at' => now()->subDays(20)]);
        $oldProfile->user()->associate($oldUser)->save();
        $oldProfile->forceFill(['created_at' => now()->subDays(20)])->save();

        $from = now()->subDays(6)->toDateString();
        $to = now()->toDateString();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/admin/analytics?period=custom&date_from='.$from.'&date_to='.$to)
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('stats.newUsers', 2)
                ->where('stats.newProfiles', 3)->where('stats.totalReports', 1)
                ->where('growth.currentUsers', 2)->where('growth.currentProfiles', 3)
                ->missing('users')->missing('emails')->has('charts.labels', 7));
    }
}
