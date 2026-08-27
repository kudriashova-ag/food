@props(['section'])

@php
    $type = $section->type;
    $isChoice = $type === \App\Enums\MenuSectionType::Choice;

    // Колір мітки підказує роль секції, не змушуючи читати підпис.
    $badge = match ($type) {
        \App\Enums\MenuSectionType::Complex => 'bg-brand-50 text-brand-700',
        \App\Enums\MenuSectionType::Choice => 'bg-sky-50 text-sky-700',
        default => 'bg-ink-100 text-ink-600',
    };
@endphp

<div>
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <h3 class="font-semibold">{{ $section->title }}</h3>
        <span class="badge {{ $badge }}">{{ $type->label() }}</span>

        @if ($isChoice)
            <span class="text-xs text-ink-400">оберіть один варіант</span>
        @endif
    </div>

    @if ($isChoice)
        <div class="space-y-2">
            @foreach ($section->sectionDishes as $sectionDish)
                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-ink-200 bg-white p-3
                              transition has-[:checked]:border-brand-400 has-[:checked]:bg-brand-50/50 hover:border-ink-300">
                    <input type="radio" name="choice[{{ $section->id }}]"
                           value="{{ $sectionDish->dish_id }}"
                           class="h-5 w-5 shrink-0 accent-brand-600"
                           data-price="{{ $sectionDish->dish->price }}" data-section="{{ $section->id }}">
                    <x-dish-row :dish="$sectionDish->dish" />
                </label>
            @endforeach

            <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-transparent p-3 text-sm text-ink-500
                          transition has-[:checked]:border-ink-200 has-[:checked]:bg-ink-100/60">
                <input type="radio" name="choice[{{ $section->id }}]" value="" checked
                       class="h-5 w-5 shrink-0 accent-ink-400">
                Не потрібно
            </label>

            <div class="flex items-center gap-2 px-3 text-sm text-ink-500">
                <span>Порцій</span>
                <select name="choice_qty[{{ $section->id }}]" data-choice-qty="{{ $section->id }}"
                        class="rounded-lg border border-ink-300 bg-white px-2.5 py-1.5 text-sm font-medium
                               focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100">
                    @for ($n = 1; $n <= 5; $n++)
                        <option value="{{ $n }}">{{ $n }}</option>
                    @endfor
                </select>
            </div>
        </div>
    @else
        <div class="space-y-2">
            @foreach ($section->sectionDishes as $sectionDish)
                @php
                    // Комплекс приходить із відміченими стравами, зняти галочку можна з будь-якої.
                    $default = $type === \App\Enums\MenuSectionType::Complex ? 1 : 0;
                @endphp

                <div class="flex items-center gap-3 rounded-xl border border-ink-200 bg-white p-3 transition
                            focus-within:border-brand-400 hover:border-ink-300">
                    <x-dish-row :dish="$sectionDish->dish" />

                    <select name="qty[{{ $section->id }}][{{ $sectionDish->dish_id }}]"
                            data-price="{{ $sectionDish->dish->price }}"
                            class="w-16 shrink-0 rounded-lg border border-ink-300 bg-white px-2 py-2 text-center text-sm font-semibold
                                   focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100">
                        @for ($n = 0; $n <= 5; $n++)
                            <option value="{{ $n }}" @selected($n === $default)>
                                {{ $n === 0 ? '—' : $n }}
                            </option>
                        @endfor
                    </select>
                </div>
            @endforeach
        </div>
    @endif
</div>
