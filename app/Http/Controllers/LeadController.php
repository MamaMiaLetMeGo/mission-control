<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LeadController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail): void {
                $key = Str::slug((string) $value);
                if ($key === '') {
                    $fail('The name must include letters or numbers.');
                } elseif (strlen($key) > 50) {
                    $fail('Use a shorter name: its key can have at most 50 characters.');
                } elseif (Lead::query()->where('key', $key)->exists()) {
                    $fail('A lead with this name already exists.');
                }
            }],
            'instructions' => ['required', 'string', 'max:4000'],
        ]);

        Lead::query()->create([
            'key' => Str::slug($data['name']),
            'name' => $data['name'],
            'instructions' => $data['instructions'],
            'is_active' => true,
            'position' => ((int) Lead::query()->max('position')) + 1,
        ]);

        return to_route('settings.edit')->with('status', 'Lead added.');
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'instructions' => ['required', 'string', 'max:4000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $lead->update($data);

        return to_route('settings.edit')->with('status', 'Lead saved.');
    }
}
