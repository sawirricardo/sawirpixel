<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateOrganization;
use App\Http\Requests\StoreOrganizationRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class OrganizationController
{
    public function create(): Response
    {
        return Inertia::render('organizations/Create');
    }

    public function store(StoreOrganizationRequest $request, #[CurrentUser] User $user, CreateOrganization $createOrganization): RedirectResponse
    {
        $organization = $createOrganization->handle($user, $request->string('name')->value());

        Inertia::flash('success', sprintf('%s is ready. Start generating!', $organization->name));

        return to_route('home');
    }
}
