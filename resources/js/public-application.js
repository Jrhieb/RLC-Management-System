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
                if (!/^[+\d()\s-]+$/.test(value) || digits < 7 || digits > 15) message = "Enter a contact number with 7–15 digits.";
            }
            if (!message && !field.checkValidity()) message = field.type === "checkbox" ? "Please confirm the acknowledgment." : "Please complete this field with a valid value.";
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
    form.addEventListener("submit", async event => {
        event.preventDefault();
        if (!validate()) return;
        if (form.hasAttribute("data-real-intake")) {
            const mapping = { guardian_name: "application-guardian", guardian_relationship: "application-relationship", contact_number: "application-contact", email: "application-email", barangay: "application-barangay", municipality: "application-city", preferred_contact: "application-method", reason: "application-reason" };
            const data = new FormData();
            Object.entries(mapping).forEach(([name, id]) => data.set(name, form.querySelector("#" + id).value.trim()));
            data.set("submission_key", form.querySelector('[name="submission_key"]').value);
            data.set("_token", form.querySelector('[name="_token"]').value);
            data.set("acknowledgment", form.querySelector("#application-consent").checked ? "1" : "0");
            const button = form.querySelector('[type="submit"]');
            button.disabled = true;
            try {
                const response = await fetch(form.action, { method: "POST", body: data, headers: { Accept: "application/json" }, credentials: "same-origin" });
                const result = await response.json();
                if (response.status === 422 && result.errors) {
                    const key = Object.keys(result.errors)[0];
                    const id = mapping[key] || (key === "acknowledgment" ? "application-consent" : null);
                    const field = id && form.querySelector("#" + id);
                    if (field) {
                        show(field.closest("[data-step]") ? Number(field.closest("[data-step]").dataset.step) : 3);
                        field.setAttribute("aria-invalid", "true");
                        status.textContent = result.errors[key][0];
                        field.focus();
                    } else status.textContent = "Please review your answers and try again.";
                    return;
                }
                if (!response.ok || !result.reference) throw new Error("Submission unavailable");
                sections.forEach(section => { section.hidden = true; });
                status.textContent = result.message + " Reference: " + result.reference;
                status.focus();
            } catch {
                status.textContent = "We could not confirm your submission. Your answers remain here; please try again.";
                status.focus();
            } finally {
                button.disabled = false;
            }
            return;
        }
        completing = true;
        form.reset();
        completing = false;
        updateEmail();
        sections.forEach(section => { section.hidden = true; });
        status.textContent = "Sample preview complete. Nothing has been submitted, saved, or emailed. Online applications are not open yet.";
        status.focus();
    });
    form.addEventListener("reset", () => { if (!completing) setTimeout(() => { updateEmail(); show(1); }, 0); });
    if (!form.hasAttribute("data-real-intake")) form.ownerDocument.defaultView.addEventListener("pagehide", () => form.reset());
    form.noValidate = true;
    form.querySelector("fieldset").disabled = false;
}
