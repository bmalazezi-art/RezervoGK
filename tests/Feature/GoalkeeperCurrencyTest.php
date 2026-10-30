<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\GoalkeeperProfile;
use App\Models\User;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoalkeeperCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GoalkeeperDemoSeeder::class);
    }

    private function city(string $country): City
    {
        return City::whereHas('country', fn ($query) => $query->where('code', $country))->firstOrFail();
    }

    private function registration(string $country, array $changes = []): array
    {
        $city = $this->city($country);

        return array_replace(['name' => 'Currency Keeper', 'email' => 'currency@example.test', 'password' => 'Currency-Test-12345', 'password_confirmation' => 'Currency-Test-12345', 'accept_terms' => true,
            'phone' => '+38344123456', 'country_id' => $city->country_id, 'city_id' => $city->id, 'bio' => 'Test profile', 'price' => '12.29', 'formats' => ['5v5']], $changes);
    }

    private function keeper(string $country, string $currency, int $amount): GoalkeeperProfile
    {
        $user = User::factory()->create();
        $profile = GoalkeeperProfile::firstOrFail()->replicate();
        $profile->slug = 'currency-'.$user->id;
        $profile->city_id = $this->city($country)->id;
        $profile->currency = $currency;
        $profile->price_cents = $amount;
        $user->goalkeeperProfile()->save($profile);
        $profile->contact()->create(['phone' => '+38344123456', 'whatsapp' => '38344123456']);
        $profile->matchFormats()->attach(1);

        return $profile;
    }

    public static function defaults(): array
    {
        return [['XK', 'EUR'], ['AL', 'ALL'], ['MK', 'MKD']];
    }

    #[DataProvider('defaults')]
    public function test_registration_defaults_to_country_currency_and_stores_exact_minor_units(string $country, string $currency): void
    {
        $this->post('/register', $this->registration($country))->assertRedirect('/dashboard');
        $profile = User::sole()->goalkeeperProfile;
        $this->assertSame($currency, $profile->currency);
        $this->assertSame(1229, $profile->price_cents);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('keeper.priceAmount', '12.29')->where('keeper.priceMinor', 1229)->where('keeper.currency', $currency));
    }

    public static function allowedChoices(): array
    {
        return [['AL', 'EUR'], ['MK', 'EUR'], ['XK', 'EUR'], ['AL', 'ALL'], ['MK', 'MKD']];
    }

    #[DataProvider('allowedChoices')]
    public function test_registration_accepts_permitted_explicit_currency(string $country, string $currency): void
    {
        $this->post('/register', $this->registration($country, ['currency' => $currency, 'price' => '0.29']))->assertRedirect('/dashboard');
        $this->assertSame(29, User::sole()->goalkeeperProfile->price_cents);
        $this->assertSame($currency, User::sole()->goalkeeperProfile->currency);
    }

    public static function invalidChoices(): array
    {
        return [['XK', 'ALL'], ['XK', 'MKD'], ['AL', 'MKD'], ['MK', 'ALL'], ['AL', 'USD'], ['MK', 'mkd'], ['AL', ['ALL']]];
    }

    #[DataProvider('invalidChoices')]
    public function test_registration_rejects_currency_outside_profile_country(string $country, mixed $currency): void
    {
        $this->postJson('/register', $this->registration($country, ['currency' => $currency]))->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_editing_legacy_eur_keeps_currency_and_amount_and_owner_can_explicitly_switch(): void
    {
        $profile = $this->keeper('AL', 'EUR', 725);
        $beforeDated = $profile->availabilities()->get()->toJson();
        $data = ['name' => $profile->name, 'city_id' => $profile->city_id, 'price' => '7.25', 'phone' => '+38344123456', 'whatsapp' => '+38344123456', 'bio' => $profile->bio,
            'experience_years' => 3, 'languages' => ['Shqip'], 'formats' => ['5v5']];
        $this->actingAs($profile->user)->patch('/keepers/'.$profile->id, $data)->assertRedirect('/dashboard');
        $this->assertSame('EUR', $profile->fresh()->currency);
        $this->assertSame(725, $profile->fresh()->price_cents);
        $this->patch('/keepers/'.$profile->id, [...$data, 'currency' => 'ALL', 'price' => '1200.50'])->assertRedirect('/dashboard');
        $this->get('/keepers/'.$profile->id)->assertInertia(fn (Assert $page) => $page->where('keeper.currency', 'ALL')->where('keeper.priceAmount', '1200.50')->where('keeper.priceMinor', 120050));
        $this->patchJson('/keepers/'.$profile->id, [...$data, 'city_id' => $this->city('XK')->id])->assertJsonValidationErrors('currency');
        $this->assertSame('ALL', $profile->fresh()->currency);
        $this->assertSame(120050, $profile->fresh()->price_cents);
        $this->assertSame($beforeDated, $profile->availabilities()->get()->toJson());
        $this->actingAs(User::factory()->create())->patchJson('/keepers/'.$profile->id, [...$data, 'currency' => 'EUR'])->assertForbidden();
    }

    public function test_currency_scopes_price_filters_even_for_equal_nominal_amounts(): void
    {
        $eur = $this->keeper('XK', 'EUR', 725);
        $all = $this->keeper('AL', 'ALL', 725);
        $mkd = $this->keeper('MK', 'MKD', 725);
        foreach (['EUR' => $eur, 'ALL' => $all, 'MKD' => $mkd] as $currency => $profile) {
            $this->get('/browse?currency='.$currency.'&max_price=7.25')->assertInertia(fn (Assert $page) => $page
                ->has('keepers', 1)->where('keepers.0.id', $profile->id)->where('keepers.0.currency', $currency)->where('filters.currency', $currency));
            $this->get('/browse?currency='.$currency.'&max_price=7.24')->assertInertia(fn (Assert $page) => $page->has('keepers', 0));
        }
        $this->get('/browse?max_price=7.25')->assertInertia(fn (Assert $page) => $page->has('keepers', 1)->where('keepers.0.id', $eur->id)->where('filters.currency', 'EUR'));
    }

    public function test_region_defaults_local_currency_and_eur_remains_an_option(): void
    {
        $local = $this->keeper('AL', 'ALL', 100000);
        $legacy = $this->keeper('AL', 'EUR', 1000);
        $this->get('/browse?city_id='.$local->city_id)->assertInertia(fn (Assert $page) => $page->has('keepers', 1)->where('keepers.0.id', $local->id)->where('filters.currency', 'ALL'));
        $this->get('/browse?country_id='.$local->city->country_id.'&currency=EUR')->assertInertia(fn (Assert $page) => $page->has('keepers', 1)->where('keepers.0.id', $legacy->id));
        $this->getJson('/browse?country_id='.$local->city->country_id.'&currency=MKD')->assertJsonValidationErrors('currency');
        $this->getJson('/browse?currency=USD')->assertJsonValidationErrors('currency');
    }

    public function test_currency_filters_preserve_date_and_format_constraints(): void
    {
        $profile = $this->keeper('MK', 'MKD', 90000);
        $date = now()->addDays(2)->toDateString();
        $this->get('/browse?currency=MKD&date='.$date)->assertInertia(fn (Assert $page) => $page->has('keepers', 1)
            ->where('keepers.0.id', $profile->id)->where('keepers.0.availableOnRequestedDate', false));
        $profile->availabilities()->create(['available_on' => $date]);
        $this->get('/browse?currency=MKD&max_price=900&format=5v5&date='.$date)->assertInertia(fn (Assert $page) => $page->has('keepers', 1)
            ->where('keepers.0.id', $profile->id)->where('keepers.0.availableOnRequestedDate', true));
        $this->get('/browse?currency=EUR&date='.$date)->assertInertia(fn (Assert $page) => $page->has('keepers', 0));
    }
}
