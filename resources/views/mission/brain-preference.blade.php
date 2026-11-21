@php
    $marker = (string) $preference->id;
    $editing = (string) old('brain_form') === $marker;
    $shown = array_slice(array_values(array_filter((array) $preference->evidence, 'is_int')), -5);
    $sources = collect($shown)->map(fn ($id) => $decisions->get($id))->filter();
@endphp
<article class="p-5 border-b border-line last:border-b-0">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium break-words">{{ $preference->text }}</p>
            <p class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px] text-muted"><span>{{ $preference->lead?->name ?? 'All leads' }}</span><span class="status-badge">{{ $preference->source === 'learned' ? 'Learned' : 'Yours' }}</span>@if(! $off && $preference->isNew())<span class="status-badge status-review tag-new">New</span>@endif</p>
            @if($off && $preference->note)<p class="mt-2 text-xs text-muted">{{ $preference->note }}</p>@endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form action="{{ route('brain.update', $preference) }}" method="POST">@csrf
                <input type="hidden" name="text" value="{{ $preference->text }}"><input type="hidden" name="lead" value="{{ $preference->lead?->key }}"><input type="hidden" name="status" value="{{ $off ? 'active' : 'off' }}">
                <button class="button button-secondary button-small" type="submit">{{ $off ? 'Switch on' : 'Switch off' }}</button>
            </form>
            <form action="{{ route('brain.destroy', $preference) }}" method="POST">@csrf<button class="button button-quiet button-small" type="submit">Delete</button></form>
        </div>
    </div>
    @if($sources->isNotEmpty())
    <ul class="mt-3 space-y-1.5 text-xs text-muted">
        @foreach($sources as $decision)
        <li><span class="font-medium">{{ ['approve' => 'Approved for merge', 'request_changes' => 'Changes requested', 'defer' => 'Deferred for later', 'reject' => 'Task rejected', 'merge' => 'Merged locally'][$decision->action] ?? ucfirst(str_replace('_', ' ', $decision->action)) }}</span>@if($decision->task) · <a class="text-link" href="{{ route('tasks.show', $decision->task) }}">MC-{{ str_pad((string) $decision->task->id, 3, '0', STR_PAD_LEFT) }}</a>@endif · {{ \Illuminate\Support\Str::limit((string) $decision->feedback, 120) }}</li>
        @endforeach
    </ul>
    @endif
    <details class="mt-3" @if($editing) open @endif>
        <summary class="text-xs font-medium text-accent">Edit</summary>
        <form action="{{ route('brain.update', $preference) }}" method="POST" class="mt-3 space-y-3">@csrf
            <input type="hidden" name="brain_form" value="{{ $marker }}"><input type="hidden" name="status" value="{{ $preference->status }}">
            <div class="field"><label for="preference-text-{{ $preference->id }}">Preference</label><input id="preference-text-{{ $preference->id }}" name="text" value="{{ $editing ? old('text') : $preference->text }}" maxlength="300" required>@if($editing && $errors->has('text'))<p class="text-xs text-[#a55d68]" role="alert">{{ $errors->first('text') }}</p>@endif</div>
            <div class="field"><label for="preference-lead-{{ $preference->id }}">Applies to</label><select id="preference-lead-{{ $preference->id }}" name="lead"><option value="">All leads</option>@foreach($leads as $lead)<option value="{{ $lead->key }}" @selected(($editing ? old('lead') : $preference->lead?->key) === $lead->key)>{{ $lead->name }}</option>@endforeach</select>@if($editing && $errors->has('lead'))<p class="text-xs text-[#a55d68]" role="alert">{{ $errors->first('lead') }}</p>@endif</div>
            <div class="flex justify-end"><button class="button button-secondary button-small" type="submit">Save</button></div>
        </form>
    </details>
</article>
