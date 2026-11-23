export function initializePpiApplication(form) {
    if (!form) return;

    const fields = form.querySelector("fieldset");
    const panels = [...form.querySelectorAll("[data-ppi-step]")];
    const steps = [...form.querySelectorAll(".ppi-steps li")];
    const questions = [...form.querySelectorAll("[data-ppi-question]")];
    const count = form.querySelector("[data-ppi-count]");
    const progress = form.querySelector("progress");
    const members = form.querySelector("[data-ppi-members]");
    const memberTemplate = form.querySelector("[data-ppi-member-template]");
    const addMember = form.querySelector("[data-add-member]");
    const error = form.querySelector("[data-ppi-error]");
    const result = form.querySelector("[data-ppi-result]");
    const submit = form.querySelector("[type=submit]");
    const view = form.ownerDocument.defaultView;
    const live = form.hasAttribute("action");
    let step = 0;
    let questionIndex = 0;
    let editing = null;
    let submitting = false;

    function showError(message = "", focus = false) {
        error.textContent = message;
        error.hidden = !message;
        if (message && focus) error.focus();
    }

    function selected(question) {
        return question.querySelector("select[data-ppi-answer]") || question.querySelector("input[data-ppi-answer]:checked");
    }

    function updateProgress() {
        const answered = questions.filter(question => selected(question)?.value).length;
        progress.value = answered;
        count.textContent = answered + " of " + questions.length + " answered";
    }

    function showQuestion(index, focus = false) {
        questionIndex = index;
        questions.forEach((question, position) => { question.hidden = position !== index; });
        panels[1].querySelector("[data-ppi-next]").textContent =
            index === questions.length - 1 ? "Next: Household Members" : "Next question";
        updateProgress();
        if (focus) questions[index].querySelector("legend").focus();
    }

    function memberData() {
        return [...members.children].map(row => Object.fromEntries(
            [...row.querySelectorAll("[data-member-field]")].map(input => [input.dataset.memberField, input.value.trim() || null])
        ));
    }

    function renderSummary(container, entries) {
        container.replaceChildren();
        for (const [label, value] of entries) {
            const term = form.ownerDocument.createElement("dt");
            const description = form.ownerDocument.createElement("dd");
            term.textContent = label;
            description.textContent = value || "Not provided";
            container.append(term, description);
        }
    }

    function summarize() {
        renderSummary(form.querySelector("[data-ppi-summary='0']"), [...panels[0].querySelectorAll("[data-field-label]")].map(group => [
            group.dataset.fieldLabel, group.querySelector("input:checked")?.parentElement.textContent.trim()
                || group.querySelector("input:not([type=radio]), select")?.value.trim()
        ]));
        renderSummary(form.querySelector("[data-ppi-summary='1']"), questions.map(question => {
            const answer = selected(question);
            return [
                question.querySelector("legend").lastChild.textContent.trim(),
                answer?.selectedOptions?.[0]?.textContent.trim() || answer?.closest("label")?.textContent.trim()
            ];
        }));
        const people = memberData();
        renderSummary(form.querySelector("[data-ppi-summary='2']"), people.length
            ? people.map(person => [person.name, [person.relationship, person.monthly_income && "₱" + person.monthly_income].filter(Boolean).join(" · ") || "No additional details"])
            : [["Household members", "No members listed"]]);
    }

    function showStep(index) {
        step = index;
        showError();
        panels.forEach((panel, position) => { panel.hidden = position !== index; });
        steps.forEach((item, position) => {
            if (position === index) item.setAttribute("aria-current", "step");
            else item.removeAttribute("aria-current");
        });
        if (step === 1) showQuestion(questionIndex);
        if (step === 3) summarize();
        panels[step].querySelector("h2").focus();
    }

    function setFieldError(name, message) {
        const targets = [...panels[0].querySelectorAll("[data-field-name]")].filter(input => input.dataset.fieldName === name);
        if (!targets.length) return false;

        const fieldError = [...panels[0].querySelectorAll("[data-error-for]")].find(node => node.dataset.errorFor === name);
        fieldError.textContent = message;
        fieldError.hidden = !message;
        targets.forEach(input => {
            if (message) {
                input.setAttribute("aria-invalid", "true");
                input.setAttribute("aria-describedby", fieldError.id);
            } else {
                input.removeAttribute("aria-invalid");
                input.removeAttribute("aria-describedby");
            }
        });
        return true;
    }

    function validPanel(panel) {
        let firstInvalid;
        for (const input of panel.querySelectorAll("input, select")) {
            const blankText = input.required && input.type === "text" && !input.value.trim();
            const invalidPhone = input.type === "tel" && !/^(?=(?:\D*\d){7,15}\D*$)[+0-9()\s-]+$/.test(input.value.trim());
            if (input.validity.valid && !blankText && !invalidPhone) continue;
            firstInvalid ||= input;
            if (input.dataset.fieldName) {
                const message = invalidPhone ? "Enter a contact number with 7 to 15 digits." : input.validationMessage;
                setFieldError(input.dataset.fieldName, blankText ? "Enter a value for this field." : message);
            }
        }
        if (!firstInvalid) return true;

        firstInvalid.focus();
        if (!firstInvalid.dataset.fieldName) {
            showError(firstInvalid.type === "checkbox" ? "Acknowledge the privacy notice to continue." : "Check the household member details before continuing.");
        }
        return false;
    }

    function advance() {
        showError();
        if (step === 1) {
            if (!selected(questions[questionIndex])?.value) {
                showError("Choose an answer before continuing.", true);
                return;
            }
            if (questionIndex < questions.length - 1) {
                showQuestion(questionIndex + 1, true);
                return;
            }
        } else if (!validPanel(panels[step])) {
            return;
        }
        if (editing === step) {
            editing = null;
            showStep(3);
        } else {
            showStep(step + 1);
        }
    }

    function renumberMembers() {
        [...members.children].forEach((row, index) => { row.querySelector("[data-member-number]").textContent = index + 1; });
        addMember.disabled = members.children.length >= 30;
        form.querySelector("[data-skip-members]").hidden = members.children.length > 0;
    }

    function showResult(data) {
        form.reset();
        members.replaceChildren();
        form.querySelectorAll("[data-ppi-summary]").forEach(summary => summary.replaceChildren());
        fields.hidden = true;
        form.querySelector(".ppi-steps").hidden = true;
        result.hidden = false;
        const heading = result.querySelector("[data-result-heading]");
        heading.textContent = !live ? "Sample application complete"
            : data.status === "submitted" ? "Application submitted" : "Thank you for applying";
        result.querySelector("[data-result-message]").textContent = data.message;
        const reference = result.querySelector("[data-result-reference]");
        reference.hidden = !data.reference;
        reference.textContent = data.reference ? "Application reference: " + data.reference : "";
        heading.focus();
    }

    async function send() {
        if (submitting) return;
        if (step !== 3) {
            advance();
            return;
        }
        if (!validPanel(panels[3])) return;
        if (!live) {
            showResult({ message: "This was a preview. No application was submitted and no message was sent." });
            return;
        }

        const values = new FormData(form);
        const details = Object.fromEntries([...panels[0].querySelectorAll("[data-field-label]")].map(group => {
            const name = group.querySelector("[data-field-name]").dataset.fieldName;
            return [name, String(values.get(name) ?? "").trim() || null];
        }));
        submitting = true;
        submit.disabled = true;
        submit.textContent = "Submitting…";
        showError();

        try {
            const response = await view.fetch(form.getAttribute("action"), {
                method: "POST",
                headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": values.get("_token") },
                body: JSON.stringify({
                    ...details,
                    submission_key: values.get("submission_key"),
                    privacy_acknowledgment: values.get("privacy_acknowledgment"),
                    household_members: memberData(),
                    answers: questions.map(question => ({
                        question_id: Number(question.dataset.ppiQuestion),
                        option_id: Number(selected(question)?.value),
                    })),
                }),
            });
            const data = await response.json().catch(() => ({}));
            if (response.ok && data.message) {
                showResult(data);
                return;
            }

            let invalidDetail;
            if (response.status === 422 && data.errors) {
                const names = Object.keys(data.errors);
                const detailsErrors = names.filter(name => panels[0].querySelector("[name='" + view.CSS.escape(name) + "']"));
                if (detailsErrors.length) {
                    showStep(0);
                    detailsErrors.forEach(name => setFieldError(name, data.errors[name][0]));
                    invalidDetail = panels[0].querySelector('[aria-invalid="true"]');
                } else if (names.some(name => name.startsWith("answers"))) {
                    showStep(1);
                    showQuestion(0);
                } else if (names.some(name => name.startsWith("household_members"))) {
                    showStep(2);
                }
            }

            const fallback = response.status === 419 || response.status === 403
                ? "The application session is no longer available. Reload the application page to start again."
                : "We could not submit your application. Please try again.";
            showError(response.status === 422 && data.message ? data.message : fallback, !invalidDetail);
            invalidDetail?.focus();
        } catch {
            showError("We could not confirm your submission. Please try again.", true);
        } finally {
            submitting = false;
            submit.disabled = false;
            submit.textContent = "Submit application";
        }
    }

    form.addEventListener("input", event => {
        if (event.target.dataset.fieldName) setFieldError(event.target.dataset.fieldName, "");
        if (event.target.matches("[data-ppi-answer]")) { updateProgress(); showError(); }
    });
    form.addEventListener("submit", event => { event.preventDefault(); void send(); });
    form.addEventListener("click", event => {
        const button = event.target.closest("button");
        if (!button || submitting || result.hidden === false) return;
        if (button.hasAttribute("data-ppi-next")) advance();
        if (button.hasAttribute("data-ppi-back")) {
            showError();
            if (step === 1 && questionIndex > 0) showQuestion(questionIndex - 1, true);
            else showStep(step - 1);
        }
        if (button.hasAttribute("data-ppi-edit")) {
            const edit = Number(button.dataset.ppiEdit);
            editing = edit;
            if (edit === 1) questionIndex = 0;
            showStep(edit);
        }
        if (button.hasAttribute("data-add-member")) {
            members.append(memberTemplate.content.cloneNode(true));
            renumberMembers();
            members.lastElementChild.querySelector("input").focus();
        }
        if (button.hasAttribute("data-remove-member")) {
            button.closest(".ppi-member").remove();
            renumberMembers();
            addMember.focus();
        }
        if (button.hasAttribute("data-skip-members")) {
            editing = null;
            showStep(3);
        }
    });

    form.noValidate = true;
    fields.disabled = false;
}
