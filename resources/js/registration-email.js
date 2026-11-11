export function registrationEmailForm({
    hasRequested = false,
    request = (...args) => globalThis.fetch(...args),
    formData = (form) => new FormData(form),
} = {}) {
    return {
        submitting: false,
        hasRequested,
        feedback: "",
        error: "",
        async submit(event) {
            const form = event.currentTarget;
            if (this.submitting || !form.reportValidity()) return;
            this.submitting = true;
            this.feedback = "";
            this.error = "";
            try {
                const response = await request(form.action, {
                    method: "POST",
                    credentials: "same-origin",
                    redirect: "error",
                    headers: { Accept: "application/json" },
                    body: formData(form),
                });
                if (response.status === 202) {
                    const data = await response.json();
                    if (typeof data.message !== "string" || !data.message) {
                        throw new Error("Unconfirmed response");
                    }
                    this.feedback = data.message;
                    this.hasRequested = true;
                } else if (response.status === 422) {
                    const data = await response.json();
                    this.error =
                        data.errors?.email?.[0] ||
                        data.errors?.registration?.[0] ||
                        "Check your email address and try again.";
                    if (data.errors?.email) form.elements.namedItem("email")?.focus();
                } else if (response.status === 429) {
                    this.error = "Too many attempts. Please try again later.";
                } else if (response.status === 419) {
                    this.error = "Your session has expired. Refresh this page before trying again.";
                } else {
                    this.error = "Your request could not be submitted. Please try again later.";
                }
            } catch {
                // A lost response may follow a successful enqueue. Never retry
                // automatically or expose transport/browser exception details.
                this.error =
                    "We could not confirm whether your request was queued. Please wait at least one minute before trying again.";
            } finally {
                this.submitting = false;
            }
        },
    };
}

if (typeof document !== "undefined") {
    const register = () => window.Alpine.data("registrationEmailForm", registrationEmailForm);
    if (window.Alpine) register();
    else document.addEventListener("alpine:init", register, { once: true });
}
