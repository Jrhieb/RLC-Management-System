export function initializeApplicationDraft(form) {
    if (!form || form.dataset.initialized) return;
    form.dataset.initialized = "true";
    const fields = [...form.querySelectorAll("[data-draft-field]")];
    const status = form.querySelector("[data-draft-status]");
    const errorFor = (field) => form.querySelector(`[data-draft-error="${field.dataset.draftField}"]`);
    function clearFeedback() {
        fields.forEach((field) => {
            field.removeAttribute("aria-invalid");
            errorFor(field).textContent = "";
        });
        status.textContent = "";
    }
    form.addEventListener("submit", (event) => {
        event.preventDefault();
        clearFeedback();
        let firstInvalid;
        fields.forEach((field) => {
            const kind = field.dataset.draftField;
            let error = "";
            if (kind === "guardian" && !field.value.trim()) error = "Enter a sample guardian name.";
            if (kind === "contact") {
                const value = field.value.trim();
                const digits = value.replace(/\D/g, "").length;
                if (!/^[+\d()\s-]+$/.test(value) || digits < 7 || digits > 15) error = "Enter a sample contact number with 7–15 digits.";
            }
            if (kind === "consent" && !field.checked) error = "Confirm that you are using sample information for this preview.";
            if (error) {
                errorFor(field).textContent = error;
                field.setAttribute("aria-invalid", "true");
                firstInvalid ??= field;
            }
        });
        if (firstInvalid) {
            status.textContent = "Please review the highlighted fields. Nothing has been submitted.";
            firstInvalid.focus();
            return;
        }
        form.reset();
        status.textContent = "Preview check complete. Nothing has been submitted, saved, or emailed. Sample details have been cleared. Online applications are not open yet.";
        status.focus();
    });
    form.addEventListener("reset", clearFeedback);
    form.ownerDocument.defaultView.addEventListener("pagehide", () => form.reset());
    form.noValidate = true;
    form.querySelector("fieldset").disabled = false;
}
