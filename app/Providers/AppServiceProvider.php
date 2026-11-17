<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\OrgUser;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Services\Pixelforge\Ai\PixelforgeProvider;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\WebhookSignatureVerifier;
use App\Services\Pricing\GenerationCostEstimator;
use App\Services\Pricing\PricingCatalog;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecuritySchemes\HttpSecurityScheme;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Sleep;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Ai;
use Laravel\Horizon\Horizon;
use Laravel\Sanctum\Sanctum;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn (): PixelforgeClient => new PixelforgeClient(
            baseUrl: config()->string('services.pixelforge.url'),
            apiKey: $this->nullableStringConfig('services.pixelforge.key'),
            timeout: config()->integer('services.pixelforge.timeout'),
        ));

        $this->app->singleton(GenerationCostEstimator::class, fn (Application $app): GenerationCostEstimator => new GenerationCostEstimator(
            catalog: $app->make(PricingCatalog::class),
            markup: config()->string('services.pixelforge.markup'),
        ));

        $this->app->singleton(WebhookSignatureVerifier::class, fn (): WebhookSignatureVerifier => new WebhookSignatureVerifier(
            secret: $this->nullableStringConfig('services.pixelforge.webhook_secret'),
            toleranceSeconds: config()->integer('services.pixelforge.webhook_tolerance'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::unguard();
        Model::shouldBeStrict();
        DB::prohibitDestructiveCommands(app()->isProduction());
        Date::use(CarbonImmutable::class);
        Vite::useAggressivePrefetching();
        Http::preventStrayRequests(app()->runningUnitTests());
        Sleep::fake(app()->runningUnitTests());
        URL::forceHttps(app()->isProduction());
        Password::defaults(fn (): ?Password => app()->isProduction() ? Password::min(12)->letters()->mixedCase()->numbers()->symbols()->max(255)->uncompromised() : null);

        Relation::enforceMorphMap([
            'user' => User::class,
            'org_user' => OrgUser::class,
            'role' => Role::class,
        ]);

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Ai::extend('pixelforge', fn (Application $app, array $config): PixelforgeProvider => new PixelforgeProvider(
            $config,
            $app->make(Dispatcher::class),
            $app->make(PixelforgeClient::class),
        ));

        Gate::define('viewApiDocs', fn (User $user): bool => $user->isSuperAdmin());
        Gate::define('viewHorizon', fn (User $user): bool => $user->isSuperAdmin());

        Horizon::auth(fn (Request $request): bool => app()->environment('local')
            || Gate::check('viewHorizon', [$request->user()]));

        // @codeCoverageIgnoreStart
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(
                    (new HttpSecurityScheme('bearer'))->as('http')
                );
            });
        // @codeCoverageIgnoreEnd
    }

    private function nullableStringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
