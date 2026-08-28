@extends('layouts.app')

@section('title', 'Допомога')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Допомога</h1>
        <p class="mt-1 text-ink-500">Опишіть питання — відповідь надійде на вашу пошту.</p>
    </div>

    <form method="POST" action="{{ route('support.store') }}" class="card p-5 sm:p-6">
        @csrf

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="name" class="mb-1.5 block text-sm font-semibold">Ваше ім'я</label>
                <input type="text" id="name" name="name" required maxlength="255"
                       value="{{ old('name', $name) }}" class="field w-full" placeholder="Марія Іваненко">
                @error('name')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="mb-1.5 block text-sm font-semibold">Email для відповіді</label>
                <input type="email" id="email" name="email" required maxlength="255"
                       value="{{ old('email', $email) }}" class="field w-full" placeholder="mail@example.com">
                @error('email')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="mt-4">
            <label for="message" class="mb-1.5 block text-sm font-semibold">Питання</label>
            <textarea id="message" name="message" rows="6" required minlength="10" maxlength="2000"
                      class="field w-full" placeholder="Опишіть, що сталося або що потрібно з'ясувати.">{{ old('message') }}</textarea>
            @error('message')
                <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
            @enderror
            <p class="mt-1.5 text-xs text-ink-400">Від 10 до 2000 символів.</p>
        </div>

        <button type="submit" class="btn-primary mt-5 w-full sm:w-auto">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>
            </svg>
            Надіслати питання
        </button>
    </form>

    <div class="card mt-4 flex items-start gap-3 p-5 text-sm text-ink-600">
        <svg class="mt-0.5 h-5 w-5 shrink-0 text-ink-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>
        </svg>
        <p>
            Як користуватися сайтом, описано на сторінці
            <a href="{{ route('support.info') }}" class="font-semibold text-deep-700 underline">Інформація про сервіс</a>.
            @php $contacts = \App\Models\Setting::get('school_contacts'); @endphp
            @if ($contacts)
                Термінові питання — {{ $contacts }}.
            @endif
        </p>
    </div>
@endsection
