@props(['section'])

<div>
    <div class="mb-2 flex items-baseline gap-2">
        <h3 class="text-sm font-medium">{{ $section->title }}</h3>
        <span class="text-xs text-zinc-400">{{ $section->type->label() }}</span>
    </div>

    @if ($section->type === \App\Enums\MenuSectionType::Choice)
        <div class="space-y-2">
            @foreach ($section->sectionDishes as $sectionDish)
                <label class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3">
                    <input type="radio" name="choice[{{ $section->id }}]"
                           value="{{ $sectionDish->dish_id }}" class="h-5 w-5"
                           data-price="{{ $sectionDish->dish->price }}" data-section="{{ $section->id }}">
                    <x-dish-row :dish="$sectionDish->dish" />
                </label>
            @endforeach

            <label class="flex items-center gap-3 px-3 text-sm text-zinc-500">
                <input type="radio" name="choice[{{ $section->id }}]" value="" checked
                       class="h-5 w-5">
                Не потрібно
            </label>

            <div class="px-3">
                <label class="text-xs text-zinc-500">
                    Порцій
                    <select name="choice_qty[{{ $section->id }}]" data-choice-qty="{{ $section->id }}"
                            class="ml-1 rounded border border-zinc-300 px-2 py-1 text-sm">
                        @for ($n = 1; $n <= 5; $n++)
                            <option value="{{ $n }}">{{ $n }}</option>
                        @endfor
                    </select>
                </label>
            </div>
        </div>
    @else
        <div class="space-y-2">
            @foreach ($section->sectionDishes as $sectionDish)
                @php
                    // Комплекс приходить із відміченими стравами, зняти галочку можна з будь-якої.
                    $default = $section->type === \App\Enums\MenuSectionType::Complex ? 1 : 0;
                @endphp

                <div class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3">
                    <x-dish-row :dish="$sectionDish->dish" />

                    <select name="qty[{{ $section->id }}][{{ $sectionDish->dish_id }}]"
                            data-price="{{ $sectionDish->dish->price }}"
                            class="shrink-0 rounded border border-zinc-300 px-2 py-1.5 text-sm">
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
