import './bootstrap';
import '@fontsource-variable/inter';
import Alpine from 'alpinejs';
import { initSubmitLoading } from './submit-loading';

window.Alpine = Alpine;

Alpine.start();

initSubmitLoading();
