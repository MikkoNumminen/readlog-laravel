<?php

namespace App\Http\Requests;

use App\Enums\Format;
use App\Support\UpdateReadEntryData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input for editing a read entry. The edit form posts the full current state.
 *
 * .NET counterpart: Dtos/UpdateReadEntryRequest.cs.
 */
class UpdateReadEntryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', Rule::enum(Format::class)],
            'finished_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'rating' => ['nullable', 'integer', 'min:0', 'max:5'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'finished_at' => 'finished on',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'finished_at.before_or_equal' => 'Finished on cannot be in the future.',
        ];
    }

    public function toData(): UpdateReadEntryData
    {
        $validated = $this->validated();

        return new UpdateReadEntryData(
            format: Format::from($validated['format']),
            finishedAt: $validated['finished_at'],
            rating: $validated['rating'] ?? null,
        );
    }
}
