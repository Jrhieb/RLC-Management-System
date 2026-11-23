<?php

namespace App\Http\Requests;

use App\Enums\PpiApplicationStatus;
use App\Models\PpiHouseholdApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePpiApplicationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('application');

        return $application instanceof PpiHouseholdApplication
            && ($this->user()?->can('updateStatus', $application) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(PpiApplicationStatus::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
