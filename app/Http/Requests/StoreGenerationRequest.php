<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\ValidatesGenerationInput;
use Illuminate\Foundation\Http\FormRequest;

final class StoreGenerationRequest extends FormRequest
{
    use ValidatesGenerationInput;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->generationRules(promptRequired: true);
    }
}
