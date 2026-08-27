<x-mail::message>
# Замовлення на {{ $date->translatedFormat('l, d.m.Y') }}

{{ $supplier->name }}

@foreach ($lines as $line)
@if ($line === '')

@else
{{ $line }}
@endif
@endforeach

@if ($note)
{{ $note }}
@endif

@if ($data['positions'] > 0)
Список для видачі по класах — у файлі, доданому до листа.
@endif

{{ $signature }}
</x-mail::message>
