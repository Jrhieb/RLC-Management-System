<div class="rlc-toolbar rlc-dashboard-toolbar">
    <x-rlcp-beneficiary-search />

    <div class="rlc-dashboard-appearance" role="group" aria-label="Color theme" x-data>
        <button type="button" aria-label="Dark mode" x-bind:aria-pressed="$flux.dark.toString()" x-on:click="$flux.appearance = 'dark'">
            <flux:icon.moon variant="outline" />
        </button>
        <button type="button" aria-label="Light mode" x-bind:aria-pressed="(!$flux.dark).toString()" x-on:click="$flux.appearance = 'light'">
            <flux:icon.sun variant="outline" />
        </button>
    </div>

    <x-rlcp-notifications />
</div>
