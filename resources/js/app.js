import './bootstrap';
import './charts';
import './pwa';

import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

import themeSwitcher from './alpine/theme-switcher';
import appShell from './alpine/shell';
import commandPalette from './alpine/command-palette';
import searchableSelect from './alpine/searchable-select';
import toastStack from './alpine/toast-stack';
import { toast } from './toast';

// x-collapse animates the nav groups open and closed by height.
Alpine.plugin(collapse);

/*
| Everything the shell needs, registered before Alpine starts. Pages keep using
| plain inline `x-data="{ ... }"` as they always have; these are the named
| components the layout and the shared UI components refer to.
*/
Alpine.data('themeSwitcher', themeSwitcher);
Alpine.data('appShell', appShell);
Alpine.data('commandPalette', commandPalette);
Alpine.data('searchableSelect', searchableSelect);
Alpine.data('toastStack', toastStack);

// Pages (and other agents' scripts) can raise a toast from anywhere:
//   toast('Saved', { variant: 'success' })
window.toast = toast;

window.Alpine = Alpine;
Alpine.start();
