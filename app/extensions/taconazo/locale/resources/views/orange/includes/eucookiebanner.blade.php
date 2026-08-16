@php
    $privacyPage = \Igniter\Pages\Models\Page::find($theme->gdpr_more_info_link);
    $isEs = str_starts_with((string) app()->getLocale(), 'es');
    $cookieMessage = $isEs
        ? lang('igniter.orange::default.gdpr.cookie_message')
        : $theme->gdpr_cookie_message;
    $moreInfoText = $isEs
        ? lang('igniter.orange::default.gdpr.more_info')
        : $theme->gdpr_more_info_text;
    $acceptText = $isEs
        ? lang('igniter.orange::default.gdpr.accept')
        : $theme->gdpr_accept_text;
@endphp
<div
    id="euCookieBanner"
    data-control="cookie-banner"
    data-active="{{ $theme->enable_gdpr }}"
    style="display:none;"
>
    <div
        style="background-color: {{ $theme->gdpr_background_color }}; color: {{ $theme->gdpr_text_color }};"
    >
        <div class="container">
            <div class="d-flex align-items-center">
                <p id="eu-cookie-message" class="mb-0">
                    <span>{{ $cookieMessage }}</span>
                    <a
                        href="{{ page_url($privacyPage ? $privacyPage->permalink_slug : '') }}"
                    >{{ $moreInfoText }}</a>
                </p>
                <a
                    id="eu-cookie-action"
                    class="btn btn-secondary ms-auto"
                    href="javascript:void(0);"
                >{{ $acceptText }}</a>
            </div>
        </div>
    </div>
</div>
