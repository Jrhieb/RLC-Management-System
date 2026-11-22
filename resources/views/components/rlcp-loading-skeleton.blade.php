<div {{ $attributes->class(['rlc-loading-skeleton']) }} aria-hidden="true">
    @for ($row = 0; $row < 2; $row++)
        <div class="rlc-skeleton-row">
            <span class="rlc-skeleton-avatar"></span>
            <span class="rlc-skeleton-lines"><span></span><span></span></span>
        </div>
    @endfor
</div>
