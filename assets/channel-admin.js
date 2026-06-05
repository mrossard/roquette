// Autocomplete and chip management for Channel Administrators settings
(function () {
    let currentAdmins = new Set();
    let channelMembers = [];

    window.initAdminAutocomplete = function (members, initialAdminIds) {
        channelMembers = members;
        currentAdmins = new Set(initialAdminIds);

        const autocompleteInput = document.getElementById('admin-autocomplete-input');
        if (!autocompleteInput) return;

        // Reset input value
        autocompleteInput.value = '';

        // Ensure the input is initialized with the standard autocomplete system
        if (window.initEmojiAutocomplete) {
            window.initEmojiAutocomplete();
        }

        // Remove any existing event listener to avoid duplicate additions
        autocompleteInput.removeEventListener('autocomplete-user-selected', handleUserSelected);
        autocompleteInput.addEventListener('autocomplete-user-selected', handleUserSelected);
    };

    function handleUserSelected(e) {
        const selectedUser = e.detail.user;

        // Find user in channelMembers to make sure they are a member of this channel
        const member = channelMembers.find(m => m.username === selectedUser.username);
        if (member) {
            window.addAdminChip(member);
        } else {
            alert("Cet utilisateur n'est pas membre de ce canal.");
        }
    }

    window.addAdminChip = function (member) {
        if (currentAdmins.has(member.id)) return;
        currentAdmins.add(member.id);

        const chip = document.createElement('div');
        chip.className = 'admin-chip';
        chip.id = `admin-chip-${member.id}`;
        chip.style.background = 'rgba(99, 102, 241, 0.2)';
        chip.style.border = '1px solid #6366f1';
        chip.style.padding = '0.25rem 0.75rem';
        chip.style.borderRadius = '2rem';
        chip.style.fontSize = '0.85rem';
        chip.style.display = 'flex';
        chip.style.alignItems = 'center';
        chip.style.gap = '0.5rem';
        chip.style.color = 'var(--text-primary)';

        chip.innerHTML = `
            <span>${member.name}</span>
            <button type="button" onclick="removeAdmin(${member.id})" style="background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 0; font-size: 1rem; line-height: 1; display: flex; align-items: center;">&times;</button>
            <input type="hidden" name="administrators[]" value="${member.id}">
        `;
        const chipsContainer = document.getElementById('admin-chips-container');
        if (chipsContainer) {
            chipsContainer.appendChild(chip);
        }
    };

    window.removeAdmin = function (id) {
        currentAdmins.delete(id);
        const chip = document.getElementById(`admin-chip-${id}`);
        if (chip) {
            chip.remove();
        }
    };
})();
