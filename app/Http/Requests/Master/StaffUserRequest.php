<?php

namespace App\Http\Requests\Master;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffUserRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $staff = $this->route('staff');
        $userId = $staff instanceof Staff ? $staff->user?->id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($userId),
            ],
            'password' => [
                $userId === null ? 'required' : 'nullable',
                'confirmed',
                Password::defaults(),
            ],
        ];
    }
}
