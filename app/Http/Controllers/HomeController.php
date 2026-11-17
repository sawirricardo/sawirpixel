<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class HomeController
{
    public function __invoke(#[CurrentUser] ?User $user): Response
    {
        $organization = $user?->currentOrganization;

        return Inertia::render('Home', [
            'sizes' => array_map(fn (ImageSize $size): string => $size->value, ImageSize::cases()),
            'qualities' => array_map(fn (ImageQuality $quality): string => $quality->value, ImageQuality::cases()),
            'maxPromptLength' => StoreGenerationRequest::MAX_PROMPT_LENGTH,
            'maxReferenceImages' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            'canGenerate' => $user instanceof User && $organization !== null
                && Gate::forUser($user)->allows('create', [Generation::class, $organization]),
        ]);
    }
}
