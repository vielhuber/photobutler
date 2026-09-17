let { test } = require('node:test');
let assert = require('node:assert/strict');
let { readFileSync } = require('node:fs');

test('desktop grid uses the selected columns with only a two-column mobile override', () => {
    let css = readFileSync('assets/app.css', 'utf8');
    let templates = [...css.matchAll(/\.photo-grid\s*\{[^}]*grid-template-columns:\s*([^;]+);/g)].map(
        match => match[1]
    );
    assert.deepEqual(templates, ['repeat(var(--gallery-columns), minmax(0, 1fr))', 'repeat(2, minmax(0, 1fr))']);
    assert.match(css, /--gallery-columns:\s*5;/);
    assert.match(css, /@media \(max-width: 760px\)\s*\{[\s\S]*\.photo-grid\s*\{\s*grid-template-columns: repeat\(2,/);
});

test('layout preferences load before styles and gallery markup on both entry pages', () => {
    for (let template of ['templates/gallery.php', 'templates/login.php']) {
        let html = readFileSync(template, 'utf8');
        let preferences = html.indexOf('<script src="?asset=preferences.js"></script>');
        assert.ok(preferences >= 0);
        assert.ok(preferences < html.indexOf('<link rel="stylesheet"'));
        assert.ok(preferences < html.indexOf('<body'));
    }
});

test('column dropdown offers every integer from three to nine and defaults to five', () => {
    let template = readFileSync('templates/gallery.php', 'utf8');
    let dropdown = template.match(/<select id="gallery-columns" aria-label="Spalten">(.*?)<\/select>/s)[1];
    assert.deepEqual(
        [...dropdown.matchAll(/value="(\d+)"/g)].map(match => match[1]),
        ['3', '4', '5', '6', '7', '8', '9']
    );
    assert.match(dropdown, /<option value="5" selected>5<\/option>/);
});
