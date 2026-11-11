import { registrationEmailForm } from "./registration-email.js";

// Bind both render locations to the existing email-first state machine. This
// adapter only renders state; endpoint, CSRF, responses and duplicate guard live
// in registrationEmailForm and the unchanged RegistrationController/service.
export function bindRegistrationEmail(root, onStateChange = () => {}) {
    if (!root || root.registrationController) return root?.registrationController;
    const form = root.querySelector("[data-registration-form]");
    if (!form) return;
    const feedback = root.querySelector("[data-registration-feedback]");
    const error = root.querySelector("[data-registration-error]");
    const submit = form.querySelector('[type="submit"]');
    const input = form.elements.namedItem("email");
    const model = registrationEmailForm({ hasRequested: root.dataset.hasRequested === "true" });
    model.feedback = feedback.textContent.trim();
    model.error = error.textContent.trim();
    function render() {
        form.setAttribute("aria-busy", String(model.submitting));
        submit.disabled = model.submitting;
        submit.setAttribute("aria-disabled", String(model.submitting));
        form.querySelector("[data-registration-idle]").hidden = model.submitting;
        form.querySelector("[data-registration-idle]").textContent = model.hasRequested ? "Resend verification link" : "Send verification link";
        form.querySelector("[data-registration-loading]").hidden = !model.submitting;
        form.querySelector("[data-registration-pending]").hidden = !model.submitting;
        feedback.textContent = model.submitting ? "Queuing verification email…" : model.feedback;
        error.textContent = model.error;
        onStateChange(model);
    }
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (model.submitting) return;
        input.removeAttribute("aria-invalid");
        const pending = model.submit(event);
        render();
        await pending;
        if (model.error && root.ownerDocument.activeElement === input) input.setAttribute("aria-invalid", "true");
        render();
    });
    root.registrationController = { model, render };
    render();
    return root.registrationController;
}

export function initializeRegistration(doc = document) {
    const view = doc.defaultView;
    const dialog = doc.querySelector("[data-registration-dialog]");
    const notice = doc.querySelector("[data-registration-notice]");
    let closedWithPending = false;
    function updateNotice(model) {
        if (!notice || dialog?.open || !closedWithPending) return;
        notice.hidden = false;
        notice.querySelector("[data-registration-notice-text]").textContent = model.submitting
            ? "Your request is still processing. Closing registration does not cancel it."
            : model.error || model.feedback;
    }
    doc.querySelectorAll("[data-registration-email]").forEach((root) => bindRegistrationEmail(root, updateNotice));

    if (dialog && typeof dialog.showModal === "function" && !dialog.dataset.initialized) {
        dialog.dataset.initialized = "true";
        const controller = dialog.querySelector("[data-registration-email]").registrationController;
        const reducedMotion = view.matchMedia("(prefers-reduced-motion: reduce)");
        const inertBefore = new Map();
        let opener, closing = false, closeTimer, scrollX = 0, scrollY = 0;
        const focusable = () => [...dialog.querySelectorAll('button:not(:disabled), a[href], input:not([type="hidden"]):not(:disabled), [tabindex="0"]')]
            .filter((element) => element.getClientRects().length > 0);
        function restorePage() {
            clearTimeout(closeTimer);
            closing = false;
            dialog.classList.remove("is-closing");
            inertBefore.forEach((wasInert, element) => { element.inert = wasInert; });
            inertBefore.clear();
            doc.documentElement.classList.remove("registration-scroll-locked");
            doc.body.classList.remove("registration-scroll-locked");
            doc.body.style.removeProperty("--registration-scroll-top");
            doc.body.style.removeProperty("--registration-locked-width");
            view.scrollTo({ left: scrollX, top: scrollY, behavior: "instant" });
            // A status-link opener may be hidden while the dialog is open.
            if (opener?.isConnected) {
                if (notice?.contains(opener)) notice.hidden = false;
                opener.focus({ preventScroll: true });
            }
            updateNotice(controller.model);
        }
        function close() {
            if (!dialog.open || closing) return;
            closing = true;
            closedWithPending = controller.model.submitting || closedWithPending;
            const finish = () => { if (dialog.open) dialog.close(); };
            if (reducedMotion.matches) finish();
            else {
                dialog.classList.add("is-closing");
                closeTimer = view.setTimeout(finish, 160);
            }
        }
        dialog.addEventListener("close", restorePage);
        dialog.addEventListener("cancel", (event) => { event.preventDefault(); close(); });
        dialog.querySelector("[data-registration-close]").addEventListener("click", close);
        dialog.addEventListener("keydown", (event) => {
            if (event.key !== "Tab") return;
            const elements = focusable();
            const first = elements[0], last = elements.at(-1);
            if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first?.focus(); }
        });
        doc.querySelectorAll("[data-registration-open]").forEach((link) => {
            link.setAttribute("aria-haspopup", "dialog");
            link.setAttribute("aria-controls", dialog.id);
        });
        doc.addEventListener("click", (event) => {
            const link = event.target.closest("a[data-registration-open]");
            if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target === "_blank") return;
            // No login modal exists. Let real links handle any other open dialog.
            if (doc.querySelector("dialog[open]") && !dialog.open) return;
            event.preventDefault();
            if (dialog.open || closing) return;
            opener = link;
            scrollX = view.scrollX;
            scrollY = view.scrollY;
            doc.body.style.setProperty("--registration-scroll-top", `${-scrollY}px`);
            doc.body.style.setProperty("--registration-locked-width", `${doc.documentElement.clientWidth}px`);
            doc.documentElement.classList.add("registration-scroll-locked");
            doc.body.classList.add("registration-scroll-locked");
            if (notice) notice.hidden = true;
            for (const element of doc.body.children) {
                if (element === dialog || ["SCRIPT", "STYLE", "LINK"].includes(element.tagName)) continue;
                inertBefore.set(element, element.inert);
                element.inert = true;
            }
            dialog.showModal();
            dialog.querySelector('input[name="email"]').focus();
        });
        notice?.querySelector("[data-registration-notice-dismiss]").addEventListener("click", () => {
            closedWithPending = false;
            notice.hidden = true;
        });
    }

    doc.querySelectorAll("[data-password-toggle]").forEach((button) => {
        if (button.dataset.initialized) return;
        button.dataset.initialized = "true";
        const input = doc.getElementById(button.getAttribute("aria-controls"));
        if (!input) return;
        const label = button.getAttribute("aria-label").replace(/^Show /, "");
        button.hidden = false;
        button.addEventListener("click", () => {
            const visible = input.type === "password";
            input.type = visible ? "text" : "password";
            button.textContent = visible ? "Hide" : "Show";
            button.setAttribute("aria-label", `${visible ? "Hide" : "Show"} ${label}`);
            button.setAttribute("aria-pressed", String(visible));
        });
    });
    // Native completion validation redirects render fresh, empty password fields.
    // A restored back/forward page must not retain a password either.
    view.addEventListener("pageshow", (event) => {
        if (!event.persisted) return;
        doc.querySelectorAll('[data-registration-completion] input[autocomplete="new-password"]').forEach((input) => { input.value = ""; });
    });
}

if (typeof document !== "undefined") initializeRegistration(document);
