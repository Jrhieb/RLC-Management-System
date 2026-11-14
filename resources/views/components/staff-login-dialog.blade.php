<dialog id="staff-login-dialog" class="staff-login-dialog" aria-labelledby="staff-login-title">
    <button type="button" class="staff-login-close" aria-label="Close staff login">×</button>
    <div class="staff-login-grid">
        <section class="staff-login-welcome" aria-label="Welcome to RLCP">
            <img class="staff-login-logo" src="{{ asset('images/branding/rlc-logo.png') }}" alt="Risen Lord Church Phils., Inc.">
            <p class="staff-login-church">Risen Lord Church Phils., Inc.</p>
            <div class="staff-login-message">
                <h2>Welcome Back<br>to <span>RLCP</span></h2>
                <p>Serving children and families with<br>faith, care, and community.</p>
            </div>
            <div class="staff-login-values">
                @foreach ([['faith', 'Faith', 'Nurturing spiritual growth and godly values.'], ['learning', 'Care', 'Supporting children and families.'], ['community', 'Community', 'Building a stronger, more inclusive community.']] as [$icon, $label, $description])
                    <div><span class="staff-login-value-icon"><x-public.icon :name="$icon" /></span><strong>{{ $label }}</strong><p>{{ $description }}</p></div>
                @endforeach
            </div>
        </section>
        <section class="staff-login-content">
            <h2 id="staff-login-title">Staff Login</h2>
            <p class="staff-login-subtitle">Sign in to your RLCP workspace.</p>
            <form method="POST" action="{{ route('login.store') }}" class="staff-login-form">
                @csrf
                <label for="staff-login-email">Email address</label>
                <input id="staff-login-email" name="email" type="email" autocomplete="username" placeholder="you@example.com" required>
                <label for="staff-login-password">Password</label>
                <div class="staff-login-password">
                    <input id="staff-login-password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
                    <button type="button" aria-label="Show password" aria-pressed="false" data-login-password-toggle><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </div>
                <div class="staff-login-options">
                    <label><input type="checkbox" name="remember"> Remember me</label>
                    <a href="{{ route('password.request') }}">Forgot password?</a>
                </div>
                <button class="staff-login-submit" type="submit">Log In <span aria-hidden="true">→</span></button>
            </form>
            <div class="staff-login-apply"><p>Want to join our team?</p><a href="{{ route('register') }}" data-registration-open>Apply as Caseworker</a></div>
            <p class="staff-login-security"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/></svg> Authorized staff access only.</p>
        </section>
    </div>
</dialog>
