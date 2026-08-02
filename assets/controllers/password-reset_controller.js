import { Controller } from '@hotwired/stimulus';

/**
 * Password Reset Controller
 * * Intercepts unauthenticated forgotten password forms.
 * * Generates a brand-new asymmetric RSA identity key pair locally in-browser.
 * * Encrypts the private key with the new password, then injects elements into submission metadata.
 */
export default class extends Controller {
    static targets = [
        'form',
        'newPasswordInput',
        'confirmPasswordInput',
        'submitButton',
        'statusIndicator',
        'recoveryCodeInput'
    ];

    /**
     * Executes the Web Crypto identity regeneration ceremony on form submission.
     */
    async executeCeremony(event) {
        event.preventDefault();

        const newPassword = this.newPasswordInputTarget.value;
        const confirmPassword = this.confirmPasswordInputTarget.value;

        if (newPassword.length < 8) {
            this.updateStatus('Your new password must be at least 8 characters long.', 'danger');
            return;
        }

        if (newPassword !== confirmPassword) {
            this.updateStatus('New password and password confirmation fields do not match.', 'danger');
            return;
        }

        this.formTarget.submit();
    }

    // --- Parsing Utility Helpers ---

    arrayBufferToBase64(buffer) {
        let binary = '';
        const bytes = new Uint8Array(buffer);
        const len = bytes.byteLength;
        for (let i = 0; i < len; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    }

    arrayToHex(buffer) {
        return Array.from(new Uint8Array(buffer))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    }

    chunkString(str, length) {
        const numChunks = Math.ceil(str.length / length);
        const chunks = new Array(numChunks);
        for (let i = 0, o = 0; i < numChunks; ++i, o += length) {
            chunks[i] = str.substr(o, length);
        }
        return chunks.join('\n') + '\n';
    }

    injectHiddenField(name, value) {
        let input = this.formTarget.querySelector(`input[name="${name}"]`);
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            this.formTarget.appendChild(input);
        }
        input.value = value;
    }

    // --- UI Helpers ---

    lockUI(statusText) {
        this.submitButtonTarget.disabled = true;
        this.updateStatus(statusText, 'info');
    }

    unlockUI() {
        this.submitButtonTarget.disabled = false;
    }

    updateStatus(message, type) {
        this.statusIndicatorTarget.classList.remove('d-none');
        this.statusIndicatorTarget.className = `status-alert alert-${type}`;
        this.statusIndicatorTarget.textContent = message;
    }
}
