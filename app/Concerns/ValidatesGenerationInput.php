<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use Illuminate\Validation\Rule;

/**
 * Validation and typed accessors for the generation inputs that affect cost, shared by the estimate
 * and the submission form requests.
 */
trait ValidatesGenerationInput
{
    public const int MAX_PROMPT_LENGTH = 4000;

    public const int MAX_REFERENCE_IMAGES = 4;

    /**
     * @return array<string, list<mixed>>
     */
    public function generationRules(bool $promptRequired): array
    {
        return [
            'prompt' => [$promptRequired ? 'required' : 'nullable', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'parent_id' => ['nullable', 'integer'],
            // A follow-up's parent image takes one of Pixelforge's reference slots.
            'reference_images' => ['array', 'max:'.(self::MAX_REFERENCE_IMAGES - ($this->filled('parent_id') ? 1 : 0))],
            'reference_images.*' => ['required', 'string', 'distinct', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reference_images.*' => 'reference image URL',
        ];
    }

    public function prompt(): string
    {
        return $this->string('prompt')->value();
    }

    public function size(): ImageSize
    {
        return $this->enum('size', ImageSize::class) ?? ImageSize::Square;
    }

    public function quality(): ImageQuality
    {
        return $this->enum('quality', ImageQuality::class) ?? ImageQuality::Medium;
    }

    public function parentId(): ?int
    {
        return $this->filled('parent_id') ? $this->integer('parent_id') : null;
    }

    /**
     * @return list<string>
     */
    public function referenceImages(): array
    {
        return array_values(array_filter($this->array('reference_images'), is_string(...)));
    }
}
