
(function () {
    'use strict';

    const API_URL = 'api/recommendations.php';
    const VISITOR_KEY = 'gjl_visitor_key';
    const grid = document.getElementById('gamesGrid');
    const catalogHeader = document.querySelector('.catalog-header');

    if (!catalogHeader) return;

    function getVisitorKey() {
        let key = localStorage.getItem(VISITOR_KEY);

        if (!key) {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                key = window.crypto.randomUUID().replace(/-/g, '');
            } else {
                key = 'gjl' + Date.now().toString(36) +
                    Math.random().toString(36).slice(2) +
                    Math.random().toString(36).slice(2);
            }

            localStorage.setItem(VISITOR_KEY, key);
        }

        return key;
    }

    const visitorKey = getVisitorKey();

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[ch]);
    }

    function logEvent(productId, eventType = 'view') {
        const id = Number(productId);

        if (!Number.isInteger(id) || id < 1) return;

        fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                event_key: visitorKey,
                product_id: id,
                event_type: eventType
            }),
            keepalive: true
        }).catch(() => {});
    }

    // Сохраняем просмотры игр из основного каталога.
    grid?.addEventListener('click', event => {
        const card = event.target.closest('.game-card-wrapper');

        if (!card || event.target.closest('.compare-add-btn')) {
            return;
        }

        logEvent(card.dataset.gameId, 'view');
    });

    function createSection() {
        let section = document.getElementById('personalRecommendations');

        if (section) return section;

        section = document.createElement('section');
        section.id = 'personalRecommendations';
        section.className = 'recommendations-section';

        section.innerHTML = `
            <div class="recommendations-heading">
                <h2>🎯 Рекомендации для вас</h2>
                <p id="recommendationsSubtitle">
                    Подбираем игры по вашим интересам
                </p>
            </div>

            <div class="games recommendations-grid" id="recommendationsGrid"></div>

            <p class="recommendations-error hidden" id="recommendationsError"></p>
        `;

        catalogHeader.insertAdjacentElement('afterend', section);

        return section;
    }

    function renderGames(items, mode) {
        const section = createSection();
        const list = section.querySelector('#recommendationsGrid');
        const subtitle = section.querySelector('#recommendationsSubtitle');

        if (!items || items.length === 0) {
            subtitle.textContent = 'Пока нет игр для рекомендаций.';
            return;
        }

        subtitle.textContent = mode === 'personalized'
            ? 'Подборка рассчитана по вашим просмотрам и SQL-матрицам предпочтений.'
            : 'Пока это подборка по рейтингу. Просматривайте игры — рекомендации начнут учитывать ваши интересы.';

        list.innerHTML = items.map(game => {
            const id = Number(game.id);
            const name = escapeHtml(game.name);
            const genre = escapeHtml(game.genre_text || 'Игра');
            const rating = Number(game.rating || 0).toFixed(1);
            const type = escapeHtml(game.game_type || 'Не указан');
            const platforms = escapeHtml(game.platforms || 'Не указаны');

            const image = String(game.image_url || '').trim();

            const imageHtml = image
                ? `<img src="${escapeHtml(image)}" alt="${name}" loading="lazy" onerror="this.style.display='none'">`
                : '<span>🎮</span>';

            const price = Number(game.is_free) === 1
                ? 'Бесплатно'
                : (Number(game.price) > 0
                    ? `От ${Number(game.price).toFixed(2)} USD`
                    : 'Цена не указана');

            return `
                <div class="game-card-wrapper recommendation-card" data-game-id="${id}">
                    <a href="#" class="game-link recommendation-game-link" data-game-id="${id}">
                        <div class="game-card">
                            <div class="game-card-image">
                                ${imageHtml}
                            </div>

                            <div class="game-card-content">
                                <h2>${name}</h2>
                                <p class="game-genre">${genre}</p>
                                <p class="game-rating">⭐ ${rating} / 5</p>
                                <p>🎮 ${type}</p>
                                <p>💻 ${platforms}</p>
                                <div class="game-price">
                                    <strong>${escapeHtml(price)}</strong>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            `;
        }).join('');

        list.addEventListener('click', event => {
            const link = event.target.closest('.recommendation-game-link');

            if (!link) return;

            event.preventDefault();

            logEvent(link.dataset.gameId, 'view');

            subtitle.textContent =
                'Просмотр сохранён. Обновите страницу, чтобы пересчитать рекомендации.';
        });
    }

    async function loadRecommendations() {
        createSection();

        try {
            const response = await fetch(
                `${API_URL}?event_key=${encodeURIComponent(visitorKey)}`,
                {
                    headers: {
                        'Accept': 'application/json'
                    }
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(
                    data.message || 'Не удалось загрузить рекомендации.'
                );
            }

            renderGames(data.items || [], data.mode);

        } catch (error) {
            const section = createSection();
            const errorBox = section.querySelector('#recommendationsError');

            errorBox.textContent =
                error.message || 'Ошибка загрузки рекомендаций.';

            errorBox.classList.remove('hidden');

            section.querySelector('#recommendationsSubtitle').textContent =
                'Рекомендации временно недоступны.';
        }
    }

    loadRecommendations();
})();
