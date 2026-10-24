{{--
    Marketing footer (spec Section 2.1 item 8, copy HOME-FTR-01 to
    HOME-FTR-05; redesign-spec.md Section 10.1 item 11). One shared
    footer partial renders the copyright year from the server date
    everywhere (design-system.md Section 5 item 6). Quiet links per
    Section 4.2 rule 5; column headings are styled text, not
    headings, so the page outline stays clean. The newsletter form
    uses the joined pattern (input and submit sharing one outline).
--}}
<footer aria-label="Footer" class="border-t border-line bg-surface-0">
    <div class="shell pt-16 pb-10">
        <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <x-logo :size="32" />
                <p class="type-body-sm mt-4 max-w-[36ch] text-text-2">The referral platform for local businesses.</p>

                <p class="type-title-4 mt-8 text-text-1">Stay Updated</p>
                @if (session('newsletter_status'))
                    <p class="banner banner-success mt-3" role="status">{{ session('newsletter_status') }}</p>
                @else
                    <form method="POST" action="{{ route('newsletter') }}" class="mt-3" data-loading>
                        @csrf
                        <label class="form-label" for="newsletter-email">Email address</label>
                        <div class="joined max-w-md">
                            <input class="form-input" id="newsletter-email" name="email" type="email" placeholder="Your email address" value="{{ old('email') }}" @if ($errors->has('email')) aria-invalid="true" aria-describedby="newsletter-email-error" @endif>
                            {{-- C-02: the primary fill is reserved for the
                                 "Start free" signup action; the newsletter
                                 submit is a secondary action. --}}
                            <button type="submit" class="btn btn-secondary">Subscribe</button>
                        </div>
                        @error('email')
                            <p class="form-error" id="newsletter-email-error">{{ $message }}</p>
                        @enderror
                        <p class="form-help">We send occasional product updates. Unsubscribe any time.</p>
                    </form>
                @endif
            </div>

            <nav aria-label="Company">
                <p class="type-title-4 text-text-1">Company</p>
                <ul class="mt-3">
                    <li><a class="flex min-h-12 items-center text-[15px] text-text-2 hover:text-text-1" href="{{ route('about') }}">About us</a></li>
                </ul>
            </nav>

            <nav aria-label="Business Portal">
                <p class="type-title-4 text-text-1">Business Portal</p>
                <ul class="mt-3">
                    <li><a class="flex min-h-12 items-center text-[15px] text-text-2 hover:text-text-1" href="{{ route('login') }}">Business Portal</a></li>
                </ul>
            </nav>

            <div>
                <nav aria-label="Support">
                    <p class="type-title-4 text-text-1">Support</p>
                    <ul class="mt-3">
                        <li><a class="flex min-h-12 items-center text-[15px] text-text-2 hover:text-text-1" href="{{ route('faq') }}">FAQ</a></li>
                        <li><a class="flex min-h-12 items-center text-[15px] text-text-2 hover:text-text-1" href="{{ route('about') }}#contact">Contact</a></li>
                        <li><a class="flex min-h-12 items-center text-[15px] text-text-2 hover:text-text-1" href="{{ route('faq') }}">Help Center</a></li>
                        <li><a class="flex min-h-12 items-center text-[15px] text-text-2 hover:text-text-1" href="{{ route('blog') }}">Blog</a></li>
                    </ul>
                </nav>
                <nav aria-label="Legal" class="mt-8">
                    <p class="type-title-4 text-text-1">Legal</p>
                    <ul class="mt-3">
                        <li><a class="flex min-h-12 items-center text-[15px] text-text-2 hover:text-text-1" href="{{ route('legal') }}#privacy-policy">Privacy Policy</a></li>
                        <li><a class="flex min-h-12 items-center text-[15px] text-text-2 hover:text-text-1" href="{{ route('legal') }}#terms-of-service">Terms &amp; Conditions</a></li>
                        <li><a class="flex min-h-12 items-center text-[15px] text-text-2 hover:text-text-1" href="{{ route('refund') }}">Refund Policy</a></li>
                        <li><a class="flex min-h-12 items-center text-[15px] text-text-2 hover:text-text-1" href="{{ route('legal') }}#compliance">Compliance</a></li>
                    </ul>
                </nav>
            </div>
        </div>

        <div class="mt-12 flex flex-col gap-4 border-t border-line pt-6 md:flex-row md:items-center md:justify-between">
            <ul class="flex gap-3">
                <li><a class="flex h-12 w-12 items-center justify-center rounded-full border border-line text-text-2 hover:border-line-strong hover:text-text-1" href="https://www.facebook.com/people/EasyRefer/61571833155688" aria-label="Facebook"><x-icon name="facebook" :size="20" /></a></li>
                <li><a class="flex h-12 w-12 items-center justify-center rounded-full border border-line text-text-2 hover:border-line-strong hover:text-text-1" href="https://www.instagram.com/easy.refer" aria-label="Instagram"><x-icon name="instagram" :size="20" /></a></li>
                <li><a class="flex h-12 w-12 items-center justify-center rounded-full border border-line text-text-2 hover:border-line-strong hover:text-text-1" href="https://www.linkedin.com/company/easyrefer" aria-label="LinkedIn"><x-icon name="linkedin" :size="20" /></a></li>
                <li><a class="flex h-12 w-12 items-center justify-center rounded-full border border-line text-text-2 hover:border-line-strong hover:text-text-1" href="https://x.com/easy_refer" aria-label="X"><x-icon name="twitter" :size="20" /></a></li>
            </ul>
            <p class="type-body-sm text-text-2">
                {{-- TX-D4 (release 08d): the mailto link carries a
                     24px box; as a bare inline link it measured 19px,
                     under the text-link floor. --}}
                <a class="inline-flex min-h-6 items-center text-text-2 hover:text-text-1" href="mailto:support@easy-refer.com">support@easy-refer.com</a>
            </p>
            <p class="type-caption text-text-2">&copy; {{ now()->year }} Easy Refer. All rights reserved.</p>
        </div>
    </div>
</footer>
