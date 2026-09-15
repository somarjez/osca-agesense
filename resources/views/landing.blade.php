<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AgeSense &ndash; OSCA Pagsanjan | Explainable ML for Healthy Ageing</title>
    <meta name="description" content="AgeSense is the decision-support platform of the Office of Senior Citizens Affairs (OSCA), Pagsanjan, Laguna &mdash; using explainable machine learning and the WHO Healthy Ageing Framework for senior citizen profiling, possible-risk indicators, and healthy-ageing recommendations.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://pagsanjan-osca.online/">
    <meta property="og:title" content="AgeSense &ndash; OSCA Pagsanjan | Explainable ML for Healthy Ageing">
    <meta property="og:description" content="Decision support for senior citizen profiling, explainable analysis, and healthy-ageing recommendations &mdash; built for the Office of Senior Citizens Affairs, Pagsanjan, Laguna, on the WHO Healthy Ageing Framework.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://pagsanjan-osca.online/">
    <meta property="og:site_name" content="AgeSense">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="AgeSense &ndash; OSCA Pagsanjan">
    <meta name="twitter:description" content="Explainable machine learning and decision support for healthy ageing, built for OSCA Pagsanjan, Laguna.">
    <meta name="theme-color" content="#fbfaf6">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "GovernmentOrganization",
        "name": "AgeSense",
        "alternateName": ["OSCA Pagsanjan", "Office of Senior Citizens Affairs - Pagsanjan"],
        "url": "https://pagsanjan-osca.online/",
        "description": "Decision-support platform for senior citizen profiling, explainable machine learning insights, and healthy-ageing recommendations, developed for the Office of Senior Citizens Affairs (OSCA) of Pagsanjan, Laguna.",
        "address": {
            "@type": "PostalAddress",
            "addressLocality": "Pagsanjan",
            "addressRegion": "Laguna",
            "addressCountry": "PH"
        }
    }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter+Tight:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,500;8..60,600;8..60,700;8..60,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        /* ── Landing-page-only presentation layer ─────────────────────────────
           Scoped to this page (same precedent as auth/login.blade.php's own
           <style> block) — bento spans, showcase framing, and glow decoration
           are page-specific composition, not reusable app.css components. */

        html { scroll-behavior: smooth; }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto !important; } }

        .shell { max-width: 1440px; margin-inline: auto; padding-inline: 1.5rem; }
        @media (min-width: 1024px) { .shell { padding-inline: 3rem; } }

        /* Scroll-triggered entrance */
        .reveal-up {
            opacity: 0; transform: translateY(18px);
            transition: opacity 0.7s cubic-bezier(0.22,1,0.36,1), transform 0.7s cubic-bezier(0.22,1,0.36,1);
        }
        .reveal-up.in-view { opacity: 1; transform: none; }
        @media (prefers-reduced-motion: reduce) { .reveal-up { opacity: 1; transform: none; transition: none; } }

        /* Nav: transparent over hero, solid once scrolled */
        #site-nav { transition: background-color 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease, backdrop-filter 0.25s ease; }
        #site-nav.nav-scrolled {
            background-color: rgba(251,250,246,0.92);
            backdrop-filter: blur(8px);
            box-shadow: 0 1px 0 rgba(20,30,25,0.04), 0 4px 16px -6px rgba(22,33,58,0.12);
            border-color: theme('colors.paper.rule');
        }

        .glow-orb { position: absolute; border-radius: 9999px; filter: blur(70px); pointer-events: none; }
        .dot-field {
            background-image: radial-gradient(circle at 1px 1px, currentColor 1px, transparent 1px);
            background-size: 26px 26px;
        }

        @keyframes heroFloat { 0%, 100% { transform: translateY(0) rotate(var(--r,0deg)); } 50% { transform: translateY(-10px) rotate(var(--r,0deg)); } }
        .float-slow { animation: heroFloat 7s ease-in-out infinite; }
        .float-slower { animation: heroFloat 9s ease-in-out infinite; animation-delay: .6s; }
        @media (prefers-reduced-motion: reduce) { .float-slow, .float-slower { animation: none; } }

        /* Browser-frame chrome for the system showcase composite */
        .browser-frame { border-radius: 16px; overflow: hidden; background: #0f1729; box-shadow: 0 30px 70px -20px rgba(0,0,0,0.55), 0 2px 0 rgba(255,255,255,0.04) inset; }
        .browser-frame-bar { display: flex; align-items: center; gap: 6px; padding: 10px 14px; background: #16213a; }
        .browser-frame-dot { width: 9px; height: 9px; border-radius: 9999px; background: rgba(255,255,255,0.18); }

        /* Workflow connector — desktop */
        .flow-line { background: repeating-linear-gradient(to right, theme('colors.paper.rule') 0 8px, transparent 8px 14px); }

        summary::-webkit-details-marker { display: none; }
    </style>
</head>
<body class="min-h-screen bg-paper text-ink-900">
<a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:bg-white focus:text-ink-900 focus:px-3 focus:py-2 focus:rounded-lg focus:shadow-md">
    Skip to content
</a>

<x-gov-band />
<x-landing.nav />

<main id="main-content">
    <x-landing.hero />
    <x-landing.impact-strip />
    <x-landing.about />
    <x-landing.who-framework />
    <x-landing.system-showcase />
    <x-landing.how-it-works />
    <x-landing.features />
    <x-landing.gis />
    <x-landing.explainable-ai />
    <x-landing.stakeholders />
    <x-landing.responsible-use />
    <x-landing.research-identity />
    <x-landing.final-cta />
</main>

<x-landing.footer />

<script>
    (function () {
        // ── Mobile menu ──────────────────────────────────────────────────
        var toggle = document.getElementById('nav-menu-toggle');
        var panel  = document.getElementById('nav-mobile-panel');
        if (toggle && panel) {
            toggle.addEventListener('click', function () {
                var open = panel.classList.toggle('hidden') === false;
                toggle.setAttribute('aria-expanded', String(open));
            });
            panel.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    panel.classList.add('hidden');
                    toggle.setAttribute('aria-expanded', 'false');
                });
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !panel.classList.contains('hidden')) {
                    panel.classList.add('hidden');
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.focus();
                }
            });
        }

        // ── Sticky nav: transparent over hero, solid once scrolled ────────
        var nav = document.getElementById('site-nav');
        if (nav) {
            var onScroll = function () { nav.classList.toggle('nav-scrolled', window.scrollY > 24); };
            onScroll();
            window.addEventListener('scroll', onScroll, { passive: true });
        }

        // ── Scroll-in entrance ───────────────────────────────────────────
        var reveals = document.querySelectorAll('.reveal-up');
        if ('IntersectionObserver' in window && reveals.length) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('in-view');
                        io.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
            reveals.forEach(function (el) { io.observe(el); });
        } else {
            reveals.forEach(function (el) { el.classList.add('in-view'); });
        }

        // ── Workflow line draws in once the process section is visible ───
        var flow = document.getElementById('workflow-line');
        if (flow && 'IntersectionObserver' in window) {
            var flowIo = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        flow.classList.add('is-active');
                        flowIo.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.3 });
            flowIo.observe(flow);
        } else if (flow) {
            flow.classList.add('is-active');
        }
    })();
</script>
</body>
</html>
