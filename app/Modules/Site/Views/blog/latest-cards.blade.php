{{-- Ultimele articole (fragmentul dinamic s_blog_posts), cu markupul randat de Odoo pe prima pagină. --}}
<div class="row mb-4">
    @foreach ($articles as $article)
        <div class="d-flex flex-grow-0 flex-shrink-0 col-4">
            <div class="s_blog_posts_post w-100">
                <div class="card h-100">
                    <a class="text-decoration-none text-reset" href="{{ $article->path }}" title="Citește {{ $article->title }}">
                        <div class="s_blog_posts_post_cover card-img-top ratio ratio-16x9">
                            <div class="o_record_cover_container d-flex flex-column h-100 o_colored_level o_cc o_cc o_cc3 position-absolute w-100 h-100 bg-600 o_snippet_not_selectable">
                                <div @if ($article->cover) style="background-image: url(&quot;{{ $article->cover->bestUrl(480) }}&quot;);" @endif class="o_record_cover_component o_record_cover_image"></div>
                            </div>
                        </div>
                        <div class="card-body">
                            <h5 class="text-truncate">{{ $article->title }}</h5>
                        </div>
                    </a>
                    <div class="card-footer d-flex justify-content-between mt-auto border-0 p-3 pt-0 bg-transparent small"></div>
                </div>
            </div>
        </div>
    @endforeach
</div>
