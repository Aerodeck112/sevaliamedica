{{-- Antetul siteului, reprodus după șablonul Odoo „Contact” (aceleași clase); meniul vine din CMS. --}}
@php
    $isActive = fn ($url) => $url && ($url === $currentPath || ($url !== '/' && str_starts_with($currentPath, rtrim($url, '/').'/')));
@endphp
<header id="top" class="bg-white-85 o_header_disappears o_hoverable_dropdown">
    <nav aria-label="Principal" class="navbar navbar-expand-lg navbar-light o_colored_level o_cc o_header_force_no_radius d-none d-lg-block p-0 shadow-sm rounded-0">
        <div id="o_main_nav" class="o_main_nav">
            <div class="container d-flex align-items-center py-2">
                <a href="/" class="navbar-brand logo me-4">
                    <span role="img" aria-label="Logo of sevalia.ro" title="sevalia.ro"><img src="{{ $logoUrl }}" class="img img-fluid" width="95" height="40" alt="sevalia.ro" fetchpriority="high"></span>
                </a>
                <div class="flex-fill min-w-0">
                    @if ($showLanguageSelector)
                        <ul class="o_header_sales_three_small_links navbar-nav justify-content-end align-items-center gap-2 w-100 o_header_separator">
                            <li class="o_header_language_selector position-relative">
                                <div class="js_language_selector dropdown d-print-none">
                                    <button type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="border-0 dropdown-toggle nav-link d-flex align-items-center fw-bold text-uppercase o_nav-link_secondary">
                                        <img class="o_lang_flag" src="/images/flag-ro.png" alt="" width="25" height="25" loading="lazy">
                                        <span class="ms-1"> română</span>
                                    </button>
                                    <div role="menu" class="dropdown-menu dropdown-menu-end">
                                        <a class="dropdown-item js_change_lang" role="menuitem" href="/en" title="English (US)">
                                            <img class="o_lang_flag" src="/images/flag-us.png" alt="" width="25" height="25" loading="lazy">
                                            <span class="ms-1">English (US)</span>
                                        </a>
                                        <a class="dropdown-item js_change_lang active" role="menuitem" href="{{ $currentPath }}" title=" română">
                                            <img class="o_lang_flag" src="/images/flag-ro.png" alt="" width="25" height="25" loading="lazy">
                                            <span class="ms-1"> română</span>
                                        </a>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    @endif
                    <ul role="menu" id="top_menu" class="nav navbar-nav top_menu justify-content-start">
                        @foreach ($menu as $item)
                            @if ($item->kind === 'mega' || $item->kind === 'dropdown')
                                <li role="presentation" class="nav-item position-static dropdown">
                                    <a href="#" data-bs-toggle="dropdown" data-bs-auto-close="outside" role="menuitem" aria-expanded="false" class="dropdown-toggle nav-link o_mega_menu_toggle" data-bs-display="static">
                                        <span>{{ $item->label }}</span>
                                    </a>
                                    @if ($item->kind === 'mega')
                                        <div role="menuitem" class="o_mega_menu dropdown-menu o_no_parent_editor border-top-0 border-top-0">{!! $megaMenus[$item->id] ?? '' !!}</div>
                                    @else
                                        <ul role="menu" class="dropdown-menu">
                                            @foreach ($item->children->where('is_visible', true) as $child)
                                                <li role="presentation"><a role="menuitem" href="{{ $child->url }}" class="dropdown-item @if ($isActive($child->url)) active @endif" @if ($child->new_tab) target="_blank" rel="noopener" @endif><span>{{ $child->label }}</span></a></li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </li>
                            @else
                                <li role="presentation" class="nav-item">
                                    <a role="menuitem" href="{{ $item->url }}" class="nav-link @if ($isActive($item->url)) active @endif" @if ($item->new_tab) target="_blank" rel="noopener" @endif>
                                        <span>{{ $item->label }}</span>
                                    </a>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </nav>
    <nav aria-label="Mobile" class="navbar navbar-light o_colored_level o_cc o_header_mobile d-block d-lg-none shadow-sm o_header_force_no_radius">
        <div class="o_main_nav container flex-wrap justify-content-between">
            <div class="d-flex flex-grow-1">
                <a href="/" class="navbar-brand logo">
                    <span role="img" aria-label="Logo of sevalia.ro" title="sevalia.ro"><img src="{{ $logoUrl }}" class="img img-fluid" width="95" height="40" alt="sevalia.ro"></span>
                </a>
                <ul class="o_header_mobile_buttons_wrap navbar-nav d-flex flex-row align-items-center gap-2 mb-0 ms-auto"></ul>
            </div>
            <button class="nav-link btn p-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#top_menu_collapse_mobile" aria-controls="top_menu_collapse_mobile" aria-expanded="false" aria-label="Comutare navigare">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div id="top_menu_collapse_mobile" class="offcanvas offcanvas-end o_navbar_mobile" tabindex="-1" aria-label="Meniu">
                <div class="offcanvas-header justify-content-end">
                    <button type="button" class="nav-link btn-close" data-bs-dismiss="offcanvas" aria-label="Închide"></button>
                </div>
                <div class="offcanvas-body d-flex flex-column justify-content-between h-100 w-100 pt-0">
                    <ul class="navbar-nav">
                        <li>
                            <ul role="menu" class="nav navbar-nav top_menu o_mega_menu_is_offcanvas mx-n3">
                                @foreach ($menu as $item)
                                    @if ($item->kind === 'mega' || $item->kind === 'dropdown')
                                        <li role="presentation" class="nav-item border-top position-static dropdown">
                                            <a href="#" data-bs-toggle="dropdown" data-bs-auto-close="outside" role="menuitem" aria-expanded="false" class="dropdown-toggle nav-link p-3 text-wrap o_mega_menu_toggle d-flex justify-content-between align-items-center" data-bs-display="static">
                                                <span>{{ $item->label }}</span>
                                            </a>
                                            @if ($item->kind === 'mega')
                                                <div role="menuitem" class="o_mega_menu dropdown-menu o_mobile_mega">{!! $megaMenus[$item->id] ?? '' !!}</div>
                                            @else
                                                <ul role="menu" class="dropdown-menu">
                                                    @foreach ($item->children->where('is_visible', true) as $child)
                                                        <li role="presentation"><a role="menuitem" href="{{ $child->url }}" class="dropdown-item"><span>{{ $child->label }}</span></a></li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </li>
                                    @else
                                        <li role="presentation" class="nav-item border-top @if ($loop->last) border-bottom @endif px-0">
                                            <a role="menuitem" href="{{ $item->url }}" class="nav-link p-3 text-wrap @if ($isActive($item->url)) active @endif">
                                                <span>{{ $item->label }}</span>
                                            </a>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </li>
                    </ul>
                    @if ($showLanguageSelector)
                        <ul class="navbar-nav gap-2 mt-3 w-100">
                            <li class="o_header_language_selector">
                                <div class="js_language_selector dropup dropdown d-print-none">
                                    <button type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="border-0 dropdown-toggle nav-link d-flex align-items-center w-100">
                                        <img class="o_lang_flag me-2" src="/images/flag-ro.png" alt="" width="25" height="25" loading="lazy">
                                        <span class="me-auto small"> română</span>
                                    </button>
                                    <div role="menu" class="dropdown-menu w-100">
                                        <a class="dropdown-item js_change_lang" role="menuitem" href="/en" title="English (US)">
                                            <img class="o_lang_flag me-2" src="/images/flag-us.png" alt="" width="25" height="25" loading="lazy">
                                            <span class="me-auto small">English (US)</span>
                                        </a>
                                        <a class="dropdown-item js_change_lang active" role="menuitem" href="{{ $currentPath }}" title=" română">
                                            <img class="o_lang_flag me-2" src="/images/flag-ro.png" alt="" width="25" height="25" loading="lazy">
                                            <span class="me-auto small"> română</span>
                                        </a>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </nav>
</header>
