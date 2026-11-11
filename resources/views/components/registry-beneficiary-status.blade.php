@props(['status'])
<span class="rlcr-status {{ $status === 'active' ? 'rlcr-status-active' : 'rlcr-status-neutral' }}"><span aria-hidden="true"></span>{{ ucfirst($status) }}</span>
