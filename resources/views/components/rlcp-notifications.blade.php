@props(['activityFeed' => null])
@php($activityFeed ??= auth()->user()->canAccessStaff())
<div class="rlc-notifications" x-data="accountNotificationBell"
     data-endpoint="{{ route('account.notifications.index', absolute: false) }}"
     data-count-endpoint="{{ route('account.notifications.count', absolute: false) }}"
     data-read-all-endpoint="{{ route('account.notifications.read-all', absolute: false) }}"
     data-csrf="{{ csrf_token() }}"
     @if ($activityFeed) data-activity-endpoint="{{ route('dashboard.notifications', absolute: false) }}" @endif
     x-on:click.outside="close()" x-on:keydown.escape.prevent.stop="close()"
     x-on:focusout="if (!$el.contains($event.relatedTarget)) close()">
    <button type="button" class="rlc-theme-button rlc-notification-button" aria-label="Notifications"
            :aria-label="unreadCount ? `Notifications, ${unreadCount} unread notifications` : 'Notifications'"
            aria-controls="rlc-notification-panel" :aria-expanded="open.toString()" x-on:click="toggle()">
        <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
        </svg>
        <span class="rlc-notification-count" x-cloak x-show="unreadCount > 0" x-text="unreadCount > 99 ? '99+' : unreadCount" aria-hidden="true"></span>
        <span class="sr-only" role="status" aria-live="polite" x-text="`${unreadCount} unread notifications`"></span>
    </button>
    <section id="rlc-notification-panel" class="rlc-notification-panel" aria-label="Notifications"
             x-cloak x-show="open" x-transition.opacity.duration.150ms>
        <div class="rlc-notification-heading">
            <div><h2>Notifications</h2><p>{{ auth()->user()->isEligibleCaseworker() ? 'Account and caseload updates' : 'Account updates' }}</p></div>
            <button type="button" x-on:click="refresh()" :disabled="loading" aria-label="Refresh notifications">Refresh</button>
        </div>
        <section class="rlc-notification-section" aria-labelledby="rlc-account-notifications-title">
            <div class="rlc-notification-section-heading">
                <h3 id="rlc-account-notifications-title">Your notifications</h3>
                <button type="button" x-on:click="markRead()" :disabled="marking || unreadCount === 0">Mark all read</button>
            </div>
            <p class="rlc-notification-message" role="status" aria-live="polite"
               x-show="error || countError || (items.length === 0)"
               x-text="loading ? 'Loading notifications...' : (error || countError || 'No notifications yet.')"></p>
            <ul :aria-busy="loading.toString()">
                <template x-for="item in items" :key="item.id">
                    <li :class="!item.read_at && 'rlc-notification-unread'">
                        <a :href="item.url" x-on:click.prevent="visit(item)">
                            <strong x-text="item.title"></strong>
                            <span x-text="item.message"></span>
                            <small x-text="item.time"></small>
                        </a>
                        <button class="rlc-notification-read" type="button" x-show="!item.read_at" :disabled="marking"
                                :aria-label="`Mark ${item.title} as read`" x-on:click="markRead(item)">Mark read</button>
                    </li>
                </template>
            </ul>
        </section>
        @if ($activityFeed)
            <section class="rlc-notification-section" aria-labelledby="rlc-activity-feed-title">
                <div class="rlc-notification-section-heading"><h3 id="rlc-activity-feed-title">Activity feed</h3></div>
                <p class="rlc-notification-message" role="status" x-show="activityError || activity.length === 0"
                   x-text="activityLoading ? 'Loading activity...' : (activityError || 'No recorded activity yet.')"></p>
                <ul :aria-busy="activityLoading.toString()">
                    <template x-for="item in activity" :key="item.id">
                        <li><a :href="item.url"><strong x-text="item.title"></strong><span x-text="item.detail"></span><small x-text="item.time"></small></a></li>
                    </template>
                </ul>
            </section>
        @endif
        <p class="rlc-notification-footer">Latest 20 notifications. Unread count refreshes while this page is visible.</p>
    </section>
</div>
