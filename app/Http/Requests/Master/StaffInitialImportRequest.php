<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

class StaffInitialImportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:5120',
                'mimes:csv,txt,xlsx',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'インポートファイルを選択してください。',
            'file.max' => 'ファイルサイズは5MB以下にしてください。',
            'file.mimes' => 'CSVまたはXLSXファイルを選択してください。',
            'file.mimetypes' => 'CSVまたはXLSXファイルを選択してください。',
        ];
    }
}
