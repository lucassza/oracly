@if (($rank ?? null) === 1)
    <span class="ml-1 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-800 dark:bg-amber-400/20 dark:text-amber-200">👑 Melhor da hora</span>
@elseif (($rank ?? null) === 2)
    <span class="ml-1 inline-flex items-center rounded-full bg-orange-100 px-2 py-0.5 text-[11px] font-bold text-orange-800 dark:bg-orange-400/20 dark:text-orange-200">🔥 2ª da hora</span>
@elseif (($rank ?? null) === 3)
    <span class="ml-1 inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800 dark:bg-emerald-400/20 dark:text-emerald-200">● 3ª da hora</span>
@endif
