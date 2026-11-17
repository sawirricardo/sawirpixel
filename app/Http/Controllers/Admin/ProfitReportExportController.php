<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\ProfitReportRequest;
use App\Models\Generation;
use App\Services\Reporting\ProfitReport;
use Brick\Math\BigDecimal;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every billed generation in the range, one row each, to check against our own records.
 */
final class ProfitReportExportController
{
    public function __invoke(ProfitReportRequest $request, ProfitReport $report): StreamedResponse
    {
        $filename = sprintf('generations-%s-to-%s.csv', $request->startDate()->toDateString(), $request->endDate()->toDateString());

        return response()->streamDownload(function () use ($report, $request): void {
            $output = fopen('php://output', 'w') ?: throw new RuntimeException('Could not open the CSV output stream.');

            fputcsv($output, ['completed_at_utc', 'generation_id', 'pixelforge_id', 'organization_id', 'user_id', 'user_email', 'charged_usd', 'provider_cost_usd', 'profit_usd'], escape: '');

            $report->billedGenerations($request->startDate(), $request->endDate())
                ->with('user:id,email')
                ->lazy()
                ->each(function (Generation $generation) use ($output): void {
                    $charged = BigDecimal::of($generation->charge ?? '0');
                    $providerCost = BigDecimal::of($generation->provider_cost ?? '0');

                    fputcsv($output, [
                        $generation->completed_at?->utc()->toIso8601ZuluString(),
                        $generation->id,
                        $generation->pixelforge_id,
                        $generation->organization_id,
                        $generation->user_id,
                        $generation->user->email ?? '',
                        (string) $charged,
                        (string) $providerCost,
                        (string) $charged->minus($providerCost),
                    ], escape: '');
                });

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
