{{-- The status filter, shared by the phone list and the desktop table so the
     two cannot offer different controls. --}}
<form method="GET" class="flex flex-wrap items-end gap-2">
    <div class="w-44">
        <x-form.select name="status" label="Status" blank="Any" :options="$statuses" :selected="request('status')" />
    </div>
    <label class="flex items-center gap-2 py-2 text-sm text-slate-600">
        <input type="checkbox" name="outstanding" value="1" @checked(request()->boolean('outstanding'))
               class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        Still open only
    </label>
    <button type="submit" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Filter</button>
    @if (request()->hasAny(['status', 'outstanding']))
        <a href="{{ route('assistant.tickets.index') }}" class="px-2 py-2 text-sm text-slate-500 underline">Clear</a>
    @endif
</form>
