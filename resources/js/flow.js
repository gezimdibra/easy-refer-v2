import Alpine from 'alpinejs';

// The customer flow entry is deliberately minimal (design-system.md
// Section 4.6): Alpine only, no other library, and no webfont payload;
// the flow's font stack falls back to the system face, which Section
// 2.2 explicitly permits on this surface.
window.Alpine = Alpine;

Alpine.start();

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

// Six-box code entry (inventory REF-VER-01): auto-advance,
// backspace steps back, a paste distributes across the boxes,
// and the boxes compose into the single code field the server
// verifies. Without JS the server assembles the boxes itself.
const otp = document.querySelector('[data-otp]');

if (otp) {
    const boxes = Array.from(otp.querySelectorAll('[data-otp-box]'));
    const composed = document.querySelector('[data-otp-value]');
    const sync = () => {
        if (composed) {
            composed.value = boxes.map((b) => b.value).join('');
        }
    };

    boxes.forEach((box, i) => {
        box.addEventListener('input', () => {
            const digits = box.value.replace(/\D/g, '');
            if (digits.length > 1) {
                // An OS one-time-code autofill drops the whole
                // code into one box: spread it across the boxes
                // like a paste instead of keeping one digit.
                digits.slice(0, boxes.length - i).split('').forEach((d, j) => {
                    boxes[i + j].value = d;
                });
                const next = boxes[Math.min(i + digits.length, boxes.length - 1)];
                if (next) {
                    next.focus();
                }
            } else {
                box.value = digits;
                if (box.value && i < boxes.length - 1) {
                    boxes[i + 1].focus();
                }
            }
            sync();
        });

        box.addEventListener('keydown', (event) => {
            if (event.key === 'Backspace' && !box.value && i > 0) {
                boxes[i - 1].focus();
            }
        });

        box.addEventListener('paste', (event) => {
            event.preventDefault();
            const digits = (event.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
            digits.split('').forEach((d, j) => {
                if (boxes[j]) {
                    boxes[j].value = d;
                }
            });
            const next = boxes[Math.min(digits.length, boxes.length - 1)];
            if (next) {
                next.focus();
            }
            sync();
        });
    });
}
