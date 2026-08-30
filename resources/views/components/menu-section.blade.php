@props(['section'])

@php
    $type = $section->type;
    $isChoice = $type === \App\Enums\MenuSectionType::Choice;
@endphp

<div>
    @if ($isChoice)
        <div class="mb-3">
            <span class="text-xs text-ink-400">оберіть один варіант</span>
        </div>
    @endif

    @if ($type === \App\Enums\MenuSectionType::Complex)
        {{-- Комплекс: фото, назва й вага кожної страви в сітці по 4 в ряд,
             вибору немає — лише кількість порцій набору. --}}
        <div class="rounded-xl border border-ink-200 bg-white p-4">
            <div class="mb-3 grid grid-cols-4 gap-3">
                @foreach ($section->sectionDishes as $sectionDish)
                    <div class="flex flex-col items-center gap-1.5 text-center">
                        <x-dish-thumb :dish="$sectionDish->dish" size="grid" />

                        <div class="min-w-0 leading-tight">
                            <div class="truncate text-xs font-medium text-ink-700">{{ $sectionDish->dish->name }}</div>

                            @if ($sectionDish->dish->portion)
                                <div class="text-xs text-ink-400">{{ $sectionDish->dish->portion }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

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
