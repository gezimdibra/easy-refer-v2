// The submit loading state (ui-ux-standards CP-02): the one
// mechanism every surface uses, imported by both the app and the
// flow entries. A form opts in by carrying a data-loading
// attribute; that is the whole integration, one line per form.
//
// On submit, each of the form's submit buttons, including a
// button outside the form that names it through the form
// attribute (the flow's sticky action bar pattern), enters the
// loading state: a spinner appears beside the label (the label
// stays visible), the button takes a real disabled attribute
// and aria-busy, and the app.css .is-loading rules hold its
// variant colors so the wait reads as working, not broken. The
// script locks each button's width before the spinner lands,
// so layout never jumps (CP-02). A submit the browser blocks
// (an invalid field) never fires the submit event, so nothing
// locks for a form that is not actually leaving. The dataset
// guard swallows a repeat Enter-key submit from a text field
// while the first submit is in flight; the page that answers
// the post replaces this one, which is what clears the state.

export function initSubmitLoading() {
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-loading')) {
            return;
        }

        if (form.dataset.loading === 'true') {
            event.preventDefault();
            return;
        }

        form.dataset.loading = 'true';

        const buttons = new Set(form.querySelectorAll('button[type="submit"], button:not([type])'));

        if (form.id) {
            document
                .querySelectorAll(`button[form="${form.id}"][type="submit"]`)
                .forEach((button) => buttons.add(button));
        }

        buttons.forEach((button) => {
            if (button.disabled) {
                return;
            }

            const width = button.offsetWidth;

            if (width > 0) {
                button.style.width = `${width}px`;
            }

            if (!button.querySelector('.spinner')) {
                const spinner = document.createElement('span');
                spinner.className = 'spinner';
                spinner.setAttribute('aria-hidden', 'true');
                button.prepend(spinner);
            }

            button.classList.add('is-loading');
            button.setAttribute('aria-busy', 'true');
            button.disabled = true;
        });
    });
}
