{{-- Help-desk chatbot (App\Support\ChatbotWidget): rendered only when configured.
     The interface language follows the portal's EN / Hindi switch. --}}
@if (\App\Support\ChatbotWidget::enabled())
    <script src="{{ \App\Support\ChatbotWidget::scriptUrl() }}"
            data-site-id="{{ \App\Support\ChatbotWidget::siteId() }}"
            data-language="{{ app()->getLocale() === 'hi' ? 'hi' : 'en' }}"
            defer></script>
@endif
