// Preserve scroll position when loading older messages (prepending to top of #live-feed)
let loadMoreScrollTracker = null;

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
    } else {
        const lastMessage = feed.querySelector('[data-last-message="true"]');
        if (lastMessage) {
            lastMessage.scrollIntoView({block: 'end', behavior: 'auto'});
        } else {
            feed.scrollTop = feed.scrollHeight;
        }
    }
}

export function adjustScrollForLinkPreview(previewCard) {
    const feed = document.getElementById('live-feed');
    if (!feed) return;

    const unreadSeparator = feed.querySelector('.unread-separator');
    const lastMessage = feed.querySelector('[data-last-message="true"]');

    const performScroll = () => {
        if (unreadSeparator) {
            const rect = unreadSeparator.getBoundingClientRect();
            const feedRect = feed.getBoundingClientRect();
            const isSeparatorVisible = (rect.top >= feedRect.top && rect.bottom <= feedRect.bottom);
            if (isSeparatorVisible) {
                unreadSeparator.scrollIntoView({block: 'start'});
            }
        } else if (lastMessage) {
            const rect = lastMessage.getBoundingClientRect();
            const feedRect = feed.getBoundingClientRect();
            const isLastMessageVisible = (rect.bottom <= feedRect.bottom + 50);
            if (isLastMessageVisible) {
                lastMessage.scrollIntoView({block: 'end'});
            }
        } else {
            const threshold = 200;
            const isNearBottom = (feed.scrollHeight - feed.scrollTop - feed.clientHeight) < threshold;
            if (isNearBottom) {
                feed.scrollTop = feed.scrollHeight;
            }
        }
    };

    performScroll();

    const images = previewCard.querySelectorAll('img');
    images.forEach(img => {
        if (!img.complete && !img.dataset.scrollListener) {
            img.dataset.scrollListener = 'true';
            img.addEventListener('load', performScroll, {once: true});
            img.addEventListener('error', performScroll, {once: true});
        }
    });
}

export function initInfiniteScroll() {
    const feed = document.getElementById('live-feed');
    if (!feed) return;

    if (!feed.dataset.infiniteScrollBound) {
        feed.addEventListener('scroll', () => {
            if (window.triggerInfiniteScrollCheck) {
                window.triggerInfiniteScrollCheck();
            }
        });
        feed.dataset.infiniteScrollBound = 'true';
    }

    // Always schedule a check to see if we need to load more immediately (e.g. if not scrollable or already near top)
    setTimeout(() => {
        if (window.triggerInfiniteScrollCheck) {
            window.triggerInfiniteScrollCheck();
        }
    }, 100);
}

window.triggerInfiniteScrollCheck = function () {
    const feed = document.getElementById('live-feed');
    if (!feed) return;

    const trigger = document.getElementById('load-more-trigger');
    if (trigger && !trigger.classList.contains('htmx-request')) {
        const isNearTop = feed.scrollTop < 30;
        const isNotScrollable = feed.scrollHeight <= feed.clientHeight;
        if (isNearTop || isNotScrollable) {
            const btn = trigger.querySelector('.btn-load-more');
            if (btn) {
                btn.click();
            }
        }
    }
};

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
