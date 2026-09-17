let { test } = require('node:test');
let assert = require('node:assert/strict');
let { readFileSync } = require('node:fs');
let { runInNewContext } = require('node:vm');

for (let [status, message] of [
    [403, 'Sitzung ist abgelaufen oder ungültig. Bitte die Seite neu laden und erneut anmelden.'],
    [401, 'Benutzername oder Passwort stimmt nicht.'],
    [429, 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.']
]) {
    test(`login reports HTTP ${status} without creating a session or retrying credentials`, async () => {
        let submit;
        let requests = 0;
        let $error = { textContent: '' };
        let $button = { disabled: false };
        let $form = {
            action: '/index.php/login',
            querySelector: () => $button,
            addEventListener: (event, callback) => (submit = callback)
        };
        runInNewContext(readFileSync('assets/login.js', 'utf8').replace('export ', '') + '\ninitializeLogin();', {
            document: { querySelector: selector => (selector === '#login-form' ? $form : $error) },
            FormData: class {},
            fetch: async () => {
                requests++;
                return { status, ok: false };
            }
        });
        await submit({ preventDefault() {} });
        assert.equal($error.textContent, message);
        assert.equal($button.disabled, false);
        assert.equal(requests, 1);
    });
}
