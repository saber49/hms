<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterTenantRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('branche') === null || $this->input('branche') === '') {
            $this->merge(['branche' => \App\Support\TenantProfile::INDUSTRY]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name'    => 'required|string|max:200',
            'full_name'       => 'required|string|max:100',
            'email'           => 'required|email|unique:central_users,email',
            'password'        => 'required|string|min:8',
            'phone'           => 'nullable|string|max:50',
            'plan'            => 'nullable|string|in:trial,basic,pro,enterprise',
            'branche'         => 'required|string|in:' . \App\Support\TenantProfile::INDUSTRY,
            'accept_terms'    => 'required|accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'company_name.required'  => 'Firmenname ist erforderlich.',
            'full_name.required'     => 'Vollstaendiger Name ist erforderlich.',
            'email.required'         => 'E-Mail-Adresse ist erforderlich.',
            'email.email'            => 'Bitte geben Sie eine gueltige E-Mail-Adresse ein.',
            'email.unique'           => 'Diese E-Mail-Adresse ist bereits registriert.',
            'password.required'      => 'Passwort ist erforderlich.',
            'password.min'           => 'Passwort muss mindestens 8 Zeichen lang sein.',
            'password.confirmed'     => 'Passwoerter stimmen nicht ueberein.',
            'accept_terms.required'  => 'Sie muessen die AGB akzeptieren.',
            'accept_terms.accepted'  => 'Sie muessen die AGB akzeptieren.',
            'branche.in'             => 'Clood-X ist ausschließlich für Sicherheitsdienstleistungen verfügbar.',
        ];
    }
}
