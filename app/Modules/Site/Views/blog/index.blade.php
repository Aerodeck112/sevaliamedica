{{-- Lista articolelor blogului, cu markupul paginii Odoo /blog/blog-sevalia-1 (căutare, grilă, paginare). --}}
@php use App\Modules\Site\Services\Renderer; @endphp
<nav class="navbar navbar-expand-lg navbar-light pt-4 pb-0 px-0" aria-label="Căutare în blog">
    <div class="container gap-2 w-100 flex-wrap flex-sm-nowrap">
        <form method="get" class="o_searchbar_form s_searchbar_input ms-auto flex-grow-1 flex-lg-grow-0" action="{{ \App\Modules\Cms\Models\Page::BLOG_PATH }}">
            <div role="search" class="input-group">
                <input type="search" name="search" class="search-query form-control oe_search_box border-0 bg-light" placeholder="Caută..." value="{{ $search }}" aria-label="Caută în articole">
                <button type="submit" aria-label="Căutare" title="Căutare" class="btn oe_search_button btn-light">
                    <i class="oi oi-search"></i>
                </button>
            </div>
        </form>
    </div>
</nav>
<section id="o_wblog_index_content">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div id="o_wblog_posts_loop_container" class="col">
                <div id="o_wblog_posts_loop">
                    <div class="row">
                        @forelse ($articles as $article)
                            <div class="pb-4 col-12 col-md-6 col-xl-4">
                                <article class="o_wblog_post position-relative">
                                    <a href="{{ $article->path }}" class="text-decoration-none d-block mb-2" aria-label="{{ $article->title }}">
                                        <div class="o_record_cover_container d-flex flex-column h-100 o_colored_level o_cc o_cc o_cc3 o_list_cover rounded overflow-hidden shadow mb-3">
                                            <div @if ($article->cover) style="background-image: url(&quot;{{ $article->cover->bestUrl(768) }}&quot;);" @endif class="o_record_cover_component o_record_cover_image"></div>
                                        </div>
                                    </a>
                                    <div>
                                        <a href="{{ $article->path }}" class="d-block text-reset text-decoration-none o_blog_post_title my-0 h5">{{ $article->title }}</a>
                                        <div>
                                            <a class="text-reset text-decoration-none" href="{{ $article->path }}" tabindex="-1">
                                                <div class="mt-2 o_wblog_normalize_font">{{ $teaser($article) }}</div>
                                            </a>
                                        </div>
                                    </div>
                                    <div class="o_wblog_normalize_font pe-2 pb-2">
                                        <div class="d-flex small flex-wrap mb-1 w-100">
                                            <div class="d-flex flex-wrap align-items-center justify-content-between mx-n2 flex-grow-1">
                                                <time class="text-nowrap fw-bold px-2" datetime="{{ $article->published_at?->toDateString() }}">{{ Renderer::shortDate($article->published_at) }}</time>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            </div>
                        @empty
                            <div class="col-12"><p>Nu am găsit articole{{ $search !== '' ? ' pentru „'.$search.'”' : '' }}.</p></div>
                        @endforelse
                    </div>
                </div>
                @if ($articles->lastPage() > 1)
                    @php $url = fn ($n) => $n <= 1 ? \App\Modules\Cms\Models\Page::BLOG_PATH : \App\Modules\Cms\Models\Page::BLOG_PATH.'/page/'.$n; @endphp
                    <ul class="justify-content-center pagination m-0">
                        <li class="page-item {{ $articles->onFirstPage() ? 'disabled' : '' }}">
                            <a class="page-link post_link" @unless ($articles->onFirstPage()) href="{{ $url($articles->currentPage() - 1) }}" @endunless>
                                <span class="oi oi-chevron-left" role="img" aria-label="Pagina anterioară" title="Pagina anterioară"></span>
                            </a>
                        </li>
                        @for ($n = 1; $n <= $articles->lastPage(); $n++)
                            <li class="page-item {{ $n === $articles->currentPage() ? 'active' : '' }}">
                                <a href="{{ $url($n) }}" class="page-link post_link" @if ($n === $articles->currentPage()) aria-current="page" @endif>{{ $n }}</a>
                            </li>
                        @endfor
                        <li class="page-item {{ $articles->hasMorePages() ? '' : 'disabled' }}">
                            <a class="page-link post_link" @if ($articles->hasMorePages()) href="{{ $url($articles->currentPage() + 1) }}" @endif>
                                <span class="oi oi-chevron-right" role="img" aria-label="Pagina următoare" title="Pagina următoare"></span>
                            </a>
                        </li>
                    </ul>
                @endif
            </div>
        </div>
    </div>
</section>
