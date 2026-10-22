import { initializePageUi } from './page-ui';

initializePageUi(document, window);
// Match the existing public-page install-prompt behavior. Account pages own
// the install button and its saved prompt; guests have no account notifications.
window.addEventListener('beforeinstallprompt', event => event.preventDefault());
