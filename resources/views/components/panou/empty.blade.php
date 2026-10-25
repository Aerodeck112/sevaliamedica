@props(['title'])
<div class="empty">
    <h3>{{ $title }}</h3>
    <div>{{ $slot }}</div>
</div>
