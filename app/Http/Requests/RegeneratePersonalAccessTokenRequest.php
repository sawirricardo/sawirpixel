<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\ValidatesTokenExpiration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new lifetime for a token whose secret is being replaced.
 */
final class RegeneratePersonalAccessTokenRequest extends FormRequest
{
    use ValidatesTokenExpiration;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['expiration' => $this->expirationRules()];
    }
}
