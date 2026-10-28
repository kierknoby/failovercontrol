'use strict';

const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { JSDOM, VirtualConsole } = require('jsdom');

const fields = {
    number: { 'fc-failover-number': '02079460123', 'fc-route': '8' },
    trunk: { 'fc-failover-trunk': '6' },
    misc_destination: { 'fc-failover-misc': '10' }
};
const fixture = (action, payload) => execFileSync('php', [path.join(__dirname, 'view_fixture.php'), action], {
    input: JSON.stringify(payload),
    encoding: 'utf8',
    env: { ...process.env, XDEBUG_MODE: 'off' },
    stdio: ['pipe', 'pipe', 'pipe']
});
const save = (control, input) => JSON.parse(fixture('save', { control, input }));
const render = (control) => fixture('render', { control });
const errors = [];
const virtualConsole = new VirtualConsole();
virtualConsole.on('jsdomError', (error) => errors.push(error.message));

function assertVisibility(window, type) {
    for (const [destinationType, controls] of Object.entries(fields)) {
        for (const id of Object.keys(controls)) {
            const field = window.document.getElementById(id);
            const group = field.closest('[data-failover-type]');
            assert.equal(group.hidden, destinationType !== type, id + ' visibility');
            assert.equal(window.getComputedStyle(group).display === 'none', destinationType !== type, id + ' display');
            assert.equal(field.disabled, destinationType !== type, id + ' disabled state');
        }
    }
}

const existing = Object.keys(fields).map((type) => save(null, {
    name: 'Existing ' + type,
    primary_trunk_id: '3',
    failover_type: type,
    failover_number: '07876838629',
    outbound_route_id: '7',
    failover_trunk_id: '5',
    failover_misc_destination_id: '9',
    ring_timeout: '0'
}));

for (const control of [null, ...existing]) {
    const html = render(control);
    const initialType = control?.failover_type ?? 'number';
    const initial = new JSDOM(html);
    assertVisibility(initial.window, initialType);
    initial.window.close();
    const dom = new JSDOM(html, { runScripts: 'dangerously', virtualConsole });
    const { window } = dom;
    const { document } = window;
    const typeSelect = document.getElementById('fc-failover-type');
    const form = document.getElementById('fc-control-form');
    const switchType = (type) => {
        typeSelect.value = type;
        typeSelect.dispatchEvent(new window.Event('change', { bubbles: true }));
        assertVisibility(window, type);
    };
    try {
        assert.equal(typeSelect.value, initialType);
        assertVisibility(window, initialType);
        if (control !== null) {
            const values = {
                number: { 'fc-failover-number': control.failover_number, 'fc-route': String(control.outbound_route_id) },
                trunk: { 'fc-failover-trunk': control.failover_destination_id },
                misc_destination: { 'fc-failover-misc': control.failover_destination_id }
            };
            for (const [id, value] of Object.entries(values[initialType])) {
                assert.equal(document.getElementById(id).value, value, 'Saved field did not load');
            }
        }
        document.getElementById('fc-name').value = 'Visibility test';
        document.getElementById('fc-trunk').value = '3';
        for (const [type, controls] of Object.entries(fields)) {
            switchType(type);
            for (const [id, value] of Object.entries(controls)) {
                document.getElementById(id).value = value;
            }
        }
        for (const [type, controls] of Object.entries(fields)) {
            switchType(type);
            for (const [id, value] of Object.entries(controls)) {
                assert.equal(document.getElementById(id).value, value, 'Switching types lost an entered value');
            }
            const input = Object.fromEntries(new window.FormData(form));
            const names = { number: ['failover_number', 'outbound_route_id'], trunk: ['failover_trunk_id'], misc_destination: ['failover_misc_destination_id'] };
            for (const [destinationType, destinationNames] of Object.entries(names)) {
                destinationNames.forEach((name) => assert.equal(Object.hasOwn(input, name), destinationType === type, 'Unexpected submitted field: ' + name));
            }
            const saved = save(control, input);
            assert.equal(saved.failover_type, type);
            assert.equal(saved.failover_number, type === 'number' ? '02079460123' : '');
            assert.equal(saved.failover_destination_id, type === 'number' ? '' : (type === 'trunk' ? '6' : '10'));
            if (control !== null) {
                assert.equal(saved.id, control.id, 'Editing changed the control identifier');
            }
            const readback = new JSDOM(render(saved), { runScripts: 'dangerously', virtualConsole });
            assertVisibility(readback.window, type);
            for (const [id, value] of Object.entries(controls)) {
                assert.equal(readback.window.document.getElementById(id).value, value, 'Saved destination did not round-trip');
            }
            readback.window.close();
        }
        const number = document.getElementById('fc-failover-number');
        number.setCustomValidity('Blocked number');
        switchType('trunk');
        assert.equal(number.willValidate, false);
        assert.equal(form.checkValidity(), true, 'Hidden invalid field blocked submission');
        switchType('number');
        assert.equal(form.checkValidity(), false, 'Active invalid field did not block submission');
        number.setCustomValidity('');
    } finally {
        window.close();
    }
}
assert.throws(() => save(null, {
    name: 'Invalid submitted destination',
    primary_trunk_id: '3',
    failover_type: 'number',
    failover_number: '999',
    ring_timeout: '0'
}), /UK geographic or mobile number/, 'Server-side number validation was bypassed');
assert.deepEqual(errors, [], 'Form JavaScript errors');
console.log('Destination field DOM contracts passed (new and saved controls, switching, values, validation, submission and persistence)');
