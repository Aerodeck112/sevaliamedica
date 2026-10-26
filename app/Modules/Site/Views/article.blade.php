@extends('site::layout')
@php
    use App\Modules\Site\Services\Renderer;
    $coverClass = $articleSettings['cover_class'] ?? 'o_record_cover_container d-flex flex-column h-100 o_colored_level o_cc o_cc3 o_cc o_half_screen_height o_record_has_cover o_wblog_post_page_cover';
    $filterStyle = $articleSettings['cover_filter_style'] ?? 'opacity: 0.2;';
@endphp
@section('main')
    <div id="wrap" class="js_blog website_blog">
        <section id="o_wblog_post_top">
            <div id="title" class="blog_header">
                <div class="{{ $coverClass }}">
                    <div class="o_record_cover_component o_record_cover_image {{ $coverBgClass }}"></div>
                    <div style="{{ $filterStyle }}" class="o_record_cover_component o_record_cover_filter oe_black"></div>
                    <div class="container text-center position-relative h-100 d-flex flex-column flex-grow-1 justify-content-around">
                        <div class="o_wblog_post_title">
                            <h1 class="o_wblog_post_name o_editable_no_shadow">{{ $version->title }}</h1>
                            <div class="o_wblog_post_subtitle o_editable_no_shadow">{{ $articleSettings['subtitle'] ?? '' }}</div>
                        </div>
                        <a id="o_wblog_post_content_jump" href="#o_wblog_post_main" class="css_editable_mode_hidden justify-content-center align-items-center rounded-circle mx-auto mb-5 text-decoration-none">
                            <i class="fa fa-angle-down fa-3x text-white" aria-label="La conținutul blogului" title="La conținutul blogului"></i>
                        </a>
                    </div>
                </div>
            </div>
        </section>
        <section id="o_wblog_post_main" class="container pt-4 pb-5">
            <div class="mx-auto o_wblog_read_with_sidebar">
                <div class="d-flex flex-column flex-lg-row justify-content-between">
                    <div id="o_wblog_post_content" class="o_container_small mx-0 w-100 flex-shrink-0">
                        <div id="o_wblog_post_info" class="text-muted mb-2">
                            <i class="fa fa-clock-o fa-fw"></i>
                            <span class="text-muted">{{ Renderer::longDate($page->published_at) }}</span>
                            @if ($page->author_name)
                                <span style="display: inline-flex">de
                                    <div class="align-items-center position-relative d-inline-flex me-2 ms-2">
                                        <div style="line-height:1"><span>{{ $page->author_name }}</span></div>
                                    </div>
                                </span>
                            @endif
                        </div>
                        <div class="o_wblog_post_content_field o_wblog_read_text">
                            {!! $main !!}
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <section id="o_wblog_post_footer"></section>
    </div>
@endsection
