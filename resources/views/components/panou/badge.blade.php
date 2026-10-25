@props(['tone' => null])
<span {{ $attributes->merge(['class' => 'badge'.($tone ? ' badge--'.$tone : '')]) }}>{{ $slot }}</span>
