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

// Options dynamic fields additions are handled via hx-post/vals and server-side routes

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
