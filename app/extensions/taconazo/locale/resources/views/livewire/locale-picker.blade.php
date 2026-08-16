<div class="ms-md-3 my-2 my-md-0" wire:ignore.self>
    @if(count($locales) > 1)
        <div class="btn-group btn-group-sm" role="group" aria-label="Language">
            @foreach($locales as $code => $name)
                @php
                    $short = str_starts_with((string)$code, 'es') ? 'ES' : strtoupper(substr((string)$code, 0, 2));
                @endphp
                <button
                    type="button"
                    class="btn {{ $code === $activeLocale ? 'btn-dark' : 'btn-outline-dark' }}"
                    wire:click="switchLocale('{{ $code }}')"
                    @if($code === $activeLocale) aria-current="true" @endif
                    title="{{ $name }}"
                >{{ $short }}</button>
            @endforeach
        </div>
    @endif
</div>
