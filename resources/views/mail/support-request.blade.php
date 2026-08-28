<x-mail::message>
# Питання з сайту

**Від:** {{ $request->name }}
**Email:** {{ $request->email }}
@if ($request->user)
**Обліковий запис:** {{ $request->user->login }}
@endif
**Надіслано:** {{ $request->created_at->translatedFormat('d.m.Y о H:i') }}

---

{{ $request->message }}

---

Щоб відповісти, просто натисніть «Відповісти» — лист піде на {{ $request->email }}.

{{ \App\Models\Setting::get('notification_signature', 'Шкільна їдальня') }}
</x-mail::message>
