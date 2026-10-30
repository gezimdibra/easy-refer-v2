import Alpine from 'alpinejs';
import { initSubmitLoading } from './submit-loading';
import { initOtp } from './otp';

// The customer flow entry is deliberately minimal (design-system.md
// Section 4.6): Alpine only, no other library, and no webfont payload;
// the flow's font stack falls back to the system face, which Section
// 2.2 explicitly permits on this surface. The one shared module is
// the submit loading state, so a posting flow form gives the same
// CP-02 feedback as every other surface.
window.Alpine = Alpine;

Alpine.start();

initSubmitLoading();

// Device signal (spec Section 3.1): a stable per-device random id
// persisted locally and offered to the flow forms through their
// hidden device_signal fields. It feeds risk scoring only;
// nothing in the flow depends on it being present.
try {
    let deviceId = window.localStorage.getItem('er2_device');

    if (!deviceId) {
        deviceId = Array.from(window.crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
        window.localStorage.setItem('er2_device', deviceId);
    }

    document.querySelectorAll('[data-device-signal]').forEach((el) => {
        el.value = deviceId;
    });
} catch (e) {
    // Storage or crypto unavailable: the signal is simply absent.
}

initOtp();
