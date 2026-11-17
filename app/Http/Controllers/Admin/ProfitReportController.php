<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\Pixelforge\PixelforgeException;
use App\Http\Requests\ProfitReportRequest;
use App\Services\Pixelforge\Data\UsageReport;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Reporting\ProfitReport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class ProfitReportController
{
    public function index(ProfitReportRequest $request, ProfitReport $report, PixelforgeClient $client): Response
    {
        Gate::authorize('viewProfitReport');

        $pixelforge = null;
        $pixelforgeError = null;

        try {
            $pixelforge = $client->usage($request->startDate(), $request->endDate());
        } catch (PixelforgeException|ConnectionException|RuntimeException $exception) {
            $pixelforgeError = $exception->getMessage();
        }

        return Inertia::render('admin/ProfitReport', [
            'filters' => [
                'start_date' => $request->startDate()->toDateString(),
                'end_date' => $request->endDate()->toDateString(),
            ],
            'report' => $report->build($request->startDate(), $request->endDate(), $pixelforge instanceof UsageReport ? $pixelforge : null),
            'pixelforgeError' => $pixelforgeError,
        ]);
    }
}
