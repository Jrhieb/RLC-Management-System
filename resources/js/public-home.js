import "./registration-ui.js";

// Homepage navigation never fetches operational application data.
export function initializePublicHome(root) {
    if (!root || root.dataset.publicInitialized) return;
    const view = root.ownerDocument.defaultView;
    const doc = root.ownerDocument;
    root.dataset.publicInitialized = "true";

    const toggle = root.querySelector("[data-menu-toggle]");
    const navigation = root.querySelector("[data-public-navigation]");
    const header = root.querySelector("[data-public-header]");
    if (toggle && navigation && header) {
        const compact = view.matchMedia("(max-width: 960px)");
        const label = toggle.querySelector("[data-menu-label]");
        let expanded = false;
        function setMenu(open, returnFocus = false) {
            expanded = compact.matches && open;
            navigation.hidden = compact.matches && !expanded;
            toggle.setAttribute("aria-expanded", String(!navigation.hidden));
            if (label) label.textContent = expanded ? "Close" : "Menu";
            if (returnFocus) toggle.focus();
        }
        toggle.addEventListener("click", () => setMenu(!expanded));
        header.addEventListener("keydown", (event) => {
            if (event.key === "Escape" && expanded) {
                event.preventDefault();
                setMenu(false, true);
            }
        });
        navigation.addEventListener("click", (event) => {
            const link = event.target.closest("a");
            if (!link) return;
            if (compact.matches) setMenu(false);
            const href = link.getAttribute("href");
            if (href?.startsWith("#")) {
                const target = doc.getElementById(href.slice(1));
                if (target) {
                    target.setAttribute("tabindex", "-1");
                    target.focus({ preventScroll: true });
                }
            }
        });
        header.addEventListener("focusout", (event) => {
            if (expanded && event.relatedTarget && !header.contains(event.relatedTarget)) setMenu(false);
        });
        doc.addEventListener("click", (event) => {
            if (expanded && !header.contains(event.target)) setMenu(false);
        });
        compact.addEventListener("change", () => {
            const focusedInside = navigation.contains(doc.activeElement);
            setMenu(false, compact.matches && focusedInside);
        });
        root.classList.add("has-public-menu");
        toggle.hidden = false;
        setMenu(false);
    }

    const about = root.querySelector("[data-about-tabs]");
    if (about) {
        const tablist = about.querySelector("[data-tab-list]");
        const tabs = [...about.querySelectorAll("[data-about-tab]")];
        const panels = tabs.map((tab) => doc.getElementById(tab.hash.slice(1)));
        if (tablist && tabs.length && panels.every(Boolean)) {
            function selectTab(index, focus = false) {
                tabs.forEach((tab, current) => {
                    const selected = index === current;
                    tab.setAttribute("aria-selected", String(selected));
                    tab.tabIndex = selected ? 0 : -1;
                    panels[current].hidden = !selected;
                });
                if (focus) tabs[index].focus();
            }
            tabs.forEach((tab, index) => {
                tab.setAttribute("role", "tab");
                tab.setAttribute("aria-controls", panels[index].id);
                panels[index].setAttribute("role", "tabpanel");
                panels[index].setAttribute("aria-labelledby", tab.id);
                panels[index].tabIndex = 0;
                tab.addEventListener("click", (event) => {
                    event.preventDefault();
                    selectTab(index);
                });
                tab.addEventListener("keydown", (event) => {
                    const directions = { ArrowRight: 1, ArrowLeft: -1 };
                    let next;
                    if (event.key in directions) next = (index + directions[event.key] + tabs.length) % tabs.length;
                    else if (event.key === "Home") next = 0;
                    else if (event.key === "End") next = tabs.length - 1;
                    else if (event.key === " ") next = index;
                    else return;
                    event.preventDefault();
                    selectTab(next, true);
                });
            });
            tablist.setAttribute("role", "tablist");
            about.classList.add("is-enhanced");
            const hashTab = () => tabs.findIndex((tab) => tab.hash === view.location.hash);
            selectTab(Math.max(0, hashTab()));
            view.addEventListener("hashchange", () => {
                const index = hashTab();
                if (index >= 0) selectTab(index);
            });
        }
    }

    const sectionLinks = [...root.querySelectorAll('[data-public-navigation] > a[href^="#"]')];
    function updateCurrentLink() {
        const hash = view.location.hash || "#home";
        sectionLinks.forEach((link) => {
            if (link.hash === hash) link.setAttribute("aria-current", "location");
            else link.removeAttribute("aria-current");
        });
    }
    updateCurrentLink();
    view.addEventListener("hashchange", updateCurrentLink);

    // Visibility never depends on IntersectionObserver or successful JavaScript.
    if (view.IntersectionObserver && !view.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        const observer = new view.IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add("is-revealed");
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });
        root.querySelectorAll("[data-public-reveal]").forEach((element) => observer.observe(element));
    }
}

if (typeof document !== "undefined") initializePublicHome(document.querySelector("[data-public-home]"));
