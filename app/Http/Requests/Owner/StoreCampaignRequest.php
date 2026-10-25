<?php

namespace App\Http\Requests\Owner;

use App\Rules\CampaignBodyLength;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The campaign body (spec Section 10.3): required, and bounded
 * by an abuse ceiling of ten segments' worth of UCS-2 (670
 * UTF-16 units). The discipline is the segment counter, not a
 * character cap; the ceiling exists so a paste error cannot
 * become a four-figure send.
 */
class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => [
                'required',
                'string',
                new CampaignBodyLength,
            ],
            'draft_id' => ['nullable', 'integer'],
        ];
    }
}
