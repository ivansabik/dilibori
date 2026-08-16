@php
    $locales = \Igniter\System\Models\Language::listSupported();
    $activeLocale = resolve('translator.localization')->getLocale();
@endphp
@if(count($locales) > 1)
    <div class="ms-md-3 my-2 my-md-0">
        <div class="btn-group btn-group-sm" role="group" aria-label="Language">
            @foreach($locales as $code => $name)
                @php
                    $short = str_starts_with((string)$code, 'es') ? 'ES' : strtoupper(substr((string)$code, 0, 2));
                    $isActive = $code === $activeLocale;
                @endphp
                <a
                    href="{{ url('locale/'.$code) }}"
                    class="btn {{ $isActive ? 'btn-dark' : 'btn-outline-dark' }}"
                    @if($isActive) aria-current="true" @endif
                    title="{{ $name }}"
                >{{ $short }}</a>
            @endforeach
        </div>
    </div>
@endif
