@props(['target' => null])
{{-- Reusable wire:loading overlay for a position:relative container (pass
     class="relative" to the wrapping <x-card> or <div>). Replaces the card's
     content with an unmistakable loading state — a large spinner plus a
     label, on a near-opaque panel — while the given wire:target
     propert(ies)/method(s) are being processed by Livewire, e.g. a
     wire:model.live filter. A translucent dim alone reads as barely-there,
     especially in dark mode where the tint sits on an already-dark card;
     this is deliberately closer to opaque so it reads as "this card is
     loading" rather than a subtle darkening. Always scope :target
     explicitly to the filter properties/methods that affect this card; a
     bare wire:loading with no target would also fire on unrelated
     background requests (e.g. the dashboard's silent freshness poller).

     !opacity-100 overrides Livewire's own injected baseline style for every
     [wire:loading] element (`opacity: 0.6; pointer-events: none;`), which
     otherwise washes this out to a faint, half-transparent panel — verified
     by inspecting Livewire's injected <style> tag directly. Its
     pointer-events: none is fine to keep (this overlay isn't interactive). --}}
<div
    wire:loading.flex
    wire:target="{{ $target }}"
    class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2.5 rounded-2xl bg-paper/95 dark:bg-[#131917]/95 !opacity-100"
    role="status" aria-live="polite" aria-label="Loading"
>
    {{-- inline-block: a bare <span> defaults to display:inline, which ignores
         explicit width/height entirely — the element was present in the DOM
         with the right classes but rendering as a near-zero-size box (this
         is what "Loading… text shows but no spinner" turned out to be).
         border-transparent + border-t-{color} (not border-{color} +
         border-r-transparent): the border-{color} utility sets the
         border-color shorthand (all 4 sides), so pairing it with
         border-r-transparent is a coin-flip depending on which rule lands
         later in the compiled stylesheet — here it was losing, filling in
         solid on every side and hiding the spin. Coloring only the top side
         is unambiguous regardless of source order. --}}
    <span class="inline-block w-9 h-9 rounded-full border-[3px] border-transparent border-t-forest-600 dark:border-t-[#4a8a68] animate-spin"></span>
    <span class="text-[11.5px] font-medium text-ink-500 dark:text-[#8a9087]">Loading…</span>
</div>
