// ==UserScript==
// @name         Roquette Theme for Rocket.Chat
// @namespace    http://tampermonkey.net/
// @version      2.0
// @description  Rocket.Chat restylé aux couleurs de Roquette (glassmorphisme, user-hue, lettres-avatars)
// @author       MR
// @match        https://chat.u-bordeaux.fr/*
// @icon         https://www.google.com/s2/favicons?sz=64&domain=u-bordeaux.fr
// @grant        GM_addStyle
// @run-at       document-start
// ==/UserScript==

(function () {
    'use strict';

    // ============================================================
    //  1.  OUTIL — hue déterministe depuis un pseudo
    // ============================================================

    const getHue = (str) => {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            hash = (str.charCodeAt(i) + ((hash << 5) - hash)) | 0;
        }
        return Math.abs(hash) % 360;
    };

    const getInitials = (name) => {
        if (!name) return null;
        const parts = name.split(/[\s._-]+/).filter(Boolean);
        if (parts.length === 0) return null;
        if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
        return parts[0].charAt(0).toUpperCase() + parts[parts.length - 1].charAt(0).toUpperCase();
    };

    // ============================================================
    //  2.  CHARGEMENT POLICE Outfit
    // ============================================================

    const fontStyle = document.createElement('style');
    fontStyle.textContent = "@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300..700&display=swap');";
    document.documentElement.insertBefore(fontStyle, document.documentElement.firstChild);

    // ============================================================
    //  3.  CSS GLOBAL —  tout le thème Roquette
    // ============================================================

    GM_addStyle(`
        /* ---- 3.1  THÈME FONDAMENTAL ---- */
        body {
            background-color: hsl(222, 47%, 9%) !important;
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
                         Helvetica, Arial, sans-serif !important;
            color: hsl(210, 40%, 98%) !important;
        }

        /* ---- 3.2  SCROLLBAR ---- */
        ::-webkit-scrollbar { width: 6px !important; }
        ::-webkit-scrollbar-track { background: transparent !important; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1) !important; border-radius: 3px !important; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2) !important; }
        * { scrollbar-width: thin !important; scrollbar-color: rgba(255,255,255,0.1) transparent !important; }

        /* ---- 3.3  GLASSMORPHISME / CARTES ---- */
        #rocket-chat {
            gap: 12px !important;
        }
        .rcx-sidebar,
        main {
            background: hsla(223, 47%, 14%, 0.6) !important;
            backdrop-filter: blur(20px) !important;
            -webkit-backdrop-filter: blur(20px) !important;
        }

        /* ---- 3.4  TYPOGRAPHIE ---- */
        .rcx-message-body {
            font-size: 0.925rem !important;
            line-height: 1.4 !important;
        }
        .rcx-message-header__name,
        .rcx-message-header__alias {
            font-size: 0.8rem !important;
            font-weight: 600 !important;
        }
        .rcx-message-header__time,
        .rcx-message-toolbar__item {
            font-size: 0.75rem !important;
            color: hsl(215, 20%, 65%) !important;
        }
        .rcx-sidebar-v2-item__title {
            font-weight: 600 !important;
            font-size: 0.95rem !important;
        }
        .rcx-sidebar-v2-item__subtitle {
            font-style: italic !important;
            opacity: 0.45 !important;
            font-size: 0.7rem !important;
        }
        .rcx-sidebar-v2-item__title {
            font-size: 0.9rem !important;
            font-weight: 500 !important;
            letter-spacing: 0.05em !important;
        }

        /* ---- 3.5  CONTENEUR DU FEED ---- */
        /* Transparent uniquement dans les z    ones principales — pas dans les menus/popups/overlays */
        main .rcx-box:not([role="listbox"]),
        main .rcx-box--full:not([role="listbox"]),
        [data-qa="message-list"],
        [data-qa="message-list"] ul,
        [data-qa="message-list"] ol,
        .rcx-message-list,
        .rcx-message-list ul,
        .rcx-message-list ol,
        ul[role="list"],
        ol[role="list"] {
            background: transparent !important;
        }
        /* Search results & overlays — solid background to defeat sidebar glass inheritance */
        .rcx-sidebar [role="listbox"],
        .rcx-sidebar [role="listbox"] * {
            background: hsl(223, 47%, 14%) !important;
        }

        /* ---- 3.6  MESSAGES ---- */
        .rcx-message {
            border: 1px solid rgba(255,255,255,0.06) !important;
            border-left: 3px solid hsl(var(--user-hue, 0), 85%, 55%) !important;
            border-radius: 8px !important;
            background: rgba(255,255,255,0.025) !important;
            margin-bottom: 0.5rem !important;
            padding: 0.5rem 0.75rem !important;
            transition: all 0.15s cubic-bezier(0.4, 0, 0.2, 1) !important;
        }
        .rcx-message:hover {
            border-left-color: hsl(var(--user-hue, 0), 85%, 65%) !important;
            background: rgba(255,255,255,0.04) !important;
            box-shadow: 0 2px 8px hsla(var(--user-hue, 0), 85%, 55%, 0.15) !important;
        }

        /* Fusion séquentielle */
        .rcx-message[data-sequential="true"],
        .rcx-message--sequential {
            border-top: 1px solid transparent !important;
            border-top-left-radius: 0 !important;
            border-top-right-radius: 0 !important;
            margin-top: -0.5rem !important;
        }
        .rcx-message:has(+ .rcx-message[data-sequential="true"]),
        .rcx-message:has(+ .rcx-message--sequential) {
            border-bottom: 1px solid transparent !important;
            border-bottom-left-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
        }

        /* ---- 3.7  MESSAGES SYSTÈME ---- */
        .rcx-message-system {
            opacity: 0.4 !important;
            filter: grayscale(0.8) !important;
            font-size: 0.8rem !important;
            border: none !important;
            border-left: 2px solid hsl(215, 20%, 65%) !important;
            background: transparent !important;
            padding: 0.25rem 0.75rem !important;
            transition: opacity 0.2s ease !important;
        }
        .rcx-message-system:hover {
            opacity: 1 !important;
            filter: grayscale(0) !important;
        }

        /* ---- 3.8  MESSAGES /me ---- */
        .rcx-message[data-is-me="true"] {
            border-left: none !important;
            background: transparent !important;
        }

        /* ---- 3.9  FILS DE DISCUSSION (THREADS) ---- */
        .rcx-message-thread {
            border: 1px solid rgba(255,255,255,0.06) !important;
            border-left: 2px solid hsl(var(--user-hue, 0), 85%, 55%) !important;
            border-radius: 6px !important;
            margin: 0 0 0 28px !important;
            padding: 0.25rem 0.5rem !important;
            background: rgba(255,255,255,0.01) !important;
            display: flex !important;
        }
        .rcx-message-thread__row {
            border: none !important;
            border-radius: 0 !important;
            padding: 0.15rem 0 !important;
            margin: 0 !important;
            flex-grow: 1 !important;
        }
        .rcx-message-thread-list li,
        li:has(.rcx-message-thread) {
            margin: 0 !important;
            padding: 0 !important;
            list-style: none !important;
            display: block !important;
        }

        /* ---- 3.10  AVATARS LETTRES ---- */
        .rcx-avatar {
            position: relative !important;
            flex-shrink: 0 !important;
        }
        .rcx-avatar[data-first-letter] {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 26px !important;
            height: 26px !important;
            min-width: 26px !important;
            min-height: 26px !important;
            overflow: visible !important;
        }
        .rcx-avatar[data-first-letter].rcx-avatar--x36,
        .rcx-avatar[data-first-letter].rcx-avatar--x32,
        .rcx-avatar[data-first-letter].rcx-avatar--x28,
        .rcx-avatar[data-first-letter].rcx-avatar--x24,
        .rcx-avatar[data-first-letter].rcx-avatar--x20,
        .rcx-avatar[data-first-letter].rcx-avatar--x18,
        .rcx-avatar[data-first-letter].rcx-avatar--x16,
        .rcx-avatar[data-first-letter].rcx-avatar--x14,
        .rcx-avatar[data-first-letter].rcx-avatar--x12,
        .rcx-avatar[data-first-letter].rcx-avatar--x10 {
            width: 26px !important;
            height: 26px !important;
            min-width: 26px !important;
            min-height: 26px !important;
        }
        .rcx-avatar[data-first-letter] .rcx-avatar__avatar,
        .rcx-avatar[data-first-letter] img.avatar-image,
        .rcx-avatar[data-first-letter] img:not([data-qa]),
        .rcx-avatar[data-first-letter] .rcx-avatar__element {
            display: none !important;
        }
        .rcx-message-header__avatar,
        [data-qa="avatar"] {
            overflow: visible !important;
        }
        .rcx-avatar[data-first-letter]::before {
            content: attr(data-first-letter);
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 26px !important;
            height: 26px !important;
            border-radius: 50% !important;
            background: linear-gradient(
                135deg,
                hsl(var(--user-hue, 0), 85%, 55%),
                hsl(calc(var(--user-hue, 0) + 40), 85%, 40%)
            ) !important;
            color: #fff !important;
            font-weight: 700 !important;
            font-size: 0.7rem !important;
            text-transform: uppercase !important;
            line-height: 26px !important;
            text-align: center !important;
            box-shadow: 0 2px 6px hsla(var(--user-hue, 0), 85%, 55%, 0.3) !important;
            flex-shrink: 0 !important;
        }

        /* ---- 3.11  SIDEBAR ---- */
        .rcx-sidebar-v2-item {
            border-radius: 0.375rem !important;
            transition: all 0.15s ease !important;
        }
        .rcx-sidebar-v2-item:hover {
            border-left: 3px solid hsl(var(--room-hue, 187), 85%, 55%) !important;
            background: rgba(255,255,255,0.03) !important;
        }
        .rcx-sidebar-v2-item--selected {
            border-left: 3px solid hsl(var(--room-hue, 187), 100%, 50%) !important;
            background: hsla(var(--room-hue, 187), 100%, 50%, 0.08) !important;
            box-shadow: inset 0 0 10px hsla(var(--room-hue, 187), 100%, 50%, 0.05) !important;
        }
        .rcx-sidebar-v2-item--selected .rcx-sidebar-v2-item__title {
            color: hsl(var(--room-hue, 187), 100%, 50%) !important;
            font-weight: 600 !important;
        }

        /* Sidebar — avatars seulement dans les DMs */
        .rcx-sidebar-v2-item:not([href*="/direct/"]) .rcx-sidebar-v2-item__avatar {
            display: none !important;
        }

        /* Sidebar — sections */
        .rcx-sidebar-v2-collapse-group {
            margin: 4px 0 !important;
        }
        #rocket-chat .rcx-sidebar-v2-collapse-group__title {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            text-align: center !important;
            font-size: 0.8rem !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.05em !important;
            background: hsla(var(--section-hue, 187), 100%, 45%, 0.12) !important;
            color: hsl(var(--section-hue, 187), 100%, 55%) !important;
            padding: 0.35rem 0.5rem !important;
            border-radius: 0.375rem !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
        }
        #rocket-chat .rcx-sidebar-v2-collapse-group__title:hover {
            background: hsla(var(--section-hue, 187), 100%, 45%, 0.2) !important;
            color: hsl(var(--section-hue, 187), 100%, 65%) !important;
        }

        /* Sidebar — utilisateurs offline atténués */
        .rcx-sidebar-v2-item:has(.rcx-status-bullet--offline) {
            filter: grayscale(0.5) !important;
            opacity: 0.3 !important;
        }

        /* ---- 3.12  BADGES ---- */
        .rcx-badge--danger,
        .rcx-badge--unread {
            background: linear-gradient(135deg, hsl(0, 84%, 60%), hsl(330, 84%, 60%)) !important;
            border-radius: 9999px !important;
            font-size: 0.7rem !important;
            box-shadow: 0 0 8px hsla(0, 84%, 60%, 0.4) !important;
        }
        .rcx-badge--mention {
            background: linear-gradient(135deg, hsl(271, 91%, 65%), hsl(271, 91%, 55%)) !important;
            animation: roquette-pulse 1.5s ease-in-out infinite !important;
        }

        /* ---- 3.13  MENTIONS ---- */
        .mention-link--user,
        .mention-link {
            background: hsl(var(--user-hue, 0), 85%, 55%) !important;
            color: #1f2329 !important;
            border-radius: 4px !important;
            padding: 0 0.25rem !important;
        }

        /* ---- 3.14  SURVOL UTILISATEUR (highlight) ---- */
        body[data-highlight-user] .rcx-message,
        body[data-highlight-user] .rcx-message-system {
            opacity: 0.3 !important;
            filter: grayscale(0.6) !important;
        }
        body[data-highlight-user] [data-highlight-match='true'] {
            opacity: 1 !important;
            filter: grayscale(0) !important;
            transform: scale(1.005) !important;
            z-index: 10 !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3) !important;
        }
        body[data-highlight-user]
            .rcx-message-system:not([data-highlight-match='true']) {
            opacity: 0.05 !important;
            filter: grayscale(1) !important;
            pointer-events: none !important;
        }

        /* ---- 3.15  SUPPRESSIONS ---- */
        #css-theme { display: none !important; }
        .rcx-sidebar-footer { display: none !important; }

        /* ---- 3.16  ANIMATIONS ---- */
        @keyframes roquette-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
    `);

    // ============================================================
    //  4.  ÉTAT
    // ============================================================

    let currentUser = null;
    const processedRooms = new WeakSet();

    // ============================================================
    //  5.  FONCTIONS DE TRAITEMENT
    // ============================================================

    const detectCurrentUser = () => {
        if (currentUser) return;
        const img = document.querySelector(
            '.rcx-navbar .rcx-avatar img[data-username]'
        );
        if (img) {
            currentUser =
                img.getAttribute('data-username') ||
                img.getAttribute('title') ||
                null;
        }
    };

    const getUsernameForNode = (node) => {
        let username = node.getAttribute('data-username');
        if (username) return username;

        const nameEl = node.querySelector(
            '.rcx-message-header__name, .rcx-message-system__name, .rcx-avatar img[data-username]'
        );
        username =
            nameEl?.getAttribute('data-username') ||
            nameEl?.getAttribute('title') ||
            nameEl?.closest('[data-username]')?.getAttribute('data-username');

        if (!username) {
            let prev = node.parentElement;
            while ((prev = prev.previousElementSibling) && !username) {
                const prevMsg = prev.querySelector(
                    '.rcx-message, .rcx-message-system, .rcx-message-thread'
                );
                if (prevMsg) {
                    username = prevMsg.getAttribute('data-username');
                }
            }
        }
        return username || null;
    };

    const processAvatar = (avatarEl, username, displayName) => {
        if (!avatarEl || !username) return;
        const hue = getHue(username);
        avatarEl.style.setProperty('--user-hue', hue);
        const initials = getInitials(displayName || username);
        avatarEl.setAttribute('data-first-letter', initials || username.charAt(0).toUpperCase());
        avatarEl.setAttribute('data-roquette', '');
        const img = avatarEl.querySelector('img');
        if (img) img.style.display = 'none';
    };

    const processMessage = (node) => {
        if (!node || node.nodeType !== Node.ELEMENT_NODE) return;
        const selector =
            '.rcx-message, .rcx-message-system, .rcx-message-thread, .rcx-message-thread__row';
        if (!node.matches(selector)) return;

        const username = getUsernameForNode(node);

        if (username) {
            const hue = getHue(username);
            node.style.setProperty('--user-hue', hue);
            node.setAttribute('data-username', username);
        } else if (node.matches('[data-sequential="true"], .rcx-message--sequential')) {
            const prev = node.parentElement?.previousElementSibling;
            if (prev) {
                const prevMsg = prev.querySelector(selector);
                if (prevMsg) {
                    const hue = prevMsg.style.getPropertyValue('--user-hue');
                    if (hue) node.style.setProperty('--user-hue', hue);
                }
            }
        }

        if (!username) return;

        detectCurrentUser();
        if (currentUser && username === currentUser) {
            node.setAttribute('data-is-me', 'true');
        }

        const displayName = node.querySelector('.rcx-message-header__name')?.textContent?.trim();
        const avatar = node.querySelector('.rcx-avatar');
        if (avatar) processAvatar(avatar, username, displayName);
    };

    const processAllMessages = (root) => {
        if (!root || root.nodeType !== Node.ELEMENT_NODE) return;
        processMessage(root);
        root.querySelectorAll(
            '.rcx-message, .rcx-message-system, .rcx-message-thread, .rcx-message-thread__row'
        ).forEach(processMessage);
    };

    const getDisplayNameForAvatar = (av) => {
        const msg = av.closest('.rcx-message, .rcx-message-thread, .rcx-message-system');
        if (msg) {
            return msg.querySelector('.rcx-message-header__name')?.textContent?.trim();
        }
        const sidebar = av.closest('.rcx-sidebar-v2-item');
        if (sidebar) {
            return sidebar.querySelector('.rcx-sidebar-v2-item__title')?.textContent?.trim();
        }
        return null;
    };

    const processAllAvatars = () => {
        document.querySelectorAll('.rcx-avatar').forEach((av) => {
            if (av.hasAttribute('data-roquette')) return;
            const img = av.querySelector('img');
            let username =
                img?.getAttribute('data-username') ||
                img?.getAttribute('title') ||
                img?.getAttribute('alt') ||
                av.closest('[data-username]')?.getAttribute('data-username');
            if (!username) {
                const sidebarItem = av.closest('.rcx-sidebar-v2-item[href*="/direct/"]');
                if (sidebarItem) {
                    const href = sidebarItem.getAttribute('href');
                    username = decodeURIComponent(href.split('/direct/')[1]?.split('/')[0]?.split(',')[0] || '');
                }
            }
            if (!username) return;
            const displayName = getDisplayNameForAvatar(av);
            processAvatar(av, username, displayName);
        });
    };

    const processRooms = () => {
        document.querySelectorAll('.rcx-sidebar-v2-item').forEach((item) => {
            if (processedRooms.has(item)) return;
            const title = item.querySelector('.rcx-sidebar-v2-item__title');
            if (!title) return;
            const href = item.getAttribute('href') || '';
            let hue = 187; // cyan par défaut (channels)
            if (href.includes('/direct/')) {
                const icon = item.querySelector('.rcx-sidebar-v2-item__icon');
                const hasMultiple = icon?.querySelector('.rcx-icon--name-balloon, .rcx-icon--name-members, .rcx-icon--name-team') || title.textContent.includes(',');
                if (!hasMultiple) hue = 271; // purple pour DMs individuels
            }
            item.style.setProperty('--room-hue', hue);
            processedRooms.add(item);
        });
    };

    const processSections = () => {
        document.querySelectorAll('.rcx-sidebar-v2-collapse-group').forEach((section) => {
            const titleEl = section.querySelector('.rcx-sidebar-v2-collapse-group__title');
            if (!titleEl) return;

            if (!section.hasAttribute('data-section-hue')) {
                const text = titleEl.textContent?.toLowerCase().trim() || '';
                let hue = 187; // cyan par défaut (canaux)
                if (text.includes('direct') || text.includes('message') || text.includes('dm')) {
                    hue = 271; // purple pour DMs
                } else if (text.includes('favori') || text.includes('star') || text.includes('favorite')) {
                    hue = 55; // gold pour favoris
                } else if (text.includes('todo') || text.includes('task')) {
                    hue = 142; // green pour todos
                } else if (text.includes('shortcut') || text.includes('raccourci') || text.includes('fréquent') || text.includes('frequent')) {
                    hue = 35; // orange pour raccourcis
                }
                section.style.setProperty('--section-hue', hue);
                section.setAttribute('data-section-hue', hue);
            }

            titleEl.style.setProperty('display', 'flex', 'important');
            titleEl.style.setProperty('align-items', 'center', 'important');
            titleEl.style.setProperty('justify-content', 'center', 'important');
            titleEl.style.setProperty('text-align', 'center', 'important');
            titleEl.style.setProperty('width', '100%', 'important');
            titleEl.style.setProperty('box-sizing', 'border-box', 'important');
        });
    };

    const handleHover = (e) => {
        const nameNode = e.target.closest(
            '.rcx-message-header__name, .rcx-avatar, .rcx-message-system__name, .rcx-message-header__alias'
        );
        if (nameNode) {
            const msg = e.target.closest(
                '.rcx-message, .rcx-message-system, .rcx-message-thread'
            );
            const username =
                nameNode.getAttribute('data-username') ||
                nameNode.querySelector('img')?.getAttribute('data-username') ||
                msg?.getAttribute('data-username');
            if (username) {
                document.body.setAttribute('data-highlight-user', username);
                document
                    .querySelectorAll(`[data-username="${username}"]`)
                    .forEach((el) => {
                        if (
                            el.matches(
                                '.rcx-message, .rcx-message-system, .rcx-message-thread, .rcx-message-thread__row'
                            )
                        ) {
                            el.setAttribute('data-highlight-match', 'true');
                        }
                    });
            }
        } else if (document.body.hasAttribute('data-highlight-user')) {
            document.body.removeAttribute('data-highlight-user');
            document
                .querySelectorAll('[data-highlight-match]')
                .forEach((el) => el.removeAttribute('data-highlight-match'));
        }
    };

    // ============================================================
    //  6.  OBSERVER
    // ============================================================

    const observer = new MutationObserver((mutations) => {
        const theme = document.querySelector('#css-theme');
        if (theme) theme.remove();
        const footer = document.querySelector('.rcx-sidebar-footer');
        if (footer) footer.remove();

        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                processAllMessages(node);
            }
        }
        processAllAvatars();
        processRooms();
        processSections();
    });

    // ============================================================
    //  7.  INIT
    // ============================================================

    document.addEventListener('DOMContentLoaded', () => {
        document.body.classList.add('dark-mode');

        processAllMessages(document.body);
        processAllAvatars();
        processRooms();
        processSections();

        document.body.addEventListener('mouseover', handleHover);
        document.body.addEventListener('mouseout', (e) => {
            if (
                !e.relatedTarget ||
                !e.relatedTarget.closest(
                    '.rcx-message-header__name, .rcx-avatar, .rcx-message-system__name'
                )
            ) {
                handleHover(e);
            }
        });

        observer.observe(document.body, { childList: true, subtree: true });
    });
})();
