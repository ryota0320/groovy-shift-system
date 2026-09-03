<?php

namespace App\Http\Controllers\Master;

use App\Enums\EmploymentType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StaffUserRequest;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StaffUserController extends Controller
{
    public function store(StaffUserRequest $request, Staff $staff): RedirectResponse
    {
        $this->ensureEmployee($staff);
        $data = $request->validated();

        DB::transaction(function () use ($staff, $data): void {
            Staff::query()->lockForUpdate()->findOrFail($staff->id);

            $attributes = [
                'staff_id' => $staff->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => UserRole::Employee,
            ];

            if (! empty($data['password'])) {
                $attributes['password'] = $data['password'];
            }

            User::query()->updateOrCreate(['staff_id' => $staff->id], $attributes);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'ログインアカウントを保存しました。']);

        return back();
    }

    public function destroy(Staff $staff): RedirectResponse
    {
        $user = $staff->user()->firstOrFail();
        abort_if(auth()->id() === $user->id, 422, '自分自身のアカウントは削除できません。');

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'ログインアカウントを削除しました。']);

        return back();
    }

    private function ensureEmployee(Staff $staff): void
    {
        if ($staff->employment_type !== EmploymentType::Employee) {
            throw ValidationException::withMessages([
                'staff' => 'アルバイトにはログインアカウントを作成できません。',
            ]);
        }
    }
}
