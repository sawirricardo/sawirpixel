<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The terms of service and the privacy policy. Who runs the service and how to reach them come from
 * config, so the documents stay accurate across deployments.
 */
final class LegalController
{
    public function show(string $document): Response
    {
        abort_unless(in_array($document, ['terms', 'privacy'], true), 404);

        $contactEmail = Config::get('app.legal.contact_email');

        return Inertia::render($document === 'terms' ? 'legal/Terms' : 'legal/Privacy', [
            'appName' => Config::string('app.name'),
            'operator' => Config::string('app.legal.operator'),
            'contactEmail' => is_string($contactEmail) && $contactEmail !== '' ? $contactEmail : null,
            'updatedAt' => Config::string('app.legal.updated_at'),
        ]);
    }
}
