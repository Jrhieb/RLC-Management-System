export function loginForm({ freshLogin = (url) => window.location.replace(url) } = {}) {
    return {
        submitting: false,
        submit(event) {
            if (event.defaultPrevented) return;
            if (this.submitting) {
                event.preventDefault();
                return;
            }
            this.submitting = true;
            queueMicrotask(() => {
                if (event.defaultPrevented) this.submitting = false;
            });
        },
        restore(event) {
            if (!event.persisted) return;
            this.submitting = true;
            // History restoration must fetch a current form, never replay a POST.
            freshLogin(this.$root.action);
        },
    };
}

if (typeof document !== "undefined") {
    const register = () => window.Alpine.data("loginForm", loginForm);
    if (window.Alpine) register();
    else document.addEventListener("alpine:init", register, { once: true });
}
