<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\PollGeneration;
use App\Jobs\ReconcileGenerations;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Support\Facades\Queue;

test('re-checks generations that stopped moving and leaves the rest alone', function (): void {
    Queue::fake([SubmitGeneration::class, PollGeneration::class]);
    $this->travel(-15)->minutes();
    $stalePending = Generation::factory()->create();
    $staleProcessing = Generation::factory()->queued()->create(['status' => GenerationStatus::Processing]);
    Generation::factory()->queued()->create(['status' => GenerationStatus::Succeeded]);
    Generation::factory()->create(['status' => GenerationStatus::Failed]);
    $this->travelBack();
    $this->freezeTime();
    Generation::factory()->create();
    Generation::factory()->queued()->create();

    (new ReconcileGenerations)->handle();

    Queue::assertPushed(SubmitGeneration::class, 1);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job): bool => $job->generation->is($stalePending));
    Queue::assertPushed(PollGeneration::class, 1);
    Queue::assertPushed(PollGeneration::class, fn (PollGeneration $job): bool => $job->generation->is($staleProcessing));
});
