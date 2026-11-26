(function () {
    'use strict';

    async function sendEvent(productId, eventType) {
        try {
            const res = await fetch('api/event.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ product_id: productId, event_type: eventType })
            });
            return await res.json().catch(() => ({}));
        } catch (e) {
            console.warn('Event send error:', e);
            return { success: false };
        }
    }

    document.querySelectorAll('.game-action-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            const id = parseInt(btn.dataset.gameId, 10);
            const type = btn.dataset.event;
            if (!id || !type) return;
            const res = await sendEvent(id, type);
            if (res.success) {
                if (type === 'cart') {
                    btn.textContent = '✅ В корзине';
                    btn.classList.add('success');
                } else if (type === 'purchase') {
                    btn.textContent = '✅ Куплено';
                    btn.classList.add('success');
                } else if (type === 'view') {
                    btn.textContent = '👁 Просмотрено';
                    btn.classList.add('success');
                }
            }
        });
    });
})();