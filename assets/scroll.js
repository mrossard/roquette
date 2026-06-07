// Preserve scroll position when loading older messages (prepending to top of #live-feed)
let loadMoreScrollTracker = null;
let wasAtBottom = true;

document.body.addEventListener('htmx:beforeSwap', (evt) => {
    const target = evt.detail.target;
    if (target && (target.id === 'load-more-trigger' || target.classList.contains('load-more-container'))) {
        const feed = document.getElementById('live-feed');
        if (feed) {
            loadMoreScrollTracker = {
                scrollHeight: feed.scrollHeight,
                scrollTop: feed.scrollTop
            };
        }
    }
});

document.body.addEventListener('htmx:afterSwap', (evt) => {
    const target = evt.detail.target;
    if (target && (target.id === 'load-more-trigger' || target.classList.contains('load-more-container')) && loadMoreScrollTracker) {
        const feed = document.getElementById('live-feed');
        if (feed) {
            const heightDifference = feed.scrollHeight - loadMoreScrollTracker.scrollHeight;
            feed.scrollTop = loadMoreScrollTracker.scrollTop + heightDifference;
        }
        loadMoreScrollTracker = null;
    }
});

export function initializeChannelScroll() {
    const feed = document.getElementById('live-feed');
    if (!feed) return;

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('jumpTo')) {
        return;
    }

    const unreadSeparator = feed.querySelector('.unread-separator');
    if (unreadSeparator) {
        unreadSeparator.scrollIntoView({block: 'start', behavior: 'auto'});
        wasAtBottom = false;
    } else {
        const lastMessage = feed.querySelector('[data-last-message="true"]');
        if (lastMessage) {
            lastMessage.scrollIntoView({block: 'end', behavior: 'auto'});
            wasAtBottom = true;
        } else {
            feed.scrollTop = feed.scrollHeight;
            wasAtBottom = true;
        }
    }

    // Bind capturing image load/error listeners once
    if (!feed.dataset.imageListenersBound) {
        feed.dataset.imageListenersBound = 'true';

        const onImageLoad = (event) => {
            if (event.target && event.target.tagName === 'IMG') {
                adjustScrollForFeedContent();
            }
        };

        feed.addEventListener('load', onImageLoad, true); // capturing phase captures non-bubbling events
        feed.addEventListener('error', onImageLoad, true);
    }
}

export function adjustScrollForFeedContent() {
    const feed = document.getElementById('live-feed');
    if (!feed) return;

    const unreadSeparator = feed.querySelector('.unread-separator');
    const lastMessage = feed.querySelector('[data-last-message="true"]');

    // 1. Check if user was at the bottom!
    if (wasAtBottom) {
        feed.scrollTop = feed.scrollHeight;
        return;
    }

    // 2. Otherwise, if they were looking at the unread separator, keep it visible
    if (unreadSeparator) {
        const rect = unreadSeparator.getBoundingClientRect();
        const feedRect = feed.getBoundingClientRect();
        const isSeparatorVisible = (rect.top >= feedRect.top && rect.bottom <= feedRect.bottom);
        if (isSeparatorVisible) {
            unreadSeparator.scrollIntoView({block: 'start'});
            return;
        }
    }

    // 3. Otherwise, if they were looking at the last message, keep it visible
    if (lastMessage) {
        const rect = lastMessage.getBoundingClientRect();
        const feedRect = feed.getBoundingClientRect();
        const isLastMessageVisible = (rect.bottom <= feedRect.bottom + 50);
        if (isLastMessageVisible) {
            lastMessage.scrollIntoView({block: 'end'});
            return;
        }
    }
}

export function adjustScrollForLinkPreview(previewCard) {
    adjustScrollForFeedContent();
}



// Scroll and maintain data-last-message attribute on new SSE messages
document.body.addEventListener('htmx:sseMessage', (event) => {
    if (event.detail.type && event.detail.type.startsWith('message_')) {
        setTimeout(() => {
            const feed = document.getElementById('live-feed');
            if (feed) {
                const oldLast = feed.querySelector('[data-last-message="true"]');
                let shouldScroll = false;
                if (oldLast) {
                    const rect = oldLast.getBoundingClientRect();
                    const feedRect = feed.getBoundingClientRect();
                    shouldScroll = (rect.bottom <= feedRect.bottom + 50);
                    oldLast.removeAttribute('data-last-message');
                } else {
                    shouldScroll = true;
                }

                const newLast = feed.querySelector('.feed-item:last-of-type');
                if (newLast) {
                    newLast.setAttribute('data-last-message', 'true');
                    if (shouldScroll) {
                        newLast.scrollIntoView({block: 'end', behavior: 'smooth'});
                    }
                }
            }
        }, 50);
    }
});
