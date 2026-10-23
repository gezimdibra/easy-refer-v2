{{--
    Analytics tags (spec Section 16): they load only on marketing and
    auth pages, never on /refer pages and never on the dashboard, and
    only when the environment has IDs configured. Consent Mode v2
    defaults deny ad and analytics storage until the visitor accepts
    on the consent banner; the Meta Pixel is not loaded at all before
    consent. With no IDs configured (every current environment), this
    partial renders nothing at all.
--}}
@php($ga4Id = config('attribution.ga4_id'))
@php($metaPixelId = config('attribution.meta_pixel_id'))
@if ($ga4Id)
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('consent', 'default', {
            'ad_storage': 'denied',
            'ad_user_data': 'denied',
            'ad_personalization': 'denied',
            'analytics_storage': 'denied'
        });
    </script>
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga4Id }}"></script>
    <script>
        gtag('js', new Date());
        gtag('config', '{{ $ga4Id }}');
    </script>
@endif
@if ($metaPixelId && request()->cookie('er_consent') === 'accepted')
    {{-- The Meta Pixel is not loaded at all before consent (spec
         Section 16): this block renders only for visitors who have
         accepted on the consent banner. --}}
    <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '{{ $metaPixelId }}');
        fbq('track', 'PageView');
    </script>
@endif
