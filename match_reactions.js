(function () {
    if (window.__matchReactionsInit) {
        return;
    }

    window.__matchReactionsInit = true;

    function setButtonState(button, active, count) {
        button.setAttribute('aria-pressed', active ? 'true' : 'false');

        const countEl = button.querySelector('.reaction-count');
        if (countEl) {
            countEl.textContent = String(count);
        }

        button.classList.toggle('bg-[#ff0080]/25', active);
        button.classList.toggle('border-[#ff0080]/60', active);
        button.classList.toggle('text-white', active);
        button.classList.toggle('shadow-[0_0_15px_rgba(255,0,128,0.25)]', active);
        button.classList.toggle('bg-black/25', !active);
        button.classList.toggle('border-white/15', !active);
        button.classList.toggle('text-gray-200', !active);
    }

    function applySummary(root, summary) {
        if (!root || !summary) {
            return;
        }

        root.querySelectorAll('.reaction-btn').forEach(function (button) {
            const type = button.getAttribute('data-reaction-type');
            const data = summary[type];

            if (!data) {
                return;
            }

            setButtonState(button, !!data.active, parseInt(data.count || 0, 10));

            const usersEl = button.parentElement
                ? button.parentElement.querySelector('.reaction-users')
                : null;

            if (usersEl && Array.isArray(data.users)) {
                usersEl.textContent = data.users.length
                    ? data.users.map(function (user) { return user.username; }).join(', ')
                    : '\u00a0';
            }
        });
    }

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.reaction-btn');

        if (!button || button.disabled) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const matchId = button.getAttribute('data-match-id');
        const reactionType = button.getAttribute('data-reaction-type');
        const root = button.closest('[data-reactions-root]');

        if (!matchId || !reactionType) {
            return;
        }

        button.disabled = true;

        const body = new URLSearchParams();
        body.set('match_id', matchId);
        body.set('reaction_type', reactionType);

        const toggleUrl = new URL('reaction_toggle.php', window.location.href).href;

        fetch(toggleUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'Accept': 'application/json',
            },
            body: body.toString(),
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok || !payload || !payload.success) {
                        throw new Error((payload && payload.message) || 'Could not update reaction.');
                    }

                    return payload;
                });
            })
            .then(function (payload) {
                applySummary(root, payload.summary);
            })
            .catch(function (error) {
                alert(error.message || 'Could not update reaction.');
            })
            .finally(function () {
                button.disabled = false;
            });
    });
})();
