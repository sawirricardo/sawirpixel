<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('services.pixelforge.key', 'pf_test_key');
    config()->set('app.admins', ['ops@example.com']);
});

function operator(): User
{
    return User::factory()->create(['email' => 'ops@example.com']);
}

/**
 * Two users' generations around 15–17 Nov 2026, plus ones the report must leave out.
 *
 * @return array{ada: User, grace: User}
 */
function seedBilledGenerations(): array
{
    $ada = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
    $grace = User::factory()->create(['name' => 'Grace', 'email' => 'grace@example.com']);
    $succeeded = fn (User $user, string $completedAt, string $charge, string $providerCost) => Generation::factory()->queued()->for($user)->create([
        'status' => GenerationStatus::Succeeded,
        'completed_at' => $completedAt,
        'charge' => $charge,
        'provider_cost' => $providerCost,
    ]);

    $succeeded($ada, '2026-11-16 10:00:00', '0.3818875000', '0.305510000');
    $succeeded($ada, '2026-11-17 01:00:00', '0.0140000000', '0.011200000');
    $succeeded($grace, '2026-11-16 23:30:00', '0.0677375000', '0.054190000');
    $succeeded($grace, '2026-11-14 12:00:00', '0.5000000000', '0.400000000');
    Generation::factory()->queued()->for($ada)->create([
        'status' => GenerationStatus::Failed,
        'completed_at' => '2026-11-16 11:00:00',
        'charge' => '0',
        'provider_cost' => '0',
    ]);

    return ['ada' => $ada, 'grace' => $grace];
}

/**
 * @param  array<string, array{int, string}>  $days  date => [generations, cost_usd]
 */
function fakePixelforgeUsage(array $days): void
{
    $data = [];

    foreach ($days as $date => [$generations, $cost]) {
        $data[] = ['date' => $date, 'generations' => $generations, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => $cost];
    }

    Http::fake(['pixelforge.promptedit.dev/v1/usage*' => Http::response([
        'object' => 'usage',
        'start_date' => array_key_first($days),
        'end_date' => array_key_last($days),
        'data' => $data,
        'total' => ['generations' => 4, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.382100000'],
    ])]);
}

test('reports charges, provider costs and profit per day and per user, reconciled against pixelforge', function (): void {
    seedBilledGenerations();
    fakePixelforgeUsage([
        '2026-11-15' => [0, '0.000000000'],
        '2026-11-16' => [2, '0.359700000'],
        '2026-11-17' => [2, '0.022400000'],
    ]);

    $response = $this->actingAs(operator())->get(route('admin.reports.profit', ['start_date' => '2026-11-15', 'end_date' => '2026-11-17']));

    $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('admin/ProfitReport')
        ->where('pixelforgeError', null)
        ->where('report.totals.generations', 3)
        ->where('report.totals.charged', ['amount' => '0.4636250000', 'formatted' => '$0.46'])
        ->where('report.totals.provider_cost', ['amount' => '0.370900000', 'formatted' => '$0.370900000'])
        ->where('report.totals.profit', ['amount' => '0.0927250000', 'formatted' => '$0.09'])
        ->where('report.totals.pixelforge_cost.amount', '0.382100000')
        ->where('report.reconciliation', ['available' => true, 'matched_days' => 2, 'mismatched_days' => 1])
        ->where('report.days.0.date', '2026-11-17')
        ->where('report.days.0.profit', ['amount' => '0.0028000000', 'formatted' => '$0.0028'])
        ->where('report.days.0.pixelforge', [
            'generations' => 2,
            'cost' => ['amount' => '0.022400000', 'formatted' => '$0.022400000'],
            'difference' => ['amount' => '0.011200000', 'formatted' => '$0.011200000'],
            'matches' => false,
        ])
        ->where('report.days.1.date', '2026-11-16')
        ->where('report.days.1.generations', 2)
        ->where('report.days.1.provider_cost.amount', '0.359700000')
        ->where('report.days.1.pixelforge.matches', true)
        ->where('report.days.2.date', '2026-11-15')
        ->where('report.days.2.generations', 0)
        ->where('report.days.2.pixelforge.matches', true)
        ->has('report.users', 2)
        ->where('report.users.0.email', 'ada@example.com')
        ->where('report.users.0.generations', 2)
        ->where('report.users.0.charged.amount', '0.3958875000')
        ->where('report.users.0.provider_cost.amount', '0.316710000')
        ->where('report.users.0.profit.amount', '0.0791775000')
        ->where('report.users.1.email', 'grace@example.com')
        ->where('report.users.1.charged.amount', '0.0677375000'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.promptedit.dev/v1/usage?start_date=2026-11-15&end_date=2026-11-17');
});

test('still shows our own figures when pixelforge usage cannot be loaded', function (): void {
    seedBilledGenerations();
    Http::fake(['pixelforge.promptedit.dev/v1/usage*' => Http::response(['error' => ['message' => 'Overloaded.']], 503)]);

    $response = $this->actingAs(operator())->get(route('admin.reports.profit', ['start_date' => '2026-11-15', 'end_date' => '2026-11-17']));

    $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('pixelforgeError', 'Overloaded.')
        ->where('report.totals.generations', 3)
        ->where('report.totals.pixelforge_cost', null)
        ->where('report.reconciliation.available', false)
        ->where('report.days.0.pixelforge', null));
});

test('defaults to the last 30 days ending today, like pixelforge', function (): void {
    $this->travelTo('2026-11-17 12:00:00');
    fakePixelforgeUsage(['2026-11-17' => [0, '0.000000000']]);

    $response = $this->actingAs(operator())->get(route('admin.reports.profit'));

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('filters', ['start_date' => '2026-10-19', 'end_date' => '2026-11-17'])
        ->has('report.days', 30));
});

test('rejects a range pixelforge would refuse', function (array $range, string $message): void {
    Http::fake();

    $response = $this->actingAs(operator())->get(route('admin.reports.profit', $range));

    $response->assertSessionHasErrors(['start_date' => $message]);
    Http::assertNothingSent();
})->with([
    'longer than 92 days' => [['start_date' => '2026-08-01', 'end_date' => '2026-11-17'], 'The report can cover at most 92 days.'],
]);

test('rejects an end date before the start date', function (): void {
    Http::fake();

    $response = $this->actingAs(operator())->get(route('admin.reports.profit', ['start_date' => '2026-11-17', 'end_date' => '2026-11-01']));

    $response->assertSessionHasErrors('end_date');
    Http::assertNothingSent();
});

test('exports every billed generation in the range as csv', function (): void {
    ['ada' => $ada] = seedBilledGenerations();
    $first = Generation::query()->where('charge', '0.3818875000')->sole();

    $response = $this->actingAs(operator())->get(route('admin.reports.profit.export', ['start_date' => '2026-11-15', 'end_date' => '2026-11-17']));

    $response->assertOk()->assertDownload('generations-2026-11-15-to-2026-11-17.csv');
    $lines = explode("\n", mb_trim($response->streamedContent()));
    expect($lines)->toHaveCount(4);
    expect($lines[0])->toBe('completed_at_utc,generation_id,pixelforge_id,organization_id,user_id,user_email,charged_usd,provider_cost_usd,profit_usd');
    expect($lines[1])->toBe(implode(',', [
        '2026-11-16T10:00:00Z', $first->id, $first->pixelforge_id, $first->organization_id, $ada->id, 'ada@example.com', '0.3818875000', '0.305510000', '0.0763775000',
    ]));
});

test('is only for operators', function (string $route): void {
    Http::fake();

    $this->actingAs(User::factory()->create(['email' => 'member@example.com']))->get(route($route))->assertForbidden();
    $this->post(route('logout'));
    $this->get(route($route))->assertRedirect(route('login'));
    Http::assertNothingSent();
})->with([
    'report' => ['admin.reports.profit'],
    'csv export' => ['admin.reports.profit.export'],
]);
