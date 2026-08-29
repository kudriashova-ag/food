@props(['section'])

@php
    $type = $section->type;
    $isChoice = $type === \App\Enums\MenuSectionType::Choice;

    // Колір мітки підказує роль секції, не змушуючи читати підпис.
    $badge = match ($type) {
        \App\Enums\MenuSectionType::Complex => 'bg-brand-50 text-deep-700',
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

    @if ($type === \App\Enums\MenuSectionType::Complex)
        {{-- Комплекс: інформаційний список страв + єдиний select на весь набір. --}}
        <div class="rounded-xl border border-ink-200 bg-white p-4">
            <ul class="mb-3 space-y-1 text-sm text-ink-600">
                @foreach ($section->sectionDishes as $sectionDish)
                    <li class="flex items-center gap-2">
                        <span class="text-ink-400">•</span>
                        {{ $sectionDish->dish->name }}
                    </li>
                @endforeach
            </ul>

            <div class="flex items-center justify-between gap-3 border-t border-ink-100 pt-3">
                <span class="font-semibold text-deep-700 tabular-nums">
                    {{ number_format((float) $section->price, 2, ',', ' ') }} грн
                </span>

                <div class="flex items-center gap-2 text-sm text-ink-500">
                    <span>Порцій</span>
                    <select name="complex_qty[{{ $section->id }}]" data-price="{{ $section->price }}"
                            data-complex="{{ $section->id }}"
                            class="w-16 rounded-lg border border-ink-300 bg-white px-2 py-1.5 text-center text-sm font-semibold
                                   focus:border-deep-500 focus:outline-none focus:ring-2 focus:ring-deep-100">
                        @for ($n = 0; $n <= 5; $n++)
                            <option value="{{ $n }}" @selected($n === 1)>{{ $n === 0 ? '—' : $n }}</option>
                        @endfor
                    </select>
                </div>
            </div>
        </div>
    @elseif ($isChoice)
        {{-- Вибір однієї страви з переліку. Можна замовити окремо від комплексу. --}}
        <div class="space-y-2">
            @foreach ($section->sectionDishes as $sectionDish)
                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-ink-200 bg-white p-3
                              transition has-[:checked]:border-deep-500 has-[:checked]:bg-brand-50/50 hover:border-ink-300">
                    <input type="radio" name="choice[{{ $section->id }}]"
                           value="{{ $sectionDish->dish_id }}"
                           class="h-5 w-5 shrink-0 accent-deep-700"
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

            <div class="flex items-center gap-2 px-3 text-xs text-ink-400">
                <span>💡 Можна замовити тільки цю страву, без комплексу</span>
            </div>

            <div class="flex items-center gap-2 px-3 text-sm text-ink-500">
                <span>Порцій</span>
                <select name="choice_qty[{{ $section->id }}]" data-choice-qty="{{ $section->id }}"
                        class="rounded-lg border border-ink-300 bg-white px-2.5 py-1.5 text-sm font-medium
                               focus:border-deep-500 focus:outline-none focus:ring-2 focus:ring-deep-100">
                    @for ($n = 1; $n <= 5; $n++)
                        <option value="{{ $n }}">{{ $n }}</option>
                    @endfor
                </select>
            </div>
        </div>
    @else
        {{-- Extra: незалежні додаткові страви. --}}
        <div class="space-y-2">
            @foreach ($section->sectionDishes as $sectionDish)
                <div class="flex items-center gap-3 rounded-xl border border-ink-200 bg-white p-3 transition
                            focus-within:border-deep-500 hover:border-ink-300">
                    <x-dish-row :dish="$sectionDish->dish" />

                    <select name="qty[{{ $section->id }}][{{ $sectionDish->dish_id }}]"
                            data-price="{{ $sectionDish->dish->price }}"
                            class="w-16 shrink-0 rounded-lg border border-ink-300 bg-white px-2 py-2 text-center text-sm font-semibold
                                   focus:border-deep-500 focus:outline-none focus:ring-2 focus:ring-deep-100">
                        @for ($n = 0; $n <= 5; $n++)
                            <option value="{{ $n }}" @selected($n === 0)>
                                {{ $n === 0 ? '—' : $n }}
                            </option>
                        @endfor
                    </select>
                </div>
            @endforeach
        </div>
    @endif
</div>
