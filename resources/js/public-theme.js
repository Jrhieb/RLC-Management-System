export function initializePublicTheme(root) {
    const toggle = root.querySelector("[data-public-theme-toggle]");
    if (!toggle) return;
    const view = root.ownerDocument.defaultView;
    const system = view.matchMedia("(prefers-color-scheme: dark)");
    let preference;
    try { preference = view.localStorage.getItem("rlcp.public.theme"); } catch { /* Theme still works when browser storage is unavailable. */ }
    if (!["light", "dark"].includes(preference)) preference = null;
    const apply = () => {
        const dark = preference ? preference === "dark" : system.matches;
        root.dataset.publicTheme = dark ? "dark" : "light";
        toggle.setAttribute("aria-pressed", String(dark));
        toggle.title = dark ? "Switch to light theme" : "Switch to dark theme";
    };
    toggle.addEventListener("click", () => {
        preference = root.dataset.publicTheme === "dark" ? "light" : "dark";
        try { view.localStorage.setItem("rlcp.public.theme", preference); } catch { /* The current page retains the selected theme. */ }
        apply();
    });
    system.addEventListener("change", apply);
    apply();
    toggle.hidden = false;
}
