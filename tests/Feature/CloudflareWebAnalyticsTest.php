<?php

namespace Tests\Feature;

use App\Models\GoalkeeperProfile;
use App\Models\User;
use Database\Seeders\GoalkeeperDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CloudflareWebAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'b986a4a614f049b68b3e885b48fea484';

    private const META = '<meta name="cf-web-analytics-token" content="'.self::TOKEN.'">';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.cloudflare_web_analytics' => ['token' => self::TOKEN, 'enabled' => true, 'track_public_profiles' => false]]);
    }

    public static function trackedPages(): array
    {
        return [['/'], ['/browse'], ['/how-it-works'], ['/faq'], ['/contact'], ['/privacy'], ['/terms']];
    }

    public static function excludedGuestPages(): array
    {
        return [
            ['/login'], ['/register'], ['/forgot-password'], ['/reset-password/secret-reset-token'],
            ['/admin/login'], ['/admin/verify'], ['/dashboard'], ['/admin'], ['/admin/users/1'],
            ['/payments/1/receipt'], ['/pricing'], ['/keepers'], ['/does-not-exist'],
        ];
    }

    #[DataProvider('trackedPages')]
    public function test_public_pages_expose_only_the_beacon_token_to_guests(string $url): void
    {
        $this->get($url)->assertOk()
            ->assertSee(self::META, false)
            ->assertDontSee('cloudflareinsights.com', false);
    }

    #[DataProvider('excludedGuestPages')]
    public function test_private_auth_and_admin_pages_are_never_tracked(string $url): void
    {
        $this->get($url)->assertDontSee('cf-web-analytics-token', false);
    }

    public function test_authenticated_users_are_never_tracked(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['/', '/browse', '/faq', '/dashboard', '/admin'] as $url) {
            $this->get($url)->assertDontSee('cf-web-analytics-token', false);
        }
    }

    public function test_query_strings_are_never_tracked(): void
    {
        foreach (['/?utm_source=instagram', '/browse?city=1', '/browse?date=2026-11-20', '/faq?ref=x'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('cf-web-analytics-token', false);
        }
    }

    public function test_do_not_track_global_privacy_control_and_prefetch_are_respected(): void
    {
        foreach ([['DNT' => '1'], ['Sec-GPC' => '1'], ['Purpose' => 'prefetch'], ['Sec-Purpose' => 'prefetch;prerender'], ['X-Inertia-Prefetch' => 'true']] as $headers) {
            $this->withHeaders($headers)->get('/')->assertDontSee('cf-web-analytics-token', false);
            $this->flushHeaders();
        }
        $this->withHeaders(['DNT' => '0'])->get('/')->assertSee(self::META, false);
    }

    public function test_public_profiles_are_excluded_unless_explicitly_enabled(): void
    {
        $this->seed(GoalkeeperDemoSeeder::class);
        $profile = GoalkeeperProfile::firstOrFail();
        $profile->user()->associate(User::factory()->create())->save();

        $this->get($profile->publicPath())->assertOk()->assertDontSee('cf-web-analytics-token', false);
        $this->get('/keepers/'.$profile->id)->assertOk()->assertDontSee('cf-web-analytics-token', false);

        config(['services.cloudflare_web_analytics.track_public_profiles' => true]);
        $this->get($profile->publicPath())->assertOk()->assertSee(self::META, false);
        $this->get($profile->publicPath().'?date=2026-11-20')->assertDontSee('cf-web-analytics-token', false);
    }

    public function test_disabled_or_invalid_configuration_renders_nothing(): void
    {
        config(['services.cloudflare_web_analytics.enabled' => false]);
        $this->get('/')->assertDontSee('cf-web-analytics-token', false);

        config(['services.cloudflare_web_analytics' => ['token' => '"><script>alert(1)</script>', 'enabled' => true]]);
        $this->get('/')->assertDontSee('cf-web-analytics-token', false)->assertDontSee('alert(1)', false);
    }

    public static function booleanEnvValues(): array
    {
        return [
            'true' => ['true', true], 'TRUE' => ['TRUE', true], '1' => ['1', true], 'on' => ['on', true], 'yes' => ['yes', true],
            'false' => ['false', false], '0' => ['0', false], 'off' => ['off', false], 'no' => ['no', false],
            'disabled' => ['disabled', false], 'typo' => ['ture', false], 'empty' => ['', false],
        ];
    }

    #[DataProvider('booleanEnvValues')]
    public function test_analytics_flags_parse_env_booleans_strictly(string $value, bool $expected): void
    {
        $config = $this->servicesConfigWithEnv([
            'APP_ENV' => 'production',
            'CLOUDFLARE_WEB_ANALYTICS_ENABLED' => $value,
            'CLOUDFLARE_WEB_ANALYTICS_TRACK_PROFILES' => $value,
        ]);

        $this->assertSame($expected, $config['enabled']);
        $this->assertSame($expected, $config['track_public_profiles']);
    }

    public function test_production_default_and_explicit_opt_out(): void
    {
        $this->assertTrue($this->servicesConfigWithEnv(['APP_ENV' => 'production', 'CLOUDFLARE_WEB_ANALYTICS_ENABLED' => null])['enabled']);
        $this->assertFalse($this->servicesConfigWithEnv(['APP_ENV' => 'production', 'CLOUDFLARE_WEB_ANALYTICS_ENABLED' => 'off'])['enabled']);
        $this->assertFalse($this->servicesConfigWithEnv(['APP_ENV' => 'local', 'CLOUDFLARE_WEB_ANALYTICS_ENABLED' => null])['enabled']);
    }

    public function test_profile_tracking_stays_off_in_production_unless_explicitly_enabled(): void
    {
        foreach ([null, '', 'false', '0', 'off', 'no', 'ture', 'enabled', 'disabled'] as $value) {
            $config = $this->servicesConfigWithEnv(['APP_ENV' => 'production', 'CLOUDFLARE_WEB_ANALYTICS_ENABLED' => 'true', 'CLOUDFLARE_WEB_ANALYTICS_TRACK_PROFILES' => $value]);
            $this->assertTrue($config['enabled']);
            $this->assertFalse($config['track_public_profiles'], 'TRACK_PROFILES='.var_export($value, true));
        }
    }

    public function test_runtime_string_flags_are_parsed_strictly(): void
    {
        config(['services.cloudflare_web_analytics.enabled' => 'off']);
        $this->get('/')->assertDontSee('cf-web-analytics-token', false);

        config(['services.cloudflare_web_analytics.enabled' => 'true']);
        $this->get('/')->assertSee(self::META, false);
    }

    /** Evaluates config/services.php with temporary environment values (null = unset), restoring them afterwards. */
    private function servicesConfigWithEnv(array $values): array
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = [getenv($key), $_ENV[$key] ?? null, array_key_exists($key, $_ENV), $_SERVER[$key] ?? null, array_key_exists($key, $_SERVER)];
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }

        try {
            return (require config_path('services.php'))['cloudflare_web_analytics'];
        } finally {
            foreach ($previous as $key => [$env, $envValue, $hadEnv, $serverValue, $hadServer]) {
                $env === false ? putenv($key) : putenv("{$key}={$env}");
                if ($hadEnv) {
                    $_ENV[$key] = $envValue;
                } else {
                    unset($_ENV[$key]);
                }
                if ($hadServer) {
                    $_SERVER[$key] = $serverValue;
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }

    public function test_beacon_is_off_by_default_outside_production(): void
    {
        $config = require config_path('services.php');

        $this->assertSame('testing', app()->environment());
        $this->assertFalse($config['cloudflare_web_analytics']['enabled']);
        $this->assertFalse($config['cloudflare_web_analytics']['track_public_profiles']);
    }
}
