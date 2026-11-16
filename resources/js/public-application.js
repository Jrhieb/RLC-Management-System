export function initializeApplicationDraft(form) {
    if (!form || form.dataset.initialized) return;
    form.dataset.initialized = "true";
    const sections = [...form.querySelectorAll("[data-step]")];
    const status = form.querySelector("[data-draft-status]");
    const method = form.querySelector("[data-preview-method]");
    const email = form.querySelector("[data-preview-email]");
    const labels = { "application-guardian": "Guardian", "application-relationship": "Relationship", "application-contact": "Contact number", "application-email": "Email", "application-barangay": "Barangay", "application-city": "City / municipality", "application-method": "Preferred contact", "application-reason": "Reason" };
    let current = 1;
    let completing = false;
    const updateEmail = () => { email.required = method.value === "email"; };
    method.addEventListener("change", updateEmail);
    updateEmail();
    function show(number) {
        current = number;
        sections.forEach(section => { section.hidden = Number(section.dataset.step) !== number; });
        form.closest(".public-application__card").querySelectorAll("[data-step-indicator]").forEach(item => {
            if (Number(item.dataset.stepIndicator) === number) item.setAttribute("aria-current", "step");
            else item.removeAttribute("aria-current");
        });
        status.textContent = "";
        sections[number - 1].focus();
    }
    function validate() {
        updateEmail();
        const section = sections[current - 1];
        const fields = [...section.querySelectorAll("input, select, textarea")];
        for (const field of fields) {
            field.removeAttribute("aria-invalid");
            const error = field.dataset.draftField && form.querySelector('[data-draft-error="' + field.dataset.draftField + '"]');
            if (error) error.textContent = "";
        }
        for (const field of fields) {
            let message = "";
            if (field.dataset.draftField === "contact") {
                const value = field.value.trim();
                const digits = value.replace(/\D/g, "").length;
                if (!/^[+\d()\s-]+$/.test(value) || digits < 7 || digits > 15) message = "Enter a sample contact number with 7–15 digits.";
            }
            if (!message && !field.checkValidity()) message = field.type === "checkbox" ? "Confirm this is a sample preview." : "Please complete this field with a valid value.";
            if (!message && field.hasAttribute("data-preview-required") && !field.value.trim()) message = "Please complete this field.";
            if (message) {
                field.setAttribute("aria-invalid", "true");
                const error = field.dataset.draftField && form.querySelector('[data-draft-error="' + field.dataset.draftField + '"]');
                if (error) error.textContent = message;
                status.textContent = message + " Nothing has been submitted.";
                field.focus();
                return false;
            }
        }
        return true;
    }
    function review() {
        const list = form.querySelector("[data-review]");
        list.replaceChildren();
        Object.entries(labels).forEach(([id, label]) => {
            const term = document.createElement("dt");
            const value = document.createElement("dd");
            term.textContent = label;
            value.textContent = form.querySelector("#" + id).value.trim() || "Not provided";
            list.append(term, value);
        });
    }
    form.querySelectorAll("[data-next]").forEach(button => button.addEventListener("click", () => {
        if (!validate()) return;
        if (current === 2) review();
        show(current + 1);
    }));
    form.querySelectorAll("[data-back]").forEach(button => button.addEventListener("click", () => show(current - 1)));
    form.querySelectorAll("[data-edit]").forEach(button => button.addEventListener("click", () => show(Number(button.dataset.edit))));
    form.addEventListener("submit", event => {
        event.preventDefault();
        if (!validate()) return;
        completing = true;
        form.reset();
        completing = false;
        updateEmail();
        sections.forEach(section => { section.hidden = true; });
        status.textContent = "Sample preview complete. Nothing has been submitted, saved, or emailed. Online applications are not open yet.";
        status.focus();
    });
    form.addEventListener("reset", () => { if (!completing) setTimeout(() => { updateEmail(); show(1); }, 0); });
    form.ownerDocument.defaultView.addEventListener("pagehide", () => form.reset());
    form.noValidate = true;
    form.querySelector("fieldset").disabled = false;
}
