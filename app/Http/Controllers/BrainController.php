<?php

namespace App\Http\Controllers;

use App\Models\BrainRun;
use App\Models\Decision;
use App\Models\Lead;
use App\Models\Preference;
use App\Services\Brain;
use App\Services\MissionSettings;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BrainController extends Controller
{
    public function index(Brain $brain, MissionSettings $settings): Response
    {
        $preferences = Preference::query()->with('lead:id,key,name')->orderBy('id')->get();
        $active = $preferences->where('status', 'active')->values();
        $off = $preferences->where('status', 'off')->values();
        $evidenceIds = $preferences->flatMap(fn (Preference $preference): array => array_slice(array_values(array_filter((array) $preference->evidence, 'is_int')), -5))->unique()->all();
        $decisions = Decision::query()->whereIn('id', $evidenceIds)->with('task:id,title')->get()->keyBy('id');
        $learnAfter = (int) $settings->all()['brain_learn_after'];

        $html = view('mission.brain', [
            'active' => $active,
            'off' => $off,
            'decisions' => $decisions,
            'leads' => Lead::query()->orderBy('position')->orderBy('id')->get(),
            'run' => BrainRun::query()->latest('id')->first(),
            'waiting' => min($brain->pendingDecisions()->count(), $learnAfter),
            'learnAfter' => $learnAfter,
            'maxActive' => Preference::MAX_ACTIVE,
        ])->render();

        $unseen = $active->filter(fn (Preference $preference): bool => $preference->isNew())->pluck('id');
        if ($unseen->isNotEmpty()) {
            Preference::query()->whereIn('id', $unseen)->update(['seen_at' => now()]);
        }

        return response($html);
    }

    public function store(Request $request, Brain $brain): RedirectResponse
    {
        $data = $this->validated($request, $brain, false);

        $brain->withPreferenceLock(fn () => DB::transaction(function () use ($data, $brain): void {
            $this->guardCap();
            Preference::query()->create([
                'text' => $brain->clean($data['text']),
                'lead_id' => $this->leadId($data['lead'] ?? null),
                'status' => 'active',
                'source' => 'manual',
            ]);
        }));

        return to_route('brain.index')->with('status', 'Preference added.');
    }

    public function update(Request $request, Brain $brain, Preference $preference): RedirectResponse
    {
        $data = $this->validated($request, $brain, true);

        $brain->withPreferenceLock(fn () => DB::transaction(function () use ($data, $brain, $preference): void {
            $preference = Preference::query()->lockForUpdate()->findOrFail($preference->id);
            if ($data['status'] === 'active' && $preference->status !== 'active') {
                $this->guardCap();
            }

            $changes = ['status' => $data['status']];
            if (array_key_exists('text', $data)) {
                $changes['text'] = $brain->clean($data['text']);
                $changes['lead_id'] = $this->leadId($data['lead'] ?? null);
            }
            if ($preference->source === 'learned' && $preference->seen_at === null && array_key_exists('text', $data)) {
                $changes['seen_at'] = now();
            }
            $preference->update($changes);
        }));

        return to_route('brain.index')->with('status', 'Preference saved.');
    }

    public function destroy(Preference $preference): RedirectResponse
    {
        $preference->delete();

        return to_route('brain.index')->with('status', 'Preference deleted.');
    }

    public function learn(Brain $brain): RedirectResponse
    {
        $brain->start(true);

        return to_route('brain.index')->with('status', 'The Brain is learning from your latest decisions. This page shows the result when it finishes.');
    }

    /**
     * @return array{text?: string, lead?: ?string, status: string}
     */
    private function validated(Request $request, Brain $brain, bool $updating): array
    {
        $statusOnly = $updating && ! $request->hasAny(['text', 'lead']);
        $data = $request->validate([
            'text' => [$statusOnly ? 'sometimes' : 'required', 'string', 'max:300', function (string $attribute, mixed $value, Closure $fail) use ($brain): void {
                if ($brain->clean((string) $value) === '') {
                    $fail('The text must include some words.');
                }
            }],
            'lead' => ['nullable', 'string', Rule::exists('leads', 'key')],
            'status' => $updating ? ['required', Rule::in(Preference::STATUSES)] : ['nullable'],
        ]);
        $data['status'] = $updating ? $data['status'] : 'active';

        return $data;
    }

    private function guardCap(): void
    {
        if (Preference::query()->active()->count() >= Preference::MAX_ACTIVE) {
            throw ValidationException::withMessages(['text' => Preference::MAX_ACTIVE.' preferences are already active. Switch one off first.']);
        }
    }

    private function leadId(?string $key): ?int
    {
        return $key === null ? null : Lead::query()->where('key', $key)->value('id');
    }
}
