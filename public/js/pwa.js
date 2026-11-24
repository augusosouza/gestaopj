const augiPwa = document.querySelector('[data-augi-pwa]');

if (augiPwa && window.isSecureContext && 'serviceWorker' in navigator) {
    const installButton = augiPwa.querySelector('[data-pwa-install]');
    const instructions = augiPwa.querySelector('details');
    const updateNotice = augiPwa.querySelector('[data-pwa-update]');
    const standalone = window.matchMedia('(display-mode: standalone)');
    let installPrompt;

    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        installPrompt = event;
        installButton.hidden = false;
        instructions.hidden = true;
    });

    function updateInstallControls() {
        const installed = standalone.matches || navigator.standalone === true;
        if (installed) {
            instructions.hidden = true;
            installButton.hidden = true;
        }
        augiPwa.hidden = installed && updateNotice.hidden;
    }

    standalone.addEventListener('change', updateInstallControls);
    window.addEventListener('appinstalled', () => {
        installPrompt = undefined;
        augiPwa.hidden = true;
    });

    installButton.addEventListener('click', async () => {
        if (!installPrompt) return;

        const prompt = installPrompt;
        installPrompt = undefined;
        installButton.hidden = true;
        try {
            const { outcome } = await prompt.prompt();
            if (outcome === 'accepted') augiPwa.hidden = true;
        } catch {
            // O navegador pode revogar o pedido após a captura.
        }
        instructions.hidden = false;
    });

    navigator.serviceWorker.register(augiPwa.dataset.augiPwa, { updateViaCache: 'none' })
        .then(registration => {
            function showUpdate() {
                if (registration.waiting && navigator.serviceWorker.controller) {
                    updateNotice.hidden = false;
                    augiPwa.hidden = false;
                }
            }

            registration.addEventListener('updatefound', () => {
                registration.installing?.addEventListener('statechange', showUpdate);
            });
            showUpdate();
            updateInstallControls();
        })
        .catch(() => { augiPwa.hidden = true; });
}
