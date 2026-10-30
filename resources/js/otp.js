// Six-box code entry (inventory REF-VER-01), shared by the
// customer flow's verify step and the owner's two-factor
// challenge (AU-9): auto-advance, backspace steps back, a paste
// distributes across the boxes, and the boxes compose into the
// single code field the server verifies. Without JS the server
// assembles the boxes itself.
export function initOtp() {
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

        // OTP-01: a tap on any box starts at the first box. An OS
        // one-time-code autofill drops the code into the FOCUSED
        // box and the spread below distributes only forward, so a
        // code offered while a middle box is focused loses its
        // last digits (the owner's iPhone report). Programmatic
        // focus moves mark their target here so the redirect can
        // tell the component's own navigation (auto-advance,
        // backspace, paste) from a person's tap or tab.
        let navTarget = -1;
        const focusBox = (index) => {
            navTarget = index;
            boxes[index].focus();
            // A focus() on the box that already holds focus fires
            // no event to consume the mark; clear any remainder
            // after this task so a later genuine tap is never
            // mistaken for the component's own navigation.
            setTimeout(() => {
                navTarget = -1;
            }, 0);
        };

        boxes.forEach((box, i) => {
            if (i > 0) {
                box.addEventListener('focus', () => {
                    if (navTarget === i) {
                        navTarget = -1;
                        return;
                    }
                    boxes[0].focus();
                });
            }

            box.addEventListener('input', () => {
                const digits = box.value.replace(/\D/g, '');
                if (digits.length > 1) {
                    // An OS one-time-code autofill drops the whole
                    // code into one box: spread it across the boxes
                    // like a paste instead of keeping one digit.
                    digits.slice(0, boxes.length - i).split('').forEach((d, j) => {
                        boxes[i + j].value = d;
                    });
                    focusBox(Math.min(i + digits.length, boxes.length - 1));
                } else {
                    box.value = digits;
                    if (box.value && i < boxes.length - 1) {
                        focusBox(i + 1);
                    }
                }
                sync();
            });

            box.addEventListener('keydown', (event) => {
                if (event.key === 'Backspace' && !box.value && i > 0) {
                    focusBox(i - 1);
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
                focusBox(Math.min(digits.length, boxes.length - 1));
                sync();
            });
        });
    }
}
