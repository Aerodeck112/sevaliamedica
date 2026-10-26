@extends('site::layout')
{{-- Pagina 404, cu textele și aspectul paginii de eroare a siteului original. --}}
@section('main')
    <div id="wrap">
        <div class="oe_structure oe_empty">
            <section class="s_text_image pt104 pb104" data-snippet="s_image_text">
                <div class="container">
                    <div class="row align-items-center">
                        <div class="col-lg-4 pb32">
                            <img class="img img-fluid mx-auto" src="/images/404.svg" alt="" width="310" height="120">
                        </div>
                        <div class="col-lg-8 text-lg-start text-center my-auto">
                            <h1 class="visually-hidden">Eroare 404</h1>
                            <h2>Nu am putut găsi pagina pe care o căutați!</h2>
                            <p></p>
                            <p><b> Nu vă panicați. </b>Dacă credeți că este greșeala noastră, vă rugăm să ne trimiteți un mesaj la <a href="/contactus">această pagină</a>.</p>
                        </div>
                        <div class="col-lg-12">
                            <div class="s_hr pt32 pb48" data-snippet="s_hr">
                                <hr class="w-100 mx-auto" style="border-top-width: 1px; border-top-style: solid; border-top-color: var(--400);">
                            </div>
                        </div>
                        <div class="col-lg-12">
                            <p class="text-center">Poate căutați una dintre aceste <b> pagini populare?</b></p>
                            <ul class="list-inline text-center">
                                <li class="list-inline-item mb-2"><a href="/" class="btn btn-primary">Acasă</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
@endsection
