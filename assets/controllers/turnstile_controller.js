import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['widget', 'submit']
    static values = {
        sitekey: String,
        // Spin telemetry marker. With explicit rendering Turnstile reads the
        // action from the render options, not from a data-action attribute
        // (which Stimulus would also try to parse as an action descriptor).
        action: { type: String, default: 'turnstile-spin-v2' },
        size: { type: String, default: 'normal' }
    }

    connect() {
        this.disableSubmit();
        this.render();
    }

    disconnect() {
        // Turbo swaps the page without a reload; drop the widget so a stale
        // one is not left behind holding an expired token.
        if (this.checkInterval) {
            clearInterval(this.checkInterval);
        }

        if (this.widgetId && typeof window.turnstile !== 'undefined') {
            window.turnstile.remove(this.widgetId);
        }

        this.widgetId = null;
    }

    render() {
        const widgetElement = this.hasWidgetTarget ? this.widgetTarget : this.element;

        if (typeof window.turnstile !== 'undefined') {
            if (!this.widgetId) {
                // A Turbo cache restore replays the snapshot taken before the
                // last disconnect, which can still hold a dead widget iframe.
                // Turnstile refuses to render into an occupied container.
                widgetElement.replaceChildren();

                this.widgetId = window.turnstile.render(widgetElement, {
                    sitekey: this.sitekeyValue,
                    action: this.actionValue,
                    size: this.sizeValue,
                    callback: () => this.enableSubmit(),
                    'expired-callback': () => this.disableSubmit(),
                    'error-callback': () => this.disableSubmit(),
                });
            }
        } else {
            // Load the script if not already present
            if (!document.querySelector('script[src*="challenges.cloudflare.com"]')) {
                const script = document.createElement('script');
                script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
                script.async = true;
                script.defer = true;
                script.onload = () => this.render();
                document.head.appendChild(script);
            } else {
                // Script is present but window.turnstile is not yet available
                this.checkInterval = setInterval(() => {
                    if (typeof window.turnstile !== 'undefined') {
                        clearInterval(this.checkInterval);
                        this.render();
                    }
                }, 100);

                // Safety timeout
                setTimeout(() => clearInterval(this.checkInterval), 5000);
            }
        }
    }

    /**
     * Buttons to keep disabled until Turnstile hands back a token.
     *
     * An explicit `submit` target wins: login.html.twig scopes this controller to a
     * wrapper holding both the widget and its button, so it can name the button
     * directly. Templates that pull in _turnstile.html.twig cannot -- that partial
     * scopes the controller to the widget alone, leaving the button a sibling outside
     * it and therefore unreachable as a Stimulus target. Fall back to the enclosing
     * form so those pages are gated too.
     */
    get gatedButtons() {
        if (this.hasSubmitTarget) {
            return this.submitTargets;
        }

        const form = this.element.closest('form');

        if (!form) {
            return [];
        }

        // A bare <button> defaults to type="submit" and must be caught. Explicit
        // type="button" ones drive other controllers (passkey sign-in, login step
        // navigation, "not you?") and have to stay clickable.
        return Array.from(
            form.querySelectorAll('button:not([type]), button[type="submit"], input[type="submit"]')
        );
    }

    enableSubmit() {
        this.gatedButtons.forEach((button) => { button.disabled = false; });
    }

    disableSubmit() {
        this.gatedButtons.forEach((button) => { button.disabled = true; });
    }
}
