import './bootstrap';
import '@fontsource-variable/inter';
import Alpine from 'alpinejs';
import { initSubmitLoading } from './submit-loading';
import { initOtp } from './otp';

window.Alpine = Alpine;

Alpine.start();

initSubmitLoading();

initOtp();
