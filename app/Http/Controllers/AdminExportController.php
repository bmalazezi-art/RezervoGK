<?php

namespace App\Http\Controllers;

use App\Models\AdminSystemAudit;
use App\Models\GoalkeeperProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV downloads for the Super Admin. Every export is recorded in the admin audit log. */
class AdminExportController extends Controller
{
    public function users(Request $request): StreamedResponse
    {
        $this->audit($request, 'export_users');

        return $this->csv('gjej-portier-users-'.now()->format('Y-m-d').'.csv',
            ['id', 'name', 'email', 'registered_at', 'is_admin', 'is_suspended', 'has_goalkeeper_profile', 'city', 'country'],
            function () {
                foreach (User::with('goalkeeperProfile.city.country')->orderBy('id')->lazy() as $user) {
                    $profile = $user->goalkeeperProfile;
                    yield [$user->id, $user->name, $user->email, $user->created_at?->toDateTimeString(), $user->is_admin ? 'yes' : 'no',
                        $user->is_suspended ? 'yes' : 'no', $profile ? 'yes' : 'no', $profile?->city?->name, $profile?->city?->country?->name];
                }
            });
    }

    public function goalkeepers(Request $request): StreamedResponse
    {
        $this->audit($request, 'export_goalkeepers');
        $since = now()->subDays(29)->toDateString();
        $stats = DB::table('goalkeeper_profile_analytics')->where('event_date', '>=', $since)
            ->select('goalkeeper_profile_id', 'event_type', DB::raw('SUM(event_count) as total'))
            ->groupBy('goalkeeper_profile_id', 'event_type')->get()->groupBy('goalkeeper_profile_id');
        $public = GoalkeeperProfile::publiclyVisible()->pluck('id')->flip();

        return $this->csv('gjej-portier-goalkeepers-'.now()->format('Y-m-d').'.csv',
            ['id', 'name', 'public_link', 'city', 'country', 'price', 'currency', 'formats', 'publicly_visible', 'verified', 'featured',
                'views_30_days', 'whatsapp_30_days', 'calls_30_days', 'created_at'],
            function () use ($stats, $public) {
                foreach (GoalkeeperProfile::with(['city.country', 'matchFormats'])->orderBy('id')->lazy() as $profile) {
                    $events = ($stats[$profile->id] ?? collect())->pluck('total', 'event_type');
                    yield [$profile->id, $profile->name, rtrim((string) config('app.url'), '/').$profile->publicPath(),
                        $profile->city?->name, $profile->city?->country?->name, number_format($profile->price_cents / 100, 2, '.', ''), $profile->currency,
                        $profile->matchFormats->pluck('code')->implode(' '), isset($public[$profile->id]) ? 'yes' : 'no',
                        $profile->verified_at ? 'yes' : 'no', $profile->is_featured ? 'yes' : 'no',
                        (int) ($events['view'] ?? 0), (int) ($events['whatsapp'] ?? 0), (int) ($events['phone'] ?? 0), $profile->created_at?->toDateTimeString()];
                }
            });
    }

    private function csv(string $filename, array $header, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ë/ç correctly
            fputcsv($out, $header);
            foreach ($rows() as $row) {
                fputcsv($out, array_map([self::class, 'safe'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /** Stops spreadsheet apps from running cell text as a formula. */
    public static function safe(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function audit(Request $request, string $action): void
    {
        AdminSystemAudit::create([
            'actor_id' => $request->user()->id, 'target_id' => null, 'action' => $action,
            'previous_values' => [], 'new_values' => [], 'reason' => 'CSV export downloaded from the admin panel.', 'created_at' => now(),
        ]);
    }
}
