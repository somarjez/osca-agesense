<section class="py-24 lg:py-32 bg-white">
    <div class="shell grid lg:grid-cols-[1fr_1fr] gap-16 items-start">
        <div class="reveal-up">
            <div class="eyebrow">Research &amp; Development</div>
            <h2 class="font-serif text-[30px] sm:text-[38px] font-semibold tracking-snug text-ink-900 text-balance mt-3">
                Developed Through Research. Designed for Community Use.
            </h2>
            <p class="mt-6 text-[14px] leading-relaxed text-ink-600 max-w-md">
                AgeSense: An Explainable Machine Learning Framework for Profiling and Prescriptive Recommendations of Healthy Ageing among Senior Citizens Using the WHO Healthy Ageing Framework.
            </p>
            <p class="mt-4 text-[14px] leading-relaxed text-ink-600 max-w-md">
                Developed as an undergraduate research project of the College of Computer Studies, Laguna State Polytechnic University &ndash; Santa Cruz Main Campus, using senior citizen data from Pagsanjan, Laguna as the study locale.
            </p>
        </div>

        <div class="reveal-up grid sm:grid-cols-2 gap-x-8 gap-y-7 lg:pt-16">
            @foreach (['Jezreel Ramos', 'Judeelyn Capili', 'Shaila Patrice Avellaneda'] as $name)
                <div class="border-t border-paper-rule pt-4">
                    <div class="text-[14px] font-semibold text-ink-900">{{ $name }}</div>
                    <div class="eyebrow mt-1">Researcher</div>
                </div>
            @endforeach
            <div class="border-t border-accent-200 pt-4">
                <div class="text-[14px] font-semibold text-ink-900">Mia V. Villarica, DIT</div>
                <div class="eyebrow mt-1 text-accent-700">Adviser</div>
            </div>
        </div>
    </div>
</section>
