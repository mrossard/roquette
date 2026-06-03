// JS for Poll Composer in Chat Room

window.togglePollComposer = function() {
    const pollComposer = document.querySelector('.poll-composer');
    if (!pollComposer) return;

    const messageInput = document.getElementById('message');
    const questionInput = document.getElementById('poll_question');
    const toggleBtn = document.querySelector('.btn-poll-toggle');

    if (pollComposer.style.display === 'none' || pollComposer.style.display === '') {
        // Open poll composer
        pollComposer.style.display = 'block';
        if (toggleBtn) toggleBtn.classList.add('active');

        if (messageInput) {
            messageInput.removeAttribute('required');
            messageInput.setAttribute('disabled', 'disabled');
            messageInput.value = '';
            messageInput.placeholder = "Sondage en cours de création...";
        }
        if (questionInput) {
            questionInput.setAttribute('required', 'required');
            questionInput.focus();
        }

        // Make first two option inputs required
        const optionInputs = document.querySelectorAll('.poll-option-input');
        optionInputs.forEach((input, index) => {
            if (index < 2) {
                input.setAttribute('required', 'required');
            }
        });
    } else {
        // Close poll composer
        pollComposer.style.display = 'none';
        if (toggleBtn) toggleBtn.classList.remove('active');

        if (messageInput) {
            messageInput.removeAttribute('disabled');
            // Restore textarea to required if there's no file uploaded
            const fileInput = document.getElementById('file-upload');
            messageInput.placeholder = `Saisissez votre message...`;

            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                messageInput.setAttribute('required', 'required');
            }
        }
        if (questionInput) {
            questionInput.removeAttribute('required');
            questionInput.value = '';
        }

        // Clean option values and remove dynamically added options
        const optionInputs = document.querySelectorAll('.poll-option-input');
        optionInputs.forEach((input, index) => {
            input.value = '';
            input.removeAttribute('required');
            // Remove options beyond the first two
            if (index >= 2) {
                input.closest('div').remove();
            }
        });
    }
};

// Generic helper to update placeholders
function updatePlaceholders(container, inputClass) {
    const inputs = container.querySelectorAll(`.${inputClass}`);
    inputs.forEach((input, index) => {
        input.placeholder = `Option ${index + 1}`;
    });
}

// Public API for updating composer placeholders
window.updateOptionPlaceholders = function() {
    const container = document.getElementById('poll-options-container');
    if (container) {
        updatePlaceholders(container, 'poll-option-input');
    }
};

// Generic helper to append a poll option input
function createPollOptionElement(container, inputClass, isRequired, onRemoveCallback) {
    const count = container.querySelectorAll(`.${inputClass}`).length;
    if (count >= 8) {
        alert('Un maximum de 8 options est autorisé.');
        return;
    }

    const div = document.createElement('div');
    div.style.display = 'flex';
    div.style.gap = '0.5rem';
    div.style.alignItems = 'center';

    const input = document.createElement('input');
    input.type = 'text';
    input.name = 'poll_options[]';
    input.placeholder = `Option ${count + 1}`;
    input.className = `${inputClass} input-sidebar`;
    if (isRequired) {
        input.required = true;
    }
    input.style.flex = '1';

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.innerHTML = '✕';
    removeBtn.style.width = '24px';
    removeBtn.style.height = '24px';
    removeBtn.style.borderRadius = '50%';
    removeBtn.style.border = 'none';
    removeBtn.style.background = 'rgba(239, 68, 68, 0.2)';
    removeBtn.style.color = 'var(--accent-red, #ff5b5b)';
    removeBtn.style.cursor = 'pointer';
    removeBtn.style.display = 'flex';
    removeBtn.style.alignItems = 'center';
    removeBtn.style.justifyContent = 'center';
    removeBtn.onclick = function() {
        div.remove();
        if (onRemoveCallback) {
            onRemoveCallback();
        }
    };

    div.appendChild(input);
    div.appendChild(removeBtn);
    container.appendChild(div);
}

window.addPollOption = function() {
    const container = document.getElementById('poll-options-container');
    if (!container) return;
    createPollOptionElement(container, 'poll-option-input', false, window.updateOptionPlaceholders);
};

window.addEditPollOption = function(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    createPollOptionElement(container, 'edit-poll-option-input', true, () => {
        updatePlaceholders(container, 'edit-poll-option-input');
    });
};

// Clear poll fields after form submit succeeds (htmx event)
document.body.addEventListener('htmx:afterRequest', (evt) => {
    // Check if the source element is the message publish form and request succeeded
    if (evt.detail.elt && evt.detail.elt.classList.contains('chat-message-form') && evt.detail.successful) {
        const pollComposer = document.querySelector('.poll-composer');
        if (pollComposer && pollComposer.style.display === 'block') {
            // Close composer to reset everything
            window.togglePollComposer();
        }
    }
});
