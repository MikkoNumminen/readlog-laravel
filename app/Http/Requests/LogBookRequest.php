<?php

namespace App\Http\Requests;

use App\Enums\Format;
use App\Support\LogBookData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input for logging a finished book.
 *
 * .NET counterpart: Dtos/LogBookRequest.cs, whose rules are DataAnnotations
 * attributes on the properties. Laravel keeps the rules in a method on a request
 * class instead of on the value object, which is why LogBookData next door has no
 * validation on it at all.
 *
 * The attribute-to-rule mapping, one for one with the source:
 *
 *   [Required] [StringLength(200)]  ->  ['required', 'string', 'max:200']
 *   [Url]                           ->  'url'
 *   [Range(1, 100_000)]             ->  'integer|min:1|max:100000'
 *   [NotInFuture]                   ->  'before_or_equal:today'
 *   [Range(0, 5)]                   ->  'integer|min:0|max:5'
 *
 * The one custom .NET attribute, Validation/NotInFutureAttribute.cs, needs no
 * counterpart class: Laravel ships before_or_equal with a relative date, so the
 * rule is a string rather than a type.
 */
class LogBookRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Provider key: an Open Library work id, a google:..., or a manual:....
            'open_library_id' => ['required', 'string', 'max:200'],
            'title' => ['required', 'string', 'max:500'],
            'author' => ['nullable', 'string', 'max:300'],
            'cover_url' => ['nullable', 'url'],
            'page_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'first_publish_year' => ['nullable', 'integer', 'min:1', 'max:3000'],
            'format' => ['required', Rule::enum(Format::class)],
            'finished_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],

            // Null clears the rating; 0 is a real value, so 'nullable' has to do the
            // work that a C# int? does in the type system.
            'rating' => ['nullable', 'integer', 'min:0', 'max:5'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        // .NET counterpart: [Display(Name = "Finished on")].
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

    public function toData(): LogBookData
    {
        $validated = $this->validated();

        return new LogBookData(
            openLibraryId: $validated['open_library_id'],
            title: $validated['title'],
            author: $validated['author'] ?? null,
            coverUrl: $validated['cover_url'] ?? null,
            pageCount: $validated['page_count'] ?? null,
            firstPublishYear: $validated['first_publish_year'] ?? null,
            format: Format::from($validated['format']),
            finishedAt: $validated['finished_at'],
            rating: $validated['rating'] ?? null,
        );
    }
}
