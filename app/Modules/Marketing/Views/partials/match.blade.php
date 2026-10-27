@if ($value === 'exact')
    <x-panou.badge tone="ok">exact</x-panou.badge>
@elseif ($value === 'partial')
    <x-panou.badge tone="warn">parțial</x-panou.badge>
@else
    <x-panou.badge>lipsă</x-panou.badge>
@endif
