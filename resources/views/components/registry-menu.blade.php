@props(['label', 'id'])

<details class="rlcr-more" x-data="{
    positionMenu() {
        const trigger = this.$refs.trigger.getBoundingClientRect();
        const menu = this.$refs.menu;
        const bounds = menu.getBoundingClientRect();
        menu.style.left = Math.max(12, Math.min(trigger.right - bounds.width, window.innerWidth - bounds.width - 12)) + 'px';
        menu.style.top = Math.max(12, trigger.bottom + bounds.height + 8 > window.innerHeight
            ? trigger.top - bounds.height - 8 : trigger.bottom + 8) + 'px';
    }
}"
         x-on:toggle.self="if ($el.open) { $refs.menu.showPopover(); positionMenu(); } else { $refs.menu.hidePopover(); }"
         x-on:click.outside="$el.open = false" x-on:keydown.escape="$el.open = false"
         x-on:resize.window="if ($el.open) positionMenu()" x-on:scroll.window="$el.open = false">
    <summary x-ref="trigger" aria-label="{{ $label }}" aria-controls="{{ $id }}" title="{{ $label }}">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <circle cx="12" cy="5" r="2" /><circle cx="12" cy="12" r="2" /><circle cx="12" cy="19" r="2" />
        </svg>
    </summary>
    <div id="{{ $id }}" class="rlcr-menu" popover="auto" x-ref="menu"
         x-on:toggle="if ($event.newState === 'closed') $el.closest('details').open = false">
        {{ $slot }}
    </div>
</details>
