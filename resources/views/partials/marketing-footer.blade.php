{{--
    Marketing footer (spec Section 2.1 item 8, copy HOME-FTR-01 to
    HOME-FTR-05). One shared footer partial renders the copyright
    year from the server date everywhere (design-system.md Section 5
    item 6). Every link resolves to a route or anchor that exists.
--}}
<footer aria-label="Footer" class="border-t border-line bg-surface-0">
    <div class="mx-auto max-w-[1120px] px-5 py-12">
        <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <p class="text-xl font-bold text-text-1">Easy Refer</p>
                <p class="mt-3 max-w-[68ch] text-base text-text-2">Turn your customers into your best marketers with our easy-to-use referral platform.</p>

                <h2 class="mt-8 text-base font-semibold text-text-1">Stay Updated</h2>
                @if (session('newsletter_status'))
                    <p class="banner banner-success mt-3" role="status">{{ session('newsletter_status') }}</p>
                @else
                    <form method="POST" action="{{ route('newsletter') }}" class="mt-3">
                        @csrf
                        <label class="form-label" for="newsletter-email">Email address</label>
                        <div class="flex gap-2">
                            <input class="form-input" id="newsletter-email" name="email" type="email" placeholder="Your email address" value="{{ old('email') }}" @if ($errors->has('email')) aria-invalid="true" aria-describedby="newsletter-email-error" @endif>
                            <button type="submit" class="btn btn-primary shrink-0">Subscribe</button>
                        </div>
                        @error('email')
                            <p class="form-error" id="newsletter-email-error">{{ $message }}</p>
                        @enderror
                        <p class="form-help">We send occasional product updates. Unsubscribe any time.</p>
                    </form>
                @endif
            </div>

            <nav aria-label="Company">
                <h2 class="text-base font-semibold text-text-1">Company</h2>
                <ul class="mt-3 space-y-2 text-base">
                    <li><a class="text-brand-700 hover:underline" href="{{ route('about') }}">About Us</a></li>
                </ul>
            </nav>

            <nav aria-label="Business Portal">
                <h2 class="text-base font-semibold text-text-1">Business Portal</h2>
                <ul class="mt-3 space-y-2 text-base">
                    <li><a class="text-brand-700 hover:underline" href="{{ route('login') }}">Business Portal</a></li>
                </ul>
            </nav>

            <div>
                <nav aria-label="Support">
                    <h2 class="text-base font-semibold text-text-1">Support</h2>
                    <ul class="mt-3 space-y-2 text-base">
                        <li><a class="text-brand-700 hover:underline" href="{{ route('faq') }}">FAQ</a></li>
                        <li><a class="text-brand-700 hover:underline" href="{{ route('about') }}#contact">Contact</a></li>
                        <li><a class="text-brand-700 hover:underline" href="{{ route('faq') }}">Help Center</a></li>
                        <li><a class="text-brand-700 hover:underline" href="{{ route('blog') }}">Blog</a></li>
                    </ul>
                </nav>
                <nav aria-label="Legal" class="mt-8">
                    <h2 class="text-base font-semibold text-text-1">Legal</h2>
                    <ul class="mt-3 space-y-2 text-base">
                        <li><a class="text-brand-700 hover:underline" href="{{ route('legal') }}#privacy-policy">Privacy Policy</a></li>
                        <li><a class="text-brand-700 hover:underline" href="{{ route('legal') }}#terms-of-service">Terms &amp; Conditions</a></li>
                        <li><a class="text-brand-700 hover:underline" href="{{ route('refund') }}">Refund Policy</a></li>
                        <li><a class="text-brand-700 hover:underline" href="{{ route('legal') }}#compliance">Compliance</a></li>
                    </ul>
                </nav>
            </div>
        </div>

        <div class="mt-10 flex flex-col gap-4 border-t border-line pt-6 md:flex-row md:items-center md:justify-between">
            <ul class="flex gap-5 text-base">
                <li><a class="text-brand-700 hover:underline" href="https://www.facebook.com/people/EasyRefer/61571833155688">Facebook</a></li>
                <li><a class="text-brand-700 hover:underline" href="https://www.instagram.com/easy.refer">Instagram</a></li>
                <li><a class="text-brand-700 hover:underline" href="https://www.linkedin.com/company/easyrefer">LinkedIn</a></li>
                <li><a class="text-brand-700 hover:underline" href="https://x.com/easy_refer">X</a></li>
            </ul>
            <p class="text-base text-text-2">
                <a class="text-brand-700 hover:underline" href="mailto:support@easy-refer.com">support@easy-refer.com</a>
            </p>
            <p class="text-sm text-text-2">&copy; {{ now()->year }} Easy Refer. All rights reserved.</p>
        </div>
    </div>
</footer>
