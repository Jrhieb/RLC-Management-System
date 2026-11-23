<?php

namespace App\Http\Requests;

use App\Services\PpiGuardianResultDelivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StorePpiApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (filled($this->input('email')) || $validator->errors()->has('contact_number')) {
                    return;
                }

                try {
                    PpiGuardianResultDelivery::smsRecipient((string) $this->input('contact_number'));
                } catch (ValidationException) {
                    $validator->errors()->add('contact_number', 'Enter a mobile number that can receive an SMS.');
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $requiredAnswers = (int) config('ppi.scorecard.required_questions');

        return [
            'submission_key' => ['required', 'uuid'],
            'child_first_name' => ['required', 'string', 'max:100'],
            'child_middle_name' => ['nullable', 'string', 'max:100'],
            'child_last_name' => ['required', 'string', 'max:100'],
            'child_suffix' => ['nullable', 'string', 'max:10'],
            'child_sex' => ['required', 'in:male,female'],
            'child_date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'guardian_name' => ['required', 'string', 'max:150'],
            'guardian_relationship' => ['required', 'in:Parent,Guardian,Other'],
            'contact_number' => ['required', 'string', 'max:32', 'regex:/^(?=(?:\D*\d){7,15}\D*$)[+0-9()\s-]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'barangay' => ['required', 'string', 'max:255'],
            'city_municipality' => ['required', 'string', 'max:255'],
            'complete_address' => ['required', 'string', 'max:500'],
            'privacy_acknowledgment' => ['accepted'],
            'answers' => ['required', 'array', 'size:'.$requiredAnswers],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.option_id' => ['required', 'integer'],
            'household_members' => ['nullable', 'array', 'max:30'],
            'household_members.*.name' => ['required', 'string', 'max:150'],
            'household_members.*.relationship' => ['nullable', 'string', 'max:50'],
            'household_members.*.monthly_income' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }
}
