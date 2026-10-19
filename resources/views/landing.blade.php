<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Technique RAG — Conversational Inventory Intelligence</title>
    <meta name="description" content="Upload your inventory, sales, purchase order, or returns Excel file and get instant stats, dashboards, and an AI you can ask questions.">
    @vite(['resources/css/app.css', 'resources/css/landing/main.scss', 'resources/js/app.js'])
    <style>
        html, body { height: 100%; }
        body { display: flex; flex-direction: column; min-height: 100vh; }
        body > main { flex: 1 0 auto; }
        body > footer { flex-shrink: 0; }
    </style>
</head>
<body>

<header class="header" x-data="{ menuOpen: false }">
    <div class="container-page">
        <div class="header__wrapper">
            <div class="branding">
                <a href="/" class="header__logo flex-center gap-10">
                    <img src="/assets/images/technique-rag-logo.svg" alt="Technique RAG Logo" class="bg-cover ic-s38 radius-lg">
                    <div class="flex flex-col leading-tight">
                        <h1 class="text-primary fs-18 fw-bold">Technique RAG</h1>
                        <span class="text-muted fs-12">Inventory Intelligence</span>
                    </div>
                </a>
            </div>

            <div class="header__actions flex items-center gap-12">
                <div class="header__auth-actions hidden flex items-center gap-12">
                    <x-landing.button href="{{ route('login') }}" variant="ghost" size="md">Sign In</x-landing.button>
                    <x-landing.button href="{{ route('register') }}" variant="outline" size="md" :rounded="true">Register</x-landing.button>
                </div>

                <button class="header__toggle px-10 py-10 radius-lg" @click="menuOpen = !menuOpen">
                    <i class="ic-base ic-s22 text-foreground bg-cover" :class="menuOpen ? 'ic-close' : 'ic-menu'"></i>
                </button>
            </div>
        </div>
    </div>

    <nav class="header__mobile flex flex-col gap-20 p-24" :class="{ 'header__mobile--open': menuOpen }">
        <x-landing.button href="{{ route('login') }}" variant="ghost" size="md" class="w-full">Sign In</x-landing.button>
        <x-landing.button href="{{ route('register') }}" variant="outline" size="md" :rounded="true" class="w-full">Register</x-landing.button>
    </nav>
</header>

<main class="home flex flex-col">

    {{-- ===================== HERO ===================== --}}
    <section id="home" class="hero relative overflow-hidden isolate">
        <div class="container-page hero__container py-65 flex flex-col lg:flex-row items-center gap-18">

            <div class="hero-content flex-column flex-start gap-20">
                <div class="main-content__badge">
                    <span class="hero-content__dot radius-rounded ic-s6 bg-primary"></span>
                    <span>Excel-to-Insights, No Code Required</span>
                </div>

                <h1 class="hero-content__title font-monospace">
                    Know Your
                    <br>
                    Inventory
                    <span class="text-primary font-monospace">Instantly</span>
                </h1>

                <p class="hero-content__description fs-16 text-muted">
                    Technique RAG turns your Zoho — or any other system's — Excel exports into automatic stats,
                    dashboards, and a chat you can ask questions in plain language. Inventory, sales, purchase
                    orders, returns — all in one place.
                </p>
                <p class="hero-content__description fs-16 text-muted">
                    Upload a file, get real computed answers — never a guess.
                </p>

                <div class="hero-content__actions flex-start flex-wrap gap-16">
                    <x-landing.button href="{{ route('register') }}" variant="primary" size="md" :rounded="true" rightIcon="arrow-right ic-s16 text-surface bg-cover">
                        Get Started Free
                    </x-landing.button>
                    <x-landing.button href="mailto:hello@techniquesoftware.com" variant="outline" size="sm" class="mt-4" :rounded="true" leftIcon="envelope ic-s16 text-primary bg-cover">
                        Contact Us
                    </x-landing.button>
                </div>

                <div class="hero-content__features flex flex-wrap gap-24">
                    <div class="hero-content__feature">
                        <i class="ic-base ic-s12 ic-check text-primary bg-cover"></i>
                        <span class="fs-12 text-muted">Works with any Excel layout</span>
                    </div>
                    <div class="hero-content__feature">
                        <i class="ic-base ic-s12 ic-check text-primary bg-cover"></i>
                        <span class="fs-12 text-muted">AI-powered, cited answers</span>
                    </div>
                    <div class="hero-content__feature">
                        <i class="ic-base ic-s12 ic-check text-primary bg-cover"></i>
                        <span class="fs-12 text-muted">No code required</span>
                    </div>
                </div>
            </div>

            {{-- Decorative dashboard preview --}}
            <div class="hero-dashboard fade-right dashboard-float">
                <article class="growth-card card card--glass growth-card-float radius-lg">
                    <div class="growth-card__icon radius-rounded flex items-center p-10">
                        <i class="ic-base ic-s12 ic-arrow-trend-up text-success bg-cover"></i>
                    </div>
                    <div class="growth-card__content flex flex-col">
                        <span class="growth-card__title fs-10 text-muted">Stock Value</span>
                        <h3 class="growth-card__value fs-12 fw-bold text-foreground leading-normal">
                            +12% <span class="growth-card__subtitle">this month</span>
                        </h3>
                    </div>
                </article>

                <div class="hero-dashboard__window">
                    <div class="hero-dashboard__frame px-10">
                        <div class="browser flex items-center justify-between py-10 px-16">
                            <div class="browser__traffic flex gap-10">
                                <span class="browser__dot browser__dot--orange bg-warning ic-s10 radius-rounded"></span>
                                <span class="browser__dot browser__dot--green bg-success ic-s10 radius-rounded"></span>
                            </div>
                            <div class="browser__title flex justify-between items-center">technique-rag / dashboard</div>
                            <div class="w-6"></div>
                        </div>

                        <div class="hero-dashboard__body flex flex-col gap-12 p-20">
                            <div class="stats mt-4 grid grid-cols-3 gap-3">
                                <article class="card flex-column card--secondry p-12">
                                    <span class="stat-card__title">Stock Value</span>
                                    <h3 class="stat-card__value">$18,685</h3>
                                    <span class="stat-card__badge">+12%</span>
                                </article>
                                <article class="card flex-column card--secondry p-12">
                                    <span class="stat-card__title">Items Tracked</span>
                                    <h3 class="stat-card__value">19</h3>
                                    <span class="stat-card__badge">+3</span>
                                </article>
                                <article class="card flex-column card--secondry p-12">
                                    <span class="stat-card__title">Stockouts</span>
                                    <h3 class="stat-card__value">3</h3>
                                    <span class="stat-card__badge">Watch</span>
                                </article>
                            </div>

                            <section class="sales-chart card card--glass flex flex-col gap-20">
                                <div class="sales-chart__canvas card--secondry p-14">
                                    <header class="sales-chart__header flex justify-between items-center">
                                        <h3 class="sales-chart__title fs-12 fw-semibold text-foreground">Stock Value Trend</h3>
                                        <span class="sales-chart__branches fs-10 text-muted">4 categories</span>
                                    </header>
                                    <svg class="sales-chart__svg w-full h-[180px] block" viewBox="0 0 300 90" preserveAspectRatio="none">
                                        <defs>
                                            <linearGradient id="salesGradient" x1="0" x2="0" y1="0" y2="1">
                                                <stop offset="0%" stop-color="var(--primary)" stop-opacity=".30" />
                                                <stop offset="100%" stop-color="var(--primary)" stop-opacity="0" />
                                            </linearGradient>
                                        </defs>
                                        <path class="sales-chart__area opacity-95" d="M0,70 L40,60 L80,65 L120,45 L160,50 L200,30 L240,35 L300,15 L300,90 L0,90 Z" fill="url(#salesGradient)" />
                                        <path class="sales-chart__line" d="M0,70 L40,60 L80,65 L120,45 L160,50 L200,30 L240,35 L300,15" fill="none" stroke="var(--primary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    <footer class="sales-chart__footer fs-8 text-muted grid grid-cols-7 text-center">
                                        <span>May</span><span>May</span><span>Jun</span><span>Jun</span><span>Jul</span><span>Jul</span><span>Aug</span>
                                    </footer>
                                </div>
                            </section>

                            <section class="orders-table overflow-hidden card card--glass">
                                <header class="orders-table__header p-4">
                                    <h3 class="fs-12 fw-semibold text-foreground">Recent Activity</h3>
                                </header>
                                <div class="orders-table__body flex flex-col">
                                    <div class="orders-table__row p-4 flex justify-between items-center">
                                        <div class="orders-table__info flex items-center gap-8 min-w-0">
                                            <span class="orders-table__id fs-12 text-muted fw-semibold font-monospace">SKU-1002</span>
                                            <span class="orders-table__name text-truncate fs-12 text-foreground">Electronics · Import</span>
                                        </div>
                                        <span class="orders-table__price whitespace-nowrap fs-12 fw-bold text-primary">153 units</span>
                                    </div>
                                    <div class="orders-table__row p-4 flex justify-between items-center">
                                        <div class="orders-table__info flex items-center gap-8 min-w-0">
                                            <span class="orders-table__id fs-12 text-muted fw-semibold font-monospace">SKU-2002</span>
                                            <span class="orders-table__name text-truncate fs-12 text-foreground">Furniture · Stockout</span>
                                        </div>
                                        <span class="orders-table__price whitespace-nowrap fs-12 fw-bold text-primary">0 units</span>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

</main>

<footer class="footer bg-primary text-scurface">
    <div class="container-page">
        <div class="footer__wrapper grid gap-30 md:grid-cols-3 py-48">
            <div class="flex flex-col flex-start gap-10">
                <a href="/" class="footer__logo flex-center gap-12">
                    <img src="/assets/images/technique-rag-logo.svg" alt="Technique RAG Logo" class="bg-cover ic-s38 radius-md">
                    <div class="flex flex-col leading-tight">
                        <h1 class="text-surface fs-18 fw-bold">Technique RAG</h1>
                        <span class="text-surface opacity-70 fs-12">Inventory Intelligence</span>
                    </div>
                </a>
                <p class="footer__description opacity-80 text-surface fs-12 lh-relaxed">
                    A conversational inventory intelligence platform — import Excel, get instant stats, ask questions in plain language.
                </p>
            </div>

            <div class="footer__column">
                <span class="footer__heading fs-12 fw-semibold opacity-60 text-surface text-uppercase">Contact</span>
                <div class="footer__contact flex flex-col gap-8">
                    <a class="footer__link flex items-center gap-10" href="mailto:hello@techniquesoftware.com">
                        <i class="ic-base ic-envelope ic-s14 bg-cover text-surface"></i>
                        hello@techniquesoftware.com
                    </a>
                </div>
            </div>

            <div class="footer__column">
                <span class="footer__heading fs-12 fw-semibold opacity-60 text-surface text-uppercase">Developed By</span>
                <h4 class="footer__company fw-bold fs-16 text-surface">Technique Software</h4>
                <p class="footer__company-description opacity-80 text-surface fs-12 lh-tight">Reliable software solutions and business systems.</p>
            </div>
        </div>
    </div>

    <div class="footer__bottom fs-10 text-surface opacity-80">
        <div class="footer__bottom-container flex flex-col items-center gap-4 text-center container">
            <span>&copy; {{ date('Y') }} Technique RAG. All rights reserved.</span>
            <span>Developed by Technique Software</span>
        </div>
    </div>
</footer>

</body>
</html>
