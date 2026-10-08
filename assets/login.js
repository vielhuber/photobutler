export function initializeLogin(navigatePage) {
    let $form = document.querySelector('#login-form');
    let $error = document.querySelector('#login-error');
    let $submit = $form.querySelector('button');

    $form.addEventListener('submit', async event => {
        event.preventDefault();
        $submit.disabled = true;
        $error.textContent = '';
        try {
            let initialization = await fetch('./', { cache: 'no-store' });
            let $page = new DOMParser().parseFromString(await initialization.text(), 'text/html');
            let csrf = $page.querySelector('meta[name="csrf-token"]')?.content;
            if (!initialization.ok || !csrf)
                throw new Error('Sitzung konnte nicht vorbereitet werden. Bitte erneut versuchen.');
            let body = new FormData($form);
            body.set('csrf', csrf);
            let response = await fetch($form.action, { method: 'POST', body });
            if (response.status === 403)
                throw new Error('Sitzung ist abgelaufen oder ungültig. Bitte die Seite neu laden und erneut anmelden.');
            if (response.status === 429) throw new Error('Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.');
            if (!response.ok) throw new Error('Benutzername oder Passwort stimmt nicht.');
            let result = await response.json();
            if (!result.success || !result.data?.access_token) throw new Error('Anmeldung fehlgeschlagen.');
            let session = await fetch('./', {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'login',
                    csrf,
                    access_token: result.data.access_token
                })
            });
            if (!session.ok) throw new Error('Sitzung konnte nicht erstellt werden. Bitte die Seite neu laden.');
            await navigatePage(location.href, { replace: true });
        } catch (error) {
            $error.textContent = error.message;
            $submit.disabled = false;
        }
    });
}
