import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["row", "button", "spinner", "buttonText"];

    async dispatch(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const url = button.dataset.dispatchUrlValue;

        // UI Feedback: Disable button and show spinner
        button.disabled = true;
        if (this.hasSpinnerTarget) this.spinnerTarget.classList.remove('d-none');
        if (this.hasButtonTextTarget) this.buttonTextTarget.textContent = 'Dispatching...';

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json'
                }
            });

            const data = await response.json();

            if (response.ok && data.success) {
                // Animate row removal upon successful dispatch
                if (this.hasRowTarget) {
                    this.rowTarget.classList.add('table-success');
                    setTimeout(() => {
                        this.rowTarget.remove();
                        this.checkEmptyState();
                    }, 500);
                }
            } else {
                alert(data.message || 'Failed to dispatch order.');
                this.resetButton(button);
            }
        } catch (error) {
            console.error('Error dispatching position:', error);
            alert('A network error occurred while dispatching the order.');
            this.resetButton(button);
        }
    }

    resetButton(button) {
        button.disabled = false;
        if (this.hasSpinnerTarget) this.spinnerTarget.classList.add('d-none');
        if (this.hasButtonTextTarget) this.buttonTextTarget.textContent = 'Approve & Execute';
    }

    checkEmptyState() {
        const tbody = document.querySelector('tbody');
        if (tbody && tbody.children.length === 0) {
            window.location.reload();
        }
    }
}
