{{--
    The command menu. Ctrl+K or / from anywhere, or the search button in the
    sidebar. Lists every screen this person can open and their common actions.
    The entries come from App\View\Components\AppLayout; this only filters.
--}}
<div x-data="commandMenu(@js($commands))"
     @open-command-menu.window="show()"
     @keydown.window="onKey($event)"
     x-show="open" x-cloak
     class="fixed inset-0 z-modal"
     role="dialog" aria-modal="true" aria-label="Search or jump to">

    <div class="absolute inset-0 bg-[rgb(var(--shadow)/0.45)]" @click="hide()"
         x-show="open" x-transition.opacity.duration.150ms></div>

    <div class="relative mx-auto mt-[12vh] w-[min(36rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-line bg-raised shadow-lg"
         x-show="open"
         x-transition:enter="transition duration-150 ease-out"
         x-transition:enter-start="translate-y-1 scale-[0.98] opacity-0"
         x-transition:enter-end="translate-y-0 scale-100 opacity-100">

        <div class="flex items-center gap-3 border-b border-line px-4">
            <x-icon name="search" class="size-[18px] flex-none text-fg-subtle" />
            <input x-ref="input" x-model="query" @input="index = 0"
                   @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
                   @keydown.enter.prevent="go()" @keydown.escape.prevent.stop="hide()"
                   type="text" autocomplete="off" spellcheck="false"
                   placeholder="Search screens and actions"
                   aria-label="Search screens and actions"
                   class="h-12 w-full border-0 bg-transparent px-0 text-[15px] shadow-none focus:ring-0">
            <span class="kbd flex-none">Esc</span>
        </div>

        <ul x-ref="list" class="max-h-[50vh] overflow-y-auto p-1.5" role="listbox">
            <template x-for="(item, i) in results" :key="item.label + item.url">
                <li role="option" :aria-selected="i === index" :data-active="i === index"
                    @mouseenter="index = i" @click="go(item)"
                    :class="i === index ? 'bg-sunken text-fg' : 'text-fg-muted'"
                    class="flex h-10 cursor-pointer items-center gap-3 rounded-md px-3 text-sm">
                    <svg class="size-4 flex-none" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true" x-html="item.svg"></svg>
                    <span class="truncate" x-text="item.label"></span>
                    <span class="ml-auto flex-none text-xs text-fg-subtle" x-text="item.hint"></span>
                </li>
            </template>

            <li x-show="results.length === 0" class="px-3 py-8 text-center text-sm text-fg-muted">
                Nothing matches "<span x-text="query"></span>".
            </li>
        </ul>

        <div class="hidden items-center gap-4 border-t border-line px-4 py-2 text-xs text-fg-subtle sm:flex">
            <span class="flex items-center gap-1.5"><span class="kbd">&uarr;</span><span class="kbd">&darr;</span> to move</span>
            <span class="flex items-center gap-1.5"><span class="kbd">Enter</span> to open</span>
        </div>
    </div>
</div>
