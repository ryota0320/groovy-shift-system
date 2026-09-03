<?php

namespace App\Http\Controllers\Master;

use App\Enums\EmploymentType;
use App\Enums\IncomeTaxCategory;
use App\Enums\TransportationTaxType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StaffRequest;
use App\Models\Staff;
use App\Models\Store;
use App\Services\ShiftMasterDataGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function __construct(private ShiftMasterDataGuard $shiftGuard) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'employment_type' => ['nullable', Rule::enum(EmploymentType::class)],
            'status' => ['nullable', Rule::in(['employed', 'retired'])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $targetDate = Carbon::parse($filters['date'] ?? today())->toDateString();
        $employmentType = isset($filters['employment_type'])
            ? EmploymentType::from($filters['employment_type'])
            : null;
        $status = $filters['status'] ?? '';
        $search = trim($filters['search'] ?? '');

        $staffs = Staff::query()
            ->when($employmentType, fn (Builder $query) => $query->where('employment_type', $employmentType->value))
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
            ->with([
                'storeAssignments' => fn ($query) => $query
                    ->whereDate('effective_from', '<=', $targetDate)
                    ->where(fn ($query) => $query
                        ->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $targetDate))
                    ->with('store:id,name'),
                'wageRates' => fn ($query) => $query
                    ->whereDate('effective_from', '<=', $targetDate)
                    ->where(fn ($query) => $query
                        ->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $targetDate)),
            ])
            ->orderBy('name')
            ->get()
            ->filter(function (Staff $staff) use ($status, $targetDate): bool {
                return match ($status) {
                    'employed' => $staff->isEmployedOn($targetDate),
                    'retired' => ! $staff->isEmployedOn($targetDate),
                    default => true,
                };
            })
            ->values()
            ->map(fn (Staff $staff): array => [
                'id' => $staff->id,
                'name' => $staff->name,
                'employment_type' => $staff->employment_type->value,
                'employment_type_label' => $staff->employment_type->label(),
                'is_employed' => $staff->isEmployedOn($targetDate),
                'stores' => $staff->storeAssignments
                    ->pluck('store.name')
                    ->filter()
                    ->values(),
                'hourly_wage' => $staff->wageRates->first()?->hourly_wage,
            ]);

        return Inertia::render('staffs/index', [
            'staffs' => $staffs,
            'filters' => [
                'date' => $targetDate,
                'employment_type' => $employmentType === null ? '' : $employmentType->value,
                'status' => in_array($status, ['employed', 'retired'], true) ? $status : '',
                'search' => $search,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('staffs/create');
    }

    public function store(StaffRequest $request): RedirectResponse
    {
        $staff = Staff::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'スタッフを登録しました。']);

        return to_route('staffs.edit', $staff);
    }

    public function edit(Staff $staff): Response
    {
        $staff->load([
            'user',
            'storeAssignments.store',
            'wageRates',
            'transportationFees.store',
            'incomeTaxSettings',
        ]);

        return Inertia::render('staffs/edit', [
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'employment_type' => $staff->employment_type->value,
                'employment_type_label' => $staff->employment_type->label(),
                'hired_at' => $staff->hired_at?->toDateString(),
                'retired_at' => $staff->retired_at?->toDateString(),
                'user' => $staff->user === null ? null : [
                    'id' => $staff->user->id,
                    'name' => $staff->user->name,
                    'email' => $staff->user->email,
                ],
                'assignments' => $staff->storeAssignments
                    ->sortByDesc('effective_from')
                    ->values()
                    ->map(fn ($assignment): array => [
                        'id' => $assignment->id,
                        'store_id' => $assignment->store_id,
                        'store_name' => $assignment->store->name,
                        'effective_from' => $assignment->effective_from->toDateString(),
                        'effective_to' => $assignment->effective_to?->toDateString(),
                    ]),
                'wage_rates' => $staff->wageRates
                    ->sortByDesc('effective_from')
                    ->values()
                    ->map(fn ($rate): array => [
                        'id' => $rate->id,
                        'hourly_wage' => $rate->hourly_wage,
                        'effective_from' => $rate->effective_from->toDateString(),
                        'effective_to' => $rate->effective_to?->toDateString(),
                    ]),
                'transportation_fees' => $staff->transportationFees
                    ->sortByDesc('effective_from')
                    ->values()
                    ->map(fn ($fee): array => [
                        'id' => $fee->id,
                        'store_id' => $fee->store_id,
                        'store_name' => $fee->store->name,
                        'amount_per_day' => $fee->amount_per_day,
                        'tax_type' => $fee->tax_type->value,
                        'tax_type_label' => $fee->tax_type->label(),
                        'effective_from' => $fee->effective_from->toDateString(),
                        'effective_to' => $fee->effective_to?->toDateString(),
                    ]),
                'income_tax_settings' => $staff->incomeTaxSettings
                    ->sortByDesc('effective_from')
                    ->values()
                    ->map(fn ($setting): array => [
                        'id' => $setting->id,
                        'tax_category' => $setting->tax_category->value,
                        'tax_category_label' => $setting->tax_category->label(),
                        'dependent_count' => $setting->dependent_count,
                        'effective_from' => $setting->effective_from->toDateString(),
                        'effective_to' => $setting->effective_to?->toDateString(),
                    ]),
            ],
            'stores' => Store::query()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active']),
            'options' => [
                'transportation_tax_types' => collect(TransportationTaxType::cases())
                    ->map(fn (TransportationTaxType $type): array => ['value' => $type->value, 'label' => $type->label()]),
                'income_tax_categories' => collect(IncomeTaxCategory::cases())
                    ->map(fn (IncomeTaxCategory $type): array => ['value' => $type->value, 'label' => $type->label()]),
            ],
        ]);
    }

    public function update(StaffRequest $request, Staff $staff): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($staff, $data): void {
            $staff = Staff::query()->lockForUpdate()->findOrFail($staff->id);

            if ($data['employment_type'] === EmploymentType::PartTime->value && $staff->user()->exists()) {
                throw ValidationException::withMessages([
                    'employment_type' => 'ログインアカウントがあるスタッフをアルバイトへ変更できません。',
                ]);
            }

            if ($staff->employment_type === EmploymentType::PartTime
                && $data['employment_type'] === EmploymentType::Employee->value
                && ($staff->wageRates()->exists() || $staff->incomeTaxSettings()->exists())) {
                throw ValidationException::withMessages([
                    'employment_type' => '時給または所得税設定の履歴があるアルバイトを社員へ変更できません。',
                ]);
            }

            $this->shiftGuard->ensureStaffPeriodCoversShifts(
                $staff,
                $data['hired_at'] ?? null,
                $data['retired_at'] ?? null,
            );
            $staff->update($data);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'スタッフ情報を更新しました。']);

        return back();
    }
}
